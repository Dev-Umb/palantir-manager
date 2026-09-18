<?php

namespace App\Integrations\Feishu;

class FeishuRollout
{
    public function userId(): ?int
    {
        if ($this->userIds() !== []) {
            return null;
        }

        $userId = filter_var(config('services.feishu.rollout_user_id'), FILTER_VALIDATE_INT);

        return $userId && $userId > 0 ? (int) $userId : null;
    }

    /** @return array<int, int> */
    public function userIds(): array
    {
        $configured = config('services.feishu.rollout_user_ids');
        $values = is_array($configured) ? $configured : explode(',', (string) $configured);

        return collect($values)
            ->map(fn (mixed $userId): int|false => filter_var(trim((string) $userId), FILTER_VALIDATE_INT))
            ->filter(fn (int|false $userId): bool => $userId !== false && $userId > 0)
            ->map(fn (int|false $userId): int => (int) $userId)
            ->unique()
            ->values()
            ->all();
    }

    /** @return array<int, int> */
    public function allowedUserIds(): array
    {
        $rolloutUserIds = $this->userIds();
        if ($rolloutUserIds !== []) {
            return $rolloutUserIds;
        }

        $rolloutUserId = $this->userId();

        return $rolloutUserId === null ? [] : [$rolloutUserId];
    }

    public function allowsUser(int $userId): bool
    {
        $allowedUserIds = $this->allowedUserIds();

        return $allowedUserIds === [] || in_array($userId, $allowedUserIds, true);
    }

    public function isRestricted(): bool
    {
        return $this->allowedUserIds() !== [];
    }

    public function redirectsReminderDeliveries(): bool
    {
        return $this->userIds() === [] && $this->userId() !== null;
    }

    public function reminderDestinationUserId(int $intendedUserId): ?int
    {
        if ($this->redirectsReminderDeliveries()) {
            return $this->userId();
        }

        return $this->allowsUser($intendedUserId) ? $intendedUserId : null;
    }
}
