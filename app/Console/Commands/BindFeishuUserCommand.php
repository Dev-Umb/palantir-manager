<?php

namespace App\Console\Commands;

use App\Integrations\Feishu\FeishuClient;
use App\Models\FeishuUserBinding;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('feishu:bind-user
    {email : Palantir user email}
    {--open-id= : Known Feishu open_id; omit to resolve it by email}
    {--tenant-key= : Override the configured Feishu tenant key}')]
#[Description('Bind a Palantir user to a Feishu application identity')]
class BindFeishuUserCommand extends Command
{
    public function handle(FeishuClient $client): int
    {
        $email = trim((string) $this->argument('email'));
        $user = User::whereRaw('LOWER(email) = ?', [mb_strtolower($email)])->first();
        if (! $user) {
            $this->error('Palantir user not found.');

            return self::FAILURE;
        }

        $tenantKey = trim((string) ($this->option('tenant-key') ?: config('services.feishu.tenant_key')));
        if ($tenantKey === '') {
            $this->error('Feishu tenant key is required.');

            return self::FAILURE;
        }

        $openId = trim((string) $this->option('open-id'));
        if ($openId === '') {
            $openId = (string) ($client->openIdsByEmails([$user->email])[$user->email] ?? '');
        }
        if ($openId === '') {
            $this->error('No Feishu user was found for that email.');

            return self::FAILURE;
        }

        FeishuUserBinding::updateOrCreate(
            ['user_id' => $user->id, 'tenant_key' => $tenantKey],
            ['open_id' => $openId, 'verified_at' => now(), 'disabled_at' => null],
        );
        $this->info("Bound Palantir user {$user->id} to Feishu.");

        return self::SUCCESS;
    }
}
