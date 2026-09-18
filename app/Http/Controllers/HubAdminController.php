<?php

namespace App\Http\Controllers;

use App\Actions\BuildHubResearch;
use App\Actions\ImportHubCompany;
use App\Http\Requests\HubCompanyRequest;
use App\Http\Requests\HubImportRequest;
use App\Http\Requests\HubReviewRequest;
use App\Http\Requests\HubSourceRequest;
use App\Jobs\CollectHubSource;
use App\Models\HubCompany;
use App\Models\HubImport;
use App\Models\HubRun;
use App\Models\HubSource;
use App\Support\HubFetcher;
use App\Support\HubSourceAdapters;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class HubAdminController extends Controller
{
    public function index(HubController $hub): Response
    {
        return Inertia::render('ProcurementHub/Admin', ['sources' => HubSource::orderBy('id')->get(), 'runs' => HubRun::with(['steps' => fn ($q) => $q->select('id', 'hub_run_id', 'stage', 'round', 'status', 'output', 'usage', 'attempts', 'error')])->latest('id')->paginate(15), 'hubLinks' => $hub->links()]);
    }

    public function review(HubReviewRequest $request, HubRun $run, BuildHubResearch $research): RedirectResponse
    {
        $data = $request->validated();
        abort_unless($run->kind === 'research', 422);
        DB::transaction(function () use ($request, $run, $data, $research): void {
            $locked = HubRun::lockForUpdate()->findOrFail($run->id);
            $audit = $locked->audit ?? [];
            $audit['manual_reviews'][] = ['user_id' => $request->user()->id, 'action' => $data['action'], 'note' => $data['note'], 'at' => now()->toIso8601String()];
            $locked->update(['audit' => $audit, 'status' => 'withdrawn', 'score' => null, 'cancelled_at' => now()]);
            if ($data['action'] === 'research') {
                $newRun = $research->start($request->user()->id, $locked->query, $locked->hub_notice_id);
                $newRun->update(['audit' => ['issues' => [$data['note']]]]);
            }
        });

        return back()->with('status', '审计处置已记录；重新研究仍须通过独立审计。');
    }

    public function source(HubSourceRequest $request, HubFetcher $fetcher, ?HubSource $source = null): RedirectResponse
    {
        $data = $request->validated();
        if ($data['enabled'] && ! in_array($data['adapter'], HubSourceAdapters::SUPPORTED, true)) {
            throw ValidationException::withMessages(['enabled' => '待验证来源不能启用持续采集。']);
        }
        try {
            $fetcher->validateUrl($data['url'], $data['allowed_hosts']);
        } catch (\RuntimeException $exception) {
            throw ValidationException::withMessages(['url' => $exception->getMessage()]);
        }
        ($source ?? new HubSource)->fill([...$data, 'status' => $data['enabled'] ? 'pending' : 'candidate'])->save();

        return back()->with('status', '来源已保存；启用只授权该来源公开域名的采集。');
    }

    public function collect(HubSource $source): RedirectResponse
    {
        abort_unless($source->enabled && in_array($source->adapter, HubSourceAdapters::SUPPORTED, true), 422, '请先确认并启用来源。');
        CollectHubSource::dispatch($source->id);

        return back()->with('status', '采集已排队。');
    }

    public function company(Request $request, HubController $hub): Response
    {
        $preview = $request->integer('preview') ? HubImport::where('user_id', $request->user()->id)->find($request->integer('preview')) : null;

        return Inertia::render('ProcurementHub/Company', ['companies' => HubCompany::latest('id')->get(), 'preview' => $preview, 'hubLinks' => $hub->links()]);
    }

    public function saveCompany(HubCompanyRequest $request, ?HubCompany $company = null): RedirectResponse
    {
        ($company ?? new HubCompany)->fill([...$request->validated(), 'status' => 'draft', 'confirmed_at' => null, 'confirmed_by' => null, 'revision' => ($company?->revision ?? 0) + 1])->save();

        return back()->with('status', '资料已保存为旧版档案草稿，不参与当前项目主档推荐。');
    }

    public function confirmCompany(Request $request, HubCompany $company): RedirectResponse
    {
        if ($company->status !== 'confirmed') {
            $company->update(['status' => 'confirmed', 'confirmed_by' => $request->user()->id, 'confirmed_at' => now(), 'revision' => $company->revision + 1]);
        }

        return back()->with('status', '资料已确认存档。当前推荐直接参考项目主档。');
    }

    public function preview(HubImportRequest $request, ImportHubCompany $imports): RedirectResponse
    {
        $preview = $imports->preview($request->user()->id, $request->file('file'));

        return to_route('hub.company', ['preview' => $preview->id]);
    }

    public function confirmImport(Request $request, HubImport $import, ImportHubCompany $imports): RedirectResponse
    {
        abort_unless($import->user_id === $request->user()->id, 403);
        $imports->confirm($import, $request->user()->id);

        return to_route('hub.company')->with('status', '独立业绩资料已导入，ERP 未发生写入。');
    }
}
