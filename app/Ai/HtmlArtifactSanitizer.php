<?php

namespace App\Ai;

use DomainException;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

class HtmlArtifactSanitizer
{
    public const MAX_BYTES = 100_000;

    public function sanitize(string $html): array
    {
        if (strlen($html) > self::MAX_BYTES) {
            throw new DomainException('HTML artifact exceeds the 100KB limit.');
        }

        $removedRules = $this->detectedRules($html);
        $config = (new HtmlSanitizerConfig)
            ->allowStaticElements()
            ->allowLinkSchemes([])
            ->allowMediaSchemes(['data'])
            ->allowRelativeLinks(false)
            ->allowRelativeMedias(false)
            ->dropElement('script')
            ->dropElement('form')
            ->dropElement('input')
            ->dropElement('button')
            ->dropElement('select')
            ->dropElement('textarea')
            ->dropElement('iframe')
            ->dropElement('object')
            ->dropElement('embed')
            ->dropElement('link')
            ->dropElement('meta')
            ->dropElement('base')
            ->withMaxInputLength(self::MAX_BYTES);

        $sanitized = (new HtmlSanitizer($config))->sanitize($html);

        return [
            'html' => $sanitized,
            'bytes' => strlen($sanitized),
            'original_hash' => hash('sha256', $html),
            'sanitized_hash' => hash('sha256', $sanitized),
            'changed' => $sanitized !== $html,
            'removed_rules' => $removedRules,
        ];
    }

    private function detectedRules(string $html): array
    {
        $rules = [
            'script' => '/<\s*script\b/i',
            'event_attribute' => '/\son[a-z]+\s*=/i',
            'form' => '/<\s*(form|input|button|select|textarea)\b/i',
            'embedded_content' => '/<\s*(iframe|object|embed)\b/i',
            'external_resource' => '/\b(?:src|href)\s*=\s*["\']\s*(?:https?:|\/\/)/i',
            'dangerous_url' => '/\b(?:src|href)\s*=\s*["\']\s*(?:javascript|vbscript):/i',
        ];

        return collect($rules)
            ->filter(fn (string $pattern) => preg_match($pattern, $html) === 1)
            ->keys()
            ->values()
            ->all();
    }
}
