<?php

namespace App\Http\Controllers;

use App\Actions\PublishSteelDemo;
use App\Actions\WithdrawSteelDemo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class SteelDemoController extends Controller
{
    public function index(): Response
    {
        $sites = DB::table('steel_demo_sites as s')->leftJoin('steel_demo_deployments as d', 'd.id', '=', 's.current_id')
            ->leftJoin('steel_demo_deployments as latest', function ($join) {
                $join->on('latest.site_id', '=', 's.id')->on('latest.version', '=', 's.sequence');
            })->orderByDesc('s.updated_at')->get(['s.*', 'd.version as live_version', 'latest.status as latest_status']);

        return Inertia::render('SteelGirderProjects/Demos', ['sites' => $sites]);
    }

    public function show(string $site): Response
    {
        $record = DB::table('steel_demo_sites')->where('id', $site)->firstOrFail();
        $versions = DB::table('steel_demo_deployments')->where('site_id', $site)->orderByDesc('version')->get();
        $events = DB::table('steel_demo_events')->whereIn('deployment_id', $versions->pluck('id'))->orderByDesc('id')->get()->groupBy('deployment_id');

        return Inertia::render('SteelGirderProjects/Demos', ['site' => $record, 'deployments' => $versions, 'events' => $events, 'share' => route('steel-demo.visit', $site)]);
    }

    public function visit(string $site): RedirectResponse
    {
        $url = DB::table('steel_demo_sites as s')->join('steel_demo_deployments as d', 'd.id', '=', 's.current_id')
            ->where('s.id', $site)->where('d.status', 'ready')->value('d.url');
        abort_unless($url, 404);

        return redirect()->away($url)->header('Cache-Control', 'no-store');
    }

    public function store(Request $request, PublishSteelDemo $publisher): RedirectResponse
    {
        $request->validate(['package' => ['required', 'file', 'max:204800'], 'site_id' => ['nullable', 'uuid', 'exists:steel_demo_sites,id'], 'name' => ['nullable', 'string', 'max:180']]);
        $file = $request->file('package');
        $id = (string) Str::uuid();
        $site = $request->input('site_id') ?: (string) Str::uuid();
        $name = mb_substr($file->getClientOriginalName(), 0, 250);
        $operator = $request->user()->name;
        $version = DB::transaction(function () use ($request, $site, $id, $name, $operator) {
            $sites = DB::table('steel_demo_sites')->where('id', $site);
            if (! $request->filled('site_id')) {
                $sites->insert(['id' => $site, 'name' => $request->input('name') ?: pathinfo($name, PATHINFO_FILENAME), 'created_at' => now(), 'updated_at' => now()]);
            }
            $version = $sites->lockForUpdate()->firstOrFail()->sequence + 1;
            $sites->update(['sequence' => $version, 'updated_at' => now()]);
            DB::table('steel_demo_deployments')->insert(['id' => $id, 'site_id' => $site, 'version' => $version, 'name' => $name, 'operator' => $operator, 'status' => 'publishing', 'url' => '', 'created_at' => now(), 'updated_at' => now()]);

            return $version;
        });
        $deployment = DB::table('steel_demo_deployments')->where('id', $id);
        try {
            if (strtolower($file->getClientOriginalExtension()) !== 'zip') {
                throw ValidationException::withMessages(['package' => '请选择 ZIP 文件。']);
            }
            $url = $publisher->handle($file, $id);
            DB::transaction(function () use ($deployment, $site, $version, $url, $id) {
                DB::table('steel_demo_sites')->where('id', $site)->where('published_sequence', '<', $version)
                    ->update(['published_sequence' => $version, 'current_id' => $id, 'updated_at' => now()]);
                $deployment->update(['status' => 'ready', 'url' => $url, 'updated_at' => now()]);
            });
        } catch (Throwable $error) {
            $message = $error instanceof ValidationException ? ($error->errors()['package'][0] ?? 'ZIP 校验失败。') : '发布失败：文件写入或发布记录提交失败，请联系管理员。';
            if (! $error instanceof ValidationException) {
                report($error);
            }
            $deployment->update(['status' => 'failed', 'failure' => $message, 'updated_at' => now()]);
        }

        return to_route('steel-girder-projects.demos.show', $site);
    }

    public function promote(Request $request, string $id): RedirectResponse
    {
        $demo = DB::table('steel_demo_deployments')->where('id', $id)->firstOrFail();
        DB::transaction(function () use ($demo, $request) {
            $site = DB::table('steel_demo_sites')->where('id', $demo->site_id)->lockForUpdate()->firstOrFail();
            abort_unless(DB::table('steel_demo_deployments')->where('id', $demo->id)->where('status', 'ready')->exists(), 409);
            DB::table('steel_demo_sites')->where('id', $site->id)->update(['current_id' => $demo->id, 'published_sequence' => $site->sequence, 'updated_at' => now()]);
            DB::table('steel_demo_events')->insert(['deployment_id' => $demo->id, 'action' => '设为线上版本', 'operator' => $request->user()->name, 'created_at' => now()]);
        });

        return to_route('steel-girder-projects.demos.show', $demo->site_id);
    }

    public function destroy(Request $request, string $id, WithdrawSteelDemo $withdraw): RedirectResponse
    {
        $demo = DB::table('steel_demo_deployments')->where('id', $id)->firstOrFail();
        DB::transaction(function () use ($demo, $request) {
            $site = DB::table('steel_demo_sites')->where('id', $demo->site_id)->lockForUpdate()->firstOrFail();
            $status = DB::table('steel_demo_deployments')->where('id', $demo->id)->value('status');
            abort_unless(in_array($status, ['ready', 'withdrawing'], true), 409);
            if ($status === 'ready') {
                DB::table('steel_demo_deployments')->where('id', $demo->id)->update(['status' => 'withdrawing', 'withdrawing' => true]);
                DB::table('steel_demo_events')->insert(['deployment_id' => $demo->id, 'action' => '撤下版本', 'operator' => $request->user()->name, 'created_at' => now()]);
            }
            if ($site->current_id === $demo->id) {
                DB::table('steel_demo_sites')->where('id', $site->id)->update(['current_id' => null, 'published_sequence' => $site->sequence, 'updated_at' => now()]);
            }
        });
        try {
            $withdraw->handle($id);
        } catch (Throwable $error) {
            report($error);
            throw ValidationException::withMessages(['withdraw' => '撤下尚未完成，请检查权限后重试。']);
        }

        return to_route('steel-girder-projects.demos.show', $demo->site_id);
    }
}
