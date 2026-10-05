<?php

namespace SmsRouting\RateLimit;

use SmsRouting\Contracts\SmsRateLimiter;

/**
 * Антифлуд выключен: отправка не ограничивается.
 *
 * Значение по умолчанию. Приложение, у которого есть notion
 * "не чаще раза в N минут на пользователя", обязано подставить
 * свою реализацию SmsRateLimiter, иначе антифлуд из конфига
 * sms.rate_limit_minutes не сработает.
 */
class NullSmsRateLimiter implements SmsRateLimiter
{
    public function isThrottled(mixed $notifiable, int $minutes): bool
    {
        return false;
    }

    public function record(mixed $notifiable): void
    {
        // Нечего запоминать.
    }

    public function lastSentAt(mixed $notifiable): ?string
    {
        return null;
    }
}
