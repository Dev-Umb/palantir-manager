<?php

namespace App\Http\Controllers;

use App\Actions\SaveSupportLibrary;
use App\Http\Requests\SupportLibraryCommitRequest;
use App\Http\Requests\SupportLibraryImportRequest;
use App\Http\Requests\SupportLibraryQueryRequest;
use App\Http\Requests\SupportLibrarySaveRequest;
use App\Models\SupportLibrary;
use App\Support\SupportLibraryCalculator;
use App\Support\SupportLibraryException;
use App\Support\SupportLibraryExport;
use App\Support\SupportLibraryImport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Inertia\Inertia;

class SupportLibraryController extends Controller
{
    public function __construct(private SaveSupportLibrary $writer, private SupportLibraryCalculator $calculator, private SupportLibraryImport $importer, private SupportLibraryExport $exporter) {}

    public function index(Request $request): \Inertia\Response
    {
        $this->authorizeAction($request);
        $initial = SupportLibrary::where('key', 'default')->whereNotNull('current_version_id')->exists() ? $this->writer->current()->envelope() : null;

        return Inertia::render('SupportLibrary/Index', ['initial' => $initial, 'capabilities' => ['manage' => $request->user()->fresh()->canDo('support-library.manage'), 'export' => $request->user()->fresh()->canDo('support-library.export')],
            'urls' => collect(['library', 'save', 'query', 'imports.preview', 'imports.commit', 'exports.result', 'exports.library', 'exports.html', 'template'])
                ->mapWithKeys(fn ($name) => [$name => route('support-library.'.$name, absolute: false)])->all()]);
    }

    public function library(Request $request): JsonResponse
    {
        $this->authorizeAction($request);

        return response()->json($this->writer->current()->envelope());
    }

    public function query(SupportLibraryQueryRequest $request): JsonResponse
    {
        $input = $request->validated();
        $version = $this->writer->current($input['library_revision']);
        $result = $this->calculator->query($version->payload_json, $input['entries']);

        return response()->json(['library_revision' => $version->revision, 'rule_version' => $version->rule_version, 'request_fingerprint' => $input['request_fingerprint'], 'result' => $result]);
    }

    public function save(SupportLibrarySaveRequest $request): JsonResponse
    {
        $input = $request->validated();
        $base = $this->writer->current()->payload_json;
        $payload = [...$base, ...array_intersect_key($input, array_flip(['parts', 'frames', 'composition']))];
        $version = $this->writer->save($payload, $input['expected_revision'], $input['request_id'], $request->user());

        return response()->json($version->envelope());
    }

    public function preview(SupportLibraryImportRequest $request): JsonResponse
    {
        $version = $this->writer->current((int) $request->validated('expected_revision'));
        $file = $request->file('file');
        $preview = $this->importer->preview($file->getRealPath(), $file->getClientOriginalName(), $version->payload_json);
        $token = (string) Str::uuid();
        $expires = now()->addMinutes(30);
        Cache::put('support-library-preview:'.$token, [...$preview, 'actor_id' => $request->user()->id, 'base_revision' => $version->revision], $expires);

        return response()->json(['preview_token' => $token, 'expires_at' => $expires->toIso8601String(), 'filename' => $preview['filename'], 'stats' => $preview['stats'], 'warnings' => $preview['warnings'], 'compositionCount' => $preview['compositionCount']]);
    }

    public function commit(SupportLibraryCommitRequest $request): JsonResponse
    {
        $input = $request->validated();
        $preview = Cache::get('support-library-preview:'.$input['preview_token']);
        if (! $preview) {
            throw new SupportLibraryException('导入预览已过期，请重新选择Excel。', 'PREVIEW_EXPIRED', 410);
        }
        abort_unless($preview['actor_id'] === $request->user()->id, 403);
        if ($preview['base_revision'] !== $input['expected_revision']) {
            throw new SupportLibraryException('预览版本与提交版本不一致，请重新检查Excel。', 'REVISION_CONFLICT', 409);
        }
        $version = $this->writer->save($preview['payload'], $preview['base_revision'], $input['request_id'], $request->user(), 'import');

        return response()->json([...$version->envelope(), 'stats' => $preview['stats']]);
    }

    public function exportResult(SupportLibraryQueryRequest $request): Response
    {
        $this->authorizeAction($request, 'export');
        $input = $request->validated();
        $version = $this->writer->current($input['library_revision']);
        $result = $this->calculator->query($version->payload_json, $input['entries']);

        return $this->download($this->exporter->result($result), $result['bracing_complete'] ? '支架构件明细.xlsx' : '支架构件明细_部分结果.xlsx', $version->revision);
    }

    public function exportLibrary(Request $request): Response
    {
        $this->authorizeAction($request, 'export');
        $version = $this->writer->current($this->requestedRevision($request));

        return $this->download($this->exporter->library($version->payload_json), '支架库_当前数据库.xlsx', $version->revision);
    }

    public function exportHtml(Request $request): Response
    {
        $this->authorizeAction($request, 'export');
        $version = $this->writer->current($this->requestedRevision($request));

        return $this->download($this->exporter->html($version), '钢箱梁工作台_更新版.html', $version->revision, 'text/html; charset=utf-8');
    }

    public function template(Request $request): Response
    {
        $this->authorizeAction($request, 'export');

        return $this->download(file_get_contents(resource_path('support-library/template.xlsx')), '支架库_导入模板.xlsx', 0);
    }

    private function authorizeAction(Request $request, string $action = 'view'): void
    {
        $user = $request->user()?->fresh();
        abort_unless($user?->canDo('support-library.view') && $user->canDo('support-library.'.$action), 403);
    }

    private function requestedRevision(Request $request): int
    {
        $value = $request->query('library_revision');
        if (! is_string($value) || ! preg_match('/^[1-9]\d*$/D', $value) || strlen($value) > 15) {
            throw new SupportLibraryException('请提供有效的支架库版本。', 'INVALID_REVISION');
        }

        return (int) $value;
    }

    private function download(string $bytes, string $filename, int $revision, string $mime = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'): Response
    {
        return response($bytes, 200, ['Content-Type' => $mime, 'Content-Disposition' => "attachment; filename=\"support-library\"; filename*=UTF-8''".rawurlencode($filename),
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'X-Support-Library-Revision' => (string) $revision]);
    }
}
