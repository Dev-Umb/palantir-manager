<?php

namespace App\Http\Controllers;

use App\Ai\AiRunEventPublisher;
use App\Http\Requests\FixedQuotationRequest;
use App\Models\AiRun;
use App\Support\BusinessFileStorage;
use App\Support\FixedQuotationDocument;
use App\Support\FixedQuotationPdf;
use App\Support\QuotationAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AiQuotationController extends Controller
{
    public function generate(FixedQuotationRequest $request, AiRun $run, string $artifact, FixedQuotationDocument $documents, AiRunEventPublisher $events): JsonResponse
    {
        $this->authorizeRun($request, $run);
        $data = $request->validated();
        $result = DB::transaction(function () use ($run, $artifact, $data, $documents): array {
            $locked = AiRun::query()->lockForUpdate()->findOrFail($run->id);
            abort_unless($locked->status === 'completed', 409, '任务完成后才能生成报价。');
            $artifacts = $locked->artifacts ?? [];
            $index = collect($artifacts)->search(fn (array $item) => ($item['id'] ?? '') === $artifact && ($item['type'] ?? '') === 'quotation_docx');
            abort_if($index === false, 404);
            $item = $artifacts[$index];
            if ($item['data']['generated'] ?? false) {
                abort_unless($item['data']['confirmed_input'] === $data, 409, '该报价已生成，请在对话中重新制作另一份。');

                return $item;
            }
            $bytes = $documents->generate($data);
            app(BusinessFileStorage::class)->storeBytes($this->path($locked, $artifact), $bytes, 'quotation.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
            $item['revision'] = ($item['revision'] ?? 1) + 1;
            $item['data'] = [...$data, 'generated' => true, 'confirmed_input' => $data, 'generated_at' => now()->toISOString(), 'document_sha256' => hash('sha256', $bytes), 'template_sha256' => FixedQuotationDocument::TEMPLATE_SHA256];
            $artifacts[$index] = $item;
            $locked->update(['artifacts' => $artifacts]);

            return $item;
        });
        $events->publish($run, 'artifact.upsert', ['artifact' => $result]);

        return response()->json(['artifact' => $result, 'download_url' => route('ai.quotations.download', ['run' => $run, 'artifact' => $artifact])]);
    }

    public function download(Request $request, AiRun $run, string $artifact, FixedQuotationDocument $documents, FixedQuotationPdf $pdf): StreamedResponse|RedirectResponse
    {
        $this->authorizeRun($request, $run);
        $item = collect($run->artifacts ?? [])->first(fn (array $item) => ($item['id'] ?? '') === $artifact && ($item['type'] ?? '') === 'quotation_docx');
        abort_unless($item && ($item['data']['generated'] ?? false), 404);
        $bytes = app(BusinessFileStorage::class)->diskFor($this->path($run, $artifact))->get($this->path($run, $artifact));
        abort_unless(is_string($bytes) && hash('sha256', $bytes) === ($item['data']['document_sha256'] ?? ''), 404, '报价文件校验失败。');

        $format = $request->query('format', 'docx');
        abort_unless(in_array($format, ['docx', 'pdf'], true), 422, '不支持的报价文件格式。');
        $bytes = $documents->blackText($bytes);
        if ($format === 'pdf') {
            try {
                $bytes = $pdf->convert($bytes);
            } catch (RuntimeException $exception) {
                report($exception);
                abort(503, 'PDF 转换暂时不可用，请重试或先下载 DOCX。');
            }
        }

        $mime = $format === 'pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
        $exportPath = 'ai-quotations/'.$run->id.'/exports/'.hash('sha256', $bytes).'.'.$format;
        $files = app(BusinessFileStorage::class);
        if (! $files->diskFor($exportPath)->exists($exportPath)) {
            $files->storeBytes($exportPath, $bytes, 'quotation.'.$format, $mime);
        }
        $url = $files->downloadUrl($exportPath, 'quotation.'.$format, $mime);
        if ($url !== null && (! str_starts_with((string) $request->userAgent(), 'Dart/') || $request->header('X-TOS-Download') === '1')) {
            return redirect()->away($url)->withHeaders(['Cache-Control' => 'private, no-store']);
        }

        return response()->streamDownload(fn () => print ($bytes), 'quotation.'.$format, ['Content-Type' => $format === 'pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'Cache-Control' => 'private, no-store']);
    }

    private function authorizeRun(Request $request, AiRun $run): void
    {
        abort_unless(config('ai.harness_v2'), 404);
        abort_unless(QuotationAccess::allows($request->user()), 403);
        abort_unless($run->user_id === $request->user()->id, 404);
    }

    private function path(AiRun $run, string $artifact): string
    {
        return 'ai-quotations/'.$run->id.'/'.hash('sha256', $artifact).'.docx';
    }
}
