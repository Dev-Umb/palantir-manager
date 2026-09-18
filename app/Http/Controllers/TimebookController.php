<?php

namespace App\Http\Controllers;

use App\Actions\WriteTimebookEntry;
use App\Http\Requests\TimebookEntryRequest;
use App\Models\TimebookEntry;
use App\Models\TimebookEntryAudit;
use App\Support\TimebookExport;
use App\Support\TimebookQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TimebookController extends Controller
{
    public function __construct(private TimebookQuery $query) {}

    public function index(Request $request): Response
    {
        $filters = $this->query->filters($request->query() ?: [
            'start' => now('Asia/Shanghai')->startOfMonth()->toDateString(),
            'end' => now('Asia/Shanghai')->endOfMonth()->toDateString(),
        ]);

        return Inertia::render('Timebook/Index', [
            'initial' => $this->query->result($filters),
            'urls' => ['records' => route('timebook.records', absolute: false),
                'names' => route('timebook.names', absolute: false),
                'entries' => route('timebook.store', absolute: false),
                'export' => route('timebook.export', absolute: false)],
        ]);
    }

    public function records(Request $request): JsonResponse
    {
        $data = $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);

        return response()->json($this->query->result($this->query->filters($request->query()), (int) ($data['page'] ?? 1)));
    }

    public function names(Request $request): JsonResponse
    {
        $filters = $this->query->filters($request->query());

        return response()->json($this->query->names($filters['q']));
    }

    public function store(TimebookEntryRequest $request, WriteTimebookEntry $writer): JsonResponse
    {
        return response()->json($writer->save($request->validated(), $request->user())->snapshot(), 201);
    }

    public function update(TimebookEntryRequest $request, TimebookEntry $entry, WriteTimebookEntry $writer): JsonResponse
    {
        return response()->json($writer->save($request->validated(), $request->user(), $entry)->snapshot());
    }

    public function destroy(Request $request, TimebookEntry $entry, WriteTimebookEntry $writer): JsonResponse
    {
        $data = $request->validate(['version' => ['required', 'integer:strict', 'min:1']]);

        return response()->json($writer->setDeleted($entry, $data['version'], true, $request->user())->snapshot());
    }

    public function restore(Request $request, TimebookEntry $entry, WriteTimebookEntry $writer): JsonResponse
    {
        $data = $request->validate(['version' => ['required', 'integer:strict', 'min:1']]);

        return response()->json($writer->setDeleted($entry, $data['version'], false, $request->user())->snapshot());
    }

    public function history(TimebookEntry $entry): JsonResponse
    {
        return response()->json(TimebookEntryAudit::query()->where('entry_id', $entry->id)->latest('id')->get());
    }

    public function export(Request $request, TimebookExport $export): \Illuminate\Http\Response
    {
        $result = $this->query->result($this->query->filters($request->query()), perPage: PHP_INT_MAX);
        $filename = '工日簿_'.($result['filters']['start'] ?: '全部').'_'.($result['filters']['end'] ?: '全部').'.xlsx';

        return response($export->make($result), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"timebook.xlsx\"; filename*=UTF-8''".rawurlencode($filename),
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
