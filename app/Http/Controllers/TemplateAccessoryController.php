<?php

namespace App\Http\Controllers;

use App\Actions\SaveTemplateAccessories;
use App\Http\Requests\TemplateAccessoryMutationRequest;
use App\Http\Requests\TemplateAccessoryPreferenceRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class TemplateAccessoryController extends Controller
{
    public function index(): InertiaResponse
    {
        return Inertia::render('TemplateAccessories/Index', ['workbenchUrl' => route('template-accessories.workbench')]);
    }

    public function workbench(Request $request): Response
    {
        $boot = ['csrf' => csrf_token(), 'userId' => $request->user()->id,
            'urls' => collect(['state', 'mutate', 'preferences', 'export'])->mapWithKeys(fn (string $name): array => [$name => route('template-accessories.'.$name)])->all(),
            'capabilities' => collect(['manage', 'export', 'recover'])->mapWithKeys(fn (string $action): array => [$action => $request->user()->canDo('template-accessories.'.$action)])->all()];
        $html = str_replace(['__TEMPLATE_BOOT__', '__TEMPLATE_ADAPTER__', '__PLATFORM_HOME__'], [
            json_encode($boot, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
            collect(['config', 'core', 'files', 'database-adapter', 'ui', 'bridge'])->map(fn (string $name): string => file_get_contents(resource_path('template-accessories/'.$name.'.js')))->implode("\n"),
            e(route('dashboard')),
        ], file_get_contents(resource_path('template-accessories/workbench.html')));

        return response($html)->header('Content-Type', 'text/html; charset=UTF-8')->header('Cache-Control', 'private, no-store')
            ->header('Content-Security-Policy', "default-src 'none'; script-src 'unsafe-inline'; style-src 'unsafe-inline'; img-src data: blob:; connect-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors 'self'");
    }

    public function state(Request $request, SaveTemplateAccessories $service): JsonResponse
    {
        $preferences = DB::table('template_accessory_preferences')->where('user_id', $request->user()->id)->get()->mapWithKeys(fn ($row): array => [$row->key => ['value' => $row->value, 'revision' => (int) $row->revision]])->all();

        return response()->json(['dataset' => $service->current(), 'preferences' => $preferences])->header('Cache-Control', 'private, no-store');
    }

    public function mutate(TemplateAccessoryMutationRequest $request, SaveTemplateAccessories $service): JsonResponse
    {
        return response()->json($service->save($request->validated(), $request->user()->id))->header('Cache-Control', 'private, no-store');
    }

    public function export(SaveTemplateAccessories $service): JsonResponse
    {
        return response()->json($service->current())->header('Cache-Control', 'private, no-store');
    }

    public function preferences(TemplateAccessoryPreferenceRequest $request): JsonResponse
    {
        $input = $request->validated();
        $scope = ['user_id' => $request->user()->id, 'key' => $input['key']];
        $result = DB::transaction(function () use ($input, $scope): array {
            DB::table('template_accessory_preferences')->insertOrIgnore([...$scope, 'revision' => 0, 'value' => null]);
            $row = DB::table('template_accessory_preferences')->where($scope)->lockForUpdate()->sole();
            if ($row->value === ($input['value'] ?? null)) {
                return ['revision' => (int) $row->revision];
            }
            abort_unless((int) $row->revision === $input['expected_revision'], 409, '个人草稿或筛选已在另一页面修改，请重新打开。当前输入已保留。');
            $changed = DB::table('template_accessory_preferences')->where('id', $row->id)->where('revision', $row->revision)->update(['value' => $input['value'] ?? null, 'revision' => $row->revision + 1]);
            abort_unless($changed === 1, 409, '个人状态已变化，请重新打开。');

            return ['revision' => $row->revision + 1];
        });

        return response()->json($result);
    }
}
