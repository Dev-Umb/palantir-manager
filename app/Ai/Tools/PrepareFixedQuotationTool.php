<?php

namespace App\Ai\Tools;

use App\Models\User;
use App\Support\QuotationAccess;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class PrepareFixedQuotationTool implements Tool
{
    public function __construct(private User $user) {}

    public function name(): string
    {
        return 'prepare_fixed_quotation';
    }

    public function description(): Stringable|string
    {
        return 'Prepare a fixed original Word quotation confirmation card inside chat. Accept user-supplied comprehensive prices; never invent breakdowns. Missing title, units or phone can be filled on the card. Does not generate, seal or write business records until the user confirms.';
    }

    public function handle(Request $request): Stringable|string
    {
        if (! QuotationAccess::allows($this->user)) {
            return json_encode(['ok' => false, 'message' => '当前账号没有报价生成权限。'], JSON_UNESCAPED_UNICODE);
        }
        $items = collect($request['items'] ?? [])->filter(fn ($item) => is_array($item))->map(fn (array $item) => Arr::only($item, ['name', 'unit', 'price', 'material_price', 'processing_price']))->values()->all();

        $taxRate = trim((string) ($request['tax_rate'] ?? '13'));
        $taxRate = in_array($taxRate, ['13', '13%', '13％'], true) ? '13' : $taxRate;
        $shipping = trim((string) ($request['shipping'] ?? '含运费'));
        $shipping = in_array($shipping, ['含运费', '含运', '含运输费', '含运送到价', '含运到价', '含运费送到价'], true) ? '含运费' : $shipping;

        return json_encode(['ok' => true, 'message' => '报价确认卡已展示，等待用户补充核对并点击生成。', 'artifact' => [
            'id' => (string) Str::uuid7(), 'type' => 'quotation_docx', 'title' => '固定模板报价单', 'revision' => 1,
            'data' => [
                'title' => (string) ($request['title'] ?? ''), 'date' => (string) (($request['date'] ?? null) ?: now('Asia/Taipei')->toDateString()),
                'contact' => (string) (($request['contact'] ?? null) ?: $this->user->name), 'phone' => (string) ($request['phone'] ?? ''),
                'tax_rate' => $taxRate, 'shipping' => $shipping,
                'items' => $items, 'generated' => false,
            ],
        ]], JSON_UNESCAPED_UNICODE);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->nullable()->description('User-supplied project quotation title. Null if unknown; do not invent.'),
            'date' => $schema->string()->nullable()->description('YYYY-MM-DD, null uses current business date.'),
            'contact' => $schema->string()->nullable(), 'phone' => $schema->string()->nullable(),
            'tax_rate' => $schema->string()->nullable()->description('Explicit user tax rate; fixed template uses 13%. Preserve conflicts for confirmation.'),
            'shipping' => $schema->string()->nullable()->description('含运费 or user-supplied conflicting terms. Never discard a conflict.'),
            'items' => $schema->array()->min(1)->max(3)->description('Fixed single-page template supports at most three rows; for more, ask the user to split quotations. Never silently discard supplied products.')->items($schema->object(fn (JsonSchema $schema) => [
                'name' => $schema->string()->required(), 'unit' => $schema->string()->nullable()->description('Explicit unit only, null if unknown.'),
                'price' => $schema->string()->nullable()->description('Comprehensive price from user, zero allowed.'),
                'material_price' => $schema->string()->nullable()->description('Only if explicitly supplied; otherwise null.'),
                'processing_price' => $schema->string()->nullable()->description('Only if explicitly supplied; otherwise null.'),
            ]))->required(),
        ];
    }
}
