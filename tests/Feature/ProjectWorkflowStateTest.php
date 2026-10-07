<?php

namespace Tests\Feature;

use Database\Seeders\XycPrototypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ProjectWorkflowStateTest extends TestCase
{
    use RefreshDatabase;

    public function test_retired_hidden_workflow_metadata_is_retained_but_cannot_advance_projects_through_direct_crud(): void
    {
        $this->seed(XycPrototypeSeeder::class);
        $this->assertNotContains('drawing', array_column(config('xyc.objects'), 'key'));
        $this->get('/purchase-request')->assertNotFound();
        $this->assertFalse(Route::has('team-logs.public.create'));
    }
}
