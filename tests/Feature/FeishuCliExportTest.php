<?php

namespace Tests\Feature;

use App\Integrations\Feishu\FeishuExportService;
use App\Models\BusinessObject;
use App\Models\FeishuUserBinding;
use App\Models\ObjectRecord;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Tests\TestCase;

class FeishuCliExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('xyc:sync-metadata')->assertSuccessful();
        config()->set('services.feishu.cli', [
            'enabled' => true,
            'binary' => '/usr/local/bin/lark-cli',
            'profile' => 'palantir',
            'timeout' => 45,
            'max_rows' => 2,
            'max_columns' => 4,
            'max_payload_bytes' => 200000,
        ]);
    }

    public function test_authorized_results_can_be_exported_to_a_document_and_spreadsheet(): void
    {
        $user = $this->userWithRole('business');
        $this->bindFeishu($user, 'ou_export_user');
        $project = $this->project($user);
        Process::fake(function (PendingProcess $process) {
            if (in_array('drive', $process->command, true)) {
                return Process::result(json_encode([
                    'ok' => true,
                    'data' => ['member' => ['member_id' => 'ou_export_user', 'member_type' => 'openid', 'perm' => 'view']],
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
            }

            $isDocument = in_array('docs', $process->command, true);

            return Process::result(json_encode([
                'ok' => true,
                'data' => [$isDocument ? 'document' : 'spreadsheet' => [
                    'title' => '项目欠款清单',
                    'folder_token' => '',
                    $isDocument ? 'document_id' : 'spreadsheet_token' => $isDocument ? 'docx_test_1' : 'sht_test_1',
                    'url' => $isDocument
                        ? 'https://example.feishu.cn/docx/docx_test_1'
                        : 'https://example.feishu.cn/sheets/sht_test_1',
                ]],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        });

        $query = [
            'object' => 'project',
            'select' => ['title', 'business_owner_user_id', 'arrears'],
            'filters' => [['field' => 'title', 'operator' => 'contains', 'value' => '祁离']],
            'limit' => 10,
            'title' => '项目欠款清单',
        ];
        $document = app(FeishuExportService::class)->export($user, 'docx', $query);
        $spreadsheet = app(FeishuExportService::class)->export($user, 'sheet', $query);

        $this->assertTrue($document['ok'], json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $this->assertSame('https://example.feishu.cn/docx/docx_test_1', data_get($document, 'file.url'));
        $this->assertSame(1, $document['record_count']);
        $this->assertTrue($spreadsheet['ok'], json_encode($spreadsheet, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $this->assertSame('https://example.feishu.cn/sheets/sht_test_1', data_get($spreadsheet, 'file.url'));
        $this->assertSame(1, $spreadsheet['record_count']);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'action' => 'ai.feishu_export.created',
            'subject_id' => 'docx_test_1',
        ]);
        $this->assertDatabaseCount('audit_logs', 4);

        Process::assertRan(function (PendingProcess $process) use ($project): bool {
            $command = $process->command;

            return in_array('--profile', $command, true)
                && in_array('palantir', $command, true)
                && in_array('docs', $command, true)
                && ! in_array('openclaw', $command, true)
                && str_contains((string) $process->input, '<table>')
                && str_contains((string) $process->input, $project->title);
        });
        Process::assertRan(function (PendingProcess $process): bool {
            $command = $process->command;
            $sheets = json_decode((string) ($command[array_search('--sheets', $command, true) + 1] ?? ''), true);

            return in_array('sheets', $command, true)
                && data_get($sheets, 'sheets.0.name') === '数据'
                && data_get($sheets, 'sheets.0.header') === true
                && in_array('名称', data_get($sheets, 'sheets.0.columns', []), true)
                && in_array('欠款', data_get($sheets, 'sheets.0.columns', []), true)
                && data_get($sheets, 'sheets.0.dtypes.欠款') === 'float64'
                && collect(data_get($sheets, 'sheets.0.data', []))->flatten()->contains('祁离4标');
        });
        Process::assertRanTimes(function (PendingProcess $process): bool {
            $command = $process->command;
            $data = $command[array_search('--data', $command, true) + 1] ?? '';

            return in_array('drive', $command, true)
                && in_array('permission.members', $command, true)
                && in_array('--yes', $command, true)
                && str_contains($data, 'ou_export_user')
                && str_contains($data, '"perm":"view"');
        }, 2);
    }

    public function test_forbidden_empty_disabled_and_cli_failure_boundaries_do_not_create_files(): void
    {
        Process::fake([
            '*' => Process::result(output: '', errorOutput: 'access_token=must-not-leak permission denied', exitCode: 1),
        ]);
        $forbiddenUser = $this->userWithRole('production');
        $this->bindFeishu($forbiddenUser, 'ou_forbidden');
        $forbidden = app(FeishuExportService::class)->export($forbiddenUser, 'sheet', [
            'object' => 'project', 'select' => ['title'],
        ]);
        $this->assertFalse($forbidden['ok']);
        Process::assertNothingRan();

        $business = $this->userWithRole('business');
        $this->bindFeishu($business, 'ou_business');
        $empty = app(FeishuExportService::class)->export($business, 'docx', [
            'object' => 'project', 'select' => ['title'],
        ]);
        $this->assertSame('empty_result', $empty['error']);
        Process::assertNothingRan();

        $this->project($business);
        $failure = app(FeishuExportService::class)->export($business, 'docx', [
            'object' => 'project', 'select' => ['title'],
        ]);
        $this->assertSame('export_failed', $failure['error']);
        $this->assertStringNotContainsString('must-not-leak', json_encode($failure, JSON_THROW_ON_ERROR));

        config()->set('services.feishu.cli.enabled', false);
        $disabled = app(FeishuExportService::class)->export($business, 'sheet', [
            'object' => 'project', 'select' => ['title'],
        ]);
        $this->assertSame('export_disabled', $disabled['error']);
    }

    public function test_missing_binding_and_oversized_payload_do_not_create_files(): void
    {
        Process::fake();
        $user = $this->userWithRole('business');
        $this->project($user);

        $missingBinding = app(FeishuExportService::class)->export($user, 'sheet', [
            'object' => 'project', 'select' => ['title'],
        ]);
        $this->assertSame('feishu_binding_missing', $missingBinding['error']);
        Process::assertNothingRan();

        $this->bindFeishu($user, 'ou_payload_user');
        config()->set('services.feishu.cli.max_payload_bytes', 10);
        $oversized = app(FeishuExportService::class)->export($user, 'docx', [
            'object' => 'project', 'select' => ['title', 'arrears'],
        ]);
        $this->assertSame('payload_too_large', $oversized['error']);
        Process::assertNothingRan();
    }

    private function project(User $owner): ObjectRecord
    {
        return BusinessObject::where('key', 'project')->firstOrFail()->records()->create([
            'code' => 'PRJ-'.Str::uuid(),
            'title' => '祁离4标',
            'created_by' => $owner->id,
            'payload' => [
                'name' => '祁离4标',
                'business_owner_user_id' => (string) $owner->id,
                'contract_amount' => 100000,
                'paid_amount' => 25000,
                'arrears' => 75000,
            ],
        ]);
    }

    private function userWithRole(string $role): User
    {
        $user = User::create([
            'name' => "导出测试{$role}",
            'email' => $role.'-'.Str::uuid().'@example.test',
            'password' => Hash::make('password'),
        ]);
        $user->roles()->attach(Role::where('name', $role)->firstOrFail());

        return $user;
    }

    private function bindFeishu(User $user, string $openId): void
    {
        FeishuUserBinding::factory()->for($user)->create([
            'tenant_key' => 'test-tenant',
            'open_id' => $openId,
            'verified_at' => now(),
        ]);
    }
}
