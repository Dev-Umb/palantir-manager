<?php

namespace App\Support;

use App\Models\FinanceShipmentLedger;
use App\Models\ObjectRecord;
use App\Models\User;

class FinanceShipmentAccess
{
    public function __construct(private BusinessWorkspace $workspace, private ProjectVisibility $visibility) {}

    public function allows(?User $user): bool
    {
        return $user && ! $user->isTimebookOperator()
            && ($this->workspace->isAdmin($user) || $this->workspace->isFinance($user))
            && $user->canDo('object.project.view') && $user->canDo('object.project.update');
    }

    public function allowsUnlinked(User $user): bool
    {
        return $this->allows($user) && ! $this->visibility->hasGlobalBusinessView($user);
    }

    public function authorize(User $user, ObjectRecord|FinanceShipmentLedger|null $project = null): void
    {
        abort_unless($this->allows($user), 403);
        if ($project instanceof FinanceShipmentLedger) {
            if (! $project->project_id) {
                abort_unless($this->allowsUnlinked($user), 403);

                return;
            }
            $project = ObjectRecord::findOrFail($project->project_id);
        }
        if ($project) {
            abort_unless($project->businessObject->key === 'project' && $this->visibility->allowsRecordWrite($user, $project), 403);
        }
    }
}
