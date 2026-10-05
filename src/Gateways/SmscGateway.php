<?php

namespace SmsRouting\Gateways;

use SmsRouting\Enum\SmsGateway;
use SmsRouting\DTOs\SmsResultDTO;
use SmsRouting\Clients\SmscClient;

/**
 * SMSC.RU. Использует curl напрямую (не Laravel Http), поэтому
 * для тестов подменяется сам класс шлюза, а не HTTP-фабрика.
 */
class SmscGateway extends AbstractSmsGateway
{
    public function __construct(private readonly SmscClient $api) {}

    protected function codeEnum(): SmsGateway
    {
        return SmsGateway::SMSC;
    }

    /**
     * @return array<string, string>
     */
    /**
     * SMSC пока не поддерживает per-instance credentials: адаптер
     * получает готовый SmscClient из контейнера, а значит один на весь
     * процесс. Исторически креды читались из define() на уровне файла,
     * из-за чего разделить аккаунты было нельзя.
     *
     * Теперь SmscClient держит креды в состоянии экземпляра, поэтому
     * ограничение снимаемое: достаточно создавать SmscClient с ключами
     * инстанса внутри send() и вернуть здесь true. Сделано отдельно
     * от переезда пакета, чтобы не менять поведение существующих
     * маршрутов вместе с местоположением кода.
     */
    public function supportsInstanceCredentials(): bool
    {
        return false;
    }

    protected function credentialPaths(): array
    {
        return [
            'password' => 'services.smscru.password',
            'login' => 'services.smscru.login',
        ];
    }

    protected function apiKey(): ?string
    {
        return $this->credential('password') ?: $this->credential('login');
    }

    public function send(string $phone, string $text, ?string $sender = null): SmsResultDTO
    {
        try {
            // SMSC ожидает номера без ведущего "+".
            $response = $this->api->send_sms(
                ltrim($phone, '+'),
                $text,
                0,
                0,
                0,
                0,
                $sender ?? $this->sender() ?? false,
            );
        } catch (\Throwable $e) {
            $this->logFailure($phone, $e->getMessage());

            throw $e;
        }

        return $this->interpret($phone, $response);
    }

    /**
     * SMSC отвечает либо [id, cnt, cost, balance] при успехе,
     * либо [id, -errorCode] при ошибке.
     */
    private function interpret(string $phone, array $response): SmsResultDTO
    {
        $id = $response[0] ?? null;
        $parts = isset($response[1]) ? (int) $response[1] : 0;

        if ($parts <= 0) {
            $errorCode = (int) ($response[1] ?? 0);
            $error = 'SMSC error code: ' . $errorCode;

            $this->logFailure($phone, $error);

            return SmsResultDTO::failure($this->codeEnum(), $error, $phone, $response);
        }

        $result = SmsResultDTO::success(
            gateway: $this->codeEnum(),
            instance: $this->instance(),
            phone: $phone,
            messageId: is_scalar($id) ? (string) $id : null,
            status: 'sent',
            cost: isset($response[2]) ? (float) $response[2] : null,
            raw: $response,
        );

        $this->logSuccess($phone, $result);

        return $result;
    }
}
