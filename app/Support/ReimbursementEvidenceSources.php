<?php

namespace App\Support;

use App\Models\ReimbursementUpload;
use Illuminate\Validation\ValidationException;

class ReimbursementEvidenceSources
{
    public function validate(array $items, bool $forSubmission = false): void
    {
        $seen = [];
        $numbers = [];
        foreach ($items as $item) {
            $source = $item['evidence_source'] ?? null;
            if ($source && ! in_array($source['upload_id'], $item['attachments'], true)) {
                throw ValidationException::withMessages(['items' => '费用来源必须是本项附件。']);
            }
            foreach ($item['attachments'] as $id) {
                $candidate = $source && $source['upload_id'] === $id ? $source['candidate_id'] : null;
                if ($candidate !== null) {
                    $upload = ReimbursementUpload::find($id);
                    $identified = collect($upload?->evidence_result['items'] ?? [])->firstWhere('id', $candidate);
                    if ($forSubmission && ($number = $identified['number'] ?? null)) {
                        if (isset($numbers[$number]) && $upload->evidence_status !== 'reviewed') {
                            throw ValidationException::withMessages(['items' => '存在相同凭证号，请核对并明确处理重复费用。']);
                        }
                        $numbers[$number] = true;
                    }
                    if (! collect($upload?->evidence_result['items'] ?? [])->contains('id', $candidate)) {
                        throw ValidationException::withMessages(['items' => '费用来源不存在，请重新核对附件。']);
                    }
                }
                if (isset($seen[$id]) && ($candidate === null || in_array(null, $seen[$id], true) || in_array($candidate, $seen[$id], true))) {
                    throw ValidationException::withMessages(['items' => '同一张凭证不能重复计入费用。']);
                }
                $seen[$id][] = $candidate;
            }
        }
    }
}
