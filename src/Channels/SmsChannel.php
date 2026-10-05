<?php

namespace SmsRouting\Channels;

use SmsRouting\Contracts\HasSmsPayload;
use SmsRouting\Events\SmsSent;
use SmsRouting\SmsManager;
use Illuminate\Support\Facades\Log;
use Illuminate\Notifications\Notification;

/**
 * Единый канал отправки SMS.
 *
 * Заменяет 11 отдельных каналов: выбор шлюза, антифлуд и failover
 * решает SmsManager, каналу остаётся только достать данные из
 * уведомления и сообщить приложению о доставке.
 *
 * Получатель уходит в SmsManager как есть — пакет не проверяет его
 * тип и не знает про модели приложения. Запись в журнал приложения
 * делает слушатель SmsSent.
 */
class SmsChannel
{
    public function __construct(private readonly SmsManager $sms) {}

    public function send(mixed $notifiable, Notification $notification): void
    {
        if ( ! $notification instanceof HasSmsPayload) {
            throw new \InvalidArgumentException('Notification ' . $notification::class . ' must implement ' . HasSmsPayload::class);
        }

        $payload = $notification->toSmsPayload($notifiable);
        $phone = $payload['phone'] ?? null;
        $text = $payload['text'] ?? null;
        $otpCode = $payload['otp_code'] ?? null;

        if ( ! $phone) {
            throw new \InvalidArgumentException('SMS phone is missing');
        }

        // OTP уходит кодом, а не готовым текстом: формулировку подставит
        // тот шлюз, который реально отправит, — это важно при failover.
        if (is_string($otpCode) && '' !== trim($otpCode)) {
            $result = $this->sms->sendOtp($phone, $otpCode, $notifiable);
        } else {
            if ( ! $text) {
                throw new \InvalidArgumentException('SMS text is missing');
            }

            $result = $this->sms->send($phone, $text, $notifiable);
        }

        // Антифлуд — штатная ситуация, ошибкой не считаем.
        if ($result->isSkipped()) {
            $this->logger()->info('SMS skipped', [
                'phone' => $phone,
                'reason' => $result->error,
            ]);

            return;
        }

        $this->logger()->info('SMS delivered', [
            'phone' => $phone,
            'gateway' => $result->gateway->value,
            'instance' => $result->instance,
            'message_id' => $result->messageId,
        ]);

        if ($notifiable !== null) {
            event(new SmsSent($notifiable, $result));
        }
    }

    private function logger(): \Psr\Log\LoggerInterface
    {
        return Log::channel((string) (config('sms.log_channel') ?: 'sms'));
    }
}
