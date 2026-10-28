<?php

namespace Tests\Unit;

use App\Support\SupportCombinationParser;
use App\Support\SupportLibraryException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SupportCombinationParserTest extends TestCase
{
    /**
     * A basic unit test example.
     */
    public function test_legacy_fullwidth_and_whitespace_keep_order_and_dimensions(): void
    {
        $parser = new SupportCombinationParser;
        $a = $parser->parse('6+5@2+3@3+3/4')[0];
        foreach (['6+5@2+3@3+3@4', '６＋５＠２＋３＠３＋３／４', ' 6 + 5 @ 2 + 3 @ 3 + 3 / 4 '] as $text) {
            $b = $parser->parse($text)[0];
            unset($a['expression'], $b['expression']);
            $this->assertSame($a, $b);
        }
        $this->assertSame([6000, 5000], $a['height_segments_mm']);
        $this->assertSame([3000, 3000], $a['longitudinal_spans_mm']);
        $this->assertSame(2000, $parser->parse('2.0000')[0]['height_mm']);
    }

    public function test_independent_separators_and_default_count(): void
    {
        foreach ([' ', '   ', '　', "\n", ';', '；', ',', '，', '、'] as $separator) {
            $groups = (new SupportCombinationParser)->parse('6/2'.$separator.'6/4');
            $this->assertCount(2, $groups);
            $this->assertSame([2, 4], array_column($groups, 'groups'));
        }
        $this->assertSame(1, (new SupportCombinationParser)->parse('6@2@3')[0]['groups']);
    }

    #[DataProvider('invalidExpressions')]
    public function test_invalid_expression_is_rejected(string $expression): void
    {
        $this->expectException(SupportLibraryException::class);
        (new SupportCombinationParser)->parse($expression);
    }

    public static function invalidExpressions(): array
    {
        return array_map(fn ($value) => [$value], ['', '6@2', '6@2@3@4@5', '6@2@3@4/2', '6/2/3', '6/0', '6/-1', '6/1.5', '6/01', '6/', '0', '-6', '.7', '6m', '6000mm', '1e3', '(6+5)', '6++5', '6;', '6;;5', '2.0001', '6/9007199254740992']);
    }
}
