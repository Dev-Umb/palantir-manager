<?php

namespace App\Ai\Tools;

use Stringable;

class ExportFeishuSpreadsheetTool extends AbstractFeishuExportTool
{
    public function name(): string
    {
        return 'export_feishu_spreadsheet';
    }

    public function description(): Stringable|string
    {
        return 'Create one new Feishu spreadsheet from a Palantir query. Use only when the user explicitly asks to export or create a spreadsheet.';
    }

    protected function format(): string
    {
        return 'sheet';
    }
}
