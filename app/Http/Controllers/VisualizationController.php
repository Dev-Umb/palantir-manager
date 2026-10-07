<?php

namespace App\Http\Controllers;

use App\Actions\BuildVisualization;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class VisualizationController extends Controller
{
    public function __invoke(Request $request, BuildVisualization $build): Response
    {
        return Inertia::render('Visualization', ['visualization' => $build->handle($request->user())]);
    }
}
