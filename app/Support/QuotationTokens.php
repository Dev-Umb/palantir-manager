<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;
use Throwable;

class QuotationTokens
{
    public function issue(User $user, string $purpose, array $data): string
    {
        return Crypt::encryptString(json_encode(['user' => $user->id, 'purpose' => $purpose, 'expires' => now()->addHours(2)->timestamp, 'data' => $data], JSON_THROW_ON_ERROR));
    }

    public function read(User $user, string $purpose, string $token): array
    {
        try {
            $claims = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
            if ($claims['user'] !== $user->id || $claims['purpose'] !== $purpose || $claims['expires'] < now()->timestamp) {
                throw new \RuntimeException;
            }

            return $claims['data'];
        } catch (Throwable) {
            throw ValidationException::withMessages(['confirmation' => '确认已失效或不属于当前账号，请重新核对并确认。']);
        }
    }

    public function context(array $params): string
    {
        $fields = ['product', 'spec', 'unit', 'tax_rate', 'tax_basis', 'shipping', 'price_date', 'market', 'material', 'steel_spec'];
        $context = [];
        foreach ($fields as $field) {
            $context[$field] = (string) ($params[$field] ?? '');
        }

        return hash('sha256', json_encode($context, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}
