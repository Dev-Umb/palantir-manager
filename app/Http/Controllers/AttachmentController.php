<?php

namespace App\Http\Controllers;

use App\Models\ObjectRecord;
use App\Models\StoredAttachment;
use App\Support\ProjectVisibility;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentController extends Controller
{
    private const ALLOWED_MIME_TYPES = [
        'application/pdf',
        'image/jpeg',
        'image/png',
    ];

    public function __construct(private ProjectVisibility $projectVisibility) {}

    public function __invoke(Request $request, ObjectRecord $record, string $field, ?int $index = null): StreamedResponse
    {
        $record->loadMissing('businessObject');
        $object = $record->businessObject;
        abort_unless($object && $request->user()->canDo("object.{$object->key}.view"), 403);
        abort_unless($this->projectVisibility->allowsRecord($request->user(), $record), 403);

        $fileField = collect($object->fields ?? [])->first(
            fn (array $candidate): bool => ($candidate['key'] ?? null) === $field
                && in_array($candidate['type'] ?? null, ['file', 'files'], true)
                && ($candidate['scope'] ?? null) !== 'item',
        );
        abort_unless($fileField, 404);

        $value = $record->payload[$field] ?? null;
        if (($fileField['type'] ?? null) === 'files') {
            abort_unless(is_array($value) && $index !== null && array_key_exists($index, $value), 404);
            $value = $value[$index];
        }
        $path = $this->privatePath($value);
        abort_unless($path, 404);
        $stored = StoredAttachment::query()->where('logical_path', $path)->first();
        $disk = Storage::disk($stored?->disk ?: 'local');
        $objectKey = $stored?->object_key ?: $path;
        abort_unless($disk->exists($objectKey), 404);

        $mimeType = $stored?->mime_type ?: $disk->mimeType($objectKey);
        abort_unless(is_string($mimeType) && in_array($mimeType, self::ALLOWED_MIME_TYPES, true), 404);
        $downloadName = $stored?->original_name ?: basename($path);

        return response()->streamDownload(function () use ($disk, $objectKey): void {
            $stream = $disk->readStream($objectKey);
            abort_unless(is_resource($stream), 404);
            fpassthru($stream);
            fclose($stream);
        }, $downloadName, [
            'Content-Type' => $mimeType,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ], 'attachment');
    }

    private function privatePath(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        $path = str_starts_with($value, '/storage/attachments/')
            ? 'attachments/'.substr($value, strlen('/storage/attachments/'))
            : ltrim($value, '/');

        return preg_match('#\Aattachments/[A-Za-z0-9._-]+\z#D', $path) === 1 ? $path : null;
    }
}
