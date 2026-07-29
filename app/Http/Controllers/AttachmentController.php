<?php

namespace App\Http\Controllers;

use App\Models\ObjectRecord;
use App\Support\ProjectVisibility;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AttachmentController extends Controller
{
    private const ALLOWED_MIME_TYPES = [
        'application/pdf',
        'image/jpeg',
        'image/png',
    ];

    public function __construct(private ProjectVisibility $projectVisibility) {}

    public function __invoke(Request $request, ObjectRecord $record, string $field): BinaryFileResponse
    {
        $record->loadMissing('businessObject');
        $object = $record->businessObject;
        abort_unless($object && $request->user()->canDo("object.{$object->key}.view"), 403);
        abort_unless($this->projectVisibility->allowsRecord($request->user(), $record), 403);

        $fileField = collect($object->fields ?? [])->first(
            fn (array $candidate): bool => ($candidate['key'] ?? null) === $field
                && ($candidate['type'] ?? null) === 'file'
                && ($candidate['scope'] ?? null) !== 'item',
        );
        abort_unless($fileField, 404);

        $path = $this->privatePath($record->payload[$field] ?? null);
        $disk = Storage::disk('local');
        abort_unless($path && $disk->exists($path), 404);

        $mimeType = $disk->mimeType($path);
        abort_unless(is_string($mimeType) && in_array($mimeType, self::ALLOWED_MIME_TYPES, true), 404);

        return response()->download($disk->path($path), basename($path), [
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
