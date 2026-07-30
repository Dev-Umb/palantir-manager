<?php

namespace Tests\Feature;

use Tests\TestCase;

class DeploymentScriptTest extends TestCase
{
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
