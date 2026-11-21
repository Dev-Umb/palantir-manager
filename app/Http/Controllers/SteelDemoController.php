<?php

namespace App\Http\Controllers;

use App\Actions\PublishSteelDemo;
use App\Actions\WithdrawSteelDemo;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class SteelDemoController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('SteelGirderProjects/Demos', [
            'deployments' => DB::table('steel_demo_deployments')->latest()->get(['id', 'name', 'url', 'withdrawing']),
        ]);
    }

    public function store(Request $request, PublishSteelDemo $publisher): RedirectResponse
    {
        $request->validate(['package' => ['required', 'file', 'extensions:zip', 'max:204800']]);
        try {
            $publisher->handle($request->file('package'));
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);
            throw ValidationException::withMessages(['package' => '发布失败，请检查存储配置后重试。']);
        }

        return to_route('steel-girder-projects.demos.index');
    }

    public function destroy(string $id, WithdrawSteelDemo $withdraw): RedirectResponse
    {
        try {
            $withdraw->handle($id);
        } catch (HttpClientException|\RuntimeException $error) {
            report($error);
            throw ValidationException::withMessages(['withdraw' => '撤下尚未完成，请检查 CDN 权限并重试。']);
        }

        return to_route('steel-girder-projects.demos.index');
    }
}
