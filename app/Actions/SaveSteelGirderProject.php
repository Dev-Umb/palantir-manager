<?php

namespace App\Actions;

use App\Models\SteelGirderDrawing;
use App\Models\SteelGirderMaterial;
use App\Models\SteelGirderProject;
use App\Models\StoredAttachment;
use App\Models\User;
use App\Support\BusinessFileStorage;
use App\Support\SteelGirderWorkbook;
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SaveSteelGirderProject
{
    public function __construct(private BusinessFileStorage $files, private SteelGirderWorkbook $workbooks) {}

    public function mutate(string $id, int $revision, User $user, Closure $operation): SteelGirderProject
    {
        return DB::transaction(function () use ($id, $revision, $user, $operation): SteelGirderProject {
            $project = SteelGirderProject::lockForUpdate()->findOrFail($id);
            abort_if($project->revision !== $revision, 409, '项目已被其他人更新。请先导出未保存内容，再重新进入项目；不会覆盖他人的修改。');
            $operation($project);
            $project->revision++;
            $project->updated_by = $user->id;
            $project->save();

            return $project;
        });
    }

    public function material(string $id, array $input, ?UploadedFile $file, User $user): SteelGirderProject
    {
        SteelGirderProject::findOrFail($id);
        $sheets = $file ? $this->workbooks->read($file->getRealPath()) : null;
        $attachment = $file ? $this->upload($file) : null;

        return $this->mutate($id, $input['expected_revision'], $user, function (SteelGirderProject $project) use ($input, $sheets, $attachment): void {
            if ($attachment) {
                $material = SteelGirderMaterial::create([
                    'project_id' => $project->id, 'source_attachment_id' => $attachment->id,
                    'parsed_payload' => $sheets, 'reviews' => $input['reviews'], 'display_unit' => $input['unit'],
                ]);
                $project->current_material_id = $material->id;
            } else {
                abort_unless($project->current_material_id && $project->current_material_id === ($input['material_id'] ?? null), 409, '材料版本已变化，请重新进入项目。');
                $project->material()->firstOrFail()->update(['reviews' => $input['reviews'], 'display_unit' => $input['unit']]);
            }
        });
    }

    public function drawing(string $id, int $revision, UploadedFile $file, User $user): array
    {
        SteelGirderProject::findOrFail($id);
        $extension = strtolower($file->getClientOriginalExtension());
        $header = file_get_contents($file->getRealPath(), false, null, 0, 1024);
        if (($extension === 'pdf' && ! str_contains($header, '%PDF-')) || ($extension === 'dwg' && ! preg_match('/^AC10\d{2}/', $header))) {
            throw ValidationException::withMessages(['file' => '文件内容与扩展名不符，请选择有效 PDF 或 DWG。']);
        }
        $hash = hash_file('sha256', $file->getRealPath());
        $existing = SteelGirderDrawing::where('project_id', $id)->where('sha256', $hash)->first();
        $attachment = $existing ? null : $this->upload($file);
        $added = false;
        $project = $this->mutate($id, $revision, $user, function (SteelGirderProject $project) use ($hash, $attachment, &$added): void {
            $drawing = SteelGirderDrawing::withTrashed()->where('project_id', $project->id)->where('sha256', $hash)->first();
            if (! $drawing) {
                SteelGirderDrawing::create(['project_id' => $project->id, 'stored_attachment_id' => $attachment->id, 'sha256' => $hash]);
                $added = true;
            } elseif ($drawing->trashed()) {
                $drawing->restore();
                $added = true;
            }
        });

        return [$project, $added];
    }

    private function upload(UploadedFile $file): StoredAttachment
    {
        abort_unless($this->files->diskName() === 'contract_tos', 503, '本模块必须使用 TOS 文件存储，请联系管理员检查配置。');
        $key = 'steel-girder/'.Str::uuid().'.'.strtolower($file->getClientOriginalExtension());
        $size = filesize($file->getRealPath());
        $hash = hash_file('sha256', $file->getRealPath());
        $stream = fopen($file->getRealPath(), 'rb');
        try {
            // Original TOS objects are retained even when the subsequent database commit fails.
            $this->files->writeVerified($key, $stream, $size, $hash, $file->getMimeType() ?: 'application/octet-stream');
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return StoredAttachment::create([
            'logical_path' => $key, 'object_key' => $key, 'disk' => 'contract_tos',
            'original_name' => basename(str_replace('\\', '/', $file->getClientOriginalName())),
            'mime_type' => $file->getMimeType(), 'size' => $size, 'sha256' => $hash,
            'status' => StoredAttachment::STATUS_ATTACHED,
        ]);
    }
}
