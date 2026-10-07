<?php

namespace App\Http\Controllers;

use App\Ai\Agents\QuotationAssistant;
use App\Http\Requests\QuotationAdoptRequest;
use App\Http\Requests\QuotationCalculateRequest;
use App\Http\Requests\QuotationChatRequest;
use App\Http\Requests\QuotationPriceRequest;
use App\Models\BusinessObject;
use App\Models\ObjectRecord;
use App\Models\QuotationArchive;
use App\Support\ProjectVisibility;
use App\Support\QuotationAgentRunner;
use App\Support\QuotationCalculator;
use App\Support\QuotationMarketSearch;
use App\Support\QuotationTokens;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class QuotationController extends Controller
{
    public function index(Request $request): Response|\Illuminate\Http\Response
    {
        if ($request->header('X-Inertia')) {
            return Inertia::location(route('quotations.index'));
        }
        Inertia::setRootView('quotation');
        $object = BusinessObject::where('key', 'project')->first();
        $projects = $object && $request->user()->canDo('object.project.view')
            ? app(ProjectVisibility::class)->scope($object->records(), $request->user())->orderBy('title')->get(['id', 'code', 'title'])->map->only(['id', 'code', 'title'])->all()
            : [];

        return Inertia::render('Quotations/Index', [
            'projects' => $projects,
            'owner' => $request->user()->only(['id', 'name']),
            'archives' => $this->archiveRows($request),
            'today' => now()->timezone('Asia/Shanghai')->toDateString(),
            'urls' => collect(['chat', 'market', 'history', 'price', 'calculate', 'adopt', 'archives', 'export'])->mapWithKeys(fn (string $name) => [$name => route('quotations.'.$name)])->all(),
        ]);
    }

    public function chat(QuotationChatRequest $request, QuotationAgentRunner $runner): JsonResponse
    {
        try {
            $data = $request->validated();
            $params = array_intersect_key($data['params'] ?? [], array_flip(QuotationAssistant::FIELDS));
            $result = $runner->run(new QuotationAssistant, ['current_parameters' => $params, 'history' => $data['context'] ?? [], 'request' => $data['message']], $request->file('attachments', []));
            if (! is_string($result['answer'] ?? null) || blank($result['answer']) || ! is_array($result['questions'] ?? null) || ! is_array($result['proposals'] ?? null)) {
                throw new \RuntimeException('AI 返回缺少必要的报价信息。');
            }
            $result['proposals'] = collect($result['proposals'] ?? [])->filter(fn ($proposal) => is_array($proposal) && in_array($proposal['field'] ?? '', QuotationAssistant::FIELDS, true) && is_string($proposal['value'] ?? null) && mb_strlen($proposal['value']) <= 1500)->values()->all();

            return response()->json($result);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['message' => 'AI 暂时无法完成识别，请重试；你仍可手动填写参数，已有内容已保留。'], 502);
        }
    }

    public function history(Request $request, QuotationTokens $tokens): JsonResponse
    {
        $params = $this->priceParameters($request);
        $records = QuotationArchive::where('user_id', $request->user()->id)->latest()->limit(200)->get();
        $matches = $records->filter(function (QuotationArchive $archive) use ($params): bool {
            foreach (['product', 'spec', 'unit', 'tax_rate', 'tax_basis', 'shipping'] as $key) {
                if ((string) ($archive->snapshot['params'][$key] ?? '') !== (string) $params[$key]) {
                    return false;
                }
            }

            return true;
        })->take(5)->map(function (QuotationArchive $archive) use ($request, $tokens, $params): array {
            $fee = [...$archive->snapshot['fee'], 'source' => 'history', 'source_name' => '我的历史报价：'.$archive->title, 'archive_id' => $archive->id, 'date' => $archive->created_at->toDateString()];

            return ['title' => $archive->title, 'date' => $archive->created_at->toDateString(), 'price' => $fee, 'suggestion_token' => $tokens->issue($request->user(), 'suggestion', ['kind' => 'fee', 'context' => $tokens->context($params), 'price' => $fee])];
        })->values()->all();

        return response()->json(['suggestions' => $matches, 'message' => $matches ? '仅推荐你本人相同规格、单位和税运口径的历史加工费；采纳前请确认适用性。' : '本人最近200份档案中暂无完全匹配的历史报价，请提供加工费。']);
    }

    public function market(Request $request, QuotationMarketSearch $search, QuotationTokens $tokens): JsonResponse
    {
        $params = $this->priceParameters($request);
        if ($params['unit'] !== '吨') {
            return response()->json(['candidates' => [], 'sources' => [], 'limitations' => ['网价为元/吨；当前按套或吨日计价，无法直接混用。请提供同计价单位材料基价。']]);
        }
        try {
            $result = $search->search(array_intersect_key($params, array_flip(['price_date', 'market', 'material', 'steel_spec', 'tax_basis'])));
            $result['candidates'] = array_map(fn (array $price) => [...$price, 'suggestion_token' => $tokens->issue($request->user(), 'suggestion', ['kind' => 'steel', 'context' => $tokens->context($params), 'price' => $price])], $result['candidates']);

            return response()->json($result);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['message' => '联网取价失败或来源不可访问，请重试或输入你确认的钢材价；未采用任何替代价格。'], 502);
        }
    }

    public function price(QuotationPriceRequest $request, QuotationTokens $tokens): JsonResponse
    {
        $data = $request->validated();
        $params = $data['params'];
        if ($data['source'] === 'user') {
            $price = ['amount' => (string) $data['amount'], 'source' => 'user', 'source_name' => $data['source_note'], 'date' => $params['price_date'], 'unit' => '元/'.$params['unit'], 'tax_basis' => $params['tax_basis'], 'market' => $params['market'], 'material' => $params['material'], 'spec' => $params['steel_spec']];
        } else {
            $suggestion = $tokens->read($request->user(), 'suggestion', $data['suggestion_token']);
            if ($suggestion['kind'] !== $data['kind'] || $suggestion['context'] !== $tokens->context($params) || $suggestion['price']['source'] !== $data['source']) {
                throw ValidationException::withMessages(['confirmation' => '推荐与当前规格或价格口径不一致，请重新获取。']);
            }
            $price = $suggestion['price'];
        }
        $price['confirmed_at'] = now()->toIso8601String();

        return response()->json(['price' => $price, 'token' => $tokens->issue($request->user(), 'price', ['kind' => $data['kind'], 'context' => $tokens->context($params), 'price' => $price])]);
    }

    public function calculate(QuotationCalculateRequest $request, QuotationTokens $tokens, QuotationCalculator $calculator): JsonResponse
    {
        $data = $request->validated();
        $params = $data['params'];
        if (! empty($params['project_id'])) {
            $project = ObjectRecord::findOrFail($params['project_id']);
            abort_unless($request->user()->canDo('object.project.view') && app(ProjectVisibility::class)->allowsProject($request->user(), $project), 403);
            $params['project_name'] = $project->title;
            $params['project_code'] = $project->code;
        }
        $prices = [];
        foreach (['fee', 'steel'] as $kind) {
            $claim = $tokens->read($request->user(), 'price', $data[$kind.'_token']);
            if ($claim['kind'] !== $kind || $claim['context'] !== $tokens->context($params)) {
                throw ValidationException::withMessages(['confirmation' => '参数已改变，请分别重新确认加工费和材料基价。']);
            }
            $prices[$kind] = $claim['price'];
        }
        $snapshot = ['version' => 1, 'reference_only' => true, 'owner' => $request->user()->only(['id', 'name']), 'generated_at' => now()->toIso8601String(), 'params' => $params, ...$prices, 'calculation' => $calculator->calculate($params, $prices['fee'], $prices['steel'])];

        $snapshot['export_csv'] = base64_encode($this->csvContent($snapshot));

        return response()->json(['snapshot' => $snapshot, 'preview_token' => $tokens->issue($request->user(), 'preview', $snapshot)]);
    }

    public function adopt(QuotationAdoptRequest $request, QuotationTokens $tokens): JsonResponse
    {
        $data = $request->validated();
        $snapshot = $tokens->read($request->user(), 'preview', $data['preview_token']);
        $hash = hash('sha256', json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $archive = QuotationArchive::firstOrCreate(['user_id' => $request->user()->id, 'adoption_key' => $data['adoption_key']], ['title' => $data['title'], 'snapshot' => $snapshot, 'snapshot_hash' => $hash]);
        if ($archive->snapshot_hash !== $hash || $archive->title !== $data['title']) {
            throw ValidationException::withMessages(['adoption_key' => '本次采纳编号已用于另一份报价，请新建后重新采纳。']);
        }

        return response()->json(['archive' => $this->archiveRow($archive)], $archive->wasRecentlyCreated ? 201 : 200);
    }

    public function archives(Request $request): JsonResponse
    {
        return response()->json(['archives' => $this->archiveRows($request)]);
    }

    public function show(Request $request, QuotationArchive $archive): JsonResponse
    {
        abort_unless($archive->user_id === $request->user()->id, 404);

        return response()->json(['archive' => $this->archiveRow($archive), 'snapshot' => $archive->snapshot]);
    }

    public function download(Request $request, QuotationArchive $archive): StreamedResponse
    {
        abort_unless($archive->user_id === $request->user()->id, 404);

        return $this->csv($archive->snapshot);
    }

    public function export(Request $request, QuotationTokens $tokens): StreamedResponse
    {
        $data = $request->validate(['preview_token' => ['required', 'string', 'max:100000']]);

        return $this->csv($tokens->read($request->user(), 'preview', $data['preview_token']));
    }

    private function priceParameters(Request $request): array
    {
        $rules = QuotationCalculateRequest::parameterRules();
        $rules['params.quantity'] = ['nullable', 'numeric', 'decimal:0,3', 'gt:0', 'max:100000'];
        $rules['params.days'] = ['nullable', 'integer', 'min:1', 'max:3650'];

        return Validator::make($request->all(), $rules)->validate()['params'];
    }

    private function archiveRows(Request $request): array
    {
        $data = $request->validate(['search' => ['nullable', 'string', 'max:180'], 'page' => ['nullable', 'integer', 'min:1']]);
        $query = QuotationArchive::where('user_id', $request->user()->id);
        if (filled($data['search'] ?? null)) {
            $query->where('title', 'like', '%'.addcslashes($data['search'], '%_\\').'%');
        }
        $page = $query->latest()->paginate(20);

        return ['data' => $page->getCollection()->map(fn ($archive) => $this->archiveRow($archive))->all(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()];
    }

    private function archiveRow(QuotationArchive $archive): array
    {
        return ['id' => $archive->id, 'title' => $archive->title, 'project_name' => $archive->snapshot['params']['project_name'] ?? '', 'owner' => $archive->snapshot['owner']['name'] ?? '', 'total_cents' => $archive->snapshot['calculation']['total_cents'] ?? null, 'unit_price_cents' => $archive->snapshot['calculation']['unit_price_cents'] ?? 0, 'created_at' => $archive->created_at->toIso8601String(), 'show_url' => route('quotations.show', $archive), 'download_url' => route('quotations.download', $archive)];
    }

    private function csv(array $snapshot): StreamedResponse
    {
        return response()->streamDownload(function () use ($snapshot): void {
            echo isset($snapshot['export_csv']) ? base64_decode($snapshot['export_csv'], true) : $this->csvContent($snapshot);
        }, 'reference-quotation.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    private function csvContent(array $snapshot): string
    {
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, "\xEF\xBB\xBF");
        $rows = [['文件性质', '参考报价单'], ['业务员', $snapshot['owner']['name']], ['生成时间', $snapshot['generated_at']]];
        $labels = ['project_name' => '项目', 'project_code' => '项目编号', 'customer' => '客户', 'product' => '产品', 'spec' => '规格', 'unit' => '计价单位', 'quantity' => '数量', 'days' => '租期天数', 'tax_rate' => '税率%', 'tax_basis' => '基价税口径', 'shipping' => '运费口径', 'extra_fee' => '额外费用（同基价税口径）', 'destination' => '交付地点', 'terms' => '报价说明', 'price_date' => '钢材价日期', 'market' => '市场', 'material' => '材质', 'steel_spec' => '钢材规格'];
        foreach ($labels as $key => $label) {
            $rows[] = [$label, $snapshot['params'][$key] ?? '未提供'];
        }
        foreach (['fee' => '加工费', 'steel' => '材料基价'] as $key => $label) {
            foreach (['amount' => '单价', 'source_name' => '来源', 'url' => '链接', 'quote' => '原文', 'date' => '来源日期', 'confirmed_at' => '确认时间'] as $field => $name) {
                $rows[] = [$label.$name, $snapshot[$key][$field] ?? ''];
            }
        }
        foreach (['unit_price_cents' => '含税单价（元）', 'total_cents' => '含税总额（元）', 'net_cents' => '未税总额（元）', 'tax_cents' => '税额（元）'] as $key => $label) {
            $value = $snapshot['calculation'][$key];
            $rows[] = [$label, $value === null ? '未知数量，仅出单价' : number_format($value / 100, 2, '.', '')];
        }
        $rows[] = ['计算公式', $snapshot['calculation']['formula']];
        foreach ($rows as $row) {
            fputcsv($stream, array_map(fn ($cell) => preg_match('/^[\s]*[=+@\-]/u', (string) $cell) ? "'".$cell : $cell, $row), ',', '"', '');
        }
        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);

        return $content;
    }
}
