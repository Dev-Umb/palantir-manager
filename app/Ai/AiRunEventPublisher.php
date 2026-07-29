<?php

namespace App\Ai;

use App\Events\AiRunEventCreated;
use App\Models\AiRun;
use App\Models\AiRunEvent;
use Illuminate\Support\Facades\DB;
use JsonException;
use RuntimeException;

class AiRunEventPublisher
{
    public function publish(AiRun $run, string $type, array $payload = []): AiRunEvent
    {
        $event = DB::connection($run->getConnectionName())->getDriverName() === 'pgsql'
            ? $this->publishToPostgres($run, $type, $payload)
            : $this->publishWithTransaction($run, $type, $payload);

        event(new AiRunEventCreated($event->envelope()));

        return $event;
    }

    /**
     * @throws JsonException
     */
    private function publishToPostgres(AiRun $run, string $type, array $payload): AiRunEvent
    {
        $now = now();
        $row = DB::connection($run->getConnectionName())->selectOne(<<<'SQL'
            WITH next_seq AS (
                UPDATE ai_runs
                SET last_event_seq = last_event_seq + 1, updated_at = ?
                WHERE id = ?
                RETURNING last_event_seq
            )
            INSERT INTO ai_run_events (run_id, seq, type, payload, created_at)
            SELECT ?, next_seq.last_event_seq, ?, ?::jsonb, ?
            FROM next_seq
            RETURNING id, run_id, seq, type, payload, created_at
            SQL, [
            $now,
            $run->id,
            $run->id,
            $type,
            json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            $now,
        ], false);

        if (! $row) {
            throw new RuntimeException("AI run {$run->id} no longer exists.");
        }

        $event = new AiRunEvent;
        $event->setConnection($run->getConnectionName());
        $event->setRawAttributes((array) $row, true);
        $event->exists = true;
        $run->last_event_seq = (int) $row->seq;
        $run->syncOriginalAttribute('last_event_seq');

        return $event;
    }

    private function publishWithTransaction(AiRun $run, string $type, array $payload): AiRunEvent
    {
        return DB::transaction(function () use ($run, $type, $payload) {
            $locked = AiRun::query()->lockForUpdate()->findOrFail($run->id);
            $seq = $locked->last_event_seq + 1;

            $event = AiRunEvent::create([
                'run_id' => $locked->id,
                'seq' => $seq,
                'type' => $type,
                'payload' => $payload,
                'created_at' => now(),
            ]);

            $locked->update(['last_event_seq' => $seq]);
            $run->last_event_seq = $seq;
            $run->syncOriginalAttribute('last_event_seq');

            return $event;
        });
    }
}
