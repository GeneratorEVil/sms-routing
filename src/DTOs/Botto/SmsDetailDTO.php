<?php

namespace SmsRouting\DTOs\Botto;

/**
 * Детальная информация об SMS от Botto.
 *
 * Botto отдаёт её как сериализованный PHP-массив, поэтому значения
 * приходят строками и без типов. Валидация из старого App\DTOs\DTO
 * здесь не воспроизводится намеренно: отсутствующие поля не должны
 * ронять разбор ответа провайдера.
 */
class SmsDetailDTO
{
    public function __construct(
        public readonly ?string $id = null,
        public readonly ?string $timeChangeState = null,
        public readonly ?string $state = null,
        public readonly ?int $parts = null,
        public readonly ?float $price = null,
        public readonly ?int $sync = null,
        public readonly array $raw = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data  результат unserialize() ответа Botto
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: self::stringOrNull($data['id_sms'] ?? null),
            timeChangeState: self::stringOrNull($data['time_change_state'] ?? null),
            state: self::stringOrNull($data['state_sms'] ?? null),
            parts: isset($data['num_parts']) ? (int) $data['num_parts'] : null,
            price: isset($data['price']) ? (float) $data['price'] : null,
            sync: isset($data['sync']) ? (int) $data['sync'] : null,
            raw: $data,
        );
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }
}
