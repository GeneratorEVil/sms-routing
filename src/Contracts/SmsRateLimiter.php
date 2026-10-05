<?php

namespace SmsRouting\Contracts;

/**
 * Антифлуд-хранилище пакета.
 *
 * Пакет не знает, что именно он ограничивает: модель игрока, сессию
 * или IP. Приложение подставляет свою реализацию через
 * config('sms.rate_limiter') или биндинг в контейнере.
 *
 * Если реализация не задана, используется NullSmsRateLimiter —
 * отправка не ограничивается, но все вызовы SmsManager остаются
 * валидными.
 */
interface SmsRateLimiter
{
    /**
     * Слишком ли часто по этому получателю уже отправляли?
     *
     * @param  mixed  $notifiable  получатель: тот объект, который
     *                             приложение передало в SmsManager::send()
     */
    public function isThrottled(mixed $notifiable, int $minutes): bool;

    /**
     * Запомнить факт успешной отправки.
     */
    public function record(mixed $notifiable): void;

    /**
     * Когда последний раз отправляли — только для контекста в логах.
     */
    public function lastSentAt(mixed $notifiable): ?string;
}
