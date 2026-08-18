<?php

namespace App\Console\Commands;

use App\Actions\UpdateProjectCollectionProgress;
use App\Models\BusinessObject;
use App\Models\ObjectRecord;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('xyc:recalculate-project-collection-progress {--execute : 写入需要更新的项目；省略时仅预览}')]
#[Description('按已回款金额除以已发生金额的公式预览或重算项目回款进度')]
class RecalculateProjectCollectionProgressCommand extends Command
{
    public function handle(UpdateProjectCollectionProgress $collectionProgress): int
    {
        $projectObject = BusinessObject::query()->where('key', 'project')->first();
        if (! $projectObject) {
            $this->warn('未找到项目主档，无需处理。');

            return self::SUCCESS;
        }

        $execute = (bool) $this->option('execute');
        $summary = [
            'scanned' => 0,
            'changed' => 0,
            'unchanged' => 0,
            'unavailable' => 0,
            'failed' => 0,
        ];

        $projectObject->records()
            ->lazyById(100)
            ->each(function (ObjectRecord $project) use ($execute, $collectionProgress, &$summary): void {
                $summary['scanned']++;

                try {
                    $expectedProgress = $collectionProgress->expected($project);
                    if ($expectedProgress === null) {
                        $summary['unavailable']++;
                    }

                    if (! $collectionProgress->requiresUpdate($project, $expectedProgress)) {
                        $summary['unchanged']++;

                        return;
                    }

                    if (! $execute) {
                        $summary['changed']++;

                        return;
                    }

                    $updated = $collectionProgress->handle($project->id);

                    $summary[$updated ? 'changed' : 'unchanged']++;
                } catch (Throwable $exception) {
                    report($exception);
                    $summary['failed']++;
                    $this->error("项目 {$project->code} 重算失败：{$exception->getMessage()}");
                }
            });

        $mode = $execute ? '执行' : '预览';
        $this->info(sprintf(
            '%s完成：扫描 %d，%s %d，无需变化 %d，无法计算 %d，失败 %d。',
            $mode,
            $summary['scanned'],
            $execute ? '已更新' : '预计更新',
            $summary['changed'],
            $summary['unchanged'],
            $summary['unavailable'],
            $summary['failed'],
        ));

        return $summary['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
