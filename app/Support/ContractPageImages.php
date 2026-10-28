<?php

namespace App\Support;

use App\Models\StoredAttachment;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Ai\Files\Image;
use RuntimeException;

class ContractPageImages
{
    /** @param callable(array<int, Image>): array $recognize */
    public function recognize(StoredAttachment $attachment, callable $recognize): array
    {
        $directory = sys_get_temp_dir().'/contract-pages-'.Str::uuid();
        if (! mkdir($directory, 0700)) {
            throw new RuntimeException('contract_temporary_directory_failed');
        }
        try {
            $disk = Storage::disk($attachment->disk);
            if ($disk->size($attachment->object_key) !== $attachment->size) {
                throw new RuntimeException('contract_source_size_invalid');
            }
            $source = $disk->readStream($attachment->object_key);
            if (! is_resource($source)) {
                throw new RuntimeException('contract_source_read_failed');
            }
            $path = $directory.'/contract.pdf';
            $target = fopen($path, 'w+b');
            try {
                if (stream_copy_to_stream($source, $target, $attachment->size) !== $attachment->size) {
                    throw new RuntimeException('contract_source_incomplete');
                }
            } finally {
                fclose($source);
                fclose($target);
            }
            if (! hash_equals($attachment->sha256, hash_file('sha256', $path))) {
                throw new RuntimeException('contract_source_digest_invalid');
            }
            $info = Process::path($directory)->timeout(10)->run(['pdfinfo', $path]);
            if (! $info->successful() || ! preg_match('/^Pages:\s+(\d+)\s*$/m', $info->output(), $match) || (int) $match[1] < 1) {
                throw new RuntimeException('contract_pdf_invalid');
            }
            $render = Process::path($directory)->timeout(40)->run(['pdftoppm', '-jpeg', '-jpegopt', 'quality=75', '-r', '144', '-scale-to', '2000', $path, $directory.'/page']);
            $paths = glob($directory.'/page-*.jpg');
            natsort($paths);
            $paths = array_values($paths);
            if (! $render->successful() || count($paths) !== (int) $match[1]) {
                throw new RuntimeException('contract_pages_incomplete');
            }
            $bytes = 0;
            foreach ($paths as $index => $image) {
                $bytes += filesize($image);
                if ($bytes > 20 * 1024 * 1024 || (new \finfo(FILEINFO_MIME_TYPE))->file($image) !== 'image/jpeg'
                    || ! preg_match('/page-0*'.($index + 1).'\.jpg$/', $image)) {
                    throw new RuntimeException('contract_pages_invalid_or_too_large');
                }
            }

            return $recognize(array_map(fn (string $path): Image => Image::fromPath($path), $paths));
        } finally {
            foreach (glob($directory.'/*') as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }
}
