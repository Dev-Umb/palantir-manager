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

    public function test_shared_navigation_exposes_the_four_retained_business_tables_only(): void
    {
        $this->seed(XycPrototypeSeeder::class);
        $admin = User::create(['name' => 'admin', 'email' => 'nav@example.com', 'password' => Hash::make('password123')]);
        $admin->roles()->attach(Role::where('name', 'admin')->firstOrFail());

        $this->actingAs($admin)->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('auth')->has('flash')->has('notificationUnreadCount')
            ->has('nav', 10)
            ->where('nav.4.key', 'settings')
            ->where('nav.6.key', 'hub')
            ->where('nav.7.key', 'ai')
            ->where('nav.0.key', 'dashboard')
            ->where('nav.0.label', '经营大盘')
            ->where('nav.0.mobile_priority', 10)
            ->where('nav.0.visible', true)
            ->where('nav.3.key', 'ontology')
            ->where('nav.3.children', fn ($groups): bool => collect($groups)->flatMap(fn ($group) => $group['items'])->pluck('href')->sort()->values()->all() === collect([
                route('objects.index', 'customer'), route('objects.index', 'tender'), route('objects.index', 'project'), route('objects.index', 'project_business_summary'), route('objects.index', 'contract'),
            ])->sort()->values()->all())
            ->has('nav.3.children.0.items.0.new_task_count'));
    }
}
