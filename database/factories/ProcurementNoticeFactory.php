<?php

namespace Database\Factories;

use App\Models\ProcurementBatch;
use App\Models\ProcurementClient;
use App\Support\ProcurementPayload;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProcurementNoticeFactory extends Factory
{
    public function definition(): array
    {
        $item = json_decode(file_get_contents(resource_path('skills/procurement-publish/references/example.json')), true)['items'][0];
        $item['code'] = fake()->unique()->uuid();
        $adapter = app(ProcurementPayload::class);
        $dates = $adapter->dates($item);

        return ['batch_id' => ProcurementBatch::factory(), 'client_id' => fn (array $a) => ProcurementBatch::findOrFail($a['batch_id'])->client_id, 'user_id' => fn (array $a) => ProcurementClient::findOrFail($a['client_id'])->user_id, 'identity_key' => hash('sha256', $item['platform'].'|'.$item['code']), 'title' => $item['title'], 'method' => $item['nature'], 'priority' => $item['priority'], 'status' => $item['status'], 'invited' => false, 'has_issues' => true, 'published_date' => $dates['published']['date'], 'dates' => $dates, 'payload' => $item, 'content_hash' => $adapter->contentHash($item)];
    }
}
