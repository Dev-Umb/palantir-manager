<?php

namespace App\Ai\Tools;

use App\Ai\HtmlArtifactSanitizer;
use DomainException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class PublishHtmlArtifactTool implements Tool
{
    public function __construct(private HtmlArtifactSanitizer $sanitizer) {}

    public function name(): string
    {
        return 'publish_html_artifact';
    }

    public function description(): Stringable|string
    {
        return 'Publish a static HTML/CSS report artifact after data analysis. Scripts, forms, iframes, and remote resources are removed.';
    }

    public function handle(Request $request): Stringable|string
    {
        try {
            $sanitized = $this->sanitizer->sanitize((string) $request->string('html'));
        } catch (DomainException $exception) {
            return json_encode([
                'ok' => false,
                'error' => 'invalid_html',
                'message' => $exception->getMessage(),
            ], JSON_UNESCAPED_UNICODE);
        }

        return json_encode([
            'ok' => true,
            'artifact' => [
                'id' => (string) Str::uuid7(),
                'type' => 'html',
                'title' => (string) ($request->string('title')->value() ?: 'HTML 分析结果'),
                'revision' => 1,
                'data' => $sanitized,
            ],
        ], JSON_UNESCAPED_UNICODE);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->required()->description('Short Chinese title for the HTML report.'),
            'html' => $schema->string()->required()->description('Static HTML with optional inline CSS. Never include scripts or remote resources.'),
        ];
    }
}
