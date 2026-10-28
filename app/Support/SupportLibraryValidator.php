<?php

namespace App\Support;

class SupportLibraryValidator
{
    public function validate(array $db): array
    {
        if (($db['schema_version'] ?? null) !== 'user-library-1') {
            $this->fail('数据库格式不正确。');
        }
        foreach (['parts', 'frames', 'composition'] as $section) {
            if (! isset($db[$section]) || ! is_array($db[$section]) || ! array_is_list($db[$section])) {
                $this->fail($section.'必须为记录数组。', $section);
            }
            if (count($db[$section]) > 20000) {
                $this->fail($section.'最多20000条记录。', $section);
            }
        }
        $ids = $keys = [];
        foreach ($db['parts'] as $i => &$part) {
            $path = 'parts.'.$i;
            $this->id($part, $ids, $path);
            if (! in_array($part['kind'] ?? null, ['钢柱', '柱撑'], true) || ! in_array($part['spec'] ?? null, $part['kind'] === '钢柱' ? ['φ273', 'φ530'] : ['C'], true)) {
                $this->fail('类别与规格不对应。', $path.'.spec');
            }
            SupportLibraryMath::count($part['length_mm'] ?? null, '长度mm', true);
            $weight = $part['unit_weight_kg'] ?? null;
            if ($weight !== null && ((! is_int($weight) && ! is_float($weight)) || ! is_finite((float) $weight) || $weight <= 0)) {
                $this->fail('单重须大于0，未知请留空。', $path.'.unit_weight_kg');
            }
            $part['unit_weight_kg'] = $weight;
            $part['bolts_per_piece_raw'] ??= null;
            if ($part['bolts_per_piece_raw'] !== null) {
                SupportLibraryMath::count($part['bolts_per_piece_raw'], '配套螺栓');
            }
            if (! in_array($part['hole'] ?? null, ['未注明中孔', '带中孔'], true)) {
                $this->fail('请选择孔位。', $path.'.hole');
            }
            $this->text($part['note'] ?? '', $path.'.note');
            $key = $this->partKey($part);
            if (isset($keys[$key])) {
                $this->fail('同规格、长度、孔位构件重复：'.$key, $path);
            }
            $keys[$key] = true;
            $part['models'] = $part['kind'] === '柱撑' ? ['C14A', 'C16A'] : [];
        }
        unset($part);
        $parts = array_column($db['parts'], null, 'id');
        $ids = $keys = [];
        foreach ($db['frames'] as $i => &$frame) {
            $path = 'frames.'.$i;
            $this->id($frame, $ids, $path);
            if (! in_array($frame['support_use'] ?? null, ['吊装', '顶推'], true) || ! in_array($frame['diameter_mm'] ?? null, [273, 530], true)) {
                $this->fail('用途或钢柱直径不正确。', $path);
            }
            SupportLibraryMath::count($frame['height_mm'] ?? null, '高度mm', true);
            SupportLibraryMath::count($frame['spacing_mm'] ?? null, '间距mm', true);
            $key = $this->frameKey($frame);
            if (isset($keys[$key])) {
                $this->fail('搭配条件重复：'.$key, $path);
            }
            $keys[$key] = true;
            if (! array_any($db['parts'], fn (array $p) => $p['kind'] === '钢柱' && $p['spec'] === 'φ'.$frame['diameter_mm'] && $p['length_mm'] === $frame['height_mm'])) {
                $this->fail('请先补充对应规格和高度的钢柱。', $path);
            }
            $frame['name'] = 'φ'.$frame['diameter_mm'].'-'.$frame['height_mm'] / 1000 .'m';
            $frame['column_brace_models'] = ['C14A', 'C16A'];
        }
        unset($frame);
        $frames = array_column($db['frames'], null, 'id');
        $keys = $used = [];
        foreach ($db['composition'] as $i => &$line) {
            $path = 'composition.'.$i;
            if (! is_array($line)) {
                $this->fail('组成记录必须为对象。', $path);
            }
            $frameId = $line['frame_id'] ?? '';
            $partId = $line['part_id'] ?? '';
            if (! isset($frames[$frameId], $parts[$partId]) || $parts[$partId]['kind'] !== '柱撑') {
                $this->fail('组成须关联已有搭配和柱撑。', $path);
            }
            SupportLibraryMath::count($line['per_frame'] ?? null, '每面数量', true);
            $key = $frameId.'/'.$partId;
            if (isset($keys[$key])) {
                $this->fail('同一搭配的构件重复，请整理为一行。', $path);
            }
            $keys[$key] = $used[$frameId] = true;
            $line = [...$line, 'length_mm' => $parts[$partId]['length_mm'], 'hole' => $parts[$partId]['hole'], 'per_face' => $line['per_frame'], 'unit' => '根', 'unresolved_key' => null];
        }
        unset($line);
        foreach ($frames as $id => $frame) {
            if (! isset($used[$id])) {
                $this->fail($id.'至少需要一条柱撑组成。', 'composition');
            }
        }
        $db['bolt_rule']['unit_weight_kg'] = 0.44;

        return $db;
    }

    public function partKey(array $part): string
    {
        return implode('|', [$part['kind'], $part['spec'], $part['length_mm'], $part['kind'] === '柱撑' ? $part['hole'] : '']);
    }

    public function frameKey(array $frame): string
    {
        return implode('|', [$frame['support_use'], $frame['diameter_mm'], $frame['height_mm'], $frame['spacing_mm']]);
    }

    private function id(mixed $row, array &$ids, string $path): void
    {
        if (! is_array($row) || ! is_string($row['id'] ?? null) || trim($row['id']) === '' || mb_strlen($row['id']) > 100 || isset($ids[$row['id']])) {
            $this->fail('编号为空、过长或重复。', $path.'.id');
        }
        $ids[$row['id']] = true;
    }

    private function text(mixed $value, string $path): void
    {
        if (! is_string($value) || mb_strlen($value) > 10000) {
            $this->fail('备注须为10000字以内文本。', $path);
        }
    }

    private function fail(string $message, ?string $path = null): never
    {
        throw new SupportLibraryException($message, context: ['fieldPath' => $path]);
    }
}
