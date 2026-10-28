<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\ObjectRecord;
use App\Models\StoredAttachment;
use App\Support\AttachmentPreview;
use App\Support\BusinessFileStorage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class MigrateBusinessFilesToTos extends Command
{
    protected $signature = 'business-files:tos {--phase=inventory : inventory, upload, switch or verify} {--manifest= : Absolute manifest filename}';

    protected $description = 'Upload, verify and switch business files to TOS without deleting local originals';

    public function handle(BusinessFileStorage $files): int
    {
        $phase = $this->option('phase');
        $manifest = $this->option('manifest');
        if (! is_string($manifest) || ! str_starts_with($manifest, '/') || ! in_array($phase, ['inventory', 'upload', 'switch', 'verify'], true)) {
            $this->error('Provide an absolute manifest and a valid phase.');

            return self::FAILURE;
        }
        if ($files->diskName() !== 'contract_tos') {
            $this->error('Migration requires the contract_tos target disk.');

            return self::FAILURE;
        }
        if ($phase === 'inventory') {
            $entries = $this->inventory();
            $this->saveManifest($manifest, ['at' => now()->toISOString(), 'entries' => $entries]);
            $this->info('Inventory: '.count($entries).' files; missing: '.count(array_filter($entries, fn (array $e): bool => ! $e['exists'])));

            return count(array_filter($entries, fn (array $e): bool => ! $e['exists'])) === 0 ? self::SUCCESS : self::FAILURE;
        }
        $data = json_decode(file_get_contents($manifest), true, 512, JSON_THROW_ON_ERROR);
        $failures = [];
        foreach ($data['entries'] as $i => &$entry) {
            try {
                if (! $entry['exists']) {
                    throw new RuntimeException('Source missing');
                }
                if ($phase === 'upload') {
                    $source = Storage::disk($entry['source_disk']);
                    if ($entry['source_disk'] !== 'contract_tos' || $entry['source_key'] !== $entry['logical_path']) {
                        $files->verify($entry['source_disk'], $entry['source_key'], $entry['size'], $entry['sha256']);
                    }
                    if (Storage::disk('contract_tos')->exists($entry['logical_path'])) {
                        $files->verify('contract_tos', $entry['logical_path'], $entry['size'], $entry['sha256']);
                    } else {
                        $stream = $source->readStream($entry['source_key']);
                        try {
                            $files->writeVerified($entry['logical_path'], $stream, $entry['size'], $entry['sha256'], $entry['mime_type']);
                        } finally {
                            if (is_resource($stream)) {
                                fclose($stream);
                            }
                        }
                    }
                    $entry['uploaded_verified_at'] = now()->toISOString();
                } else {
                    if (empty($entry['uploaded_verified_at'])) {
                        throw new RuntimeException('Not uploaded and verified');
                    }
                    $files->verify('contract_tos', $entry['logical_path'], $entry['size'], $entry['sha256']);
                    if ($phase === 'verify') {
                        $stored = StoredAttachment::where('logical_path', $entry['logical_path'])->firstOrFail();
                        if ($stored->disk !== 'contract_tos' || $stored->object_key !== $entry['logical_path'] || $stored->original_name !== $entry['original_name'] || $stored->sha256 !== $entry['sha256']) {
                            throw new RuntimeException('Metadata mismatch after switch');
                        }
                        $entry['post_switch_verified_at'] = now()->toISOString();
                    }
                }
                unset($entry['error']);
            } catch (\Throwable $exception) {
                $entry['error'] = $exception->getMessage();
                $failures[] = $entry['logical_path'];
            }
            $this->saveManifest($manifest, $data);
            if (($i + 1) % 25 === 0) {
                $this->info($phase.': '.($i + 1).'/'.count($data['entries']).'; errors: '.count($failures));
            }
        }
        unset($entry);
        if ($phase === 'switch' && $failures === []) {
            DB::transaction(function () use ($data): void {
                foreach ($data['entries'] as $entry) {
                    $stored = StoredAttachment::where('logical_path', $entry['logical_path'])->lockForUpdate()->first();
                    $before = $stored?->toArray();
                    if ($stored && $stored->disk === 'contract_tos' && $stored->object_key === $entry['logical_path']
                        && $stored->sha256 === $entry['sha256'] && $stored->size === $entry['size']
                        && $stored->original_name === $entry['original_name'] && $stored->status === $entry['status']) {
                        continue;
                    }

                    if ($stored && ! $entry['registered_before']) {
                        throw new RuntimeException('Concurrent metadata creation: '.$entry['logical_path']);
                    }
                    if ($stored && $entry['registered_before'] && ($stored->disk !== $entry['registered_before']['disk'] || $stored->object_key !== $entry['registered_before']['object_key'] || $stored->sha256 !== $entry['registered_before']['sha256'] || $stored->original_name !== $entry['registered_before']['original_name'] || $stored->status !== $entry['registered_before']['status'])) {
                        throw new RuntimeException('Concurrent metadata change: '.$entry['logical_path']);
                    }
                    $stored = StoredAttachment::updateOrCreate(['logical_path' => $entry['logical_path']], [
                        'disk' => 'contract_tos', 'object_key' => $entry['logical_path'], 'original_name' => $entry['original_name'],
                        'mime_type' => $entry['mime_type'], 'size' => $entry['size'], 'sha256' => $entry['sha256'],
                        'status' => $entry['status'],
                    ]);
                    AuditLog::create(['action' => 'attachment.migrate.tos', 'subject_type' => 'stored_attachment', 'subject_id' => $stored->id, 'payload' => ['before' => $before, 'after' => $stored->toArray()]]);
                }
            });
            $data['switched_at'] = now()->toISOString();
        }
        $this->saveManifest($manifest, $data);
        $this->info(json_encode(['phase' => $phase, 'files' => count($data['entries']), 'failures' => $failures], JSON_UNESCAPED_UNICODE));

        return $failures === [] ? self::SUCCESS : self::FAILURE;
    }

    /** @return array<int, array<string, mixed>> */
    private function inventory(): array
    {
        $registered = StoredAttachment::all()->keyBy('logical_path');
        $paths = $registered->keys()->all();
        foreach (['attachments', 'ai-quotations', 'procurement-hub/evidence'] as $prefix) {
            $paths = [...$paths, ...Storage::disk('local')->allFiles($prefix)];
        }
        foreach (ObjectRecord::cursor() as $record) {
            $payload = $record->payload ?? [];
            array_walk_recursive($payload, function (mixed $value) use (&$paths): void {
                if (is_string($value) && preg_match('#\A(?:/?attachments/|/storage/attachments/)#', $value) === 1) {
                    $path = AttachmentPreview::privatePath($value);
                    if ($path !== null) {
                        $paths[] = $path;
                    }
                }
            });
        }
        $result = [];
        foreach (array_unique($paths) as $path) {
            $stored = $registered->get($path);
            $sourceDisk = $stored?->disk ?: 'local';
            $sourceKey = $stored?->object_key ?: $path;
            $disk = Storage::disk($sourceDisk);
            $exists = $disk->exists($sourceKey);
            if (! $exists && $sourceDisk === 'local' && Storage::disk('public')->exists($path)) {
                $sourceDisk = 'public';
                $sourceKey = $path;
                $disk = Storage::disk('public');
                $exists = true;
            }
            $hash = null;
            if ($exists) {
                $stream = $disk->readStream($sourceKey);
                try {
                    $context = hash_init('sha256');
                    $size = $disk->size($sourceKey);
                    if (hash_update_stream($context, $stream, $size) !== $size) {
                        throw new RuntimeException('Source read incomplete: '.$path);
                    }
                    $hash = hash_final($context);
                } finally {
                    fclose($stream);
                }
                if ($stored?->sha256 && ! hash_equals($stored->sha256, $hash)) {
                    throw new RuntimeException('Original checksum mismatch: '.$path);
                }
            }
            $result[] = ['logical_path' => $path, 'source_disk' => $sourceDisk, 'source_key' => $sourceKey,
                'exists' => $exists, 'size' => $exists ? $disk->size($sourceKey) : $stored?->size,
                'sha256' => $hash, 'original_name' => $stored?->original_name ?: basename($path),
                'mime_type' => $stored?->mime_type ?: ($exists ? $disk->mimeType($sourceKey) : 'application/octet-stream'),
                'status' => $stored?->status ?: StoredAttachment::STATUS_ATTACHED, 'registered_before' => $stored?->toArray()];
        }

        return $result;
    }

    /** @param array<string, mixed> $data */
    private function saveManifest(string $path, array $data): void
    {
        $temporary = $path.'.tmp';
        file_put_contents($temporary, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        chmod($temporary, 0600);
        rename($temporary, $path);
    }
}
