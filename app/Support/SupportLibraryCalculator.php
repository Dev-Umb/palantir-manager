<?php

namespace App\Support;

class SupportLibraryCalculator
{
    public function __construct(private SupportCombinationParser $parser) {}

    public function query(array $db, array $entries): array
    {
        if (! array_is_list($entries) || count($entries) < 1 || count($entries) > 20) {
            throw new SupportLibraryException('请填写1至20个搭配。', 'INVALID_CRITERIA');
        }
        $parts = array_column($db['parts'], null, 'id');
        $frames = $composition = $columns = [];
        foreach ($db['frames'] as $frame) {
            $frames[$this->frameKey($frame['support_use'], $frame['diameter_mm'], $frame['height_mm'], $frame['spacing_mm'])] = $frame;
        }
        foreach ($db['composition'] as $line) {
            $composition[$line['frame_id']][] = $line;
        }
        foreach ($db['parts'] as $part) {
            if ($part['kind'] === '钢柱') {
                $columns[$part['spec'].'|'.$part['length_mm']][] = $part;
            }
        }
        $results = [];
        $expanded = 0;
        foreach ($entries as $i => $entry) {
            try {
                $groups = $this->parser->parse($entry['expression']);
                foreach ($groups as $group) {
                    $expanded += count($group['height_segments_mm']) * (count($group['transverse_spans_mm']) + count($group['longitudinal_spans_mm']));
                }
                if ($expanded > 20000) {
                    throw new SupportLibraryException('总展开方向跨记录超过20000，请拆分检索。', 'INVALID_EXPRESSION');
                }
                $results[] = $this->entry($db, $entry, $groups, $parts, $frames, $composition, $columns);
            } catch (SupportLibraryException $error) {
                throw new SupportLibraryException('搭配'.($i + 1).'：'.$error->getMessage(), $error->errorCode, $error->getStatusCode(), [...$error->context, 'entry' => $i + 1]);
            }
        }
        if (count($results) === 1) {
            return $results[0];
        }

        return $this->batch($results, $entries);
    }

    private function entry(array $db, array $entry, array $groups, array $parts, array $frames, array $composition, array $columns): array
    {
        $criteria = $entry['criteria'];
        $use = $criteria['support_use'] ?? null;
        $diameter = $criteria['diameter_mm'] ?? null;
        $model = $criteria['column_brace_model'] ?? null;
        if (! in_array($use, ['吊装', '顶推'], true) || ! in_array($diameter, [273, 530], true)) {
            throw new SupportLibraryException('请选择支架用途和钢柱规格。', 'INVALID_CRITERIA');
        }
        $single = array_all($groups, fn (array $g) => $g['column_only']);
        if ($single) {
            $model = null;
        } elseif (! in_array($model, ['C14A', 'C16A'], true)) {
            throw new SupportLibraryException('请选择柱撑型号：C14A或C16A。', 'INVALID_CRITERIA');
        }
        if (! $single && ! array_any($db['frames'], fn (array $f) => $f['support_use'] === $use)) {
            throw new SupportLibraryException('该用途的搭配资料待补充。', 'USE_NOT_AVAILABLE');
        }
        if (! $single && ! array_any($db['frames'], fn (array $f) => $f['support_use'] === $use && $f['diameter_mm'] === $diameter)) {
            throw new SupportLibraryException('未找到该钢柱规格的搭配。', 'DIAMETER_NOT_AVAILABLE');
        }
        $details = $combinations = $missing = [];
        $totals = ['total_groups' => 0, 'total_columns' => 0, 'total_column_positions' => 0, 'total_braces' => 0, 'total_faces' => 0];
        foreach ($groups as $index => $group) {
            $item = $index + 1;
            $nx = count($group['transverse_spans_mm']);
            $ny = count($group['longitudinal_spans_mm']);
            $positionsPerGroup = SupportLibraryMath::multiply($nx + 1, $ny + 1);
            $positions = SupportLibraryMath::multiply($positionsPerGroup, $group['groups']);
            $columnCount = SupportLibraryMath::multiply($positions, count($group['height_segments_mm']));
            foreach (['total_groups' => $group['groups'], 'total_column_positions' => $positions, 'total_columns' => $columnCount] as $key => $number) {
                $totals[$key] = SupportLibraryMath::add($totals[$key], $number);
            }
            $directions = [];
            foreach ($group['height_segments_mm'] as $level => $height) {
                foreach ([['横向', $group['transverse_spans_mm'], $ny + 1], ['纵向', $group['longitudinal_spans_mm'], $nx + 1]] as [$direction, $spans, $rows]) {
                    foreach ($spans as $bay => $spacing) {
                        $faces = SupportLibraryMath::multiply($rows, $group['groups']);
                        $totals['total_faces'] = SupportLibraryMath::add($totals['total_faces'], $faces);
                        $frame = $frames[$this->frameKey($use, $diameter, $height, $spacing)] ?? null;
                        $location = ['item' => $item, 'group_expression' => $group['expression'], 'height_segment' => $level + 1,
                            'height_mm' => $height, 'direction' => $direction, 'bay' => $bay + 1, 'spacing_mm' => $spacing, 'total_faces' => $faces];
                        if (! $frame) {
                            $record = [...$location, 'message' => '第'.$item.'项第'.($level + 1).'高度段（'.$height / 1000 .'m）'.$direction.'第'.($bay + 1).'跨（'.$spacing / 1000 .'m）未找到柱撑搭配；保留钢柱计数及计重。'];
                            $missing[] = $record;
                            $directions[] = [...$record, 'matched' => false, 'frame_id' => null];

                            continue;
                        }
                        $directions[] = [...$location, 'faces_per_group' => $rows, 'matched' => true, 'frame_id' => $frame['id']];
                        foreach ($composition[$frame['id']] as $line) {
                            $part = $parts[$line['part_id']];
                            $quantity = SupportLibraryMath::multiply($line['per_frame'], $faces);
                            $totals['total_braces'] = SupportLibraryMath::add($totals['total_braces'], $quantity);
                            $details[] = [...$line, ...$location, 'kind' => '柱撑', 'spec' => $model, 'source_part_id' => $part['id'],
                                'part_id' => $model.'-'.$part['id'], 'part_linked' => true, 'quantity' => $quantity, 'unit' => '根',
                                'unit_weight_kg' => $part['unit_weight_kg'], 'part_source' => $part['source'] ?? null,
                                'faces_per_group' => $rows, 'groups' => $group['groups'], 'per_face' => $line['per_frame'],
                                'calculation' => $line['per_frame'].'根/面 × '.$rows.'面/组 × '.$group['groups'].'组 ＝ '.$quantity.'根'];
                        }
                    }
                }
                $matches = $columns['φ'.$diameter.'|'.$height] ?? [];
                if (count($matches) !== 1) {
                    throw new SupportLibraryException('第'.$item.'项第'.($level + 1).'高度段：未找到唯一的φ'.$diameter.'、'.$height.'mm钢柱，不自动改配其他长度。', 'COLUMN_NOT_UNIQUE', context: ['item' => $item, 'heightSegment' => $level + 1]);
                }
                $part = $matches[0];
                $details[] = ['item' => $item, 'group_expression' => $group['expression'], 'kind' => '钢柱', 'spec' => $part['spec'], 'direction' => '各柱位钢柱',
                    'part_id' => $part['id'], 'source_part_id' => $part['id'], 'length_mm' => $part['length_mm'], 'height_mm' => $height, 'height_segment' => $level + 1,
                    'hole' => $part['note'] ?: '—', 'quantity' => $positions, 'unit' => '根', 'per_group' => $positionsPerGroup, 'groups' => $group['groups'],
                    'source' => $part['source'] ?? null, 'part_source' => $part['source'] ?? null, 'unit_weight_kg' => $part['unit_weight_kg'], 'frame_id' => null,
                    'calculation' => $positionsPerGroup.'柱位/组 × '.$group['groups'].'组 ＝ '.$positions.'根'];
            }
            $combinations[] = [...$group, 'item' => $item, 'support_use' => $use, 'column_brace_model' => $model, 'diameter_mm' => $diameter,
                'positions_per_group' => $positionsPerGroup, 'column_positions' => $positions, 'columns' => $columnCount, 'directions' => $directions];
        }
        $merged = [];
        $boltKnown = $columnBolts = $braceBolts = $joints = 0;
        $boltComplete = $columnComplete = $braceComplete = true;
        $profile = $db['weight_profiles'][$model ?? ''] ?? null;
        $usableProfile = isset($profile['kg_per_m'], $profile['channels_per_brace']) && $profile['kg_per_m'] > 0 && $profile['channels_per_brace'] > 0;
        foreach ($details as &$detail) {
            $part = $parts[$detail['source_part_id']];
            $group = $combinations[$detail['item'] - 1];
            $rawWeight = $part['unit_weight_kg'];
            $validWeight = (is_float($rawWeight) || is_int($rawWeight)) && is_finite((float) $rawWeight) && $rawWeight > 0;
            $theoryUnit = $detail['kind'] === '柱撑' && $usableProfile ? SupportLibraryMath::weight($detail['length_mm'] / 1000 * $profile['kg_per_m'] * $profile['channels_per_brace']) : null;
            $detail['theoretical_unit_weight_kg'] = $theoryUnit;
            $detail['linear_weight_kg_per_m'] = $detail['kind'] === '柱撑' && $usableProfile ? $profile['kg_per_m'] : null;
            $detail['channels_per_brace'] = $detail['kind'] === '柱撑' && $usableProfile ? $profile['channels_per_brace'] : null;
            $detail['accounted_unit_weight_kg'] = $validWeight ? $rawWeight : $theoryUnit;
            $detail['weight_basis'] = $validWeight ? '原库单重' : ($theoryUnit !== null ? '槽钢理论重量（未扣孔、不含附件）' : '待补');
            $detail['bolts_per_piece'] = $part['bolts_per_piece_raw'];
            $top = $detail['kind'] === '钢柱' && $detail['height_segment'] === count($group['height_segments_mm']);
            $detail['bolt_joint_count'] = $detail['kind'] === '钢柱' ? ($top ? 0 : $detail['quantity']) : null;
            if ($detail['kind'] === '钢柱' && ! $top) {
                $nextHeight = $group['height_segments_mm'][$detail['height_segment']];
                $next = $columns[$detail['spec'].'|'.$nextHeight][0];
                if ($next['bolts_per_piece_raw'] !== $detail['bolts_per_piece']) {
                    throw new SupportLibraryException('钢柱相接两段的配套螺栓数量不一致，请确认该接头用量。', 'JOINT_BOLTS_CONFLICT');
                }
                $joints = SupportLibraryMath::add($joints, $detail['quantity']);
            }
            $detail['bolt_quantity'] = $top ? 0 : ($detail['bolts_per_piece'] === null ? null : SupportLibraryMath::multiply($detail['quantity'], $detail['bolts_per_piece']));
            $detail['bolt_weight_kg'] = $detail['bolt_quantity'] === null ? null : SupportLibraryMath::weight($detail['bolt_quantity'] * 0.44);
            $detail['bolt_calculation'] = $top ? '最顶段无上接头；柱底不计螺栓' : $detail['quantity'].' × '.($detail['bolts_per_piece'] ?? '待补').'套';
            if ($detail['bolt_quantity'] === null) {
                $boltComplete = false;
                if ($detail['kind'] === '钢柱') {
                    $columnComplete = false;
                } else {
                    $braceComplete = false;
                }
            } else {
                $boltKnown = SupportLibraryMath::add($boltKnown, $detail['bolt_quantity']);
                if ($detail['kind'] === '钢柱') {
                    $columnBolts = SupportLibraryMath::add($columnBolts, $detail['bolt_quantity']);
                } else {
                    $braceBolts = SupportLibraryMath::add($braceBolts, $detail['bolt_quantity']);
                }
            }
            $id = $detail['part_id'];
            if (! isset($merged[$id])) {
                $merged[$id] = array_intersect_key($detail, array_flip(['part_id', 'kind', 'spec', 'length_mm', 'hole', 'unit', 'unit_weight_kg', 'theoretical_unit_weight_kg', 'accounted_unit_weight_kg', 'weight_basis', 'linear_weight_kg_per_m', 'channels_per_brace', 'bolts_per_piece']));
                $merged[$id] += ['quantity' => 0, 'sources' => [], 'bolt_quantity' => 0, 'bolt_joint_count' => $detail['kind'] === '钢柱' ? 0 : null];
            }
            $row = &$merged[$id];
            $row['quantity'] = SupportLibraryMath::add($row['quantity'], $detail['quantity']);
            $row['bolt_quantity'] = $row['bolt_quantity'] === null || $detail['bolt_quantity'] === null ? null : SupportLibraryMath::add($row['bolt_quantity'], $detail['bolt_quantity']);
            if ($detail['kind'] === '钢柱') {
                $row['bolt_joint_count'] = SupportLibraryMath::add($row['bolt_joint_count'], $detail['bolt_joint_count']);
            }
            $row['sources'] = array_values(array_unique(array_filter([...$row['sources'], $detail['source'] ?? null, $detail['part_source']])));
            unset($row);
        }
        unset($detail);
        foreach ($merged as &$row) {
            $this->weights($row, $profile, $usableProfile);
        }
        unset($row);
        foreach ($details as &$detail) {
            $this->weights($detail, $profile, $usableProfile);
        }
        unset($detail);
        $rows = array_values($merged);
        $this->sortRows($rows);
        $bracingComplete = count($missing) === 0;
        $rawKnown = SupportLibraryMath::sum(array_column($rows, 'weight_kg'), true);
        $rawTotal = $bracingComplete ? SupportLibraryMath::sum(array_column($rows, 'weight_kg')) : null;
        $componentKnown = SupportLibraryMath::sum(array_column($rows, 'accounted_weight_kg'), true);
        $componentFull = $bracingComplete ? SupportLibraryMath::sum(array_column($rows, 'accounted_weight_kg')) : null;
        $columnRows = array_values(array_filter($rows, fn (array $row) => $row['kind'] === '钢柱'));
        $braceRows = array_values(array_filter($rows, fn (array $row) => $row['kind'] === '柱撑'));
        $boltWeight = $boltComplete && $bracingComplete ? SupportLibraryMath::weight($boltKnown * 0.44) : null;
        $knownBoltWeight = $boltComplete ? SupportLibraryMath::weight($boltKnown * 0.44) : null;
        $accountedComponents = SupportLibraryMath::sum(array_column($rows, 'accounted_weight_kg'));

        return ['schema_version' => 'user-group-library-1', 'data_kind' => '用户支架库分组用量', 'application_verified' => true,
            'query_conditions' => ['support_use' => $use, 'column_brace_model' => $model, 'diameter_mm' => $diameter, 'combination' => $entry['expression'], 'bolt_unit_weight_kg' => 0.44],
            'source' => $db['source'], 'confirmations' => $db['confirmations'] ?? [], 'group_rule' => $db['group_rule'], 'weight_profile' => $profile,
            'combinations' => $combinations, 'details' => $details, 'merged' => $rows, 'missing_matches' => $missing, 'bracing_complete' => $bracingComplete,
            ...$totals, 'total_quantity' => SupportLibraryMath::add($totals['total_columns'], $totals['total_braces']),
            'matched_faces' => $totals['total_faces'] - array_sum(array_column($missing, 'total_faces')),
            'known_weight_kg' => $rawKnown, 'total_weight_kg' => $rawTotal,
            'column_weight_kg' => SupportLibraryMath::sum(array_column($columnRows, 'accounted_weight_kg')),
            'known_column_weight_kg' => SupportLibraryMath::sum(array_column($columnRows, 'accounted_weight_kg'), true),
            'brace_theoretical_weight_kg' => $braceRows ? SupportLibraryMath::sum(array_column($braceRows, 'theoretical_weight_kg')) : 0,
            'known_components_estimated_weight_kg' => $componentKnown, 'estimated_weight_kg' => $componentFull,
            'estimated_with_bolts_weight_kg' => SupportLibraryMath::sum([$componentFull, $boltWeight]),
            'accounted_with_bolts_weight_kg' => SupportLibraryMath::sum([$accountedComponents, $knownBoltWeight]),
            'estimated_weight_scope' => '钢柱原库重量＋柱撑采用重量；未扣孔，不含底座、连接板及组间联系撑，不进行结构验算。',
            'bolts' => ['spec' => 'M22×80', 'unit' => '套', 'unit_weight_kg' => 0.44, 'quantity' => $boltComplete && $bracingComplete ? $boltKnown : null,
                'known_quantity' => $boltKnown, 'column_bolt_quantity' => $columnComplete ? $columnBolts : null, 'brace_bolt_quantity' => $braceComplete && $bracingComplete ? $braceBolts : null,
                'known_brace_bolt_quantity' => $braceBolts, 'column_joint_count' => $joints, 'weight_kg' => $boltWeight, 'known_weight_kg' => $knownBoltWeight,
                'counting_basis' => '柱撑按根累计；钢柱仅计内部接头，每个接头计一次，柱底和柱顶不计', 'weight_source' => $db['bolt_rule']['weight_source'], 'package_description' => $db['bolt_rule']['package_description']],
            'excluded' => ['底座及连接板', '组间连接件'], 'issues' => array_map(fn (array $m) => ['id' => 'missing_brace', 'message' => $m['message'], 'source' => '当前搭配库未收录'], $missing)];
    }

    private function weights(array &$row, ?array $profile, bool $usable): void
    {
        $raw = $row['unit_weight_kg'];
        $valid = (is_int($raw) || is_float($raw)) && is_finite((float) $raw) && $raw > 0;
        $row['weight_kg'] = $valid ? SupportLibraryMath::weight($raw * $row['quantity']) : null;
        $row['theoretical_weight_kg'] = $row['kind'] === '柱撑' && $usable ? SupportLibraryMath::weight($row['length_mm'] / 1000 * $profile['kg_per_m'] * $profile['channels_per_brace'] * $row['quantity']) : null;
        $row['accounted_weight_kg'] = $valid ? $row['weight_kg'] : $row['theoretical_weight_kg'];
        $row['weight_formula'] = $row['accounted_weight_kg'] === null ? '待补' : ($valid ? $raw.'×'.$row['quantity'] : $row['length_mm'].'÷1000×'.$profile['kg_per_m'].'×'.$profile['channels_per_brace'].'×'.$row['quantity']).'＝'.$row['accounted_weight_kg'].' kg';
        $row['theoretical_weight_source'] = $profile['source_url'] ?? null;
        $row['bolt_weight_kg'] = $row['bolt_quantity'] === null ? null : SupportLibraryMath::weight($row['bolt_quantity'] * 0.44);
    }

    private function batch(array $results, array $entries): array
    {
        $result = $results[0];
        $result['query_entries'] = array_column($results, 'query_conditions');
        foreach (['support_use', 'column_brace_model', 'diameter_mm'] as $key) {
            if (! array_all($results, fn (array $r) => $r['query_conditions'][$key] === $result['query_conditions'][$key])) {
                $result['query_conditions'][$key] = null;
            }
        }
        $result['query_conditions']['combination'] = implode('；', array_column($entries, 'expression'));
        foreach (['combinations', 'details', 'missing_matches', 'issues'] as $key) {
            $result[$key] = [];
        }
        $offset = 0;
        $merged = [];
        foreach ($results as $index => $r) {
            foreach (['combinations', 'details', 'missing_matches'] as $key) {
                foreach ($r[$key] as $record) {
                    $record['item'] += $offset;
                    $record['entry'] = $index + 1;
                    if (isset($record['message'])) {
                        $record['message'] = '搭配'.($index + 1).'：'.$record['message'];
                    }
                    $result[$key][] = $record;
                }
            }
            array_push($result['issues'], ...$r['issues']);
            $offset += count($r['combinations']);
            foreach ($r['merged'] as $row) {
                $id = $row['part_id'];
                if (! isset($merged[$id])) {
                    $merged[$id] = $row;

                    continue;
                }
                $target = &$merged[$id];
                foreach (['kind', 'spec', 'length_mm', 'hole', 'accounted_unit_weight_kg'] as $key) {
                    if ($target[$key] !== $row[$key]) {
                        throw new SupportLibraryException('构件信息不一致，无法合并：'.$id);
                    }
                }
                foreach (['quantity', 'bolt_quantity', 'bolt_joint_count'] as $key) {
                    $target[$key] = $target[$key] === null || $row[$key] === null ? null : SupportLibraryMath::add($target[$key], $row[$key]);
                }
                foreach (['weight_kg', 'theoretical_weight_kg', 'accounted_weight_kg', 'bolt_weight_kg'] as $key) {
                    $target[$key] = SupportLibraryMath::sum([$target[$key], $row[$key]]);
                }
                $target['sources'] = array_values(array_unique([...$target['sources'], ...$row['sources']]));
                $target['weight_formula'] = $target['accounted_weight_kg'] === null ? '待补' : $target['accounted_unit_weight_kg'].'×'.$target['quantity'].'＝'.$target['accounted_weight_kg'].' kg';
                unset($target);
            }
        }
        $result['merged'] = array_values($merged);
        $this->sortRows($result['merged']);
        foreach (['total_groups', 'total_columns', 'total_column_positions', 'total_braces', 'total_faces', 'total_quantity', 'matched_faces'] as $key) {
            $result[$key] = array_reduce(array_column($results, $key), fn (int $n, int $v) => SupportLibraryMath::add($n, $v), 0);
        }
        $result['bracing_complete'] = array_all($results, fn (array $r) => $r['bracing_complete']);
        foreach (['total_weight_kg', 'estimated_weight_kg', 'estimated_with_bolts_weight_kg', 'accounted_with_bolts_weight_kg', 'column_weight_kg', 'brace_theoretical_weight_kg', 'known_weight_kg', 'known_column_weight_kg', 'known_components_estimated_weight_kg'] as $key) {
            $result[$key] = SupportLibraryMath::sum(array_column($results, $key), str_starts_with($key, 'known_'));
        }
        foreach (['quantity', 'known_quantity', 'column_bolt_quantity', 'brace_bolt_quantity', 'known_brace_bolt_quantity', 'column_joint_count'] as $key) {
            $values = array_map(fn (array $r) => $r['bolts'][$key], $results);
            $result['bolts'][$key] = in_array(null, $values, true) ? null : array_reduce($values, fn (int $n, int $v) => SupportLibraryMath::add($n, $v), 0);
        }
        foreach (['weight_kg', 'known_weight_kg'] as $key) {
            $result['bolts'][$key] = SupportLibraryMath::sum(array_map(fn (array $r) => $r['bolts'][$key], $results));
        }
        $result['weight_profile'] = null;
        $result['weight_profiles'] = array_column($results, 'weight_profile');

        return $result;
    }

    private function frameKey(string $use, int $diameter, int $height, int $spacing): string
    {
        return implode('|', [$use, $diameter, $height, $spacing]);
    }

    private function sortRows(array &$rows): void
    {
        usort($rows, fn (array $a, array $b) => ($a['kind'] === '钢柱' ? 0 : 1) <=> ($b['kind'] === '钢柱' ? 0 : 1)
            ?: ($a['kind'] === '柱撑' && $b['kind'] === '柱撑' ? $a['length_mm'] <=> $b['length_mm'] : 0));
    }
}
