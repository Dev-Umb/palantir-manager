<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['user_id', 'stored_attachment_id', 'reimbursement_id'])]
class ReimbursementUpload extends Model
{
    use HasFactory, HasUuids;

    protected function casts(): array
    {
        return ['user_id' => 'integer', 'reimbursement_id' => 'integer'];
    }
}
