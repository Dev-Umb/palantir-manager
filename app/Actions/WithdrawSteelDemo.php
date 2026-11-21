<?php

namespace App\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class WithdrawSteelDemo
{
    public function handle(string $id): void
    {
        $query = DB::table('steel_demo_deployments')->where('id', $id);
        $demo = $query->first();
        abort_unless($demo, 404);

        if (! $demo->refresh_task) {
            $query->update(['withdrawing' => true]);
            Storage::disk('demo_tos')->deleteDirectory("d/{$id}");
            $result = $this->request('SubmitRefreshTask', [
                'Type' => 'dir', 'Delete' => true, 'UrlList' => ["https://site.umb.ink/d/{$id}/"],
            ]);
            $task = $result['TaskID'] ?? throw new RuntimeException('未取得 CDN 刷新任务。');
            $query->update(['refresh_task' => $task]);
        } else {
            $task = $demo->refresh_task;
        }
        $result = $this->request('DescribeContentTasks', ['TaskType' => 'refresh_dir', 'TaskID' => $task]);
        $status = collect($result['Data'] ?? [])->firstWhere('TaskID', $task)['Status'] ?? null;
        if ($status === 'complete') {
            $query->delete();
        } elseif ($status === 'failed' || (! $status && now()->diffInDays($demo->updated_at, true) >= 7)) {
            $query->update(['refresh_task' => null, 'updated_at' => now()]);
        }
    }

    /** @return array<string,mixed> */
    private function request(string $action, array $parameters): array
    {
        $host = 'cdn.volcengineapi.com';
        $query = 'Action='.$action.'&Version=2021-03-01';
        $now = gmdate('Ymd\THis\Z');
        $day = substr($now, 0, 8);
        $scope = "{$day}/cn-north-1/cdn/request";
        $body = json_encode($parameters, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $hash = hash('sha256', $body);
        $headers = "content-type:application/json\nhost:{$host}\nx-content-sha256:{$hash}\nx-date:{$now}\n";
        $signed = 'content-type;host;x-content-sha256;x-date';
        $canonical = "POST\n/\n{$query}\n{$headers}\n{$signed}\n{$hash}";
        $key = (string) config('filesystems.disks.demo_tos.secret');
        foreach ([$day, 'cn-north-1', 'cdn', 'request'] as $part) {
            $key = hash_hmac('sha256', $part, $key, true);
        }
        $signature = hash_hmac('sha256', "HMAC-SHA256\n{$now}\n{$scope}\n".hash('sha256', $canonical), $key);
        $access = config('filesystems.disks.demo_tos.key');
        $response = Http::timeout(10)->withHeaders([
            'X-Date' => $now, 'X-Content-Sha256' => $hash,
            'Authorization' => "HMAC-SHA256 Credential={$access}/{$scope}, SignedHeaders={$signed}, Signature={$signature}",
        ])->withBody($body, 'application/json')->post("https://{$host}/?{$query}")->throw();

        return $response->json('Result') ?? throw new RuntimeException('CDN 刷新失败。');
    }
}
