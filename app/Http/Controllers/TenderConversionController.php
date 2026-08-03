<?php

namespace App\Http\Controllers;

use App\Actions\ConvertTenderToProject;
use App\Http\Requests\ConvertTenderRequest;
use App\Models\ObjectRecord;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

class TenderConversionController extends Controller
{
    public function __invoke(
        ConvertTenderRequest $request,
        ObjectRecord $record,
        ConvertTenderToProject $convert,
    ): RedirectResponse {
        $assignee = User::query()->findOrFail($request->integer('assignee_user_id'));
        $project = $convert->handle($record, $assignee, $request->user());

        return redirect()
            ->route('objects.index', ['object' => 'tender', 'record' => $record->id, 'mode' => 'detail'])
            ->with('status', "已中标并流转至项目 {$project->code}。");
    }
}
