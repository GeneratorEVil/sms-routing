<?php

declare(strict_types=1);

namespace SmsRouting\Tests\Support;

use SmsRouting\Contracts\SmsRateLimiter;

/**
 * Антифлуд под контролем теста: решает, считать ли получателя
 * «слишком часто отправлявшим», и запоминает факт отправки.
 */
final class FakeRateLimiter implements SmsRateLimiter
{
    public bool $throttled = false;

    /** @var array<int, mixed> */
    public array $recorded = [];

    /** @var array<int, array{notifiable: mixed, minutes: int}> */
    public array $checks = [];

    public ?string $lastSentAt = '2026-01-01 00:00:00';

    public function isThrottled(mixed $notifiable, int $minutes): bool
    {
        $this->checks[] = ['notifiable' => $notifiable, 'minutes' => $minutes];

        return $this->throttled;
    }

    public function record(mixed $notifiable): void
    {
        $this->recorded[] = $notifiable;
    }

    public function lastSentAt(mixed $notifiable): ?string
    {
        return $this->lastSentAt;
    }
}
