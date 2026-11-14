<?php

namespace App\Http\Controllers;

use App\Actions\SyncProjectContracts;
use App\Models\ObjectRecord;
use App\Support\BusinessWorkspace;
use App\Support\ObjectRelations;
use App\Support\ProjectVisibility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;

class ProjectContractUploadController extends Controller
{
    public function __invoke(Request $request, ObjectRecord $project, BusinessWorkspace $workspace, ProjectVisibility $visibility, SyncProjectContracts $contracts, ObjectRelations $relations): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->canDo('object.project.update') && $user->canDo('object.contract.view')
            && ($workspace->isAdmin($user) || $workspace->isBusiness($user))
            && $visibility->allowsProject($user, $project)
            && $visibility->allowsProjectUpdate($user, $project)
            && $workspace->writableFieldKeys($project->businessObject, $user) !== [], 403);

        $input = $request->validate([
            'contract_id' => ['nullable', 'uuid'],
            'status' => ['required_without:contract_id', Rule::in(['未签署', '已签署'])],
            'ctype' => ['nullable', Rule::in(['销售合同', '加工合同', '补充协议'])],
            'amount' => ['nullable', 'numeric'],
            'signed_date' => ['nullable', 'date'],
            'file' => ['required', File::types(['pdf', 'jpg', 'jpeg', 'png'])->max(100 * 1024)],
        ]);

        DB::transaction(function () use ($request, $project, $input, $user, $visibility, $contracts, $relations): void {
            $relations->lockReferenceGraph();
            $project = ObjectRecord::query()->lockForUpdate()->findOrFail($project->id);
            abort_unless($visibility->allowsProject($user, $project) && $visibility->allowsProjectUpdate($user, $project), 403);
            if (! empty($input['contract_id'])) {
                $contract = ObjectRecord::query()->whereRelation('businessObject', 'key', 'contract')
                    ->where('payload->project_id', $project->id)->lockForUpdate()->findOrFail($input['contract_id']);
                $values = Arr::only($contract->payload, ['status', 'ctype', 'amount', 'signed_date', 'contract_chase_record', 'contract_qty', 'remark']);
                $values['id'] = $contract->id;
                $values['status'] ??= '未签署';
            } else {
                $values = Arr::only($input, ['status', 'ctype', 'amount', 'signed_date']);
            }
            $batchRequest = new Request(['contracts' => [$values]]);
            $batchRequest->files->set('contracts', [['contract_attachments' => [$request->file('file')]]]);
            $contracts->handle($project, $contracts->validate($batchRequest, $project), $user);
        });

        return response()->json(['saved' => true]);
    }
}
