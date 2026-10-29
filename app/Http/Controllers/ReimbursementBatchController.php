<?php

namespace App\Http\Controllers;

use App\Actions\ReimbursementBatchService;
use App\Models\ReimbursementBatch;
use App\Models\ReimbursementBatchFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class ReimbursementBatchController extends Controller
{
    public function __construct(private ReimbursementBatchService $service) {}

    private function access(Request $request, ?ReimbursementBatch $batch = null): void
    {
        abort_unless(config('reimbursements.batch_enabled'), 503, '批量报销暂未开放。');
        abort_unless($request->user()->canDo('reimbursements.submit') && ! $request->user()->isTimebookOperator(), 403);
        if ($batch) {
            $this->service->authorize($request->user(), $batch);
        }
    }

    private function ownedFile(ReimbursementBatch $batch, string $file): ReimbursementBatchFile
    {
        return ReimbursementBatchFile::where('batch_id', $batch->id)->findOrFail($file);
    }

    public function index(Request $request): JsonResponse
    {
        $this->access($request);

        return response()->json(['records' => ReimbursementBatch::where('user_id', $request->user()->id)->latest()->paginate(20)->through(fn ($b) => $this->service->present($b, false))]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->access($request);
        $data = $request->validate(['id' => ['required', 'uuid']]);
        $batch = ReimbursementBatch::firstOrCreate(['id' => $data['id']], ['user_id' => $request->user()->id]);
        $this->service->authorize($request->user(), $batch);

        return response()->json(['batch' => $this->service->present($batch)], 201);
    }

    public function show(Request $request, ReimbursementBatch $batch): JsonResponse
    {
        $this->access($request, $batch);

        return response()->json(['batch' => $this->service->present($batch)]);
    }

    public function reserve(Request $request, ReimbursementBatch $batch): JsonResponse
    {
        $this->access($request, $batch);
        $data = $request->validate(['id' => ['required', 'uuid'], 'name' => ['required', 'string', 'max:200', 'regex:/\.(pdf|jpe?g|png)$/i'], 'size' => ['required', 'integer', 'min:1', 'max:20971520'], 'mime' => ['required', Rule::in(['application/pdf', 'image/jpeg', 'image/png'])]]);
        $file = $this->service->reserve($batch, $data);
        if ($file->status !== 'uploading') {
            return response()->json(['file_id' => $file->id, 'confirmed' => true]);
        }
        $signed = Storage::disk($file->disk)->temporaryUploadUrl($file->object_key.'.upload', now()->addMinutes(10), ['ContentType' => $file->mime, 'ContentLength' => $file->size]);
        $headers = [];
        foreach ($signed['headers'] as $key => $value) {
            if (! in_array(strtolower($key), ['host', 'content-length'], true)) {
                $headers[$key] = is_array($value) ? implode(',', $value) : $value;
            }
        }

        return response()->json(['file_id' => $file->id, 'confirmed' => false, 'url' => $signed['url'], 'headers' => $headers])->header('Cache-Control', 'no-store');
    }

    public function confirm(Request $request, ReimbursementBatch $batch, string $file): JsonResponse
    {
        $this->access($request, $batch);
        $this->service->confirm($this->ownedFile($batch, $file));

        return response()->json(['ok' => true]);
    }

    public function start(Request $request, ReimbursementBatch $batch): JsonResponse
    {
        $this->access($request, $batch);
        $data = $request->validate(['files' => ['required', 'array', 'min:1', 'max:300'], 'files.*' => ['uuid', 'distinct']]);
        $this->service->start($batch, $data['files']);

        return response()->json(['batch' => $this->service->present($batch)]);
    }
}
