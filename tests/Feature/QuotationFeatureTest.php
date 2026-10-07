<?php

namespace Tests\Feature;

use App\Ai\Agents\QuotationAssistant;
use App\Models\QuotationArchive;
use App\Models\Role;
use App\Models\User;
use App\Support\QuotationMarketSearch;
use App\Support\QuotationTokens;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Ai\Prompts\AgentPrompt;
use Tests\TestCase;

class QuotationFeatureTest extends TestCase
{
    use RefreshDatabase;

    private function salesperson(string $role = 'business'): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::firstOrCreate(['name' => $role], ['label' => $role]));

        return $user;
    }

    private function params(array $overrides = []): array
    {
        return [...[
            'project_id' => null, 'project_name' => '测试项目', 'customer' => '测试客户', 'product' => '现浇梁模板',
            'spec' => 'Q235B，板厚6mm', 'mode' => 'total', 'unit' => '吨', 'quantity' => '74', 'days' => null,
            'tax_rate' => '13', 'tax_basis' => '含税', 'shipping' => '含运费', 'destination' => '项目现场',
            'terms' => '仅供参考，按实结算', 'extra_fee' => '0', 'price_date' => '2026-10-05',
            'market' => '太原', 'material' => 'Q235B', 'steel_spec' => '6mm',
        ], ...$overrides];
    }

    private function preview(User $user, array $params, string $fee = '2316', string $steel = '3485'): array
    {
        $prices = [];
        foreach (['fee' => $fee, 'steel' => $steel] as $kind => $amount) {
            $prices[$kind] = $this->actingAs($user)->postJson('/quotations/price', ['params' => $params, 'kind' => $kind, 'source' => 'user', 'amount' => $amount, 'source_note' => '用户指定', 'confirmed' => true])->assertOk()->json('token');
        }

        return $this->postJson('/quotations/calculate', ['params' => $params, 'fee_token' => $prices['fee'], 'steel_token' => $prices['steel'], 'params_confirmed' => true])->assertOk()->json();
    }

    public function test_independent_entry_preserves_main_navigation_and_denies_unrelated_roles(): void
    {
        $this->get('/quotations')->assertRedirect('/login');
        $user = $this->salesperson();
        $this->actingAs($user)->get('/quotations')->assertOk()->assertSee(Vite::asset('resources/css/app.css'), false)->assertInertia(fn (Assert $page) => $page->component('Quotations/Index')->where('owner.id', $user->id)->where('nav', fn ($nav) => collect($nav)->contains(fn ($item) => $item['key'] === 'quotations' && $item['visible']) && collect($nav)->contains('key', 'ai') && collect($nav)->contains('key', 'hub')));
        $this->get('/quotations', ['X-Inertia' => 'true'])->assertStatus(409)->assertHeader('X-Inertia-Location', route('quotations.index'));
        $this->actingAs($this->salesperson('finance'))->get('/quotations')->assertForbidden();
    }

    public function test_server_calculation_confirmations_and_immutable_idempotent_archive(): void
    {
        $user = $this->salesperson();
        $preview = $this->preview($user, $this->params());
        $this->assertSame(42927400, $preview['snapshot']['calculation']['total_cents']);
        $this->assertSame(580100, $preview['snapshot']['calculation']['unit_price_cents']);
        $body = ['preview_token' => $preview['preview_token'], 'title' => '采纳报价', 'adoption_key' => (string) Str::uuid(), 'user_id' => 999, 'total_cents' => 1];
        $id = $this->postJson('/quotations/adopt', $body)->assertCreated()->json('archive.id');
        $this->postJson('/quotations/adopt', $body)->assertOk();
        $this->assertDatabaseCount('quotation_archives', 1);
        $archive = QuotationArchive::findOrFail($id);
        $this->assertSame($user->id, $archive->user_id);
        $this->preview($user, $this->params(['quantity' => '80']));
        $this->getJson('/quotations/archives/'.$id)->assertOk()->assertJsonPath('snapshot.calculation.total_cents', 42927400);
        $this->get('/quotations/archives/'.$id.'/download')->assertOk()->assertDownload('reference-quotation.csv');
        $otherPreview = $this->preview($user, $this->params(['quantity' => '80']));
        $this->postJson('/quotations/adopt', [...$body, 'preview_token' => $otherPreview['preview_token']])->assertUnprocessable();
    }

    public function test_archives_preview_and_price_tokens_are_isolated_even_for_administrators(): void
    {
        $owner = $this->salesperson();
        $preview = $this->preview($owner, $this->params());
        $id = $this->postJson('/quotations/adopt', ['preview_token' => $preview['preview_token'], 'title' => '个人报价', 'adoption_key' => (string) Str::uuid()])->assertCreated()->json('archive.id');
        $other = $this->salesperson('admin');
        $this->actingAs($other)->getJson('/quotations/archives')->assertOk()->assertJsonPath('archives.total', 0);
        $this->getJson('/quotations/archives/'.$id)->assertNotFound();
        $this->get('/quotations/archives/'.$id.'/download')->assertNotFound();
        $this->postJson('/quotations/adopt', ['preview_token' => $preview['preview_token'], 'title' => '越权报价', 'adoption_key' => (string) Str::uuid()])->assertUnprocessable();
        $this->actingAs($owner)->getJson('/quotations/archives')->assertJsonPath('archives.total', 1);
    }

    public function test_price_context_change_expiry_and_unconfirmed_inputs_cannot_calculate(): void
    {
        $user = $this->salesperson();
        $params = $this->params();
        $tokens = app(QuotationTokens::class);
        $fee = $tokens->issue($user, 'price', ['kind' => 'fee', 'context' => $tokens->context($params), 'price' => ['amount' => '1']]);
        $steel = $tokens->issue($user, 'price', ['kind' => 'steel', 'context' => $tokens->context($params), 'price' => ['amount' => '1']]);
        $base = ['params' => $params, 'fee_token' => $fee, 'steel_token' => $steel, 'params_confirmed' => true];
        $this->actingAs($user)->postJson('/quotations/calculate', [...$base, 'params_confirmed' => false])->assertUnprocessable();
        $this->postJson('/quotations/calculate', [...$base, 'params' => $this->params(['price_date' => '2026-10-06'])])->assertUnprocessable();
        $this->postJson('/quotations/calculate', [...$base, 'params' => $this->params(['spec' => '新规格'])])->assertUnprocessable();
        $this->travel(3)->hours();
        $this->postJson('/quotations/calculate', $base)->assertUnprocessable();
    }

    public function test_unknown_quantity_zero_prices_tax_and_rental_units_have_distinct_semantics(): void
    {
        $user = $this->salesperson();
        $unit = $this->preview($user, $this->params(['mode' => 'unit', 'quantity' => null]), '0', '0');
        $this->assertNull($unit['snapshot']['calculation']['total_cents']);
        $this->assertSame(0, $unit['snapshot']['calculation']['unit_price_cents']);
        $zero = $this->preview($user, $this->params(['quantity' => '1', 'tax_rate' => '0']), '0', '0');
        $this->assertSame(0, $zero['snapshot']['calculation']['total_cents']);
        $rental = $this->preview($user, $this->params(['unit' => '吨日', 'quantity' => '2.5', 'days' => 10, 'tax_basis' => '未税']), '1.25', '0');
        $this->assertSame(3531, $rental['snapshot']['calculation']['total_cents']);
        $this->assertSame(3125, $rental['snapshot']['calculation']['net_cents']);
        $this->assertSame(406, $rental['snapshot']['calculation']['tax_cents']);
    }

    public function test_history_only_recommends_own_matching_fee_and_requires_confirmation(): void
    {
        $user = $this->salesperson();
        $preview = $this->preview($user, $this->params());
        $this->postJson('/quotations/adopt', ['preview_token' => $preview['preview_token'], 'title' => '历史模板', 'adoption_key' => (string) Str::uuid()])->assertCreated();
        $result = $this->postJson('/quotations/history', ['params' => $this->params()])->assertOk()->json('suggestions.0');
        $this->postJson('/quotations/price', ['params' => $this->params(), 'kind' => 'fee', 'source' => 'history', 'suggestion_token' => $result['suggestion_token'], 'confirmed' => true])->assertOk()->assertJsonPath('price.amount', '2316');
        $this->postJson('/quotations/history', ['params' => $this->params(['spec' => '不同板厚'])])->assertJsonCount(0, 'suggestions');
        $this->actingAs($this->salesperson())->postJson('/quotations/history', ['params' => $this->params()])->assertJsonCount(0, 'suggestions');
    }

    public function test_ai_extracts_candidates_with_attachments_but_does_not_confirm_or_archive(): void
    {
        $user = $this->salesperson();
        QuotationAssistant::fake([['answer' => '已识别，请核对重量。', 'questions' => ['请确认税运口径？'], 'proposals' => [['field' => 'quantity', 'value' => '74', 'evidence' => '图纸标注74吨', 'confidence' => 'low']]]]);
        $this->actingAs($user)->post('/quotations/chat', ['message' => '识别图纸', 'attachments' => [UploadedFile::fake()->image('drawing.png')]], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('proposals.0.confidence', 'low');
        QuotationAssistant::assertPrompted(fn (AgentPrompt $prompt) => count($prompt->attachments) === 1 && $prompt->provider->name() === config('ai.default'));
        $this->assertDatabaseCount('quotation_archives', 0);
        $this->post('/quotations/chat', ['message' => '识别', 'attachments' => [UploadedFile::fake()->create('evil.html', 1, 'text/html')]], ['Accept' => 'application/json'])->assertUnprocessable();
    }

    public function test_market_suggestion_has_verified_source_and_cannot_be_forged_or_mix_units(): void
    {
        $user = $this->salesperson();
        $this->mock(QuotationMarketSearch::class, function ($mock): void {
            $mock->shouldReceive('search')->once()->andReturn(['candidates' => [['amount' => '3485', 'source' => 'internet', 'source_name' => '来源', 'date' => '2026-10-05', 'quote' => '原文', 'url' => 'https://www.mysteel.com/price']], 'sources' => [], 'limitations' => []]);
        });
        $result = $this->actingAs($user)->postJson('/quotations/market', ['params' => $this->params()])->assertOk()->json('candidates.0');
        $this->postJson('/quotations/price', ['params' => $this->params(), 'kind' => 'steel', 'source' => 'internet', 'amount' => '1', 'suggestion_token' => $result['suggestion_token'], 'confirmed' => true])->assertOk()->assertJsonPath('price.amount', '3485');
        $this->postJson('/quotations/price', ['params' => $this->params(), 'kind' => 'steel', 'source' => 'internet', 'suggestion_token' => 'forged', 'confirmed' => true])->assertUnprocessable();
        $this->postJson('/quotations/market', ['params' => $this->params(['unit' => '套'])])->assertJsonCount(0, 'candidates');
    }

    public function test_csv_preserves_unknown_totals_and_neutralizes_formulas(): void
    {
        $user = $this->salesperson();
        $preview = $this->preview($user, $this->params(['mode' => 'unit', 'quantity' => null, 'customer' => '=HYPERLINK("evil")']));
        $response = $this->post('/quotations/export', ['preview_token' => $preview['preview_token']])->assertOk();
        $csv = $response->streamedContent();
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringContainsString('未知数量，仅出单价', $csv);
        $this->assertStringContainsString('用户指定', $csv);
    }
}
