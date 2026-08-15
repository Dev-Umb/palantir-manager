<?php

namespace App\Http\Requests;

use App\Support\BusinessWorkspace;
use Illuminate\Foundation\Http\FormRequest;

class PreviewProjectCustomerProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        $workspace = app(BusinessWorkspace::class);

        return $this->user()
            && ($workspace->isAdmin($this->user()) || $workspace->isBusiness($this->user()));
    }

    public function rules(): array
    {
        return self::profileRules();
    }

    /** @return array<string, array<int, string>> */
    public static function profileRules(string $prefix = ''): array
    {
        return [
            "{$prefix}customer_id" => ['nullable', 'uuid'],
            "{$prefix}name" => ['required', 'string', 'max:200'],
            "{$prefix}address" => ['nullable', 'string', 'max:500'],
            "{$prefix}level" => ['nullable', 'in:A,B,C'],
            "{$prefix}customer_nature" => ['nullable', 'in:国央企,私企'],
            "{$prefix}overwrite_confirmed" => ['nullable', 'boolean'],
            "{$prefix}contacts" => ['nullable', 'array', 'max:50'],
            "{$prefix}contacts.*" => ['array'],
            "{$prefix}contacts.*.id" => ['nullable', 'uuid'],
            "{$prefix}contacts.*.name" => ['required', 'string', 'max:100'],
            "{$prefix}contacts.*.phone" => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * @return array{customer_id: string|null, name: string, address: string, level: string, customer_nature: string, overwrite_confirmed: bool, contacts: array<int, array{id: string|null, name: string, phone: string}>}
     */
    public function profile(): array
    {
        return self::normalizeProfile($this->validated());
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{customer_id: string|null, name: string, address: string, level: string, customer_nature: string, overwrite_confirmed: bool, contacts: array<int, array{id: string|null, name: string, phone: string}>}
     */
    public static function normalizeProfile(array $validated): array
    {
        return [
            'customer_id' => $validated['customer_id'] ?? null,
            'name' => trim($validated['name']),
            'address' => trim((string) ($validated['address'] ?? '')),
            'level' => trim((string) ($validated['level'] ?? '')),
            'customer_nature' => trim((string) ($validated['customer_nature'] ?? '')),
            'overwrite_confirmed' => (bool) ($validated['overwrite_confirmed'] ?? false),
            'contacts' => collect($validated['contacts'] ?? [])
                ->map(fn (array $contact): array => [
                    'id' => $contact['id'] ?? null,
                    'name' => trim($contact['name']),
                    'phone' => trim((string) ($contact['phone'] ?? '')),
                ])
                ->values()
                ->all(),
        ];
    }
}
