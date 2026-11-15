<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class BusinessUploadRouteConfigurationTest extends TestCase
{
    public function test_numeric_creation_and_uuid_updates_receive_business_upload_limits_only(): void
    {
        $configuration = file_get_contents(__DIR__.'/../../deploy/nginx/palantir-business-uploads.conf');
        preg_match('/location ~ "([^"]+)"/', $configuration, $matches);
        $pattern = '~'.$matches[1].'~';
        foreach (['/objects/4', '/objects/123', '/records/01a003c0-5cea-7366-ab2a-d0fdf19fdaec', '/objects/01a003c0-5cea-7366-ab2a-d0fdf19fdaec'] as $path) {
            $this->assertSame(1, preg_match($pattern, $path), $path);
        }
        foreach (['/objects/project', '/records/4', '/objects/4/files', '/login', '/reimbursements/uploads'] as $path) {
            $this->assertSame(0, preg_match($pattern, $path), $path);
        }
        $this->assertStringContainsString('upload_max_filesize=100M', $configuration);
        $this->assertStringContainsString('post_max_size=201M', $configuration);
        $this->assertStringContainsString('client_max_body_size 201m;', $configuration);
    }
}
