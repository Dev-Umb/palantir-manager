<?php

namespace App\Actions;

use App\Models\TimebookEntry;
use App\Models\TimebookEntryAudit;
use App\Models\TimebookWorker;
use App\Models\User;
use App\Support\TimebookName;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

class WriteTimebookEntry
{
    public function save(array $data, User $user, ?TimebookEntry $entry = null): TimebookEntry
    {
        try {
            return DB::transaction(function () use ($data, $user, $entry): TimebookEntry {
                $before = null;
                if ($entry) {
                    $entry = TimebookEntry::query()->lockForUpdate()->findOrFail($entry->id);
                    $this->checkVersion($entry, $data['version']);
                    if ($entry->deleted) {
                        $this->conflict('记录已删除，请重新查询。');
                    }
                    $before = $entry->snapshot();
                }
                $worker = TimebookWorker::query()->firstOrCreate(
                    ['name_key' => TimebookName::key($data['name'])],
                    ['name' => TimebookName::normalize($data['name'])],
                );
                $existing = TimebookEntry::query()->where('worker_id', $worker->id)->where('day', $data['day'])
                    ->where('deleted', 0)->when($entry, fn ($query) => $query->where('id', '!=', $entry->id))->first();
                if ($existing) {
                    $this->conflict('该人员当天已有记工，请打开已有记录修改。', $existing);
                }
                $entry ??= new TimebookEntry;
                $entry->fill([
                    'worker_id' => $worker->id, 'day' => $data['day'],
                    'half_days' => (int) ((float) $data['days'] * 2),
                    'overtime_minutes' => (int) round((float) $data['overtime'] * 60),
                    'project' => $data['project'], 'note' => $data['note'],
                    'version' => $before ? $entry->version + 1 : 1,
                ]);
                $entry->save();
                $entry->setRelation('worker', $worker);
                $this->audit($entry, $user, $before ? '修改' : '新增', $before);

                return $entry;
            });
        } catch (UniqueConstraintViolationException) {
            $existing = TimebookEntry::query()->whereRelation('worker', 'name_key', TimebookName::key($data['name']))
                ->where('day', $data['day'])->where('deleted', 0)->first();
            $this->conflict('该人员当天已有记工，请重新查询。', $existing);
        }
    }

    public function setDeleted(TimebookEntry $entry, int $version, bool $deleted, User $user): TimebookEntry
    {
        try {
            return DB::transaction(function () use ($entry, $version, $deleted, $user): TimebookEntry {
                $entry = TimebookEntry::query()->lockForUpdate()->findOrFail($entry->id);
                $this->checkVersion($entry, $version);
                if ((bool) $entry->deleted === $deleted) {
                    $this->conflict('记录状态已变更，请重新查询。');
                }
                $before = $entry->snapshot();
                $entry->update(['deleted' => (int) $deleted, 'version' => $entry->version + 1]);
                $this->audit($entry, $user, $deleted ? '删除' : '恢复', $before);

                return $entry;
            });
        } catch (UniqueConstraintViolationException) {
            $this->conflict('该人员当天已有其他有效记录，不能恢复并覆盖。');
        }
    }

    private function checkVersion(TimebookEntry $entry, int $version): void
    {
        if ($entry->version !== $version) {
            $this->conflict('记录已被其他操作修改，请刷新后核对。');
        }
    }

    private function audit(TimebookEntry $entry, User $user, string $action, ?array $before): void
    {
        TimebookEntryAudit::create([
            'entry_id' => $entry->id, 'user_id' => $user->id, 'actor_name' => $user->name,
            'action' => $action, 'before' => $before, 'after' => $entry->snapshot(),
        ]);
    }

    private function conflict(string $message, ?TimebookEntry $existing = null): never
    {
        throw new HttpResponseException(response()->json([
            'message' => $message, 'existing' => $existing?->snapshot(),
        ], 409));
    }
}
