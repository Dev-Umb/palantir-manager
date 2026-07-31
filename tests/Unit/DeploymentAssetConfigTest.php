<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class DeploymentAssetConfigTest extends TestCase
{
    public function test_nginx_snippet_enables_gzip_and_immutable_fingerprinted_asset_caching(): void
    {
        $snippet = file_get_contents(dirname(__DIR__, 2).'/deploy/nginx/palantir-assets.conf');

        $this->assertIsString($snippet);
        $this->assertStringContainsString('gzip on;', $snippet);
        $this->assertStringContainsString('gzip_types text/css application/javascript;', $snippet);
        $this->assertStringContainsString('location ^~ /build/assets/', $snippet);
        $this->assertStringContainsString('Cache-Control "public, max-age=31536000, immutable"', $snippet);
    }

    public function test_deployment_only_inspects_nginx_and_prints_manual_install_guidance(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2).'/deploy.sh');

        $this->assertIsString($script);
        $this->assertStringContainsString('sudo nginx -T 2>/dev/null | grep -Fq', $script);
        $this->assertStringContainsString('不自动修改', $script);
        $this->assertStringContainsString('sudo nginx -t', $script);
        $this->assertStringNotContainsString('cp deploy/nginx/palantir-assets.conf /etc/nginx', $script);
        $this->assertLessThan(
            strpos($script, 'php artisan db:seed --class=XycPrototypeSeeder --force'),
            strpos($script, 'php artisan migrate --force'),
        );
    }
}
