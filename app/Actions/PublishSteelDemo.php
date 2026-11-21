<?php

namespace App\Actions;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;
use ZipArchive;

class PublishSteelDemo
{
    public function handle(UploadedFile $package, string $id): string
    {
        $zip = new ZipArchive;
        if ($zip->open($package->getRealPath(), ZipArchive::RDONLY | ZipArchive::CHECKCONS) !== true) {
            throw $this->invalid('无法读取 ZIP 文件。');
        }

        $prefix = "d/{$id}";
        $disk = Storage::disk('demo_tos');
        $started = false;
        try {
            $files = $this->files($zip);
            $entry = $this->entry(array_keys($files));
            $size = $files[$entry];
            unset($files[$entry]);
            $files[$entry] = $size;
            foreach ($files as $name => $expected) {
                $source = $zip->getStream($name);
                $tmp = tmpfile();
                if (! $source || ! $tmp) {
                    throw $this->invalid('读取包内文件失败。');
                }
                try {
                    if (stream_copy_to_stream($source, $tmp, $expected + 1) !== $expected) {
                        throw $this->invalid('包内文件长度错误。');
                    }
                    rewind($tmp);
                    $mime = match (strtolower(pathinfo($name, PATHINFO_EXTENSION))) {
                        'html', 'htm' => 'text/html; charset=utf-8',
                        'js', 'mjs' => 'text/javascript',
                        'css' => 'text/css',
                        'json', 'gltf' => 'application/json',
                        'wasm' => 'application/wasm',
                        'svg' => 'image/svg+xml',
                        'mp4' => 'video/mp4',
                        default => null,
                    };
                    $disk->put("{$prefix}/{$name}", $tmp, array_filter(['ContentType' => $mime, 'CacheControl' => 'no-store']));
                    $started = true;
                } finally {
                    fclose($tmp);
                    fclose($source);
                }
            }
            $path = implode('/', array_map('rawurlencode', explode('/', $entry)));

            return "https://site.umb.ink/{$prefix}/{$path}";
        } catch (Throwable $error) {
            if ($started) {
                try {
                    $disk->deleteDirectory($prefix);
                } catch (Throwable $cleanupError) {
                    report($cleanupError);
                }
            }
            throw $error;
        } finally {
            $zip->close();
        }
    }

    /** @return array<string,int> */
    private function files(ZipArchive $zip): array
    {
        $files = [];
        $total = 0;
        if ($zip->numFiles > 5000) {
            throw $this->invalid('文件数量超过 5000 个。');
        }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $name = $stat['name'];
            $zip->getExternalAttributesIndex($i, $system, $attributes);
            if (preg_match('~(?:^|/)\.\.?(?:/|$)|^/|//|[\\\\:\x00-\x1f]~', $name) || (($attributes >> 16) & 0170000) === 0120000) {
                throw $this->invalid('压缩包包含不安全的路径。');
            }
            if (str_ends_with($name, '/') || str_starts_with($name, '__MACOSX/') || basename($name) === '.DS_Store') {
                continue;
            }
            $total += $stat['size'];
            if ($total > 1024 * 1024 * 1024 || isset($files[$name]) || ! mb_check_encoding($name, 'UTF-8') || is_numeric($name)) {
                throw $this->invalid('解压内容超过 1 GiB，或文件名无效、重复。');
            }
            $files[$name] = $stat['size'];
        }

        return $files;
    }

    /** @param list<string> $names */
    private function entry(array $names): string
    {
        if (in_array('index.html', $names, true)) {
            return 'index.html';
        }
        $html = array_values(preg_grep('/\.html?$/i', $names));
        if (count($html) === 1) {
            return $html[0];
        }
        $root = explode('/', $names[0] ?? '')[0];
        if (in_array($root.'/index.html', $html, true) && array_all($names, fn ($name) => str_starts_with($name, $root.'/'))) {
            return $root.'/index.html';
        }
        throw $this->invalid('请在根目录放置 index.html，或仅保留一个 HTML 入口。');
    }

    private function invalid(string $message): ValidationException
    {
        return ValidationException::withMessages(['package' => $message]);
    }
}
