<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProductionWorkbenchController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $workbenches = [];
        foreach (['material' => 'material-arrivals', 'template' => 'template-accessories'] as $key => $module) {
            if ($request->user()->canDo($module.'.view')) {
                $workbenches[$key] = [
                    'url' => route($module.'.workbench', ['embedded' => 1]),
                    'manage' => $request->user()->canDo($module.'.manage'),
                    'export' => $request->user()->canDo($module.'.export'),
                    'recover' => $request->user()->canDo($module.'.recover'),
                ];
            }
        }
        abort_unless($workbenches, 403);

        return Inertia::render('ProductionWorkbench', [
            'workbenches' => $workbenches,
            'initialModule' => isset($workbenches[$request->query('module', '')]) ? $request->query('module') : array_key_first($workbenches),
            'panelUrl' => route('dashboard'),
        ]);
    }
}
