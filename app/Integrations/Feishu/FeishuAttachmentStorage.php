<?php

namespace App\Integrations\Feishu;

use App\Models\StoredAttachment;
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
