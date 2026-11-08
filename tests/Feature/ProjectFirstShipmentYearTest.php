<?php

namespace Tests\Feature;

use App\Actions\CreateObjectRecord;
use App\Models\BusinessObject;
use App\Models\ObjectRecord;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\XycPrototypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProjectFirstShipmentYearTest extends TestCase
{
    use RefreshDatabase;

    private ObjectRecord $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(XycPrototypeSeeder::class);
        $owner = User::where('email', 'business@xyc.test')->sole();
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::where('name', 'admin')->sole());
        $this->actingAs($admin);
        $this->project = app(CreateObjectRecord::class)->handle(BusinessObject::where('key', 'project')->sole(), [
            'name' => '年份精度项目', 'customer_id' => ObjectRecord::whereRelation('businessObject', 'key', 'customer')->firstOrFail()->id,
            'business_owner_user_id' => (string) $owner->id, 'overall_status' => '投标中',
            'occurred_amount' => 1000, 'paid_amount' => 100, 'last_payment_date' => '2026-01-01',
            'payment_reminder_anchor_at' => '2026-01-01T00:00:00Z',
        ]);
    }

    public function test_year_round_trip_keeps_precision_permissions_and_adjacent_values(): void
    {
        $this->putJson('/records/'.$this->project->id, ['payload' => $this->project->payload])->assertOk();
        $anchor = $this->project->fresh()->payload['payment_reminder_anchor_at'];
        foreach (['finance', 'business'] as $role) {
            $user = User::whereHas('roles', fn ($q) => $q->where('name', $role))->firstOrFail();
            $this->actingAs($user);
            $this->putJson('/records/'.$this->project->id, ['payload' => [...$this->project->fresh()->payload, 'first_shipment_date' => '2016年', 'last_shipment_date' => '2016-06-01']])->assertOk();
            $payload = $this->project->fresh()->payload;
            $this->assertSame('2016', $payload['first_shipment_date']);
            $this->assertSame('2016-06-01', $payload['last_shipment_date']);
            $this->assertSame('2026-01-01', $payload['last_payment_date']);
            $this->assertSame($anchor, $payload['payment_reminder_anchor_at']);
            $this->assertEquals(1000, $payload['occurred_amount']);
        }
        $this->get('/objects/project?record='.$this->project->id)->assertInertia(fn (Assert $page) => $page
            ->where('selectedRecord.payload.first_shipment_date', '2016')
            ->where('selectedRecord.display.first_shipment_date', '2016年（月日不详）'));
        $this->actingAs(User::factory()->create())->putJson('/records/'.$this->project->id, ['payload' => ['first_shipment_date' => '2020']])->assertForbidden();
        $this->assertSame('2016', $this->project->fresh()->payload['first_shipment_date']);
    }

    public function test_invalid_dates_fail_atomically_and_other_dates_still_require_a_day(): void
    {
        foreach (['0000', '0999', '10000', '201', '2016-02-30', '2016-13-01', '2016-2', 'yesterday'] as $value) {
            $this->putJson('/records/'.$this->project->id, ['payload' => [...$this->project->payload, 'first_shipment_date' => $value]])->assertUnprocessable()->assertJsonValidationErrors('payload.first_shipment_date');
        }
        foreach (['last_shipment_date', 'last_payment_date'] as $key) {
            $this->putJson('/records/'.$this->project->id, ['payload' => [...$this->project->payload, $key => '2016']])->assertUnprocessable();
        }
        $this->putJson('/records/'.$this->project->id, ['payload' => [...$this->project->payload, 'first_shipment_date' => '2016', 'last_shipment_date' => '2015-12-31']])->assertUnprocessable()->assertJsonValidationErrors('payload.last_shipment_date');
        $this->assertArrayNotHasKey('first_shipment_date', $this->project->fresh()->payload);
    }

    public function test_full_dates_and_clearing_remain_available(): void
    {
        foreach (['2016-02-29', '2016', null] as $value) {
            $this->putJson('/records/'.$this->project->id, ['payload' => [...$this->project->fresh()->payload, 'first_shipment_date' => $value]])->assertOk();
            $this->assertSame($value, $this->project->fresh()->payload['first_shipment_date']);
        }
    }

    public function test_filters_respect_year_uncertainty_and_sorting_is_stable(): void
    {
        $this->project->update(['payload' => [...$this->project->payload, 'first_shipment_date' => '2016']]);
        $full = app(CreateObjectRecord::class)->handle($this->project->businessObject, [...$this->project->payload, 'name' => '年份精度项目完整日期', 'first_shipment_date' => '2016-06-01']);
        foreach ([['equals', '2016', 2], ['equals', '2016-06-01', 1], ['between', '2016-01-01..2016-12-31', 2], ['between', '2016-06-01..2016-06-30', 1], ['before', '2016-07-01', 1], ['after', '2016-05-01', 1], ['equals', 'bad', 0]] as [$operator, $value, $count]) {
            $query = http_build_query(['q' => '年份精度项目', 'filters' => [['field' => 'first_shipment_date', 'operator' => $operator, 'value' => $value]], 'sort' => 'first_shipment_date']);
            $this->get('/objects/project?'.$query)->assertInertia(fn (Assert $page) => $page->has('records.data', $count));
        }
        $query = http_build_query(['q' => '年份精度项目', 'filters' => [['field' => 'first_shipment_date', 'operator' => 'equals', 'value' => '2016']], 'sort' => 'first_shipment_date']);
        $this->get('/objects/project?'.$query)->assertInertia(fn (Assert $page) => $page->where('records.data.0.id', $this->project->id)->where('records.data.1.id', $full->id));
    }

    public function test_csv_exports_the_year_without_an_invented_day(): void
    {
        $this->project->update(['payload' => [...$this->project->payload, 'first_shipment_date' => '2016']]);
        $response = $this->get('/objects/project/export.csv?q='.urlencode('年份精度项目'))->assertOk();
        $csv = $response->streamedContent();
        $this->assertStringContainsString('2016', $csv);
        $this->assertStringNotContainsString('2016-01-01', $csv);
        $this->assertStringNotContainsString('月日不详', $csv);
    }
}
