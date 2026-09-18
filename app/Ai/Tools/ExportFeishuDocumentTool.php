<?php

namespace App\Ai\Tools;

use Stringable;

class ExportFeishuDocumentTool extends AbstractFeishuExportTool
{
    public function name(): string
    {
        return 'export_feishu_document';
    }

    public function description(): Stringable|string
    {
        return 'Create one new Feishu cloud document from a Palantir query. Use only when the user explicitly asks to export or create a document.';
    }

    protected function format(): string
    {
        return 'docx';
    }
}
