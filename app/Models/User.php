<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, SoftDeletes;

    protected $attributes = ['is_password_changed' => false];

    private ?array $permissionKeyCache = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_password_changed' => 'boolean',
        ];
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class)->withTimestamps();
    }

    public function permissionKeys(): array
    {
        if ($this->permissionKeyCache !== null) {
            return $this->permissionKeyCache;
        }

        $roles = $this->roles()->with('permissions')->get();
        $this->setRelation('roles', $roles);

        return $this->permissionKeyCache = $roles
            ->flatMap(fn (Role $role) => $role->permissions->pluck('key'))
            ->filter(fn (string $key) => ! $roles->contains('name', 'timebook_operator')
                || in_array($key, ['timebook.view', 'timebook.create', 'timebook.update', 'timebook.delete', 'timebook.export', 'timebook.audit', 'timebook.ai.query', 'ai.harness.view'], true))
            ->unique()
            ->values()
            ->all();
    }

    public function canDo(string $permission): bool
    {
        return in_array($permission, $this->permissionKeys(), true);
    }

    public function isTimebookOperator(): bool
    {
        return $this->roles->contains('name', 'timebook_operator');
    }
}
