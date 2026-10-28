<?php

namespace App\Integrations\Feishu;

use App\Models\StoredAttachment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class FeishuAttachmentStorage
{
    private const MIME_EXTENSIONS = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
    ];

    public function storeUpload(UploadedFile $file, int $maxBytes): StoredAttachment
    {
        $size = $file->getSize();
        if (! $file->isValid() || $size === false || $size === 0 || $size > $maxBytes) {
            throw new RuntimeException('feishu_attachment_size_invalid');
        }
        $mimeType = $file->getMimeType();
        $extension = self::MIME_EXTENSIONS[$mimeType] ?? null;
        if (! $extension) {
            throw new RuntimeException('feishu_attachment_type_not_allowed');
        }
        $diskName = (string) config('services.feishu.attachment_disk', 'local');
        $objectKey = 'attachments/'.Str::uuid().'.'.$extension;
        $disk = Storage::disk($diskName);
        $sha256 = hash_file('sha256', $file->getRealPath());
        $source = fopen($file->getRealPath(), 'rb');
        try {
            if (! $disk->put($objectKey, $source, ['visibility' => 'private', 'ContentType' => $mimeType])) {
                throw new RuntimeException('feishu_attachment_storage_verification_failed');
            }
            if (! $disk->exists($objectKey) || $disk->size($objectKey) !== $size) {
                throw new RuntimeException('feishu_attachment_storage_verification_failed');
            }
            $stored = $disk->readStream($objectKey);
            if (! is_resource($stored)) {
                throw new RuntimeException('feishu_attachment_storage_verification_failed');
            }
            try {
                $hash = hash_init('sha256');
                if (hash_update_stream($hash, $stored, $size) !== $size || ! hash_equals($sha256, hash_final($hash))) {
                    throw new RuntimeException('feishu_attachment_digest_verification_failed');
                }
            } finally {
                fclose($stored);
            }

            return StoredAttachment::create([
                'logical_path' => $objectKey, 'disk' => $diskName, 'object_key' => $objectKey,
                'original_name' => $this->safeName($file->getClientOriginalName(), $extension),
                'mime_type' => $mimeType, 'size' => $size, 'sha256' => $sha256,
                'status' => StoredAttachment::STATUS_STAGED,
            ]);
        } catch (\Throwable $exception) {
            $disk->delete($objectKey);
            throw $exception;
        } finally {
            if (is_resource($source)) {
                fclose($source);
            }
        }
    }

    public function store(string $contents, string $originalName): StoredAttachment
    {
        $maxBytes = (int) config('services.feishu.attachment_max_bytes', 20 * 1024 * 1024);
        $size = strlen($contents);
        if ($size === 0 || $size > $maxBytes) {
            throw new RuntimeException('feishu_attachment_size_invalid');
        }

        $mimeType = (new \finfo(FILEINFO_MIME_TYPE))->buffer($contents) ?: 'application/octet-stream';
        $extension = self::MIME_EXTENSIONS[$mimeType] ?? null;
        if (! $extension) {
            throw new RuntimeException('feishu_attachment_type_not_allowed');
        }

        $diskName = (string) config('services.feishu.attachment_disk', 'local');
        $objectKey = 'attachments/'.Str::uuid().'.'.$extension;
        $disk = Storage::disk($diskName);
        $disk->put($objectKey, $contents, ['visibility' => 'private', 'ContentType' => $mimeType]);
        if (! $disk->exists($objectKey) || $disk->size($objectKey) !== $size) {
            throw new RuntimeException('feishu_attachment_storage_verification_failed');
        }
        $sha256 = hash('sha256', $contents);
        if (hash('sha256', $disk->get($objectKey)) !== $sha256) {
            throw new RuntimeException('feishu_attachment_digest_verification_failed');
        }

        try {
            return StoredAttachment::create([
                'logical_path' => $objectKey,
                'disk' => $diskName,
                'object_key' => $objectKey,
                'original_name' => $this->safeName($originalName, $extension),
                'mime_type' => $mimeType,
                'size' => $size,
                'sha256' => $sha256,
                'status' => StoredAttachment::STATUS_STAGED,
            ]);
        } catch (\Throwable $exception) {
            $disk->delete($objectKey);

            throw $exception;
        }
    }

    private function safeName(string $originalName, string $extension): string
    {
        $name = trim(str_replace(["\0", '/', '\\'], '_', $originalName));
        if ($name === '') {
            return '附件.'.$extension;
        }

        return Str::limit($name, 240, '').(pathinfo($name, PATHINFO_EXTENSION) === '' ? '.'.$extension : '');
    }
}
