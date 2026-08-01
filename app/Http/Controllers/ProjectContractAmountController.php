<?php

namespace App\Http\Controllers;

use App\Actions\ResyncProjectContractAmount;
use App\Models\ObjectRecord;
use App\Support\BusinessWorkspace;
use App\Support\ProjectVisibility;
use Illuminate\Http\RedirectResponse;

class ProjectContractAmountController extends Controller
{
    public function __construct(
        private BusinessWorkspace $workspace,
        private ProjectVisibility $projectVisibility,
        private ResyncProjectContractAmount $resync,
    ) {}

    public function __invoke(ObjectRecord $project): RedirectResponse
    {
        $user = request()->user();
        abort_unless($this->workspace->isAdmin($user) || $this->workspace->isFinance($user), 403);
        abort_unless($this->projectVisibility->allowsProject($user, $project), 403);

        $this->resync->handle($project, $user);

        return back()->with('status', '合同金额已从合同表重新同步。');
    }
}
