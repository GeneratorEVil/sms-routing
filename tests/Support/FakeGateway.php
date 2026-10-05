<?php

declare(strict_types=1);

namespace SmsRouting\Tests\Support;

use SmsRouting\DTOs\SmsResultDTO;
use SmsRouting\Enum\SmsGateway;
use SmsRouting\Exceptions\SmsException;
use SmsRouting\Gateways\AbstractSmsGateway;

/**
 * Фейковый шлюз: сам ничего не отправляет, только запоминает попытки.
 * Поведение (успех / мягкий отказ / исключение / нет ключа) задаёт тест.
 */
class FakeGateway extends AbstractSmsGateway
{
    public const BEHAVIOUR_SUCCESS = 'success';

    public const BEHAVIOUR_FAILURE = 'failure';

    public const BEHAVIOUR_THROW = 'throw';

    /** @var array<int, array{phone: string, text: string, sender: ?string}> */
    public array $attempts = [];

    public string $behaviour = self::BEHAVIOUR_SUCCESS;

    public bool $hasKey = true;

    /** Провайдеры вроде SMSC читают ключи внутри SDK и не умеют per-instance. */
    public bool $supportsInstanceCredentials = true;

    public function __construct(private readonly SmsGateway $code)
    {
    }

    protected function codeEnum(): SmsGateway
    {
        return $this->code;
    }

    protected function credentialPaths(): array
    {
        return ['api_key' => 'services.fake.api_key'];
    }

    protected function apiKey(): ?string
    {
        return $this->hasKey ? 'fake-key' : null;
    }

    public function supportsInstanceCredentials(): bool
    {
        return $this->supportsInstanceCredentials;
    }

    public function send(string $phone, string $text, ?string $sender = null): SmsResultDTO
    {
        $this->attempts[] = ['phone' => $phone, 'text' => $text, 'sender' => $sender];

        return match ($this->behaviour) {
            self::BEHAVIOUR_FAILURE => SmsResultDTO::failure($this->codeEnum(), 'fake soft failure', $phone, ['raw' => 1]),
            self::BEHAVIOUR_THROW => throw new SmsException('fake hard failure'),
            default => SmsResultDTO::success(
                gateway: $this->codeEnum(),
                phone: $phone,
                messageId: 'id-' . count($this->attempts),
                instance: $this->instance(),
            ),
        };
    }

    public function attemptsCount(): int
    {
        return count($this->attempts);
    }

    public function lastText(): ?string
    {
        return $this->attempts === [] ? null : $this->attempts[count($this->attempts) - 1]['text'];
    }
}
