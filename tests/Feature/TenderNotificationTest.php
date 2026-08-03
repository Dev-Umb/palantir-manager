<?php

namespace Tests\Feature;

use App\Actions\CreateObjectRecord;
use App\Actions\SyncTenderNotifications;
use App\Actions\SyncXycMetadata;
use App\Models\BusinessObject;
use App\Models\ObjectRecord;
use App\Models\Role;
use App\Models\TenderNotification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TenderNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(SyncXycMetadata::class)->handle();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_stage_windows_are_precise_to_the_minute(): void
    {
        $sync = app(SyncTenderNotifications::class);
        $now = Carbon::parse('2026-08-03 08:00', config('xyc.tender_timezone'));

        $this->assertSame('d0', $sync->stageFor($now->copy()->setTime(23, 59), $now));
        $this->assertSame('d1', $sync->stageFor($now->copy()->addDay()->subMinute(), $now));
        $this->assertSame('d3', $sync->stageFor($now->copy()->addHours(72), $now));
        $this->assertNull($sync->stageFor($now->copy()->addHours(72)->addMinute(), $now));
        $this->assertNull($sync->stageFor($now->copy(), $now));
    }

    public function test_default_sync_clock_uses_the_tender_timezone_for_same_day_notifications(): void
    {
        $creator = $this->userWithRole('tender');
        $this->createTender($creator, '2026-08-03T02:36');
        Carbon::setTestNow(Carbon::parse('2026-08-02 16:36', 'UTC'));

        app(SyncTenderNotifications::class)->handle();

        $this->assertDatabaseHas('tender_notifications', [
            'user_id' => $creator->id,
            'deadline_type' => 'submit',
            'stage' => 'd0',
            'status' => TenderNotification::STATUS_ACTIVE,
        ]);
        $this->assertDatabaseMissing('tender_notifications', [
            'user_id' => $creator->id,
            'deadline_type' => 'submit',
            'stage' => 'd1',
        ]);
    }

    public function test_deadline_notifications_create_upgrade_resolve_reactivate_and_remain_idempotent(): void
    {
        $creator = $this->userWithRole('tender');
        $otherTender = $this->userWithRole('tender');
        $admin = $this->userWithRole('admin');
        $tender = $this->createTender($creator, '2026-08-05T10:00');
        $sync = app(SyncTenderNotifications::class);

        $first = $sync->handle(Carbon::parse('2026-08-03 08:00', config('xyc.tender_timezone')));
        $this->assertSame(3, $first['created']);
        $this->assertSame(0, $first['reactivated']);
        $this->assertSame(3, TenderNotification::query()->where('stage', 'd3')->active()->count());
        $this->assertEqualsCanonicalizing(
            [$creator->id, $otherTender->id, $admin->id],
            TenderNotification::query()->where('stage', 'd3')->pluck('user_id')->all(),
        );

        $repeat = $sync->handle(Carbon::parse('2026-08-03 08:00', config('xyc.tender_timezone')));
        $this->assertSame(['created' => 0, 'reactivated' => 0, 'resolved' => 0], $repeat);

        $upgrade = $sync->handle(Carbon::parse('2026-08-04 11:00', config('xyc.tender_timezone')));
        $this->assertSame(3, $upgrade['created']);
        $this->assertSame(3, $upgrade['resolved']);
        $this->assertSame(3, TenderNotification::query()->where('stage', 'd1')->active()->count());

        $sameDay = $sync->handle(Carbon::parse('2026-08-05 08:00', config('xyc.tender_timezone')));
        $this->assertSame(3, $sameDay['created']);
        $this->assertSame(3, $sameDay['resolved']);
        $this->assertSame(3, TenderNotification::query()->where('stage', 'd0')->active()->count());

        $expired = $sync->handle(Carbon::parse('2026-08-05 10:01', config('xyc.tender_timezone')));
        $this->assertSame(3, $expired['resolved']);
        $this->assertSame(0, TenderNotification::query()->active()->count());

        $reactivated = $sync->handle(Carbon::parse('2026-08-05 08:30', config('xyc.tender_timezone')));
        $this->assertSame(3, $reactivated['reactivated']);
        $this->assertSame(2, TenderNotification::query()->where('stage', 'd0')->firstOrFail()->occurrences);

        $tender->update(['payload' => [...$tender->payload, 'status' => '已递交']]);
        $resolved = $sync->handle(Carbon::parse('2026-08-05 08:31', config('xyc.tender_timezone')));
        $this->assertSame(3, $resolved['resolved']);
    }

    public function test_tender_notifications_are_visible_and_readable_without_changing_project_notification_routes(): void
    {
        $creator = $this->userWithRole('tender');
        $this->createTender($creator, '2026-08-03T12:30');
        Carbon::setTestNow(Carbon::parse('2026-08-03 08:00', config('xyc.tender_timezone')));
        app(SyncTenderNotifications::class)->handle();

        $notification = TenderNotification::query()
            ->where('user_id', $creator->id)
            ->where('deadline_type', 'submit')
            ->firstOrFail();
        $this->actingAs($creator)
            ->get('/notifications')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Notifications/Index')
                ->where('unreadCount', 1)
                ->has('notifications.data', 0)
                ->has('tenderNotifications.data', 1)
                ->where('tenderNotifications.data.0.type_label', '投标截止（今日）')
                ->where('tenderNotifications.data.0.deadline_at', '2026-08-03T04:30:00.000000Z')
                ->where('tenderNotifications.data.0.tender.code', fn ($code) => str_starts_with($code, 'ZB-')));

        $this->patch("/tender-notifications/{$notification->id}/read")
            ->assertRedirect();
        $this->assertNotNull($notification->fresh()->read_at);
        $this->get('/notifications')->assertOk();
    }

    public function test_business_backup_and_reset_include_tender_notifications(): void
    {
        Storage::fake('local');
        $creator = $this->userWithRole('tender');
        $this->createTender($creator, '2026-08-03T12:30');
        app(SyncTenderNotifications::class)->handle(
            Carbon::parse('2026-08-03 08:00', config('xyc.tender_timezone')),
        );

        $this->artisan('xyc:backup-business-data', [
            '--path' => 'backups/tender-test.json.gz',
        ])->assertSuccessful();
        $snapshot = json_decode(
            gzdecode(Storage::disk('local')->get('backups/tender-test.json.gz')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $this->assertSame(1, $snapshot['counts']['tender_notifications']);
        $this->assertCount(1, $snapshot['tender_notifications']);

        $this->artisan('xyc:reset-business-data', ['--force' => true])
            ->assertSuccessful();
        $this->assertDatabaseCount('tender_notifications', 0);
    }

    private function createTender(User $creator, string $submitDeadline): ObjectRecord
    {
        $customerObject = BusinessObject::query()->where('key', 'customer')->firstOrFail();
        $customer = ObjectRecord::create([
            'business_object_id' => $customerObject->id,
            'code' => 'CUST-NOTIFY-001',
            'title' => '预警客户',
            'payload' => ['name' => '预警客户'],
            'created_by' => $creator->id,
        ]);

        return app(CreateObjectRecord::class)->handle(
            BusinessObject::query()->where('key', 'tender')->firstOrFail(),
            [
                'name' => '预警标的',
                'customer_id' => $customer->id,
                'register_deadline' => '2026-09-01T10:00',
                'purchase_deadline' => '2026-09-02T10:00',
                'submit_deadline' => $submitDeadline,
                'status' => '跟踪中',
            ],
            $creator,
        );
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('name', $role)->firstOrFail());

        return $user;
    }
}
