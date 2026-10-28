<?php

namespace App\Http\Controllers;

use App\Models\ObjectRecord;
use App\Support\AttachmentPreview;
use App\Support\BusinessFileStorage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentController extends Controller
{
    public function __construct(private AttachmentPreview $attachments) {}

    public function __invoke(Request $request, ObjectRecord $record, string $field, ?int $index = null): StreamedResponse|RedirectResponse
    {
        $file = $this->attachments->resolve($request->user(), $record, $field, $index, preview: false);

        $url = app(BusinessFileStorage::class)->downloadUrl($file['path'], $file['name'], $file['mime'], false);
        if ($url !== null && (! str_starts_with((string) $request->userAgent(), 'Dart/') || $request->header('X-TOS-Download') === '1')) {
            return redirect()->away($url)->withHeaders(['Cache-Control' => 'private, no-store']);
        }

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
