<?php

namespace App\Http\Controllers;

use App\Actions\ProcessAiContractIntake;
use App\Http\Requests\ReviewAiContractIntakeRequest;
use App\Http\Requests\StoreAiContractIntakeRequest;
use App\Jobs\AnalyzeAiContractIntake;
use App\Models\AiContractIntake;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiContractIntakeController extends Controller
{
    public function __construct(private ProcessAiContractIntake $processor) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($this->processor->canUpload($request->user()), 403);

        return response()->json(['intakes' => AiContractIntake::where('user_id', $request->user()->id)->latest()->limit(20)->get()->map(fn ($intake) => $this->snapshot($intake))]);
    }

    public function store(StoreAiContractIntakeRequest $request): JsonResponse
    {
        $intake = $this->processor->stage($request->user(), $request->file('file'));
        AnalyzeAiContractIntake::dispatch($intake->id);

        return response()->json($this->snapshot($intake), 202);
    }

    public function show(Request $request, AiContractIntake $intake): JsonResponse
    {
        $this->processor->authorize($intake, $request->user());

        return response()->json($this->snapshot($intake));
    }

    public function retry(Request $request, AiContractIntake $intake): JsonResponse
    {
        $this->processor->authorize($intake, $request->user());
        $changed = AiContractIntake::whereKey($intake->id)->where('status', 'failed')->update(['status' => 'staged', 'error' => null]);
        abort_unless($changed, 409, '仅可重试识别失败的文件。');
        AnalyzeAiContractIntake::dispatch($intake->id);

        return response()->json($this->snapshot($intake->fresh()), 202);
    }

    public function projects(Request $request): JsonResponse
    {
        $validated = $request->validate(['search' => ['nullable', 'string', 'max:100']]);

        return response()->json($this->processor->candidates($request->user(), trim($validated['search'] ?? '')));
    }

    public function project(Request $request, string $project): JsonResponse
    {
        return response()->json($this->processor->projectDetails($request->user(), $project));
    }

    public function preview(ReviewAiContractIntakeRequest $request, AiContractIntake $intake): JsonResponse
    {
        return response()->json($this->processor->preview($intake, $request->user(), $request->validated()));
    }

    public function confirm(Request $request, AiContractIntake $intake): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'uuid'],
            'fields' => ['prohibited'],
            'unpaid_amount' => ['prohibited'],
            'paid_amount' => ['prohibited'],
            'occurred_amount' => ['prohibited'],
            'contract_amount' => ['prohibited'],
        ]);

        return response()->json($this->processor->confirm($intake, $request->user(), $validated['token']));
    }

    private function snapshot(AiContractIntake $intake): array
    {
        return [
            'id' => $intake->id, 'status' => $intake->status,
            'file_name' => $intake->storedAttachment->original_name,
            'extraction' => $intake->extraction, 'error' => $intake->error, 'result' => $intake->result,
        ];
    }
}
