<?php

namespace App\Http\Controllers;

use App\Models\ObjectRecord;
use App\Support\AttachmentPreview;
use App\Support\BusinessFileStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentPreviewController extends Controller
{
    public function __construct(private AttachmentPreview $attachments) {}

    public function show(Request $request, ObjectRecord $record, string $field, ?int $index = null): JsonResponse
    {
        $file = $this->attachments->resolve($request->user(), $record, $field, $index);

        return response()->json(['mime_type' => $file['mime'], 'size' => $file['size'], 'content_url' => app(BusinessFileStorage::class)->downloadUrl($file['path'], $file['name'], $file['mime'], true)], headers: [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function content(Request $request, ObjectRecord $record, string $field, ?int $index = null): BinaryFileResponse|StreamedResponse|RedirectResponse
    {
        $file = $this->attachments->resolve($request->user(), $record, $field, $index);
        $url = app(BusinessFileStorage::class)->downloadUrl($file['path'], $file['name'], $file['mime'], true);
        if ($url !== null && (! str_starts_with((string) $request->userAgent(), 'Dart/') || $request->header('X-TOS-Download') === '1')) {
            return redirect()->away($url)->withHeaders(['Cache-Control' => 'private, no-store']);
        }

        $headers = [
            'Content-Type' => $file['mime'],
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ];
        if ($file['local']) {
            return response()->file($file['disk']->path($file['key']), $headers)
                ->setContentDisposition('inline')
                ->setPrivate();
        }

        $stream = $file['disk']->readStream($file['key']);
        abort_unless(is_resource($stream), 404);

        return response()->streamDownload(function () use ($stream): void {
            try {
                fpassthru($stream);
            } finally {
                fclose($stream);
            }
        }, 'attachment', [...$headers, 'Content-Length' => (string) $file['size'], 'Accept-Ranges' => 'none'], 'inline');
    }
}
