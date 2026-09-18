<?php

namespace App\Support;

use App\Models\ObjectRecord;
use App\Models\StoredAttachment;
use App\Models\User;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

class AttachmentPreview
{
    public const FIELDS = ['processing_letter_attachments', 'contract_attachments', 'attachment'];

    private const MIME_TYPES = ['application/pdf', 'image/jpeg', 'image/png'];

    /** @var array<string, string|null> */
    private array $names = [];

    public function __construct(private ProjectVisibility $visibility) {}

    public static function privatePath(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        $path = str_starts_with($value, '/storage/attachments/')
            ? 'attachments/'.substr($value, strlen('/storage/attachments/'))
            : ltrim($value, '/');

        return preg_match('#\Aattachments/[A-Za-z0-9._-]+\z#D', $path) === 1 ? $path : null;
    }

    /** @return array{disk: FilesystemAdapter, key: string, mime: string, name: string, size: int, local: bool} */
    public function resolve(User $user, ObjectRecord $record, string $field, ?int $index, bool $preview = true): array
    {
        $record->loadMissing('businessObject');
        $object = $record->businessObject;
        abort_unless($object && $user->canDo("object.{$object->key}.view"), 403);
        abort_unless($this->visibility->allowsRecord($user, $record), 403);
        if ($preview) {
            abort_unless($object->key === 'contract' && in_array($field, self::FIELDS, true), 404);
        }

        $definition = collect($object->fields ?? [])->first(fn (array $candidate): bool => ($candidate['key'] ?? null) === $field
            && in_array($candidate['type'] ?? null, ['file', 'files'], true)
            && ($candidate['scope'] ?? null) !== 'item');
        abort_unless($definition, 404);
        $value = $record->payload[$field] ?? null;
        if ($definition['type'] === 'files') {
            abort_unless(is_array($value) && $index !== null && array_key_exists($index, $value), 404);
            $value = $value[$index];
        } elseif ($preview) {
            abort_unless($index === null, 404);
        }
        $path = self::privatePath($value);
        abort_unless($path, 404);
        $stored = StoredAttachment::query()->where('logical_path', $path)->first();
        $diskName = $stored?->disk ?: 'local';
        $disk = Storage::disk($diskName);
        $key = $stored?->object_key ?: $path;
        abort_unless($disk->exists($key), 404);
        $mime = $stored?->mime_type ?: $disk->mimeType($key);
        if ($preview) {
            $stream = $disk->readStream($key);
            abort_unless(is_resource($stream), 404);
            try {
                $prefix = fread($stream, 8192);
                $mime = is_string($prefix) ? (new \finfo(FILEINFO_MIME_TYPE))->buffer($prefix) : false;
            } finally {
                fclose($stream);
            }
        }
        abort_unless(is_string($mime) && in_array($mime, self::MIME_TYPES, true), 404);

        return [
            'disk' => $disk,
            'key' => $key,
            'mime' => $mime,
            'name' => $stored?->original_name ?: basename($path),
            'size' => $preview ? $disk->size($key) : 0,
            'local' => config("filesystems.disks.{$diskName}.driver") === 'local',
        ];
    }

    /** @param Collection<int, ObjectRecord> $records */
    public function preload(Collection $records): void
    {
        $paths = $records->flatMap(fn (ObjectRecord $record): array => array_column($this->entries($record), 'path'))->unique()->all();
        $this->names = array_fill_keys($paths, null);
        if ($paths !== []) {
            StoredAttachment::query()->whereIn('logical_path', $paths)->pluck('original_name', 'logical_path')
                ->each(function (string $name, string $path): void {
                    $this->names[$path] = $name;
                });
        }
    }

    /** @return array<string, array<int, array<string, mixed>>> */
    public function descriptors(ObjectRecord $record): array
    {
        $result = [];
        foreach ($this->entries($record) as $entry) {
            $parameters = [$record->id, $entry['field']];
            if ($entry['index'] !== null) {
                $parameters[] = $entry['index'];
            }
            $extension = strtolower(pathinfo($entry['path'], PATHINFO_EXTENSION));
            $extension = in_array($extension, ['pdf', 'jpg', 'jpeg', 'png'], true) ? $extension : '';
            $label = $entry['label'].' '.(($entry['index'] ?? 0) + 1);
            $result[$entry['field']][] = [
                'index' => $entry['index'],
                'name' => $this->names[$entry['path']] ?? $label.($extension ? '.'.$extension : ''),
                'label' => $label,
                'kind' => $extension === 'pdf' ? 'pdf' : (in_array($extension, ['jpg', 'jpeg', 'png'], true) ? 'image' : 'file'),
                'info_url' => route('attachments.preview', $parameters, false),
                'content_url' => route('attachments.content', $parameters, false),
                'download_url' => route('attachments.download', $parameters, false),
            ];
        }

        return $result;
    }

    /** @return array<int, array{field: string, index: int|null, path: string, label: string}> */
    private function entries(ObjectRecord $record): array
    {
        if ($record->businessObject?->key !== 'contract') {
            return [];
        }
        $entries = [];
        foreach ($record->businessObject->fields ?? [] as $field) {
            if (! in_array($field['key'] ?? null, self::FIELDS, true)
                || ! in_array($field['type'] ?? null, ['file', 'files'], true)
                || ($field['scope'] ?? null) === 'item') {
                continue;
            }
            $value = $record->payload[$field['key']] ?? null;
            $values = $field['type'] === 'files' ? (is_array($value) ? $value : []) : [$value];
            foreach ($values as $index => $value) {
                $path = self::privatePath($value);
                if ($path !== null && is_int($index) && $index >= 0) {
                    $entries[] = ['field' => $field['key'], 'index' => $field['type'] === 'files' ? $index : null,
                        'path' => $path, 'label' => $field['label'] ?? '附件'];
                }
            }
        }

        return $entries;
    }
}
