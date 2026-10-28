<?php

namespace App\Support;

use App\Models\ObjectRecord;
use Illuminate\Support\Collection;

class ProjectMapLocations
{
    /**
     * @param  Collection<int, ObjectRecord>  $projects
     * @param  Collection<int, ObjectRecord>|null  $contracts
     * @return array{points: array, factories: array, located_count: int, unlocated_count: int, total_count: int, excluded_count: int, representative_count: int, nearby_km: int}
     */
    public function summarize(Collection $projects, ?Collection $contracts = null): array
    {
        $byProject = ($contracts ?? collect())->groupBy('payload.project_id');
        $candidates = [];
        $eligibleCount = 0;
        $locatedCount = 0;
        foreach ($projects as $project) {
            $payload = $project->payload;
            $received = $byProject->get($project->id, collect())->filter(fn (ObjectRecord $contract): bool => ! empty($contract->payload['contract_attachments']) || ! empty($contract->payload['processing_letter_attachments']));
            $projectHasLetter = ! empty($payload['unassigned_processing_letter_attachments']) && in_array($payload['contract_status'] ?? $payload['overall_status'] ?? '', ['已有加工函', '已拿到加工函', '已签署', '合同签署'], true);
            if ($received->isEmpty() && ! $projectHasLetter) {
                continue;
            }
            $eligibleCount++;
            $name = (string) ($payload['name'] ?? $project->title);
            $confirmedFactory = config('project_map.confirmed_factories.'.$project->code);
            $factorySubject = $payload['factory_subject'] ?? null;
            if (empty($factorySubject) && is_array($confirmedFactory) && ($confirmedFactory['project_name'] ?? null) === $name) {
                $factorySubject = $confirmedFactory['factory_subject'];
            }
            $explicitLocation = trim((string) ($payload['project_location'] ?? $payload['location'] ?? ''));
            $verified = config('project_map.verified_projects.'.$project->code);
            $basis = $explicitLocation !== '' ? '项目地点字段' : '项目名称地名';
            if (is_array($verified) && ($verified['project_name'] ?? null) === $name) {
                $matches = [$project->code => array_intersect_key($verified, array_flip(['name', 'longitude', 'latitude', 'precision', 'overseas']))];
                $basis = $verified['basis'];
            } else {
                $matches = $this->locate($explicitLocation !== '' ? $explicitLocation : $name);
            }
            if ($matches === []) {
                continue;
            }
            $locatedCount++;
            $amounts = $received->pluck('payload.amount')->map(fn (mixed $value): ?float => $this->amount($value))->filter(fn (?float $value): bool => $value !== null);
            $amount = $amounts->isNotEmpty() ? round($amounts->sum(), 2) : $this->amount($payload['contract_amount'] ?? null);
            foreach ($matches as $key => $location) {
                $candidates[] = [...$location, 'id' => $project->id.'-'.$key, 'projects' => [[
                    'id' => $project->id,
                    'name' => $name,
                    'code' => $payload['project_no'] ?? $project->code,
                    'basis' => $basis,
                    'contract_amount' => $amount,
                    'factory' => match ($factorySubject) {
                        '山西鑫源昌', '文水鑫源昌' => 'wenshui',
                        '江苏晋武' => 'suqian',
                        default => null,
                    },
                ]]];
            }
        }
        usort($candidates, fn (array $left, array $right): int => (($right['projects'][0]['contract_amount'] ?? -INF) <=> ($left['projects'][0]['contract_amount'] ?? -INF)) ?: strcmp($left['projects'][0]['code'], $right['projects'][0]['code']) ?: strcmp($left['id'], $right['id']));
        $points = [];
        $nearbyKm = (int) config('project_map.nearby_km', 50);
        foreach ($candidates as $candidate) {
            if (collect($points)->contains(fn (array $point): bool => $this->distance($point, $candidate) <= $nearbyKm)) {
                continue;
            }
            $points[] = $candidate;
        }

        return [
            'points' => $points,
            'factories' => [
                ['id' => 'wenshui', 'name' => '山西文水 · 鑫源昌', 'longitude' => 112.03, 'latitude' => 37.44],
                ['id' => 'suqian', 'name' => '江苏宿迁 · 晋武重工', 'longitude' => 118.28, 'latitude' => 33.96],
            ],
            'located_count' => $locatedCount,
            'unlocated_count' => $eligibleCount - $locatedCount,
            'total_count' => $eligibleCount,
            'excluded_count' => $projects->count() - $eligibleCount,
            'representative_count' => count($points),
            'nearby_km' => $nearbyKm,
        ];
    }

    /** @return array<string, array{name: string, longitude: float|int, latitude: float|int, precision: string, overseas: bool}> */
    public function locate(string $source): array
    {
        $source = preg_replace('/[\p{Han}A-Za-z0-9]+(?:有限公司|集团|重工|建工|工程局)/u', '', $source) ?? '';
        $countries = $this->matches(str_replace('内蒙古', '', $source), config('project_map.countries'), '国家级概略', true);
        $cities = $this->matches($source, config('project_map.cities'), '城市级概略', false);
        if ($cities !== []) {
            return [...$countries, ...$cities];
        }

        $routes = $this->matches($source, config('project_map.routes'), '线路级概略', false);

        return [...$countries, ...($routes !== [] ? $routes : $this->matches($source, config('project_map.regions'), '省级概略', false))];
    }

    /** @param array{longitude: float|int, latitude: float|int} $left
     * @param  array{longitude: float|int, latitude: float|int}  $right
     */
    private function distance(array $left, array $right): float
    {
        $latitudeDelta = deg2rad($left['latitude'] - $right['latitude']);
        $longitudeDelta = deg2rad($left['longitude'] - $right['longitude']);
        $value = sin($latitudeDelta / 2) ** 2 + cos(deg2rad($left['latitude'])) * cos(deg2rad($right['latitude'])) * sin($longitudeDelta / 2) ** 2;

        return 6371 * 2 * asin(sqrt(min(1, $value)));
    }

    private function amount(mixed $value): ?float
    {
        return is_numeric($value) && is_finite((float) $value) ? (float) $value : null;
    }

    /**
     * @param  array<string|int, array{name: string, longitude: float|int, latitude: float|int, aliases: array<string>}>  $locations
     * @return array<string, array{name: string, longitude: float|int, latitude: float|int, precision: string, overseas: bool}>
     */
    private function matches(string $source, array $locations, string $precision, bool $overseas): array
    {
        $matches = [];
        $aliases = collect($locations)->pluck('aliases')->flatten()->sortByDesc(fn (string $alias): int => mb_strlen($alias));
        $matchedAliases = [];
        foreach ($aliases as $alias) {
            if (str_contains($source, $alias)) {
                $matchedAliases[] = $alias;
                $source = str_replace($alias, '', $source);
            }
        }
        foreach ($locations as $key => $location) {
            foreach ($location['aliases'] as $alias) {
                if (in_array($alias, $matchedAliases, true)) {
                    $matches[($overseas ? 'country-' : 'place-').$key] = [
                        'name' => $location['name'],
                        'longitude' => $location['longitude'],
                        'latitude' => $location['latitude'],
                        'precision' => $precision,
                        'overseas' => $overseas,
                    ];
                    break;
                }
            }
        }

        return $matches;
    }
}
