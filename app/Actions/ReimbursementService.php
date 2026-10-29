<?php

namespace App\Actions;

use App\Models\AuditLog;
use App\Models\Reimbursement;
use App\Models\ReimbursementBatchFile;
use App\Models\ReimbursementDraft;
use App\Models\ReimbursementNotice;
use App\Models\ReimbursementUpload;
use App\Models\ReimbursementVersion;
use App\Models\ReimbursementWorkflow;
use App\Models\StoredAttachment;
use App\Models\User;
use App\Support\BusinessFileStorage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReimbursementService
{
    public function visible(User $user): Builder
    {
        $query = ReimbursementVersion::query();
        if ($user->canDo('reimbursements.manage')) {
            return $query;
        }

        return $query->whereExists(fn ($q) => $q->selectRaw('1')->from('reimbursement_access')
            ->whereColumn('version_id', 'reimbursement_versions.id')->where('user_id', $user->id));
    }

    public function authorizeVersion(User $user, ReimbursementVersion $version): void
    {
        abort_unless(! $user->isTimebookOperator() && $this->visible($user)->whereKey($version->id)->exists(), 403);
    }

    public function participants(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $users = User::whereIn('id', $ids)->get();
        if ($users->count() !== count($ids) || $users->contains(fn (User $user) => $user->isTimebookOperator())) {
            throw ValidationException::withMessages(['people' => '审批或知会人员已失效，请由管理员调整流程。']);
        }

        return $users->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name])->all();
    }

    public function flowSnapshot(array $definition): array
    {
        if (empty($definition['nodes']) || collect($definition['nodes'])->contains(fn ($node) => empty($node['people']))) {
            throw ValidationException::withMessages(['flow' => '请先由管理员配置实际审批人并保存流程。']);
        }
        $nodes = array_map(fn ($node) => ['name' => $node['name'], 'mode' => $node['mode'], 'people' => $this->participants($node['people'])], $definition['nodes']);

        return ['nodes' => $nodes, 'cc' => $this->participants($definition['cc']), 'triggers' => $definition['triggers']];
    }

    public function saveWorkflow(User $actor, array $data): ReimbursementWorkflow
    {
        return DB::transaction(function () use ($actor, $data): ReimbursementWorkflow {
            $workflow = ReimbursementWorkflow::lockForUpdate()->findOrFail(1);
            abort_unless($workflow->revision === (int) $data['revision'], 409, '流程已更新，请刷新后重试。');
            $definition = array_intersect_key($data, array_flip(['nodes', 'cc', 'triggers']));
            $this->flowSnapshot($definition);
            $workflow->update(['definition' => $definition, 'revision' => $workflow->revision + 1]);
            $this->audit($actor, 'workflow.updated', $workflow->id, ['revision' => $workflow->revision]);

            return $workflow;
        });
    }

    public function submit(User $actor, array $data, ?string $bindingVersion = null): ReimbursementVersion
    {
        return DB::transaction(function () use ($actor, $data, $bindingVersion): ReimbursementVersion {
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $fingerprint = hash('sha256', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $existing = ReimbursementVersion::where('user_id', $actor->id)->where('submission_key', $data['submission_key'])->first();
            if ($existing) {
                abort_unless(hash_equals($existing->submission_fingerprint, $fingerprint), 409, '该提交已保存，请先查看本人申请，不可用同一提交标识覆盖内容。');

                return $existing;
            }
            $draft = null;
            if (! empty($data['draft_id'])) {
                $draft = ReimbursementDraft::lockForUpdate()->findOrFail($data['draft_id']);
                app(ReimbursementDraftService::class)->authorize($actor, $draft);
                abort_unless($draft->version_id === null && $draft->revision === (int) $data['draft_revision'], 409, '草稿已更新或已提交，请重新打开。');
                abort_unless(($draft->payload['reimbursement_id'] ?? null) === ($data['reimbursement_id'] ?? null) && ($draft->payload['expected_version'] ?? null) === ($data['expected_version'] ?? null), 409);
            }
            $request = null;
            if (! empty($data['reimbursement_id'])) {
                $request = Reimbursement::lockForUpdate()->findOrFail($data['reimbursement_id']);
                abort_unless($request->user_id === $actor->id, 403);
                $previous = ReimbursementVersion::where('reimbursement_id', $request->id)->where('number', $request->latest_version)->firstOrFail();
                abort_unless($previous->status === 'rejected' && $previous->number === (int) $data['expected_version'], 409, '仅当前已驳回的申请可修改重提。');
            }
            $flow = $this->flowSnapshot(ReimbursementWorkflow::findOrFail(1)->definition);
            $cc = $this->participants($data['cc']);
            $items = [];
            $allIds = [];
            foreach ($data['items'] as $index => $item) {
                $parts = explode('.', (string) $item['amount']);
                $cents = ((int) $parts[0] * 100) + (int) str_pad($parts[1] ?? '', 2, '0');
                if ($cents < 1) {
                    throw ValidationException::withMessages(["items.$index.amount" => '费用金额必须大于零。']);
                }
                $files = [];
                foreach ($item['attachments'] as $id) {
                    $upload = ReimbursementUpload::whereKey($id)->lockForUpdate()->first();
                    if (! $upload || $upload->user_id !== $actor->id || ($upload->reimbursement_id !== null && $upload->reimbursement_id !== $request?->id) || ($upload->draft_id !== null && $upload->draft_id !== $draft?->id && $upload->reimbursement_id === null) || in_array($id, $allIds, true)) {
                        throw ValidationException::withMessages(["items.$index.attachments" => '发票不存在、重复使用或不属于此申请。']);
                    }
                    $batchFile = ReimbursementBatchFile::where('upload_id', $upload->id)->first();
                    if ($batchFile) {
                        abort_unless($batchFile->status === 'recognized' && ($batchFile->reviewed || ($draft?->batch_id && ($data['batch_review_confirmed'] ?? false))), 422, '请在小程序草稿中核对批量发票后提交。');
                        $batchFile->update(['reviewed' => true]);
                    }
                    $stored = StoredAttachment::findOrFail($upload->stored_attachment_id);
                    app(BusinessFileStorage::class)->verify($stored->disk, $stored->object_key, $stored->size, $stored->sha256);
                    $files[] = ['id' => $upload->id, 'name' => $stored->original_name, 'mime' => $stored->mime_type, 'size' => (int) $stored->size];
                    $allIds[] = $id;
                }
                $items[] = ['invoice_date' => $item['invoice_date'] ?? null, 'description' => $item['description'], 'amount' => number_format($cents / 100, 2, '.', ''), 'cents' => $cents, 'attachments' => $files];
            }
            if (collect($items)->flatMap(fn ($item) => $item['attachments'])->sum('size') > 200 * 1024 * 1024) {
                throw ValidationException::withMessages(['items' => '单次提交的发票合计不能超过 200 MB。']);
            }
            $request ??= Reimbursement::create(['user_id' => $actor->id, 'latest_version' => 0]);
            $request->update(['latest_version' => $request->latest_version + 1]);
            $version = ReimbursementVersion::create([
                'reimbursement_id' => $request->id, 'user_id' => $actor->id, 'number' => $request->latest_version,
                'submission_key' => $data['submission_key'], 'submission_fingerprint' => $fingerprint, 'title' => $data['title'], 'type' => $data['type'], 'note' => $data['note'],
                'items' => $items, 'total_cents' => array_sum(array_column($items, 'cents')), 'flow' => $flow, 'cc' => $cc,
                'decisions' => [], 'node_index' => 0, 'status' => 'pending',
                'wechat_subscribed' => $bindingVersion !== null && ($data['wechat_subscribed'] ?? false), 'wechat_binding_version' => $bindingVersion,
            ]);
            ReimbursementUpload::whereIn('id', $allIds)->update(['reimbursement_id' => $request->id]);
            $draft?->update(['version_id' => $version->id]);
            $this->grant($version, [$actor->id], 'owner');
            $this->grant($version, collect($flow['nodes'])->flatMap(fn ($node) => array_column($node['people'], 'id'))->unique()->all(), 'approver');
            $this->notifyCc($version, 'submitted');
            $this->audit($actor, 'submitted', $version->id, ['number' => $version->number]);

            return $version;
        }, 3);
    }

    public function blocked(ReimbursementVersion $version): bool
    {
        if ($version->status !== 'pending') {
            return false;
        }
        try {
            $this->participants(array_column($version->flow['nodes'][$version->node_index]['people'], 'id'));

            return false;
        } catch (ValidationException) {
            return true;
        }
    }

    public function canDecide(User $actor, ReimbursementVersion $version): bool
    {
        return ! $actor->isTimebookOperator() && $version->status === 'pending' && ! $this->blocked($version)
            && in_array($actor->id, array_column($version->flow['nodes'][$version->node_index]['people'], 'id'), true)
            && ! collect($version->decisions)->contains(fn ($decision) => $decision['node'] === $version->node_index && $decision['user_id'] === $actor->id);
    }

    public function decide(User $actor, ReimbursementVersion $version, array $data): ReimbursementVersion
    {
        return DB::transaction(function () use ($actor, $version, $data): ReimbursementVersion {
            $request = Reimbursement::lockForUpdate()->findOrFail($version->reimbursement_id);
            $version = ReimbursementVersion::lockForUpdate()->findOrFail($version->id);
            abort_unless($version->status === 'pending' && $request->latest_version === $version->number && $version->node_index === (int) $data['node_index'], 409, '审批状态已变化，请刷新。');
            abort_unless($this->canDecide($actor, $version), 403, '当前账号不能处理此节点，或已经处理。');
            $decisions = $version->decisions;
            $decisions[] = ['node' => $version->node_index, 'user_id' => $actor->id, 'name' => $actor->name, 'decision' => $data['decision'], 'note' => $data['note'] ?? '', 'at' => now()->toISOString()];
            $version->decisions = $decisions;
            if ($data['decision'] === 'reject') {
                $version->status = 'rejected';
            } else {
                $node = $version->flow['nodes'][$version->node_index];
                $votes = collect($decisions)->where('node', $version->node_index)->where('decision', 'approve')->pluck('user_id')->all();
                if ($node['mode'] === 'any' || array_diff(array_column($node['people'], 'id'), $votes) === []) {
                    $this->notifyCc($version, 'node:'.$version->node_index);
                    $version->node_index++;
                    if ($version->node_index === count($version->flow['nodes'])) {
                        $version->status = 'approved';
                    }
                }
            }
            $version->save();
            if ($version->status !== 'pending') {
                $notice = $this->notice($version, $version->user_id, $version->status, $version->status === 'approved' ? '报销审批通过' : '报销申请被驳回');
                if ($version->wechat_subscribed && $notice->wasRecentlyCreated) {
                    $notice->update(['delivery_status' => 'pending']);
                }
                $this->notifyCc($version, $version->status);
            }
            $this->audit($actor, $data['decision'], $version->id, ['node' => $data['node_index']]);

            return $version;
        }, 3);
    }

    private function grant(ReimbursementVersion $version, array $ids, string $kind): void
    {
        foreach (array_unique($ids) as $id) {
            DB::table('reimbursement_access')->insertOrIgnore(['version_id' => $version->id, 'user_id' => $id, 'kind' => $kind]);
        }
    }

    private function notifyCc(ReimbursementVersion $version, string $event): void
    {
        $ids = in_array($event, $version->flow['triggers'], true) ? array_column($version->flow['cc'], 'id') : [];
        if (in_array($event, ['submitted', 'approved', 'rejected'], true)) {
            $ids = array_merge($ids, array_column($version->cc, 'id'));
        }
        $ids = User::whereIn('id', $ids)->pluck('id')->all();
        $this->grant($version, $ids, 'cc');
        foreach ($ids as $id) {
            $this->notice($version, $id, 'cc:'.$event, '报销知会：'.$version->title);
        }
    }

    private function notice(ReimbursementVersion $version, int $userId, string $event, string $message): ReimbursementNotice
    {
        return ReimbursementNotice::firstOrCreate(['version_id' => $version->id, 'user_id' => $userId, 'event' => $event], ['message' => $message]);
    }

    private function audit(User $actor, string $action, int $id, array $payload): void
    {
        AuditLog::create(['user_id' => $actor->id, 'action' => 'reimbursement.'.$action, 'subject_type' => 'reimbursement', 'subject_id' => (string) $id, 'payload' => $payload]);
    }

    public function present(User $actor, ReimbursementVersion $version): array
    {
        $data = $version->only(['id', 'reimbursement_id', 'number', 'title', 'type', 'note', 'items', 'total_cents', 'flow', 'cc', 'decisions', 'node_index', 'status', 'created_at']);
        $data['owner'] = User::withTrashed()->find($version->user_id)?->only(['id', 'name']);
        $data['can_decide'] = $this->canDecide($actor, $version);
        $data['blocked'] = $this->blocked($version);
        $data['can_resubmit'] = $actor->canDo('reimbursements.submit') && $actor->id === $version->user_id && $version->status === 'rejected' && Reimbursement::find($version->reimbursement_id)?->latest_version === $version->number;
        $data['url'] = route('reimbursements.show', $version, false);
        $data['zip_url'] = route('reimbursements.zip', $version, false);
        $data['versions'] = $this->visible($actor)->where('reimbursement_id', $version->reimbursement_id)->orderByDesc('number')->get(['id', 'number'])->map(fn ($v) => ['number' => $v->number, 'url' => route('reimbursements.show', $v, false)])->all();
        $data['items'] = array_map(function ($item) use ($version) {
            $item['attachments'] = array_map(fn ($file) => [...$file,
                'preview_url' => route('reimbursements.file', [$version, $file['id']], false).'?inline=1',
                'download_url' => route('reimbursements.file', [$version, $file['id']], false),
            ], $item['attachments']);

            return $item;
        }, $data['items']);

        return $data;
    }
}
