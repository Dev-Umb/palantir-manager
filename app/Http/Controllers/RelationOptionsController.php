<?php

namespace App\Http\Controllers;

use App\Models\BusinessObject;
use App\Models\ObjectRecord;
use App\Support\ObjectRelations;
use App\Support\ProjectVisibility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class RelationOptionsController extends Controller
{
    public function __construct(
        private ObjectRelations $relations,
        private ProjectVisibility $projectVisibility,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'source_object' => ['required', 'string', 'max:100'],
            'field' => ['required', 'string', 'max:100'],
            'q' => ['nullable', 'string', 'max:100'],
            'editing_record' => ['nullable', 'uuid'],
            'context' => ['nullable', 'array', 'max:50'],
            'context.*' => ['nullable', 'string', 'max:100'],
        ]);
        if ($validator->fails()) {
            return response()->json([
                'message' => '请求参数不正确。',
                'errors' => $validator->errors()->messages(),
            ], 422);
        }
        $validated = $validator->validated();

        $source = BusinessObject::where('key', $validated['source_object'])->firstOrFail();
        abort_unless($request->user()->canDo("object.{$source->key}.view"), 403);

        $field = collect($this->relations->relationFields($source))
            ->firstWhere('key', $validated['field']);
        if (! $field) {
            return response()->json([
                'message' => '字段必须是来源对象中已配置的关联字段。',
                'errors' => ['field' => ['字段必须是来源对象中已配置的关联字段。']],
            ], 422);
        }

        $editingRecord = null;
        if (! empty($validated['editing_record'])) {
            $editingRecord = ObjectRecord::with('businessObject')
                ->whereKey($validated['editing_record'])
                ->where('business_object_id', $source->id)
                ->firstOrFail();
            abort_unless($this->projectVisibility->allowsRecord($request->user(), $editingRecord), 404);
        }

        $result = $this->relations->searchOptions(
            $source,
            $field,
            $request->user(),
            $editingRecord,
            trim((string) ($validated['q'] ?? '')),
            $validated['context'] ?? [],
        );

        return response()->json(['items' => $result['items']]);
    }
}
