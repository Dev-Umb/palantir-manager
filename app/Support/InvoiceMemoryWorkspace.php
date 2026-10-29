<?php

namespace App\Support;

use Illuminate\Support\Str;
use RuntimeException;

class InvoiceMemoryWorkspace
{
    public function run(callable $callback): mixed
    {
        $root = realpath(config('reimbursements.batch_memory_path'));
        if (! $root || ! is_writable($root)) {
            throw new RuntimeException('invoice_memory_unavailable');
        }
        $mount = null;
        foreach (file('/proc/mounts', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $parts = explode(' ', $line);
            $path = str_replace('\\040', ' ', $parts[1] ?? '');
            if (($root === $path || str_starts_with($root, $path.'/')) && (! $mount || strlen($path) > strlen($mount[0]))) {
                $mount = [$path, $parts[2] ?? '', $parts[3] ?? ''];
            }
        }
        if ((($mount[1] ?? null) !== 'tmpfs' || ! in_array('noswap', explode(',', $mount[2] ?? ''), true)) || disk_free_space($root) < 100 * 1024 * 1024) {
            throw new RuntimeException('invoice_memory_capacity');
        }
        $lock = null;
        for ($i = 0; $i < 2; $i++) {
            $candidate = fopen($root.'/.slot-'.$i, 'c');
            if ($candidate && flock($candidate, LOCK_EX | LOCK_NB)) {
                $lock = $candidate;
                break;
            }if ($candidate) {
                fclose($candidate);
            }
        }
        if (! $lock) {
            throw new RuntimeException('invoice_memory_busy');
        }
        $directory = $root.'/job-'.Str::uuid();
        try {
            if (! mkdir($directory, 0700)) {
                throw new RuntimeException('invoice_memory_directory');
            }

            return $callback($directory);
        } finally {
            if (is_dir($directory)) {
                foreach (glob($directory.'/*') as $file) {
                    if (is_file($file)) {
                        unlink($file);
                    }
                }rmdir($directory);
            }flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
