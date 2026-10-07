<?php

namespace App\Models;

use App\Actions\SyncObjectReferenceNames;
use App\Support\ObjectRelations;
use App\Support\ReferenceGraphLock;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable([
    'business_object_id',
    'code',
    'title',
    'payload',
    'stock_dimension_key',
    'workflow_key',
    'workflow_target_roles',
    'workflow_seen_at',
    'workflow_seen_by',
    'created_by',
])]
class ObjectRecord extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @param  mixed  $value
     * @param  string|null  $field
     */
    public function resolveRouteBinding($value, $field = null): ?Model
    {
        if (! is_string($value) || ! Str::isUuid($value)) {
            return null;
        }

        return parent::resolveRouteBinding($value, $field);
    }

    public function save(array $options = []): bool
    {
        $sync = app(SyncObjectReferenceNames::class);
        $object = BusinessObject::find($this->business_object_id);
        if (! $object || ! $sync->supports($object)) {
            return parent::save($options);
        }

        return $this->getConnection()->transaction(function () use ($options, $sync, $object): bool {
            app(ReferenceGraphLock::class)->acquire();
            $this->payload = $sync->normalize($object, $this->payload ?? [])['payload'];
            $identityChanged = $this->isDirty(['title', 'code'])
                || ($this->payload['project_no'] ?? null) !== ($this->getOriginal('payload')['project_no'] ?? null);
            $saved = parent::save($options);
            if ($saved) {
                app(ObjectRelations::class)->forgetLabels();
            }
            if ($saved && $identityChanged) {
                $sync->syncDependents($this);
            }

            return $saved;
        });
    }

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'workflow_target_roles' => 'array',
            'workflow_seen_at' => 'datetime',
        ];
    }

    public function businessObject(): BelongsTo
    {
        return $this->belongsTo(BusinessObject::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
