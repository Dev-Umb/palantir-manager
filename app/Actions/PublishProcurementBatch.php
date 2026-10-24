<?php

namespace App\Actions;

use App\Models\ProcurementBatch;
use App\Models\ProcurementClient;
use App\Models\ProcurementNotice;
use App\Models\ProcurementObservation;
use App\Models\User;
use App\Support\HubAccess;
use App\Support\ProcurementPayload;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PublishProcurementBatch
{
    public function __construct(private ProcurementPayload $adapter) {}

    public function authorize(ProcurementClient $client): void
    {
        $client->refresh();
        $user = User::find($client->user_id);
        abort_unless(! $client->revoked_at && $client->expires_at->isFuture() && HubAccess::canRead($user), 403);
    }

    public function validate(ProcurementClient $client, array $payload): array
    {
        $this->authorize($client);
        $payload = $this->adapter->validate($payload);
        $batch = ProcurementBatch::create(['id' => (string) Str::uuid(), 'client_id' => $client->id, 'payload_hash' => $this->adapter->hash($payload), 'payload' => $payload, 'expires_at' => now()->addHours(24)]);

        return ['validation_id' => $batch->id, 'payload_hash' => $batch->payload_hash, 'expires_at' => $batch->expires_at->toIso8601String(), 'warnings' => collect($payload['items'])->map(fn (array $i) => ['item_id' => $i['id'], 'warnings' => $this->adapter->warnings($i)])->all()];
    }

    public function publish(ProcurementClient $client, string $validationId, string $key): array
    {
        $this->authorize($client);

        return DB::transaction(function () use ($client, $validationId, $key): array {
            ProcurementClient::whereKey($client->id)->lockForUpdate()->firstOrFail();
            $this->authorize($client);
            $batch = ProcurementBatch::where('client_id', $client->id)->whereKey($validationId)->lockForUpdate()->firstOrFail();
            $previous = ProcurementBatch::where('client_id', $client->id)->where('idempotency_key', $key)->first();
            if ($previous) {
                abort_unless(hash_equals($previous->payload_hash, $batch->payload_hash), 409, '幂等键对应不同内容。');

                return $this->result($client, $previous->id);
            }
            if ($batch->status !== 'validated') {
                abort(409, '该校验批次已发布，请查询原结果。');
            }
            abort_if($batch->expires_at->isPast(), 409, '校验已过期，请重新校验。');
            $batch->update(['idempotency_key' => $key]);
            $results = [];
            foreach ($batch->payload['items'] as $item) {
                $dates = $this->adapter->dates($item);
                $hash = $this->adapter->contentHash($item);
                $identity = hash('sha256', mb_strtolower(trim($item['platform'])).'|'.trim($item['code']));
                $candidate = ['identity_key' => $identity, 'client_id' => $client->id, 'user_id' => $client->user_id, 'batch_id' => $batch->id, 'title' => $item['title'], 'method' => $item['nature'], 'priority' => $item['priority'], 'status' => $item['status'], 'invited' => $item['invited'], 'has_issues' => $item['issues'] !== '', 'published_date' => $dates['published']['date'], 'registration_at' => $dates['registration']['value'], 'deadline_at' => $dates['deadline']['value'], 'dates' => $dates, 'payload' => $item, 'content_hash' => $hash];
                $notice = ProcurementNotice::firstOrCreate(['identity_key' => $identity], $candidate);
                $created = $notice->wasRecentlyCreated;
                $notice = ProcurementNotice::whereKey($notice->id)->lockForUpdate()->firstOrFail();
                $status = $created ? 'created' : ($notice->client_id !== $client->id ? 'identity_review' : (hash_equals($notice->content_hash, $hash) ? 'duplicate' : 'pending_update'));
                $warnings = $this->adapter->warnings($item);
                ProcurementObservation::create(['batch_id' => $batch->id, 'notice_id' => $notice->id, 'item_id' => $item['id'], 'status' => $status, 'payload' => $item, 'warnings' => $warnings]);
                $results[] = ['item_id' => $item['id'], 'status' => $status, 'record_id' => $status === 'identity_review' ? null : $notice->id, 'warnings' => $warnings];
            }
            $batch->update(['status' => 'completed', 'results' => $results]);

            return $this->result($client, $batch->id);
        }, 3);
    }

    public function result(ProcurementClient $client, string $id): array
    {
        $this->authorize($client);
        $batch = ProcurementBatch::where('client_id', $client->id)->findOrFail($id);

        return ['batch_id' => $batch->id, 'status' => $batch->status, 'results' => $batch->results ?? [], 'counts' => collect($batch->results ?? [])->countBy('status')->all()];
    }
}
