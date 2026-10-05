<?php

namespace SmsRouting\Contracts;

/**
 * Уведомление, отправляемое через SMS.
 *
 * Позволяет единому SmsChannel не знать про конкретные уведомления:
 * канал получает из payload телефон и признак, что именно отправлять.
 */
interface HasSmsPayload
{
    /**
     * phone    — номер получателя.
     * text     — готовый текст для обычного SMS.
     * otp_code — код подтверждения. Если задан, канал вызывает
     *            SmsManager::sendOtp(), и текст собирает шлюз,
     *            который реально отправит SMS, по своему шаблону.
     *            Иначе канал вызывает SmsManager::send().
     *
     * @return array{phone: string|null, text: string, otp_code?: string}
     */
    public function toSmsPayload(mixed $notifiable): array;
}
