<?php

namespace App\Http\Controllers;

use App\Actions\ReimbursementService;
use App\Http\Requests\SaveReimbursementWorkflowRequest;
use App\Http\Requests\SubmitReimbursementRequest;
use App\Models\ReimbursementNotice;
use App\Models\ReimbursementUpload;
use App\Models\ReimbursementVersion;
use App\Models\ReimbursementWorkflow;
use App\Models\StoredAttachment;
use App\Models\User;
use App\Support\BusinessFileStorage;
use App\Support\InvoicePdfRecognition;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use ZipArchive;

class ReimbursementController extends Controller
{
    public function __construct(private ReimbursementService $service, private BusinessFileStorage $files) {}

    private function page(Request $request, string $component, array $data): JsonResponse|Response
    {
        return $request->expectsJson() && ! $request->header('X-Inertia') ? response()->json($data) : Inertia::render('Reimbursements/'.$component, $data);
    }

    public function index(Request $request): JsonResponse|Response
    {
        $filters = $request->validate(['scope' => ['nullable', Rule::in(['mine', 'review', 'cc', 'all'])], 'owner' => ['nullable', 'string', 'max:120'], 'type' => ['nullable', 'string', 'max:32'], 'status' => ['nullable', Rule::in(['pending', 'approved', 'rejected'])], 'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', ...($request->filled('from') ? ['after_or_equal:from'] : [])]]);
        $scope = $filters['scope'] ?? ($request->user()->canDo('reimbursements.manage') ? 'all' : 'mine');
        $query = $this->service->visible($request->user());
        if ($scope === 'mine') {
            $query->where('user_id', $request->user()->id);
        } elseif (in_array($scope, ['review', 'cc'], true)) {
            $query->whereExists(fn ($q) => $q->selectRaw('1')->from('reimbursement_access')->whereColumn('version_id', 'reimbursement_versions.id')->where('user_id', $request->user()->id)->where('kind', $scope === 'cc' ? 'cc' : 'approver'));
        } else {
            abort_unless($request->user()->canDo('reimbursements.manage'), 403);
        }
        if ($scope !== 'cc') {
            $query->whereExists(fn ($q) => $q->selectRaw('1')->from('reimbursements')->whereColumn('reimbursements.id', 'reimbursement_versions.reimbursement_id')->whereColumn('latest_version', 'reimbursement_versions.number'));
        }
        foreach (['type'] as $key) {
            $query->when($filters[$key] ?? null, fn ($q, $value) => $q->where($key, $value));
        }
        $query->when($filters['owner'] ?? null, fn ($q, $name) => $q->whereIn('user_id', User::withTrashed()->where('name', 'like', '%'.$name.'%')->select('id')));
        $query->when($filters['from'] ?? null, fn ($q, $date) => $q->whereDate('created_at', '>=', $date));
        $query->when($filters['to'] ?? null, fn ($q, $date) => $q->whereDate('created_at', '<=', $date));
        $pendingCount = (clone $query)->where('status', 'pending')->count();
        $query->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status));
        $records = $query->latest('id')->paginate(20)->withQueryString()->through(fn ($v) => $this->service->present($request->user(), $v));

        return $this->page($request, 'Index', ['records' => $records, 'pending_count' => $pendingCount, 'filters' => [...$filters, 'scope' => $scope], ...$this->capabilities($request)]);
    }

    private function capabilities(Request $request): array
    {
        return ['capabilities' => ['submit' => $request->user()->canDo('reimbursements.submit'), 'manage' => $request->user()->canDo('reimbursements.manage'), 'configure' => $request->user()->canDo('reimbursements.configure')]];
    }

    public function options(Request $request): JsonResponse
    {
        return response()->json(['users' => User::whereDoesntHave('roles', fn ($q) => $q->where('name', 'timebook_operator'))->orderBy('name')->get(['id', 'name']), 'types' => ['差旅交通', '客户招待', '办公采购', '其他'], 'wechat_template' => config('reimbursements.wechat_template'), ...$this->capabilities($request)]);
    }

    public function show(Request $request, ReimbursementVersion $version): JsonResponse|Response
    {
        $this->service->authorizeVersion($request->user(), $version);
        ReimbursementNotice::where('version_id', $version->id)->where('user_id', $request->user()->id)->whereNull('read_at')->update(['read_at' => now()]);

        return $this->page($request, 'Show', ['record' => $this->service->present($request->user(), $version)]);
    }

    public function submit(SubmitReimbursementRequest $request): JsonResponse
    {
        $version = $this->service->submit($request->user(), $request->validated(), $request->session()->get('wechat_binding.version'));

        return response()->json(['record' => $this->service->present($request->user(), $version)], 201);
    }

    public function decide(Request $request, ReimbursementVersion $version): JsonResponse
    {
        $data = $request->validate(['node_index' => ['required', 'integer', 'min:0'], 'decision' => ['required', Rule::in(['approve', 'reject'])], 'note' => ['required_if:decision,reject', 'nullable', 'string', 'max:2000']]);
        $this->service->authorizeVersion($request->user(), $version);
        $version = $this->service->decide($request->user(), $version, $data);

        return response()->json(['record' => $this->service->present($request->user(), $version)]);
    }

    public function workflow(Request $request): JsonResponse|Response
    {
        abort_unless($request->user()->canDo('reimbursements.configure'), 403);

        return $this->page($request, 'Workflow', ['workflow' => ReimbursementWorkflow::findOrFail(1)->only(['revision', 'definition']), 'users' => $this->options($request)->getData(true)['users']]);
    }

    public function saveWorkflow(SaveReimbursementWorkflowRequest $request): JsonResponse
    {
        return response()->json(['workflow' => $this->service->saveWorkflow($request->user(), $request->validated())->only(['revision', 'definition'])]);
    }

    public function recognizeInvoice(Request $request, ReimbursementUpload $upload, InvoicePdfRecognition $recognizer): JsonResponse
    {
        abort_unless($request->user()->canDo('reimbursements.submit'), 403);
        abort_unless($upload->user_id === $request->user()->id, 404);
        $stored = StoredAttachment::findOrFail($upload->stored_attachment_id);
        abort_unless($stored->mime_type === 'application/pdf', 422, '当前仅支持 PDF 发票识别。');
        $key = 'invoice-recognition:v1:'.$request->user()->id.':'.$stored->sha256;
        $cached = Cache::get($key);
        if ($cached !== null) {
            return response()->json($cached)->header('Cache-Control', 'private, no-store');
        }
        $lock = Cache::lock($key.':lock', 60);
        if (! $lock->get()) {
            return response()->json(['message' => '这张发票正在识别，请稍后重试。'], 409);
        }
        $started = hrtime(true);
        $success = false;
        $trace = (string) Str::uuid();
        try {
            $result = $recognizer->recognize($stored, function (string $stage, float $duration, bool $success) use ($trace, $stored): void {
                Log::info('reimbursement.invoice_timing', ['trace' => $trace, 'stage' => $stage, 'bytes' => $stored->size, 'duration_ms' => round($duration, 1), 'success' => $success]);
            });
            $success = true;
            Cache::put($key, $result, now()->addMinutes(30));

            return response()->json($result)->header('Cache-Control', 'private, no-store');
        } catch (\Throwable $error) {
            return response()->json(['message' => $error instanceof \UnexpectedValueException ? $error->getMessage().' 已上传附件不受影响。' : '发票识别未完成，请重试或手动填写；已上传附件不受影响。'], 422);
        } finally {
            Log::info('reimbursement.invoice_timing', ['trace' => $trace, 'stage' => 'recognition', 'bytes' => $stored->size, 'duration_ms' => round((hrtime(true) - $started) / 1e6, 1), 'success' => $success]);
            $lock->release();
        }
    }

    public function upload(Request $request): JsonResponse
    {
        abort_unless($request->user()->canDo('reimbursements.submit'), 403);
        $request->validate(['file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'mimetypes:application/pdf,image/jpeg,image/png', 'max:20480']]);
        $started = hrtime(true);
        $trace = (string) Str::uuid();
        $timings = [];
        $bytes = $request->file('file')->getSize();
        $stored = $this->files->upload($request->file('file'), function (string $stage, float $duration, bool $success) use (&$timings, $trace, $bytes): void {
            $timings[$stage] = round($duration, 1);
            Log::info('reimbursement.invoice_timing', ['trace' => $trace, 'stage' => $stage, 'bytes' => $bytes, 'duration_ms' => $timings[$stage], 'success' => $success]);
        });
        try {
            $upload = ReimbursementUpload::create(['user_id' => $request->user()->id, 'stored_attachment_id' => $stored->id]);
        } catch (\Throwable $error) {
            $this->files->deleteUploaded($stored->logical_path);
            throw $error;
        }

        return response()->json(['file' => ['id' => $upload->id, 'name' => $stored->original_name, 'mime' => $stored->mime_type, 'size' => $stored->size], 'timing' => ['trace' => $trace, 'server_ms' => round((hrtime(true) - $started) / 1e6, 1), ...$timings]], 201);
    }

    private function attachment(ReimbursementVersion $version, string $upload): StoredAttachment
    {
        $ids = collect($version->items)->flatMap(fn ($item) => array_column($item['attachments'], 'id'))->all();
        abort_unless(in_array($upload, $ids, true), 404);
        $row = ReimbursementUpload::whereKey($upload)->where('reimbursement_id', $version->reimbursement_id)->firstOrFail();

        return StoredAttachment::findOrFail($row->stored_attachment_id);
    }

    public function file(Request $request, ReimbursementVersion $version, string $upload): HttpResponse
    {
        $this->service->authorizeVersion($request->user(), $version);
        $stored = $this->attachment($version, $upload);
        try {
            $this->files->verify($stored->disk, $stored->object_key, $stored->size, $stored->sha256);
        } catch (\Throwable $error) {
            report($error);
            abort(422, '发票缺失或校验失败，请联系管理员恢复文件。');
        }
        $headers = ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'Content-Type' => $stored->mime_type];

        return Storage::disk($stored->disk)->response($stored->object_key, $stored->original_name, $headers, $request->boolean('inline') ? 'inline' : 'attachment');
    }

    public function zip(Request $request, ReimbursementVersion $version): BinaryFileResponse
    {
        $this->service->authorizeVersion($request->user(), $version);
        $bytes = collect($version->items)->flatMap(fn ($item) => $item['attachments'])->sum('size');
        abort_if($bytes > 200 * 1024 * 1024, 422, '发票合计超过 200 MB，请逐张下载。');
        $temp = tempnam(sys_get_temp_dir(), 'reimbursement-');
        $parts = [];
        $zip = new ZipArchive;
        try {
            if ($zip->open($temp, ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('无法创建发票压缩包。');
            }
            foreach ($version->items as $i => $item) {
                foreach ($item['attachments'] as $j => $file) {
                    $stored = $this->attachment($version, $file['id']);
                    $this->files->verify($stored->disk, $stored->object_key, $stored->size, $stored->sha256);
                    $part = tempnam(sys_get_temp_dir(), 'invoice-');
                    $parts[] = $part;
                    $source = Storage::disk($stored->disk)->readStream($stored->object_key);
                    $target = fopen($part, 'wb');
                    try {
                        if (! is_resource($source) || stream_copy_to_stream($source, $target) !== (int) $stored->size) {
                            throw new \RuntimeException('发票读取不完整，请重试。');
                        }
                    } finally {
                        if (is_resource($source)) {
                            fclose($source);
                        }
                        fclose($target);
                    }
                    if (! hash_equals($stored->sha256, hash_file('sha256', $part))) {
                        throw new \RuntimeException('发票校验失败。');
                    }
                    $name = sprintf('%02d-%02d_', $i + 1, $j + 1).preg_replace('/[\\\\\/\x00-\x1f]/u', '_', $stored->original_name);
                    if (! $zip->addFile($part, $name)) {
                        throw new \RuntimeException('发票打包失败。');
                    }
                }
            }
            if (! $zip->close()) {
                throw new \RuntimeException('发票压缩包写入失败。');
            }

            return response()->download($temp, 'BX-'.$version->reimbursement_id.'-V'.$version->number.'-发票.zip', ['Cache-Control' => 'private, no-store'])->deleteFileAfterSend(true);
        } catch (\Throwable $error) {
            if ($zip->status === ZipArchive::ER_OK) {
                try {
                    $zip->close();
                } catch (\Throwable) {
                }
            }
            @unlink($temp);
            report($error);
            abort(422, '发票缺失、读取失败或压缩包无法完整生成，请重试或联系管理员。');
        } finally {
            foreach ($parts as $part) {
                @unlink($part);
            }
        }
    }

    public function notices(Request $request): JsonResponse|Response
    {
        $query = ReimbursementNotice::where('user_id', $request->user()->id);
        if ($request->boolean('unread')) {
            $query->whereNull('read_at');
        }
        $notices = $query->latest('id')->paginate(50)->withQueryString()->through(fn ($notice) => [...$notice->only(['id', 'version_id', 'event', 'message', 'read_at', 'created_at']), 'url' => route('reimbursements.show', $notice->version_id, false)]);

        return $this->page($request, 'Notices', ['notices' => $notices]);
    }

    public function readNotice(Request $request, ReimbursementNotice $notice): JsonResponse
    {
        abort_unless($notice->user_id === $request->user()->id, 403);
        $notice->update(['read_at' => $notice->read_at ?? now()]);

        return response()->json(['ok' => true]);
    }
}
