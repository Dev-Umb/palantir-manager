<?php

namespace App\Http\Controllers;

use App\Jobs\RecognizeReimbursementEvidence;
use App\Models\ReimbursementUpload;
use App\Models\StoredAttachment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReimbursementEvidenceController extends Controller
{
    private function access(Request $request, ReimbursementUpload $upload): void
    {
        abort_unless($request->user()->canDo('reimbursements.submit') && ! $request->user()->isTimebookOperator(), 403);
        abort_unless($upload->user_id === $request->user()->id, 404);
    }

    public function show(Request $request, ReimbursementUpload $upload): JsonResponse
    {
        $this->access($request, $upload);

        return response()->json(['status' => $upload->evidence_status, 'result' => $upload->evidence_result])->header('Cache-Control', 'private, no-store');
    }

    public function start(Request $request, ReimbursementUpload $upload): JsonResponse
    {
        $this->access($request, $upload);
        $request->validate(['retry' => ['sometimes', 'boolean']]);
        DB::transaction(function () use ($upload, $request): void {
            $upload = ReimbursementUpload::lockForUpdate()->findOrFail($upload->id);
            abort_if($upload->reimbursement_id !== null, 409, '已提交的凭证不能重新识别。');
            if (in_array($upload->evidence_status, ['queued', 'processing', 'completed'], true) || ($upload->evidence_status && ! $request->boolean('retry'))) {
                return;
            }
            $file = StoredAttachment::findOrFail($upload->stored_attachment_id);
            $duplicate = ReimbursementUpload::where('user_id', $upload->user_id)->where('draft_id', $upload->draft_id)
                ->where('id', '!=', $upload->id)->whereNotNull('evidence_status')
                ->whereIn('stored_attachment_id', StoredAttachment::where('sha256', $file->sha256)->select('id'))->exists();
            if ($upload->draft_id && $duplicate) {
                $upload->forceFill(['evidence_status' => 'attention', 'evidence_result' => ['items' => [], 'issues' => [['page' => 1, 'message' => '本单已有相同文件，请移除重复附件，或确认仅作为补充凭证。']]]])->save();

                return;
            }
            $result = $upload->evidence_result ?? [];
            if ($request->boolean('retry')) {
                $failedPages = array_column($result['issues'] ?? [], 'page');
                $result['done_pages'] = array_values(array_diff($result['done_pages'] ?? [], $failedPages));
                $result['issues'] = [];
            }
            $upload->forceFill(['evidence_status' => 'queued', 'evidence_result' => $result])->save();
            RecognizeReimbursementEvidence::dispatch($upload->id)->afterCommit();
        });

        return $this->show($request, $upload->fresh());
    }

    public function review(Request $request, ReimbursementUpload $upload): JsonResponse
    {
        $this->access($request, $upload);
        DB::transaction(function () use ($upload): void {
            $upload = ReimbursementUpload::lockForUpdate()->findOrFail($upload->id);
            abort_if($upload->reimbursement_id !== null || in_array($upload->evidence_status, ['queued', 'processing'], true), 409);
            $upload->forceFill(['evidence_status' => 'reviewed'])->save();
        });

        return $this->show($request, $upload->fresh());
    }

    public function preview(Request $request, ReimbursementUpload $upload): StreamedResponse
    {
        $this->access($request, $upload);
        $file = StoredAttachment::findOrFail($upload->stored_attachment_id);
        abort_unless(in_array($file->mime_type, ['application/pdf', 'image/jpeg', 'image/png'], true), 422);
        $stream = Storage::disk($file->disk)->readStream($file->object_key);
        abort_unless(is_resource($stream), 404);

        return response()->stream(function () use ($stream): void {
            try {
                fpassthru($stream);
            } finally {
                fclose($stream);
            }
        }, 200, ['Content-Type' => $file->mime_type, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
