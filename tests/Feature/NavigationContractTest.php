<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\XycPrototypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class NavigationContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_shared_navigation_exposes_the_five_retained_business_tables_only(): void
    {
        $this->seed(XycPrototypeSeeder::class);
        $admin = User::create(['name' => 'admin', 'email' => 'nav@example.com', 'password' => Hash::make('password123')]);
        $admin->roles()->attach(Role::where('name', 'admin')->firstOrFail());

        $this->actingAs($admin)->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('auth')->has('flash')->has('notificationUnreadCount')
            ->has('nav', 5)
            ->where('nav.0.key', 'dashboard')
            ->where('nav.0.label', '经营大盘')
            ->where('nav.0.mobile_priority', 10)
            ->where('nav.0.visible', true)
            ->where('nav.2.key', 'ontology')
            ->has('nav.2.children', 3)
            ->has('nav.2.children.0.items', 2)
            ->where('nav.2.children.0.items.0.label', '客户信息')
            ->where('nav.2.children.0.items.1.label', '客户联系人')
            ->has('nav.2.children.1.items', 1)
            ->where('nav.2.children.1.items.0.label', '招投标信息')
            ->has('nav.2.children.2.items', 2)
            ->where('nav.2.children.2.items.0.label', '业务项目')
            ->where('nav.2.children.2.items.1.label', '合同表')
            ->has('nav.2.children.0.items.0.new_task_count'));
    }
}
