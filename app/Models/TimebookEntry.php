<?php

namespace App\Models;

use Database\Factories\TimebookEntryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TimebookEntry extends Model
{
    /** @use HasFactory<TimebookEntryFactory> */
    use HasFactory;

    protected $fillable = ['worker_id', 'day', 'half_days', 'overtime_minutes', 'project', 'note', 'version', 'deleted'];

    protected $attributes = ['version' => 1, 'deleted' => 0, 'project' => '', 'note' => ''];

    protected function casts(): array
    {
        return ['half_days' => 'integer', 'overtime_minutes' => 'integer', 'version' => 'integer', 'deleted' => 'integer'];
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(TimebookWorker::class, 'worker_id');
    }

    public function snapshot(): array
    {
        return [
            'id' => $this->id, 'worker_id' => $this->worker_id, 'name' => $this->worker->name,
            'day' => $this->day, 'half_days' => $this->half_days, 'overtime_minutes' => $this->overtime_minutes,
            'days' => $this->half_days / 2, 'overtime' => $this->overtime_minutes / 60,
            'project' => $this->project, 'note' => $this->note, 'version' => $this->version, 'deleted' => $this->deleted,
            'created_at' => $this->created_at?->toISOString(), 'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
