<?php

namespace Tests\Unit;

use App\Ai\HtmlArtifactSanitizer;
use App\Ai\Tools\PublishHtmlArtifactTool;
use DomainException;
use Laravel\Ai\Tools\Request;
use Tests\TestCase;

class HtmlArtifactSanitizerTest extends TestCase
{
    public function test_it_keeps_static_markup_and_removes_executable_or_remote_content(): void
    {
        $result = app(HtmlArtifactSanitizer::class)->sanitize(<<<'HTML'
<section style="color: red" onclick="alert(1)">
  <h2>项目分析</h2>
  <script>alert(document.cookie)</script>
  <form action="https://evil.example"><input name="secret"></form>
  <img src="https://evil.example/track.png">
  <iframe src="https://evil.example"></iframe>
</section>
HTML);

        $this->assertStringContainsString('项目分析', $result['html']);
        $this->assertStringContainsString('style="color: red"', $result['html']);
        $this->assertStringNotContainsString('onclick', $result['html']);
        $this->assertStringNotContainsString('<script', $result['html']);
        $this->assertStringNotContainsString('<form', $result['html']);
        $this->assertStringNotContainsString('<iframe', $result['html']);
        $this->assertStringNotContainsString('evil.example', $result['html']);
        $this->assertSame(64, strlen($result['original_hash']));
        $this->assertSame(64, strlen($result['sanitized_hash']));
        $this->assertEqualsCanonicalizing(
            ['script', 'event_attribute', 'form', 'embedded_content', 'external_resource'],
            $result['removed_rules'],
        );
    }

    public function test_it_rejects_html_larger_than_one_hundred_kilobytes(): void
    {
        $this->expectException(DomainException::class);

        app(HtmlArtifactSanitizer::class)->sanitize(str_repeat('a', 100_001));
    }

    public function test_publish_tool_returns_a_sanitized_html_artifact(): void
    {
        $payload = json_decode((string) app(PublishHtmlArtifactTool::class)->handle(new Request([
            'title' => '欠款分析报告',
            'html' => '<section><h2>报告</h2><script>alert(1)</script></section>',
        ])), true);

        $this->assertTrue($payload['ok']);
        $this->assertSame('html', $payload['artifact']['type']);
        $this->assertSame('欠款分析报告', $payload['artifact']['title']);
        $this->assertStringContainsString('<h2>报告</h2>', $payload['artifact']['data']['html']);
        $this->assertStringNotContainsString('<script', $payload['artifact']['data']['html']);
    }
}
