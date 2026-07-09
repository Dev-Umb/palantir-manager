<?php

namespace App\Console\Commands;

use App\Actions\SyncXycMetadata;
use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class MakeAdminUser extends Command
{
    protected $signature = 'xyc:admin {email} {--name=系统管理员} {--password=}';

    protected $description = 'Create or promote an administrator for the prototype RBAC console.';

    public function handle(SyncXycMetadata $sync): int
    {
        $sync->handle();

        $email = (string) $this->argument('email');
        $password = (string) ($this->option('password') ?: $this->secret('Password'));

        validator(
            ['email' => $email, 'password' => $password],
            ['email' => ['required', 'email'], 'password' => ['required', Password::min(8)]],
        )->validate();

        $user = User::updateOrCreate(
            ['email' => $email],
            ['name' => (string) $this->option('name'), 'password' => Hash::make($password)],
        );

        $user->roles()->syncWithoutDetaching([Role::where('name', 'admin')->firstOrFail()->id]);

        $this->info("Administrator ready: {$user->email}");

        return self::SUCCESS;
    }
}
