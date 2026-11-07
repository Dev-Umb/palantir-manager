<?php

namespace App\Support;

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

    public function authorize(User $user, ?ObjectRecord $project = null): void
    {
        abort_unless($this->allows($user), 403);
        if ($project) {
            abort_unless($project->businessObject->key === 'project' && $this->visibility->allowsRecordWrite($user, $project), 403);
        }
    }
}
