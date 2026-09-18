<?php

namespace App\Http\Controllers;

use App\Models\ObjectRecord;
use App\Support\AttachmentPreview;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentController extends Controller
{
    public function __construct(private AttachmentPreview $attachments) {}

    public function __invoke(Request $request, ObjectRecord $record, string $field, ?int $index = null): StreamedResponse
    {
        $file = $this->attachments->resolve($request->user(), $record, $field, $index, preview: false);

        return response()->streamDownload(function () use ($file): void {
            $stream = $file['disk']->readStream($file['key']);
            abort_unless(is_resource($stream), 404);
            try {
                fpassthru($stream);
            } finally {
                fclose($stream);
            }
        }, $file['name'], [
            'Content-Type' => $file['mime'],
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ], 'attachment');
    }
}
