<?php

namespace App\Http\Controllers;

use App\Actions\SaveSteelGirderProject;
use App\Http\Requests\SteelGirderMutationRequest;
use App\Models\SteelGirderProject;
use App\Models\StoredAttachment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

class SteelGirderProjectController extends Controller
{
    public function __construct(private SaveSteelGirderProject $writer) {}

    public function index(): \Inertia\Response
    {
        return Inertia::render('SteelGirderProjects/Index', ['workspaceUrl' => route('steel-girder-projects.workspace')]);
    }

    public function workspace(Request $request): Response
    {
        $root = resource_path('steel-girder');
        $config = ['base' => route('steel-girder-projects.list', absolute: false), 'csrf' => csrf_token(), 'manage' => $request->user()->canDo('steel-girder-projects.manage'), 'export' => $request->user()->canDo('steel-girder-projects.export')];
        $html = strtr(file_get_contents($root.'/dashboard.html'), [
            '/*__CSS__*/' => file_get_contents($root.'/style.css').file_get_contents($root.'/drawings.css'),
            '/*__ZIP__*/' => file_get_contents($root.'/jszip.min.js'),
            '/*__EXPORT__*/' => file_get_contents($root.'/review-export.js'),
            '/*__PROJECTS__*/' => 'window.SteelGirderConfig='.json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT).';'.file_get_contents($root.'/project-store.js'),
            '/*__APP__*/' => file_get_contents($root.'/app.js'),
        ]);

        return response($html)->withHeaders(['Cache-Control' => 'private, no-store', 'X-Frame-Options' => 'SAMEORIGIN', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function list(): JsonResponse
    {
        return response()->json(SteelGirderProject::with('material:id,reviews,source_attachment_id')->latest('updated_at')->get()->map($this->meta(...)));
    }

    public function show(SteelGirderProject $project): JsonResponse
    {
        $material = $project->material;

        return response()->json(['meta' => $this->meta($project), 'data' => $material ? [
            'material_id' => $material->id, 'sheets' => $material->parsed_payload,
            'filename' => $material->attachment->original_name, 'reviews' => $material->reviews, 'unit' => $material->display_unit,
        ] : null]);
    }

    public function create(SteelGirderMutationRequest $request): JsonResponse
    {
        $input = $request->validated();
        $project = SteelGirderProject::withTrashed()->find($input['id']);
        if ($project) {
            abort_unless(! $project->trashed() && $project->created_by === $request->user()->id && $project->name === trim($input['name']), 409, '项目编号已使用。');
        } else {
            $project = SteelGirderProject::create([...$input, 'name' => trim($input['name']), 'created_by' => $request->user()->id, 'updated_by' => $request->user()->id, 'revision' => 1]);
        }

        return response()->json($this->meta($project));
    }

    public function material(SteelGirderMutationRequest $request, SteelGirderProject $project): JsonResponse
    {
        $project = $this->writer->material($project->id, $request->validated(), $request->file('file'), $request->user());

        return response()->json(['meta' => $this->meta($project->fresh()), 'material_id' => $project->current_material_id]);
    }

    public function destroy(SteelGirderMutationRequest $request, SteelGirderProject $project): JsonResponse
    {
        $this->writer->mutate($project->id, $request->integer('expected_revision'), $request->user(), fn (SteelGirderProject $locked) => $locked->delete());

        return response()->json(['deleted' => true]);
    }

    public function drawings(SteelGirderProject $project): JsonResponse
    {
        return response()->json($project->drawings()->with('attachment')->oldest()->get()->map(fn ($drawing) => [
            'id' => $drawing->id, 'name' => $drawing->attachment->original_name,
            'size' => $drawing->attachment->size, 'type' => strtoupper(pathinfo($drawing->attachment->original_name, PATHINFO_EXTENSION)),
            'addedAt' => $drawing->created_at->getTimestampMs(),
        ]));
    }

    public function upload(SteelGirderMutationRequest $request, SteelGirderProject $project): JsonResponse
    {
        [$updated, $added] = $this->writer->drawing($project->id, $request->integer('expected_revision'), $request->file('file'), $request->user());

        return response()->json(['meta' => $this->meta($updated->fresh()), 'added' => $added ? 1 : 0, 'skipped' => $added ? 0 : 1]);
    }

    public function removeDrawing(SteelGirderMutationRequest $request, SteelGirderProject $project, string $drawing): JsonResponse
    {
        $updated = $this->writer->mutate($project->id, $request->integer('expected_revision'), $request->user(), function (SteelGirderProject $locked) use ($drawing): void {
            $locked->drawings()->findOrFail($drawing)->delete();
        });

        return response()->json($this->meta($updated->fresh()));
    }

    public function original(Request $request, SteelGirderProject $project): StreamedResponse
    {
        abort_unless($project->material, 404);

        abort_unless($request->query('material_id') === $project->current_material_id, 409, '材料已被更新，请重新进入项目。');

        return $this->download($project->material->attachment);
    }

    public function downloadDrawing(SteelGirderProject $project, string $drawing): StreamedResponse
    {
        return $this->download($project->drawings()->findOrFail($drawing)->attachment);
    }

    public function exportDrawings(SteelGirderProject $project): StreamedResponse
    {
        $drawings = $project->drawings()->with('attachment')->get();
        abort_if($drawings->isEmpty(), 404);
        $path = tempnam(sys_get_temp_dir(), 'steel-zip-');
        $zip = new ZipArchive;
        $temporary = [];
        try {
            abort_unless($zip->open($path, ZipArchive::OVERWRITE) === true, 503, '无法生成图纸压缩包。');
            $names = [];
            foreach ($drawings as $drawing) {
                $attachment = $drawing->attachment;
                $original = $this->safeName($attachment->original_name);
                $name = $original;
                $number = 2;
                while (isset($names[mb_strtolower($name)])) {
                    $name = pathinfo($original, PATHINFO_FILENAME).' ('.$number++.').'.pathinfo($original, PATHINFO_EXTENSION);
                }
                $names[mb_strtolower($name)] = true;
                $file = tempnam(sys_get_temp_dir(), 'steel-file-');
                $temporary[] = $file;
                $stream = Storage::disk($attachment->disk)->readStream($attachment->object_key);
                abort_unless(is_resource($stream), 503, '图纸读取失败。');
                $output = fopen($file, 'wb');
                try {
                    abort_unless(stream_copy_to_stream($stream, $output) === $attachment->size, 503, '图纸读取不完整。');
                } finally {
                    fclose($stream);
                    fclose($output);
                }
                $zip->addFile($file, $name);
                $zip->setCompressionName($name, ZipArchive::CM_STORE);
            }
            abort_unless($zip->close(), 503, '图纸打包失败。');
        } catch (\Throwable $exception) {
            @unlink($path);
            throw $exception;
        } finally {
            foreach ($temporary as $file) {
                @unlink($file);
            }
        }

        return response()->streamDownload(function () use ($path): void {
            try {
                readfile($path);
            } finally {
                @unlink($path);
            }
        }, $this->safeName($project->name).'_图纸.zip', ['Content-Type' => 'application/zip', 'Cache-Control' => 'private, no-store']);
    }

    private function download(StoredAttachment $attachment): StreamedResponse
    {
        return response()->streamDownload(function () use ($attachment): void {
            $stream = Storage::disk($attachment->disk)->readStream($attachment->object_key);
            abort_unless(is_resource($stream), 404);
            try {
                fpassthru($stream);
            } finally {
                fclose($stream);
            }
        }, $this->safeName($attachment->original_name), ['Content-Type' => $attachment->mime_type ?: 'application/octet-stream', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function safeName(string $name): string
    {
        return preg_replace('/[\\\\\/:*?"<>|\x00-\x1f]/u', '_', $name) ?: '图纸';
    }

    private function meta(SteelGirderProject $project): array
    {
        $reviews = $project->material?->reviews;
        $required = ['design', 'net', 'studs', 'bolts', 'inside', 'outside'];
        $checked = collect($reviews ?? [])->where('checked', true)->pluck('id')->all();

        return ['id' => $project->id, 'name' => $project->name, 'revision' => $project->revision,
            'createdAt' => $project->created_at->getTimestampMs(), 'updatedAt' => $project->updated_at->getTimestampMs(),
            'hasUnreviewed' => $reviews !== null && (count(array_diff($required, $checked)) > 0 || collect($reviews)->contains(fn ($row) => ! $row['checked'])),
        ];
    }
}
