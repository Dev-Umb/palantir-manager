<?php

namespace Tests\Feature;

use App\Models\TimebookEntry;
use App\Models\TimebookEntryAudit;
use App\Models\TimebookWorker;
use App\Models\User;
use App\Support\TimebookQuery;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class TimebookConcurrencyTest extends TestCase
{
    public function test_postgresql_concurrent_creates_and_edits_commit_exactly_once(): void
    {
        if (getenv('TIMEBOOK_PG_CONCURRENCY') !== '1' || DB::getDriverName() !== 'pgsql'
            || DB::connection()->getDatabaseName() !== 'timebook_concurrency_test') {
            $this->markTestSkipped('Opt-in isolated PostgreSQL database timebook_concurrency_test only.');
        }
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        $user = User::factory()->create();
        $this->assertSame(['201', '409', '409', '409'], $this->race($user->id));
        $this->assertSame(1, TimebookWorker::count());
        $this->assertSame(1, TimebookEntry::count());
        $this->assertSame(1, TimebookEntryAudit::count());
        $entry = TimebookEntry::firstOrFail();
        $this->assertSame(['200', '409', '409', '409'], $this->race($user->id, $entry->id));
        $this->assertSame(2, $entry->fresh()->version);
        $this->assertSame(2, TimebookEntryAudit::count());
        $query = app(TimebookQuery::class);
        $result = $query->result($query->filters([]));
        $this->assertSame(1, $result['totals']['count']);
        $this->assertSame(1, $result['totals']['half_days']);
    }

    private function race(int $userId, ?int $entryId = null): array
    {
        $script = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$user = App\Models\User::findOrFail((int) $argv[1]);
$entry = $argv[2] ? App\Models\TimebookEntry::findOrFail((int) $argv[2]) : null;
$data = ['name' => '并发测试人员', 'day' => '2026-09-18', 'days' => $entry ? 0.5 : 1, 'overtime' => 0, 'project' => '', 'note' => '', 'version' => 1];
while (microtime(true) < (float) $argv[3]) { usleep(1000); }
try {
    $app->make(App\Actions\WriteTimebookEntry::class)->save($data, $user, $entry);
    echo $entry ? '200' : '201';
} catch (Illuminate\Http\Exceptions\HttpResponseException $exception) {
    echo $exception->getResponse()->getStatusCode();
}
PHP;
        $processes = [];
        $start = microtime(true) + 1;
        foreach (range(1, 4) as $_) {
            $process = new Process([PHP_BINARY, '-r', $script, (string) $userId, (string) ($entryId ?? ''), (string) $start], base_path());
            $process->start();
            $processes[] = $process;
        }
        $statuses = [];
        foreach ($processes as $process) {
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            $statuses[] = trim($process->getOutput());
        }
        sort($statuses);

        return $statuses;
    }
}
