<?php

namespace App\Sync\Services;

use App\Models\RehydrationSignal;
use App\Models\User;

class RehydrationService
{
    public function raiseForUsers(array $userIds, string $reason): void
    {
        $token = now()->toIso8601String();

        foreach ($userIds as $userId) {
            RehydrationSignal::create([
                'user_id' => $userId,
                'token' => $token,
                'reason' => $reason,
            ]);
        }
    }

    public function latestToken(User $user): ?string
    {
        return RehydrationSignal::applicableTo($user->id)->max('token');
    }

    public function latestReason(User $user): ?string
    {
        $token = $this->latestToken($user);

        if ($token === null) {
            return null;
        }

        return RehydrationSignal::applicableTo($user->id)
            ->where('token', $token)
            ->latest('id')
            ->value('reason');
    }
}
