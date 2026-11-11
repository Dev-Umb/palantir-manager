<?php

namespace Tests\Feature;

use Tests\TestCase;

class DeploymentScriptTest extends TestCase
{
    public function test_deployment_targets_the_new_application_host_and_preserves_update_manifests(): void
    {
        $script = file_get_contents(base_path('deploy.sh'));

        $this->assertStringContainsString('HOST=ubuntu@82.156.205.147', $script);
        $this->assertStringContainsString('KEY=~/.ssh/id_ed25519', $script);
        $this->assertStringContainsString("--exclude='public/app-updates/***'", $script);
        $this->assertStringNotContainsString('62.234.53.54', $script);
    }

    public function test_rsync_preserves_the_laravel_maintenance_marker(): void
    {
        $script = file_get_contents(base_path('deploy.sh'));

        $this->assertIsString($script);
        $this->assertStringContainsString(
            "--exclude='storage/framework/down'",
            $script,
            'deploy.sh must protect Laravel maintenance mode from rsync --delete.',
        );
        $this->assertLessThan(
            strpos($script, './ ${HOST}:${REMOTE}/'),
            strpos($script, "--exclude='storage/framework/down'"),
            'The maintenance marker exclusion must be part of the rsync command.',
        );
    }
}
