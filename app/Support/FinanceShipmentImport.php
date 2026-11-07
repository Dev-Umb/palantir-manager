<?php

namespace App\Support;

use App\Actions\SaveFinanceShipments;
use App\Models\ObjectRecord;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

class FinanceShipmentImport
{
    public function __construct(private SaveFinanceShipments $writer, private FinanceShipmentAccess $access) {}

    public function preview(ObjectRecord $project, string $path, string $name, User $user): array
    {
        $this->access->authorize($user, $project);
        $hash = hash_file('sha256', $path);
        $existing = DB::table('finance_shipment_imports')->where('project_id', $project->id)->where('hash', $hash)->first();
        if ($existing) {
            return $this->present($existing);
        }
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (! in_array($extension, ['xls', 'xlsx', 'csv'], true) || filesize($path) > 20 * 1024 * 1024) {
            throw ValidationException::withMessages(['file' => '请选择不超过20MiB的xls、xlsx或UTF-8 CSV。']);
        }
        $process = new Process([config('finance_shipments.python'), base_path('scripts/read-shipment-workbook.py'), $path, $extension]);
        $process->setTimeout(30);
        $process->run();
        if (! $process->isSuccessful()) {
            throw ValidationException::withMessages(['file' => '无法读取表格，请检查文件格式；服务器需要已安装的 Python xlrd/openpyxl 读取器。']);
        }
        $sheets = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $preview = $this->deduplicate($this->classify($sheets, $name), $this->writer->current($project)['orders']);
        $id = (string) Str::uuid();
        $stored = 'finance-shipments/'.$project->id.'/'.$hash.'.'.$extension;
        $stream = fopen($path, 'rb');
        try {
            if (! Storage::disk('local')->put($stored, $stream)) {
                throw ValidationException::withMessages(['file' => '原文件保存失败，尚未导入任何数据。']);
            }
        } finally {
            fclose($stream);
        }
        DB::table('finance_shipment_imports')->insertOrIgnore(['id' => $id, 'project_id' => $project->id, 'user_id' => $user->id,
            'hash' => $hash, 'name' => mb_substr($name, 0, 255), 'path' => $stored, 'preview' => json_encode($preview, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'created_at' => now(), 'updated_at' => now()]);

        return $this->present(DB::table('finance_shipment_imports')->where('project_id', $project->id)->where('hash', $hash)->sole());
    }

    public function present(object $import): array
    {
        return ['id' => $import->id, 'name' => $import->name, 'imported_at' => $import->imported_at, ...json_decode($import->preview, true, flags: JSON_THROW_ON_ERROR)];
    }

    public function commit(ObjectRecord $project, string $id, User $user): array
    {
        $this->access->authorize($user, $project);

        return DB::transaction(function () use ($project, $id, $user): array {
            $project = ObjectRecord::query()->lockForUpdate()->findOrFail($project->id);
            $import = DB::table('finance_shipment_imports')->where('project_id', $project->id)->where('id', $id)->lockForUpdate()->first();
            abort_unless($import, 404);
            if ($import->imported_at) {
                return [...$this->writer->current($project), 'import' => $this->present($import)];
            }
            $preview = json_decode($import->preview, true, flags: JSON_THROW_ON_ERROR);
            $current = $this->writer->current($project);
            $orders = $current['orders'];
            $preview = $this->deduplicate($preview, $orders);
            foreach ($preview['records'] as $record) {
                if ($record['status'] !== 'ready') {
                    continue;
                }
                $orders[] = ['id' => (string) Str::uuid(), 'date' => $record['date'], 'vehicle' => $record['vehicle'], 'source' => $record['source'], 'rows' => [[
                    'id' => (string) Str::uuid(), 'name' => $record['name'], 'unit' => '吨', 'quantity' => $record['quantity'], 'price' => $record['price'], 'note' => $record['note'],
                ]]];
            }
            $saved = $this->writer->save($project, ['revision' => $current['revision'], 'orders' => $orders], $user, trustedImport: true);
            DB::table('finance_shipment_imports')->where('id', $id)->update(['preview' => json_encode($preview, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'imported_at' => now(), 'updated_at' => now()]);

            return [...$saved, 'import' => $this->present(DB::table('finance_shipment_imports')->where('id', $id)->sole())];
        });
    }

    public function classify(array $sheets, string $file): array
    {
        $records = [];
        $aliases = ['date' => ['发货时间', '发货日期'], 'vehicle' => ['车号', '车牌号'], 'name' => ['货物名称', '发货名称', '物资名称', '产品名称'], 'quantity' => ['数量', '吨数', '实收数量', '重量', '重量（吨）', '数量（吨）'], 'unit' => ['单位', '计量单位'], 'price' => ['单价', '结算单价'], 'amount' => ['金额', '结算金额'], 'note' => ['备注']];
        foreach ($sheets as $sheet) {
            $map = [];
            $tonnes = false;
            foreach ($sheet['rows'] as $index => $cells) {
                $cells = array_map(fn ($v): string => trim((string) ($v ?? '')), $cells);
                if (! array_filter($cells, fn (string $v): bool => $v !== '')) {
                    continue;
                }
                $candidate = [];
                foreach ($aliases as $key => $names) {
                    foreach ($cells as $column => $label) {
                        if (in_array(preg_replace('/\s+/u', '', $label), $names, true)) {
                            $candidate[$key] = $column;
                        }
                    }
                }
                if (isset($candidate['date'], $candidate['name'], $candidate['quantity'])) {
                    $map = $candidate;
                    $tonnes = in_array($cells[$map['quantity']], ['吨数', '重量（吨）', '数量（吨）'], true);

                    continue;
                }
                if (! $map) {
                    continue;
                }
                $get = fn (string $key): string => isset($map[$key]) ? ($cells[$map[$key]] ?? '') : '';
                $source = mb_substr($file.' / '.$sheet['name'].' / 第'.($index + 1).'行', 0, 500);
                $businessText = implode(' ', array_slice($cells, 0, max($map['name'], $map['quantity'], $map['date'], $map['vehicle'] ?? 0) + 1));
                $status = 'ready';
                $reason = '';
                if (preg_match('/开票|发票|收款|回款|付款|小计|合计|总计|累计|结算|扣运费|欠款|运费/', $businessText)) {
                    $status = 'excluded';
                    $reason = '非实际发货：开票、收款、汇总或费用行';
                }
                $date = $this->date($get('date'));
                $unit = $get('unit') ?: ($tonnes ? '吨' : '');
                $quantity = is_numeric($get('quantity')) ? round((float) $get('quantity'), 3) : null;
                $price = is_numeric($get('price')) ? round((float) $get('price'), 2) : null;
                $problems = [];
                if (! $date) {
                    $problems[] = '发货日期缺失或无法识别';
                }
                if (! $get('name')) {
                    $problems[] = '货物名称缺失';
                }
                if ($unit !== '吨') {
                    $problems[] = $unit ? '非吨明细待定' : '单位未标明';
                }
                if ($quantity === null || $quantity <= 0 || $quantity > 99999.999) {
                    $problems[] = '发货数量无效或需核对';
                }
                if ($get('price') !== '' && ($price === null || $price < 0 || $price > 99999.99)) {
                    $problems[] = '单价需核对';
                }
                if (preg_match('/退货|退回|冲减|作废/', $businessText)) {
                    $problems[] = '退货或冲减口径待确认';
                }
                if ($status !== 'excluded' && $problems) {
                    $status = 'pending';
                    $reason = implode('；', $problems);
                }
                $records[] = ['status' => $status, 'reason' => $reason, 'source' => $source, 'date' => $date, 'vehicle' => mb_substr($get('vehicle'), 0, 200),
                    'name' => mb_substr($get('name'), 0, 300), 'unit' => $unit, 'quantity' => $quantity, 'price' => $price,
                    'note' => mb_substr($get('note'), 0, 1000), 'original_amount' => $get('amount'), 'raw' => $cells];
            }
        }
        $counts = ['ready' => 0, 'pending' => 0, 'excluded' => 0];
        foreach ($records as $record) {
            $counts[$record['status']]++;
        }

        return ['records' => $records, 'counts' => $counts];
    }

    private function deduplicate(array $preview, array $orders): array
    {
        $key = fn (array $row): string => json_encode([$row['date'] ?? null, trim($row['vehicle'] ?? ''), trim($row['name'] ?? ''), (float) ($row['quantity'] ?? 0), ($row['price'] ?? null) === null ? null : (float) $row['price']]);
        $seen = [];
        foreach ($orders as $order) {
            foreach ($order['rows'] as $row) {
                if (($row['unit'] ?? null) === '吨') {
                    $seen[$key([...$row, 'date' => $order['date'] ?? null, 'vehicle' => $order['vehicle'] ?? null])] = true;
                }
            }
        }
        foreach ($preview['records'] as &$record) {
            if ($record['status'] !== 'ready') {
                continue;
            }
            $identity = $key($record);
            if (isset($seen[$identity])) {
                $record['status'] = 'pending';
                $record['reason'] = '与已有明细或本文件其他行疑似重复，待确认';
            }
            $seen[$identity] = true;
        }
        unset($record);
        $preview['counts'] = array_replace(['ready' => 0, 'pending' => 0, 'excluded' => 0], array_count_values(array_column($preview['records'], 'status')));

        return $preview;
    }

    private function date(string $value): ?string
    {
        if (! preg_match('/^(\d{4})[.\/-年](\d{1,2})[.\/-月](\d{1,2})日?$/u', $value, $match)) {
            return null;
        }

        return checkdate((int) $match[2], (int) $match[3], (int) $match[1]) ? sprintf('%04d-%02d-%02d', $match[1], $match[2], $match[3]) : null;
    }
}
