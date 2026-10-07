<?php

namespace Tests\Feature;

use App\Models\BusinessObject;
use App\Models\ObjectRecord;
use App\Models\QuotationArchive;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\XycPrototypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class QuotationProjectBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_project_picker_respects_existing_salesperson_visibility(): void
    {
        $this->seed(XycPrototypeSeeder::class);
        $user = User::where('email', 'business@xyc.test')->first();
        if (! $user) {
            $user = User::factory()->create();
            $user->roles()->attach(Role::where('name', 'business')->firstOrFail());
        }
        $object = BusinessObject::where('key', 'project')->firstOrFail();
        $own = ObjectRecord::create(['business_object_id' => $object->id, 'title' => '我的报价项目', 'code' => 'Q-OWN', 'payload' => ['business_owner_user_id' => (string) $user->id], 'created_by' => $user->id]);
        $other = ObjectRecord::create(['business_object_id' => $object->id, 'title' => '他人报价项目', 'code' => 'Q-OTHER', 'payload' => ['business_owner_user_id' => (string) User::factory()->create()->id], 'created_by' => $user->id]);
        $this->actingAs($user)->get('/quotations')->assertOk()->assertInertia(fn (Assert $page) => $page->where('projects', fn ($projects) => collect($projects)->contains('id', $own->id) && ! collect($projects)->contains('id', $other->id)));
    }

    public function test_account_deletion_retains_archived_owner_snapshot_without_changing_existing_account_delete_rules(): void
    {
        $user = User::factory()->create();
        $archive = QuotationArchive::factory()->create(['user_id' => $user->id, 'snapshot' => ['owner' => ['id' => $user->id, 'name' => $user->name]]]);
        $user->delete();
        $this->assertSame($user->id, $archive->fresh()->user_id);
        $this->assertSoftDeleted($user);
        $this->assertSame($user->name, $archive->fresh()->snapshot['owner']['name']);
    }
}
