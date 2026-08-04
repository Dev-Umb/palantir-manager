<?php

namespace App\Http\Controllers;

use App\Actions\BuildCompanyOperationsDashboard;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __construct(private BuildCompanyOperationsDashboard $dashboard) {}

    public function __invoke(Request $request): Response
    {
        return Inertia::render('Dashboard', $this->dashboard->handle($request->user()));
    }
}
