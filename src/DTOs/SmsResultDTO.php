<?php

namespace SmsRouting\DTOs;

use SmsRouting\Enum\SmsGateway;

/**
 * Результат отправки SMS одним шлюзом.
 *
 * Заполняется адаптером после успешной отправки. Нормализованные поля
 * (messageId/cost) заполняются только если шлюз их отдаёт.
 *
 * Намеренно не наследует никакой базовый DTO приложения: пакет
 * не должен зависеть от кода хоста.
 */
class SmsResultDTO
{
    public function __construct(
        public readonly bool $success,
        public readonly SmsGateway $gateway,
        public readonly ?string $phone = null,
        public readonly ?string $messageId = null,
        public readonly ?string $status = null,
        public readonly ?float $cost = null,
        public readonly ?string $error = null,
        public readonly array $raw = [],
        /**
         * Ключ инстанса, который отправил SMS. Совпадает с кодом
         * провайдера для инстанса по умолчанию и отличается, если
         * у провайдера несколько аккаунтов (sms.instances).
         */
        public readonly ?string $instance = null,
    ) {}

    public static function success(
        SmsGateway $gateway,
        ?string $phone = null,
        ?string $messageId = null,
        ?string $status = null,
        ?float $cost = null,
        array $raw = [],
        ?string $instance = null,
    ): self {
        return new self(
            success: true,
            gateway: $gateway,
            phone: $phone,
            messageId: $messageId,
            status: $status,
            cost: $cost,
            raw: $raw,
            instance: $instance,
        );
    }

    public static function failure(SmsGateway $gateway, string $error, ?string $phone = null, array $raw = [], ?string $instance = null): self
    {
        return new self(
            success: false,
            gateway: $gateway,
            phone: $phone,
            error: $error,
            raw: $raw,
            instance: $instance,
        );
    }

    /**
     * Отправка сознательно пропущена (антифлуд). Не ошибка: раньше каналы
     * в этом случае просто возвращались, не бросая исключение.
     */
    public static function skipped(SmsGateway $gateway, string $reason, ?string $phone = null): self
    {
        return new self(
            success: false,
            gateway: $gateway,
            phone: $phone,
            status: 'skipped',
            error: $reason,
        );
    }

    public function isSkipped(): bool
    {
        return $this->status === 'skipped';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'gateway' => $this->gateway->value,
            'instance' => $this->instance,
            'phone' => $this->phone,
            'message_id' => $this->messageId,
            'status' => $this->status,
            'cost' => $this->cost,
            'error' => $this->error,
            'raw' => $this->raw,
        ];
    }
}
