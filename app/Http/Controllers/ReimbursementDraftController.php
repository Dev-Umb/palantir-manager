<?php

namespace App\Http\Controllers;

use App\Actions\ReimbursementDraftService;
use App\Http\Requests\SaveReimbursementDraftRequest;
use App\Models\ReimbursementDraft;
use App\Models\ReimbursementUpload;
use App\Models\StoredAttachment;
use App\Support\BusinessFileStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class ReimbursementDraftController extends Controller
{
    public function __construct(private ReimbursementDraftService $drafts) {}

    public function index(Request $request): JsonResponse|Response
    {
        $records = ReimbursementDraft::where('user_id', $request->user()->id)->whereNull('version_id')->where(fn ($q) => $q->whereNull('batch_id')->orWhereJsonLength('payload->items', '>', 0))->latest('updated_at')->paginate(20)->through(fn ($d) => $this->drafts->present($d));

        return $request->expectsJson() && ! $request->header('X-Inertia') ? response()->json(['records' => $records]) : Inertia::render('Reimbursements/Drafts', ['records' => $records]);
    }

    public function show(Request $request, ReimbursementDraft $draft): JsonResponse|Response
    {
        $this->drafts->authorize($request->user(), $draft);
        abort_if($draft->version_id !== null, 409, '草稿已提交，请查看报销清单。');
        abort_if(str_starts_with($request->userAgent() ?? '', 'Dart/') && ! in_array($draft->payload['type'] ?? '', ['差旅交通', '客户招待', '办公采购', '其他', ''], true), 409, '此草稿使用新增报销类型，请在小程序中编辑并提交。');
        $record = $this->drafts->present($draft);

        return $request->expectsJson() && ! $request->header('X-Inertia') ? response()->json(['draft' => $record]) : Inertia::render('Reimbursements/Draft', ['draft' => $record]);
    }

    public function update(SaveReimbursementDraftRequest $request, string $draft): JsonResponse
    {
        return response()->json(['draft' => $this->drafts->present($this->drafts->save($request->user(), $draft, $request->validated()))]);
    }

    public function file(Request $request, ReimbursementDraft $draft, string $upload): HttpResponse
    {
        $this->drafts->authorize($request->user(), $draft);
        abort_if($draft->version_id !== null, 404);
        abort_unless(collect($draft->payload['items'])->flatMap(fn ($i) => $i['attachments'])->contains($upload), 404);
        $row = ReimbursementUpload::whereKey($upload)->where('user_id', $request->user()->id)->firstOrFail();
        $file = StoredAttachment::findOrFail($row->stored_attachment_id);
        app(BusinessFileStorage::class)->verify($file->disk, $file->object_key, $file->size, $file->sha256);

        return Storage::disk($file->disk)->response($file->object_key, $file->original_name, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
