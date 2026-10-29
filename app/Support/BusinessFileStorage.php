<?php

namespace App\Support;

use App\Models\StoredAttachment;
use Closure;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\HeaderUtils;

class BusinessFileStorage
{
    public function diskName(): string
    {
        return (string) config('services.business_files.disk', 'contract_tos');
    }

    public function upload(UploadedFile $file, ?Closure $observe = null): StoredAttachment
    {
        $key = 'attachments/'.Str::uuid().($file->getClientOriginalExtension() !== '' ? '.'.$file->getClientOriginalExtension() : '');
        $stream = fopen($file->getRealPath(), 'rb');
        try {
            $this->writeVerified($key, $stream, (int) filesize($file->getRealPath()), hash_file('sha256', $file->getRealPath()), $file->getMimeType() ?: 'application/octet-stream', $observe);

            return StoredAttachment::create([
                'logical_path' => $key, 'disk' => $this->diskName(), 'object_key' => $key,
                'original_name' => basename($file->getClientOriginalName()), 'mime_type' => $file->getMimeType(),
                'size' => filesize($file->getRealPath()), 'sha256' => hash_file('sha256', $file->getRealPath()),
                'status' => StoredAttachment::STATUS_ATTACHED,
            ]);
        } catch (\Throwable $exception) {
            $this->deleteUploaded($key);
            throw $exception;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    public function storeBytes(string $key, string $bytes, string $name, string $mime): StoredAttachment
    {
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, $bytes);
        rewind($stream);
        try {
            $this->writeVerified($key, $stream, strlen($bytes), hash('sha256', $bytes), $mime);

            return StoredAttachment::updateOrCreate(['logical_path' => $key], [
                'disk' => $this->diskName(), 'object_key' => $key, 'original_name' => $name,
                'mime_type' => $mime, 'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes),
                'status' => StoredAttachment::STATUS_ATTACHED,
            ]);
        } finally {
            fclose($stream);
        }
    }

    /** @param resource $stream */
    public function writeVerified(string $key, mixed $stream, int $size, string $sha256, string $mime, ?Closure $observe = null): void
    {
        $started = hrtime(true);
        $stage = 'storage_write';
        $success = false;
        try {
            if (! is_resource($stream) || ! Storage::disk($this->diskName())->put($key, $stream, ['visibility' => 'private', 'ContentType' => $mime])) {
                throw new RuntimeException('业务文件上传失败。');
            }
            $observe?->__invoke($stage, (hrtime(true) - $started) / 1e6, true);
            $stage = 'storage_verify';
            $started = hrtime(true);
            $this->verify($this->diskName(), $key, $size, $sha256);
            $success = true;
        } finally {
            $observe?->__invoke($stage, (hrtime(true) - $started) / 1e6, $success);
        }
    }

    public function verify(string $diskName, string $key, int $size, string $sha256): void
    {
        $disk = Storage::disk($diskName);
        if (! $disk->exists($key) || $disk->size($key) !== $size) {
            throw new RuntimeException('业务文件大小校验失败：'.$key);
        }
        $stream = $disk->readStream($key);
        if (! is_resource($stream)) {
            throw new RuntimeException('业务文件读取失败：'.$key);
        }
        try {
            $hash = hash_init('sha256');
            if (hash_update_stream($hash, $stream, $size) !== $size) {
                throw new RuntimeException('业务文件读取不完整：'.$key);
            }
            if (! hash_equals($sha256, hash_final($hash))) {
                throw new RuntimeException('业务文件SHA256校验失败：'.$key);
            }
        } finally {
            fclose($stream);
        }
    }

    public function downloadUrl(string $path, string $name, string $mime, bool $inline = false): ?string
    {
        $stored = StoredAttachment::where('logical_path', $path)->first();
        $disk = $this->diskFor($path);
        if ($stored?->disk !== 'contract_tos' || ! $disk->providesTemporaryUrls()) {
            return null;
        }
        $disposition = HeaderUtils::makeDisposition(
            $inline ? 'inline' : 'attachment', basename($name), 'download.'.pathinfo($name, PATHINFO_EXTENSION)
        );

        return $disk->temporaryUrl($stored?->object_key ?: $path, now()->addMinutes(5), [
            'ResponseContentDisposition' => $disposition,
            'ResponseContentType' => $mime,
            'ResponseCacheControl' => 'private, no-store',
        ]);
    }

    public function diskFor(string $path): FilesystemAdapter
    {
        return Storage::disk(StoredAttachment::where('logical_path', $path)->value('disk') ?: 'local');
    }

    public function deleteUploaded(string $path): void
    {
        $stored = StoredAttachment::where('logical_path', $path)->first();
        try {
            $disk = $stored?->disk ?: $this->diskName();
            if (! Storage::disk($disk)->delete($stored?->object_key ?: $path)) {
                throw new RuntimeException('Storage cleanup failed');
            }
            $stored?->delete();
        } catch (\Throwable $exception) {
            if ($stored) {
                $stored->update(['status' => StoredAttachment::STATUS_STAGED]);
            }
            Log::warning('Business upload cleanup deferred; original upload exception is preserved.', ['path' => $path, 'exception_type' => $exception::class]);
        }
    }
}
