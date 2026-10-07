<?php

namespace App\Http\Controllers;

use App\Ai\AiRunEventPublisher;
use App\Http\Requests\FixedQuotationRequest;
use App\Models\AiRun;
use App\Support\FixedQuotationDocument;
use App\Support\FixedQuotationPdf;
use App\Support\QuotationAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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
            if (! Storage::disk('local')->put($this->path($locked, $artifact), $bytes)) {
                throw new RuntimeException('报价文件保存失败，请重试。');
            }
            $item['revision'] = ($item['revision'] ?? 1) + 1;
            $item['data'] = [...$data, 'generated' => true, 'confirmed_input' => $data, 'generated_at' => now()->toISOString(), 'document_sha256' => hash('sha256', $bytes), 'template_sha256' => FixedQuotationDocument::TEMPLATE_SHA256];
            $artifacts[$index] = $item;
            $locked->update(['artifacts' => $artifacts]);

            return $item;
        });
        $events->publish($run, 'artifact.upsert', ['artifact' => $result]);

        return response()->json(['artifact' => $result, 'download_url' => route('ai.quotations.download', ['run' => $run, 'artifact' => $artifact])]);
    }

    public function download(Request $request, AiRun $run, string $artifact, FixedQuotationDocument $documents, FixedQuotationPdf $pdf): StreamedResponse
    {
        $this->authorizeRun($request, $run);
        $item = collect($run->artifacts ?? [])->first(fn (array $item) => ($item['id'] ?? '') === $artifact && ($item['type'] ?? '') === 'quotation_docx');
        abort_unless($item && ($item['data']['generated'] ?? false), 404);
        $bytes = Storage::disk('local')->get($this->path($run, $artifact));
        abort_unless(is_string($bytes) && hash('sha256', $bytes) === ($item['data']['document_sha256'] ?? ''), 404, '报价文件校验失败。');

        $format = $request->validate(['format' => ['sometimes', 'in:docx,pdf']])['format'] ?? 'docx';
        $bytes = $documents->blackText($bytes);
        if ($format === 'pdf') {
            try {
                $bytes = $pdf->convert($bytes);
            } catch (RuntimeException $exception) {
                report($exception);
                abort(503, 'PDF 暂时无法导出，请重试；DOCX 仍可正常下载。');
            }

            return response()->streamDownload(fn () => print ($bytes), 'quotation.pdf', ['Content-Type' => 'application/pdf', 'Cache-Control' => 'private, no-store']);
        }

        return response()->streamDownload(fn () => print ($bytes), 'quotation.docx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'Cache-Control' => 'private, no-store']);
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
