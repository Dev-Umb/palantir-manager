<?php

namespace App\Actions;

use App\Models\HubCompany;
use App\Models\HubImport;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ImportHubCompany
{
    public function preview(int $userId, UploadedFile $file): HubImport
    {
        $handle = fopen($file->getRealPath(), 'r');
        $headers = fgetcsv($handle, 0, ',', '"', '') ?: [];
        $headers = array_map(fn ($h) => trim($h, "\xEF\xBB\xBF \t\n\r"), $headers);
        $rows = [];
        $errors = [];
        $allowed = ['kind', 'name', 'product', 'qualifications', 'valid_until', 'capacity', 'regions', 'buyer', 'group_name', 'project_code', 'lot', 'outcome', 'amount', 'unit', 'tax', 'freight', 'reference', 'notes'];
        if (array_diff($headers, $allowed) || count(array_unique($headers)) !== count($headers) || ! in_array('name', $headers) || ! in_array('kind', $headers)) {
            $errors[] = '表头须包含 kind、name，且仅使用模板中的列。';
        }
        while (($values = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            if (count($rows) >= 200) {
                $errors[] = '每次最多导入 200 条，请分批导入。';
                break;
            }
            if (count($values) !== count($headers)) {
                $errors[] = '第 '.(count($rows) + 2).' 行列数不匹配。';

                continue;
            }
            $data = array_combine($headers, $values);
            $row = ['kind' => $data['kind'] ?? '', 'name' => $data['name'] ?? '', 'data' => array_diff_key($data, ['kind' => 1, 'name' => 1])];
            $validator = Validator::make($row, ['kind' => 'required|in:capability,history', 'name' => 'required|string|max:200', 'data.*' => 'nullable|string|max:3000', 'data.valid_until' => 'nullable|date', 'data.outcome' => 'nullable|in:submitted,won,lost,unknown']);
            if ($validator->fails()) {
                $errors[] = '第 '.(count($rows) + 2).' 行：'.implode(' ', $validator->errors()->all());
            }
            $rows[] = $row;
        }
        fclose($handle);
        if (! $rows) {
            $errors[] = '没有可导入的记录。';
        }

        return HubImport::create(['user_id' => $userId, 'rows' => $rows, 'errors' => $errors]);
    }

    public function confirm(HubImport $import, int $userId): void
    {
        DB::transaction(function () use ($import, $userId): void {
            $import = HubImport::lockForUpdate()->findOrFail($import->id);
            if ($import->errors) {
                throw ValidationException::withMessages(['file' => '预览存在错误，请修正后重新上传。']);
            }
            if ($import->confirmed_at) {
                return;
            }
            foreach ($import->rows as $row) {
                HubCompany::create([...$row, 'status' => 'confirmed', 'confirmed_by' => $userId, 'confirmed_at' => now()]);
            }
            $import->update(['confirmed_at' => now()]);
        });
    }
}
