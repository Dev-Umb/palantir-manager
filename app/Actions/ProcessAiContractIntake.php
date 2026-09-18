<?php

namespace App\Actions;

use App\Ai\Agents\ContractExtractionAgent;
use App\Integrations\Feishu\FeishuAttachmentStorage;
use App\Models\AiContractIntake;
use App\Models\AuditLog;
use App\Models\BusinessObject;
use App\Models\ObjectRecord;
use App\Models\StoredAttachment;
use App\Models\User;
use App\Support\BusinessWorkspace;
use App\Support\ProjectVisibility;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Files\Document;
use Laravel\Ai\Files\Image;
use Throwable;

class ProcessAiContractIntake
{
    public function __construct(
        private FeishuAttachmentStorage $storage,
        private SyncProjectContracts $contracts,
        private ResyncProjectContractAmount $resync,
        private ProjectVisibility $visibility,
        private BusinessWorkspace $workspace,
    ) {}

    public function canUpload(User $user): bool
    {
        return $user->canDo('object.project.update') && $user->canDo('object.contract.view')
            && ($this->workspace->isAdmin($user) || $this->workspace->isBusiness($user));
    }

    public function stage(User $user, UploadedFile $file): AiContractIntake
    {
        abort_unless($this->canUpload($user), 403);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($file->getContent());
        if (! in_array($mime, ['application/pdf', 'image/jpeg', 'image/png'], true)) {
            throw ValidationException::withMessages(['file' => '只支持内容有效的 PDF、JPG 或 PNG 文件。']);
        }
        $attachment = $this->storage->store($file->getContent(), $file->getClientOriginalName());

        return AiContractIntake::create(['user_id' => $user->id, 'stored_attachment_id' => $attachment->id, 'status' => 'staged']);
    }

    public function analyze(AiContractIntake $intake, User $user): AiContractIntake
    {
        $this->authorize($intake, $user);
        $claimed = AiContractIntake::whereKey($intake->id)->where('status', 'staged')->update(['status' => 'analyzing']);
        abort_unless($claimed, 409, '文件已开始识别或已经处理。');
        try {
            $attachment = $intake->storedAttachment;
            $file = $attachment->mime_type === 'application/pdf'
                ? Document::fromStorage($attachment->object_key, $attachment->disk)->as($attachment->original_name)
                : Image::fromStorage($attachment->object_key, $attachment->disk);
            $response = ContractExtractionAgent::make()->stream(
                '请识别附件中的项目合同，按约定结构提取，缺失填 null，并给出原文依据和警告。',
                attachments: [$file],
                provider: config('ai.default'),
                timeout: min(180, (int) config('ai.request_timeout', 180)),
            );
            $response->each(static fn (): bool => true);
            $extraction = Validator::make(json_decode($response->text, true, flags: JSON_THROW_ON_ERROR), [
                'project_name' => ['present', 'nullable', 'string', 'max:300'],
                'project_no' => ['present', 'nullable', 'string', 'max:100'],
                'customer_name' => ['present', 'nullable', 'string', 'max:300'],
                'contract_no' => ['present', 'nullable', 'string', 'max:200'],
                'amount' => ['present', 'nullable', 'numeric'],
                'ctype' => ['present', 'nullable', 'in:销售合同,加工合同,补充协议'],
                'signed_date' => ['present', 'nullable', 'date_format:Y-m-d'],
                'contract_qty' => ['present', 'nullable', 'numeric', 'min:0'],
                'weight_tonnes' => ['present', 'nullable', 'numeric', 'min:0'],
                'evidence' => ['present', 'array'],
                'evidence.*' => ['string'],
                'warnings' => ['present', 'array'],
                'warnings.*' => ['string'],
            ])->validate();
            $intake->update(['status' => 'review', 'extraction' => $extraction, 'review' => null, 'error' => null]);
        } catch (Throwable $exception) {
            // Provider diagnostics may contain document contents; retain only the failure class.
            $intake->update(['status' => 'failed', 'error' => '合同识别失败，文件已暂存，可重试。']);
            logger()->warning('AI contract extraction failed', ['intake_id' => $intake->id, 'exception_class' => $exception::class]);
        }

        return $intake->fresh('storedAttachment');
    }

    public function candidates(User $user, string $search = ''): array
    {
        abort_unless($this->canUpload($user), 403);
        $object = BusinessObject::where('key', 'project')->firstOrFail();
        $query = $this->visibility->scope($object->records(), $user);
        if (! $this->workspace->isAdmin($user)) {
            $query->where('payload->business_owner_user_id', (string) $user->id);
        }
        if ($search !== '') {
            $query->where(function ($query) use ($search): void {
                $query->whereLike('title', '%'.$search.'%')->orWhereLike('code', '%'.$search.'%');
            });
        }
        $projects = $query->orderBy('code')->limit(51)->get();

        return ['projects' => $projects->take(50)->map(fn (ObjectRecord $project): array => [
            'id' => $project->id, 'name' => $project->title, 'code' => $project->code,
        ])->all(), 'truncated' => $projects->count() > 50];
    }

    public function project(User $user, string $id): ObjectRecord
    {
        abort_unless($this->canUpload($user), 403);
        $project = ObjectRecord::with('businessObject')->whereRelation('businessObject', 'key', 'project')->findOrFail($id);
        abort_unless($this->visibility->allowsProject($user, $project) && $this->visibility->allowsProjectUpdate($user, $project), 403);

        return $project;
    }

    public function projectDetails(User $user, string $id): array
    {
        $project = $this->project($user, $id);

        return [
            'id' => $project->id, 'name' => $project->title, 'code' => $project->code,
            'contract_amount' => $project->payload['contract_amount'] ?? null,
            'weight' => $project->payload['weight'] ?? null,
            'can_update_project_amount' => $this->workspace->isAdmin($user),
            'contracts' => $this->projectContracts($project)->map(fn (ObjectRecord $contract): array => [
                'id' => $contract->id, 'code' => $contract->code,
                'fields' => Arr::only($contract->payload ?? [], ['amount', 'ctype', 'signed_date', 'contract_qty']),
            ])->values()->all(),
        ];
    }

    public function preview(AiContractIntake $intake, User $user, array $input): array
    {
        return DB::transaction(fn (): array => $this->buildPreview(
            AiContractIntake::lockForUpdate()->findOrFail($intake->id), $user, $input,
        ));
    }

    private function buildPreview(AiContractIntake $intake, User $user, array $input): array
    {
        $this->authorize($intake, $user);
        abort_unless($intake->status === 'review', 409, '请先完成文件识别。');
        $project = $this->project($user, $input['project_id']);
        $contracts = $this->projectContracts($project);
        $contract = filled($input['contract_id'] ?? null) ? $contracts->firstWhere('id', $input['contract_id']) : null;
        $paths = $contracts->flatMap(fn ($item) => $item->payload['contract_attachments'] ?? [])->filter()->all();
        abort_if(StoredAttachment::whereIn('logical_path', $paths)->where('sha256', $intake->storedAttachment->sha256)->exists(), 422, '相同内容的合同文件已在该项目归档，请查看已有合同。');
        abort_if(filled($input['contract_id'] ?? null) && ! $contract, 422, '合同不属于该项目。');
        abort_if($input['update_project_amount'] && ! $this->workspace->isAdmin($user), 403, '当前账号无权覆盖项目主档合同金额。');
        $writable = $this->workspace->writableFieldKeys($project->businessObject, $user);
        abort_if(isset($input['project_weight']) && ! in_array('weight', $writable, true), 403);
        $input['fields']['amount'] = round((float) $input['fields']['amount'], 2);
        $changes = [];
        foreach (['amount' => '合同金额（元）', 'ctype' => '合同类型', 'signed_date' => '签订日期', 'contract_qty' => '合同数量'] as $key => $label) {
            $changes[] = ['label' => $label, 'before' => $contract?->payload[$key] ?? null, 'after' => $input['fields'][$key] ?? null];
        }
        $total = round($contracts->reject(fn ($item) => $item->id === $contract?->id)->sum(fn ($item) => (float) ($item->payload['amount'] ?? 0)) + $input['fields']['amount'], 2);
        $changes[] = [
            'label' => '项目主档合同金额（元）', 'before' => $project->payload['contract_amount'] ?? null,
            'after' => $input['update_project_amount'] || ! is_numeric($project->payload['contract_amount'] ?? null) ? $total : ($project->payload['contract_amount'] ?? null),
        ];
        if (isset($input['project_weight'])) {
            $changes[] = ['label' => '项目合同重量（吨）', 'before' => $project->payload['weight'] ?? null, 'after' => $input['project_weight']];
        }
        $review = [
            'token' => (string) Str::uuid(), 'input' => $input, 'fingerprint' => $this->fingerprint($project, $contracts),
            'project' => ['name' => $project->title, 'code' => $project->code],
            'contract' => $contract?->code ?? '新建合同（系统自动编号）',
            'file' => $intake->storedAttachment->original_name,
            'changes' => $changes,
            'effects' => ['原有附件保留，本文件追加到合同附件。', '合同状态设为已签署，项目状态及提醒沿用现有合同联动规则。', '已发生、已回款、未回款、对账和开票金额不修改。'],
        ];
        $intake->update(['review' => $review]);

        return Arr::except($review, ['input', 'fingerprint']);
    }

    public function confirm(AiContractIntake $intake, User $user, string $token): array
    {
        $this->authorize($intake, $user);

        return DB::transaction(function () use ($intake, $user, $token): array {
            $intake = AiContractIntake::lockForUpdate()->findOrFail($intake->id);
            $review = $intake->review;
            abort_unless(is_array($review) && hash_equals($review['token'], $token), 409, '确认信息已过期，请重新预览。');
            $input = $review['input'];
            $project = $this->project($user, $input['project_id']);
            $project = ObjectRecord::with('businessObject')->lockForUpdate()->findOrFail($project->id);
            if ($intake->status === 'confirmed') {
                return $intake->result;
            }
            abort_unless($intake->status === 'review', 409);
            abort_if($input['update_project_amount'] && ! $this->workspace->isAdmin($user), 403);
            $contracts = $this->projectContracts($project, true);
            abort_unless(hash_equals($review['fingerprint'], $this->fingerprint($project, $contracts)), 409, '项目或合同已被修改，请重新预览差异。');
            $contract = filled($input['contract_id'] ?? null) ? $contracts->firstWhere('id', $input['contract_id']) : null;
            $fields = $input['fields'];
            $row = [...$fields, 'status' => $contract?->payload['status'] ?? '未签署'];
            if ($contract) {
                $row['id'] = $contract->id;
                // Contract fields outside the reviewed subset remain intact.
                $row = [...Arr::only($contract->payload, ['contract_chase_record', 'remark']), ...$row];
            }
            $batch = $this->contracts->validate(new Request(['contracts' => [$row], 'deleted_contract_ids' => []]), $project);
            $this->contracts->handle($project, $batch, $user);
            if (! $contract) {
                $contract = $this->projectContracts($project)->first(fn ($item) => ! $contracts->contains('id', $item->id));
            }
            abort_unless($contract, 409);
            $this->contracts->appendStoredAttachment($project, $contract, 'contract_attachments', $intake->storedAttachment, $user, 'ai');
            if ($input['update_project_amount']) {
                $this->resync->handle($project->fresh(), $user);
            }
            if (isset($input['project_weight'])) {
                abort_unless(in_array('weight', $this->workspace->writableFieldKeys($project->businessObject, $user), true), 403);
                $project->refresh();
                $project->update(['payload' => [...$project->payload, 'weight' => (float) $input['project_weight']]]);
            }
            $result = ['project_id' => $project->id, 'contract_id' => $contract->id, 'contract_code' => $contract->code, 'project_url' => route('objects.index', ['object' => 'project', 'record' => $project->id], false), 'message' => '合同已归档，确认的字段已更新。'];
            $intake->update(['status' => 'confirmed', 'result' => $result, 'confirmed_at' => now()]);
            AuditLog::create(['user_id' => $user->id, 'action' => 'ai.contract.confirmed', 'subject_type' => 'ai_contract_intake', 'subject_id' => $intake->id, 'payload' => ['project_id' => $project->id, 'contract_id' => $contract->id, 'changes' => $review['changes']]]);

            return $result;
        });
    }

    public function authorize(AiContractIntake $intake, User $user): void
    {
        abort_unless((string) $intake->user_id === (string) $user->id && $this->canUpload($user), 403);
    }

    private function projectContracts(ObjectRecord $project, bool $lock = false): Collection
    {
        $query = ObjectRecord::whereRelation('businessObject', 'key', 'contract')->where('payload->project_id', $project->id)->orderBy('id');

        return ($lock ? $query->lockForUpdate() : $query)->get();
    }

    private function fingerprint(ObjectRecord $project, Collection $contracts): string
    {
        return hash('sha256', json_encode([$project->payload, $contracts->map(fn ($contract) => [$contract->id, $contract->payload])->all()]));
    }
}
