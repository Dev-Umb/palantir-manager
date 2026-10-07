<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\XycPrototypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RequisitionFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_retired_authenticated_and_public_submissions_preserve_existing_approval_and_rejection_flow(): void
    {
        $this->seed(XycPrototypeSeeder::class);
        $this->assertNotContains('requisition', array_column(config('xyc.objects'), 'key'));
        $this->get('/purchase-request')->assertNotFound();
        $this->assertFalse(Route::has('team-logs.public.create'));
    }

    private function userWithRole(string $role): User
    {
        $user = User::firstOrCreate(
            ['email' => "{$role}-flow@example.com"],
            ['name' => $role, 'password' => Hash::make('password123')],
        );
        $user->roles()->syncWithoutDetaching([Role::where('name', $role)->firstOrFail()->id]);

        return $user;
    }
}
