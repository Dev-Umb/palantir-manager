<?php

namespace App\Integrations\Feishu;

use App\Actions\SyncProjectContracts;
use App\Models\BusinessObject;
use App\Models\FeishuFileUpload;
use App\Models\FeishuInboundEvent;
use App\Models\FeishuUserBinding;
use App\Models\ObjectRecord;
use App\Support\ProjectVisibility;
use Illuminate\Support\Str;
use Throwable;

class FeishuContractAttachmentService
{
    public function __construct(
        private FeishuClient $client,
        private FeishuAttachmentStorage $storage,
        private SyncProjectContracts $contracts,
        private ProjectVisibility $visibility,
    ) {}

    /** @return array<string, mixed> */
    public function stage(FeishuInboundEvent $event, FeishuUserBinding $binding, array $content): array
    {
        $existing = FeishuFileUpload::query()->with('storedAttachment')
            ->where('inbound_event_id', $event->id)->first();
        if ($existing) {
            return $this->stagedCard($existing);
        }

        $fileKey = trim((string) ($content['file_key'] ?? ''));
        $fileName = trim((string) ($content['file_name'] ?? ''));
        if ($fileKey === '' || $fileName === '') {
            throw new \RuntimeException('feishu_file_metadata_missing');
        }

        $resource = $this->client->downloadMessageResource((string) $event->message_id, $fileKey);
        $attachment = $this->storage->store($resource['contents'], $fileName);
        $upload = FeishuFileUpload::create([
            'inbound_event_id' => $event->id,
            'binding_id' => $binding->id,
            'stored_attachment_id' => $attachment->id,
            'conversation_key' => $this->conversationKey($event),
            'file_key' => $fileKey,
            'status' => FeishuFileUpload::STATUS_PENDING,
        ]);

        return $this->stagedCard($upload);
    }

    /** @return array<string, mixed> */
    private function stagedCard(FeishuFileUpload $upload): array
    {
        $attachment = $upload->storedAttachment;

        return $this->card(
            '文件已安全暂存',
            "**暂存编号：** {$upload->id}\n**文件：** {$attachment->original_name}\n**大小：** ".$this->formatBytes($attachment->size)."\n\n请回复：`绑定附件 暂存编号：{$upload->id} 项目编号：项目号 合同编号：合同号 类型：合同附件`\n或将类型改为 `加工函附件`。",
            'blue',
        );
    }

    public function isBindingCommand(string $message): bool
    {
        return Str::startsWith(trim($message), '绑定附件');
    }

    /** @return array<string, mixed> */
    public function bind(FeishuInboundEvent $event, FeishuUserBinding $binding, string $message): array
    {
        $parsed = $this->parse($message);
        if (! $parsed) {
            return $this->blockedCard('格式不完整，请按卡片示例提供项目编号、合同编号和附件类型。');
        }

        $uploads = FeishuFileUpload::query()
            ->with('storedAttachment')
            ->where('binding_id', $binding->id)
            ->where('conversation_key', $this->conversationKey($event))
            ->where('status', FeishuFileUpload::STATUS_PENDING)
            ->where('created_at', '>=', now()->subDay())
            ->when($parsed['upload_id'], fn ($query, int $uploadId) => $query->whereKey($uploadId))
            ->get();
        if ($uploads->count() !== 1) {
            return $this->blockedCard($uploads->isEmpty()
                ? '当前会话没有匹配的待绑定文件，请核对暂存编号，或先发送一份合同或加工函。'
                : '当前会话有多份待绑定文件，请在命令中填写卡片显示的暂存编号，系统不会猜测文件。');
        }

        [$project, $contract, $error] = $this->resolve($parsed['project_no'], $parsed['contract_no']);
        if ($error || ! $project || ! $contract) {
            return $this->blockedCard($error ?: '未找到唯一匹配记录。');
        }
        if (! $binding->user->canDo('object.project.update')
            || ! $this->visibility->allowsProjectUpdate($binding->user, $project)) {
            return $this->blockedCard('你没有该项目的合同维护权限。');
        }

        $upload = $uploads->sole();
        try {
            $this->contracts->appendStoredAttachment(
                $project,
                $contract,
                $parsed['field'],
                $upload->storedAttachment,
                $binding->user,
            );
            $upload->update([
                'status' => FeishuFileUpload::STATUS_ATTACHED,
                'project_id' => $project->id,
                'contract_id' => $contract->id,
                'attachment_field' => $parsed['field'],
                'attached_at' => now(),
                'error' => null,
            ]);
        } catch (Throwable $exception) {
            $upload->update(['error' => mb_substr($exception->getMessage(), 0, 500)]);

            throw $exception;
        }

        return $this->card(
            '附件已追加到合同',
            "**项目：** {$project->title}（{$parsed['project_no']}）\n**合同：** {$parsed['contract_no']}\n**类型：** {$parsed['label']}\n**文件：** {$upload->storedAttachment->original_name}\n\n既有附件已保留。",
            'green',
        );
    }

    /** @return array{0: ?ObjectRecord, 1: ?ObjectRecord, 2: ?string} */
    private function resolve(string $projectNo, string $contractNo): array
    {
        $projectObject = BusinessObject::query()->where('key', 'project')->first();
        $contractObject = BusinessObject::query()->where('key', 'contract')->first();
        if (! $projectObject || ! $contractObject) {
            return [null, null, '项目或合同对象尚未初始化。'];
        }

        $projects = $projectObject->records()->get()->filter(
            fn (ObjectRecord $record): bool => $this->exactIdentifier($record, 'project_no') === $projectNo,
        )->values();
        if ($projects->count() !== 1) {
            return [null, null, $projects->isEmpty() ? '项目编号不存在。' : '项目编号对应多条记录，已阻止写入。'];
        }
        $contracts = $contractObject->records()->get()->filter(
            fn (ObjectRecord $record): bool => $this->exactIdentifier($record, 'contract_no') === $contractNo,
        )->values();
        if ($contracts->count() !== 1) {
            return [null, null, $contracts->isEmpty() ? '合同编号不存在。' : '合同编号对应多条记录，已阻止写入。'];
        }

        $project = $projects->sole();
        $contract = $contracts->sole();
        if ((string) ($contract->payload['project_id'] ?? '') !== $project->id) {
            return [null, null, '合同不属于该项目，已阻止写入。'];
        }

        return [$project, $contract, null];
    }

    private function exactIdentifier(ObjectRecord $record, string $field): string
    {
        return trim((string) (($record->payload[$field] ?? '') ?: $record->code));
    }

    /** @return array{upload_id: ?int, project_no: string, contract_no: string, field: string, label: string}|null */
    private function parse(string $message): ?array
    {
        preg_match('/暂存编号\s*[：:]\s*(\d+)/u', $message, $upload);
        preg_match('/项目编号\s*[：:]\s*([^\s]+)/u', $message, $project);
        preg_match('/合同编号\s*[：:]\s*([^\s]+)/u', $message, $contract);
        preg_match('/类型\s*[：:]\s*(合同附件|加工函附件)/u', $message, $type);
        if (! isset($project[1], $contract[1], $type[1])) {
            return null;
        }

        return [
            'upload_id' => isset($upload[1]) ? (int) $upload[1] : null,
            'project_no' => trim($project[1]),
            'contract_no' => trim($contract[1]),
            'field' => $type[1] === '合同附件' ? 'contract_attachments' : 'processing_letter_attachments',
            'label' => $type[1],
        ];
    }

    private function conversationKey(FeishuInboundEvent $event): string
    {
        $chatId = (string) data_get($event->payload, 'event.message.chat_id');

        return $chatId !== '' ? 'chat:'.$chatId : 'user:'.$event->sender_open_id;
    }

    /** @return array<string, mixed> */
    private function blockedCard(string $reason): array
    {
        return $this->card('附件尚未写入', "**原因：** {$reason}\n\n请核对精确编号后重试。", 'orange');
    }

    /** @return array<string, mixed> */
    private function card(string $title, string $content, string $template): array
    {
        return [
            'config' => ['wide_screen_mode' => true],
            'header' => ['template' => $template, 'title' => ['tag' => 'plain_text', 'content' => $title]],
            'elements' => [['tag' => 'markdown', 'content' => $content]],
        ];
    }

    private function formatBytes(int $bytes): string
    {
        return $bytes >= 1024 * 1024
            ? number_format($bytes / 1024 / 1024, 2).' MB'
            : number_format(max(1, $bytes / 1024), 1).' KB';
    }
}
