<?php

namespace App\Models;

use Database\Factories\TimebookWorkerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TimebookWorker extends Model
{
    /** @use HasFactory<TimebookWorkerFactory> */
    use HasFactory;

    protected $fillable = ['name', 'name_key'];
}
