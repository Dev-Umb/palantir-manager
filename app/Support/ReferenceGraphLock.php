<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use LogicException;

class ReferenceGraphLock
{
    public function acquire(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        if (DB::transactionLevel() < 1) {
            throw new LogicException('The reference graph lock must be acquired inside a transaction.');
        }

        DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['xyc-object-reference-graph']);
    }
}
