<?php

namespace App\Console\Commands;

use App\Actions\SaveProductionWorkspace;
use App\Models\User;
use App\Support\ProductionWorkspaceData;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InitializeProductionDemand extends Command
{
    protected $signature = 'production-workbench:initialize-demands {file : 已核对的需求计划JSON数组} {--user= : 有原材管理和双域查看权限的操作者ID}';

    protected $description = '仅在需求计划为空时初始化参考计划，不改动已有生产和进场记录';

    public function handle(SaveProductionWorkspace $service): int
    {
        $user = User::find($this->option('user'));
        if (! $user || ! $user->canDo('material-arrivals.manage') || ! $user->canDo('material-arrivals.view') || ! $user->canDo('template-accessories.view')) {
            $this->error('请指定有权限的操作者。');

            return self::FAILURE;
        }
        $data = $service->current();
        $path = $this->argument('file');
        if (! is_file($path) || filesize($path) > 10485760) {
            $this->error('需求文件不存在或超过10MB。');

            return self::FAILURE;
        }
        try {
            $plans = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($plans) || ! array_is_list($plans) || ! $plans) {
                throw new \InvalidArgumentException('需要非空需求计划数组。');
            }
            $validation = $data;
            $validation['materialDemandPlans'] = $plans;
            app(ProductionWorkspaceData::class)->validate($validation);
            DB::transaction(function () use ($service, $user, $plans, $data): void {
                $reference = DB::table('production_workspaces')->where('id', 1);
                if ($data['materialDemandPlans']) {
                    $reference->whereNull('reference_plans')->update(['reference_plans' => json_encode($plans, JSON_THROW_ON_ERROR)]);

                    return;
                }
                $data['materialDemandPlans'] = $plans;
                $service->save(['operation' => 'save', 'expected_revision' => $data['revision'], 'request_id' => (string) Str::uuid(), 'dataset_json' => json_encode($data, JSON_THROW_ON_ERROR)], $user);
                $reference->whereNull('reference_plans')->update(['reference_plans' => json_encode($plans, JSON_THROW_ON_ERROR)]);
            });
            $this->info('需求参考已核对；已有计划保留。参考批次：'.count($plans).'批；原有数据保持。');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
