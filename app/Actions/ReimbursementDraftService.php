<?php

namespace App\Actions;

use App\Models\Reimbursement;
use App\Models\ReimbursementDraft;
use App\Models\ReimbursementUpload;
use App\Models\ReimbursementVersion;
use App\Models\StoredAttachment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReimbursementDraftService
{
    public function authorize(User $actor, ReimbursementDraft $draft): void
    {
        abort_unless(! $actor->isTimebookOperator() && $actor->id === $draft->user_id, 403);
    }

    public function checkSource(User $actor, array $payload): void
    {
        if (! empty($payload['reimbursement_id'])) {
            $parent = Reimbursement::findOrFail($payload['reimbursement_id']);
            abort_unless($parent->user_id === $actor->id, 403);
            abort_unless(ReimbursementVersion::where('reimbursement_id', $parent->id)->where('number', $parent->latest_version)->where('number', $payload['expected_version'])->where('status', 'rejected')->exists(), 409, '此申请已变化，不能继续修改重提。');
        }
    }

    public function save(User $actor, string $id, array $data, bool $create = false): ReimbursementDraft
    {
        return DB::transaction(function () use ($actor, $id, $data, $create): ReimbursementDraft {
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $draft = ReimbursementDraft::lockForUpdate()->find($id);
            if ($draft) {
                $this->authorize($actor, $draft);
                abort_if($draft->version_id !== null, 409, '草稿已提交，请查看报销清单。');
                // Retrying an acknowledged-lost save is safe only for the exact same payload.
                if ($draft->revision === (int) $data['revision'] + 1 && $draft->payload === $data['payload']) {
                    return $draft;
                }
                abort_unless($draft->revision === (int) $data['revision'], 409, '草稿已在其他页面更新，请重新打开草稿，当前输入不会自动覆盖。');
                abort_unless(($draft->payload['reimbursement_id'] ?? null) === ($data['payload']['reimbursement_id'] ?? null) && ($draft->payload['expected_version'] ?? null) === ($data['payload']['expected_version'] ?? null), 409);
            } else {
                abort_unless($create && (int) $data['revision'] === 0, 404);
            }
            $this->checkSource($actor, $data['payload']);
            $ids = collect($data['payload']['items'])->flatMap(fn ($item) => $item['attachments'])->all();
            foreach ($ids as $uploadId) {
                $upload = ReimbursementUpload::find($uploadId);
                if (! $upload || $upload->user_id !== $actor->id || ($upload->draft_id !== null && $upload->draft_id !== $id && $upload->reimbursement_id === null) || ($upload->reimbursement_id !== null && $upload->reimbursement_id !== ($data['payload']['reimbursement_id'] ?? null))) {
                    throw ValidationException::withMessages(['payload.items' => '附件不属于此草稿或已用于其他申请。']);
                }
            }
            abort_unless(count($ids) === count(array_unique($ids)), 422, '附件不能重复使用。');
            $draft ??= new ReimbursementDraft(['id' => $id, 'user_id' => $actor->id]);
            $draft->fill(['payload' => $data['payload'], 'revision' => $draft->revision + 1])->save();
            ReimbursementUpload::whereIn('id', $ids)->whereNull('draft_id')->update(['draft_id' => $id]);

            return $draft;
        });
    }

    public function present(ReimbursementDraft $draft): array
    {
        $payload = $draft->payload;
        $ids = collect($payload['items'])->flatMap(fn ($i) => $i['attachments'])->all();
        $uploads = ReimbursementUpload::whereIn('id', $ids)->get()->keyBy('id');
        $stored = StoredAttachment::whereIn('id', $uploads->pluck('stored_attachment_id'))->get()->keyBy('id');
        foreach ($payload['items'] as &$item) {
            $item['attachments'] = array_map(function ($id) use ($uploads, $stored, $draft): array {
                $upload = $uploads->get($id);
                $file = $stored->get($upload?->stored_attachment_id);

                return ['id' => $id, 'name' => $file?->original_name ?? '附件不可用', 'mime' => $file?->mime_type, 'size' => $file?->size, 'url' => route('reimbursements.drafts.file', [$draft->id, $id], false)];
            }, $item['attachments']);
        }

        return ['source_version_id' => empty($payload['reimbursement_id']) ? null : ReimbursementVersion::where('reimbursement_id', $payload['reimbursement_id'])->where('number', $payload['expected_version'])->value('id'), 'id' => $draft->id, 'revision' => $draft->revision, 'payload' => $payload, 'updated_at' => $draft->updated_at->toISOString(), 'url' => route('reimbursements.drafts.show', $draft->id, false)];
    }
}
