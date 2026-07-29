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

    public function test_shared_navigation_contract_preserves_labels_links_visibility_children_and_task_counts(): void
    {
        $this->seed(XycPrototypeSeeder::class);
        $admin = User::create(['name' => 'admin', 'email' => 'nav@example.com', 'password' => Hash::make('password123')]);
        $admin->roles()->attach(Role::where('name', 'admin')->firstOrFail());

        $this->actingAs($admin)->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('auth')->has('flash')->has('notificationUnreadCount')
            ->has('nav', 8)
            ->where('nav.0.label', '经营大盘')
            ->where('nav.0.visible', true)
            ->has('nav.5.children')
            ->has('nav.5.children.0.items.0.new_task_count'));
    }
}
