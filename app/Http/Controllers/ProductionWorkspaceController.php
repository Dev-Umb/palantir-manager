<?php

namespace App\Http\Controllers;

use App\Actions\SaveProductionWorkspace;
use App\Http\Requests\ProductionWorkspaceMutationRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class ProductionWorkspaceController extends Controller
{
    public function workbench(Request $request): Response
    {
        $boot = ['csrf' => csrf_token(), 'userId' => $request->user()->id, 'base' => url('/production-workbench/data'), 'initialModule' => $request->query('module'), 'capabilities' => []];
        foreach (['material-arrivals', 'template-accessories'] as $module) {
            foreach (['manage', 'export', 'recover'] as $action) {
                $boot['capabilities'][$module][$action] = $request->user()->canDo($module.'.'.$action);
            }
        }
        $boot['legacyPreferences'] = [];
        foreach (['material_arrival_preferences', 'template_accessory_preferences'] as $table) {
            $boot['legacyPreferences'][$table] = DB::table($table)->where('user_id', $request->user()->id)->get()->mapWithKeys(fn ($row): array => [$row->key => ['value' => $row->value, 'revision' => (int) $row->revision]])->all();
        }
        $html = str_replace(['__PRODUCTION_BOOT__', '__PRODUCTION_ADAPTER__', '__PLATFORM_HOME__'], [json_encode($boot, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), file_get_contents(resource_path('production-workbench/database-adapter.js')), e(route('dashboard'))], file_get_contents(resource_path('production-workbench/workbench.html')));

        return response($html)->header('Content-Type', 'text/html; charset=UTF-8')->header('Cache-Control', 'private, no-store')->header('Content-Security-Policy', "default-src 'none'; script-src 'self' 'unsafe-inline'; style-src 'unsafe-inline'; img-src data: blob:; frame-src 'self'; connect-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors 'self'");
    }

    public function state(SaveProductionWorkspace $service): JsonResponse
    {
        return response()->json($service->current())->header('Cache-Control', 'private, no-store');
    }

    public function mutate(ProductionWorkspaceMutationRequest $request, SaveProductionWorkspace $service): JsonResponse
    {
        return response()->json($service->save($request->validated(), $request->user()))->header('Cache-Control', 'private, no-store');
    }

    public function snapshot(SaveProductionWorkspace $service): JsonResponse
    {
        return response()->json($service->snapshot())->header('Cache-Control', 'private, no-store');
    }
}
