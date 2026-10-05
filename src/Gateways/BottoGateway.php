<?php

namespace SmsRouting\Gateways;

use SmsRouting\Enum\SmsGateway;
use SmsRouting\DTOs\SmsResultDTO;
use SmsRouting\Clients\BottoClient;

/**
 * SMS: Botto. Сигнатура сервиса sendSms(sender, recipient, text) —
 * порядок аргументов обратный относительно остальных шлюзов.
 */
class BottoGateway extends AbstractSmsGateway
{
    public function __construct(private readonly BottoClient $api) {}

    protected function codeEnum(): SmsGateway
    {
        return SmsGateway::BOTTO;
    }

    /**
     * @return array<string, string>
     */
    protected function credentialPaths(): array
    {
        return [
            'password' => 'services.smsbotto.password',
        ];
    }

    protected function apiKey(): ?string
    {
        return $this->credential('password');
    }

    public function send(string $phone, string $text, ?string $sender = null): SmsResultDTO
    {
        try {
            $response = $this->api->sendSms($sender ?? $this->sender() ?? '', $phone, $text, $this->credential('password'));
        } catch (\Throwable $e) {
            $this->logFailure($phone, $e->getMessage());

            throw $e;
        }

        $result = SmsResultDTO::success(
            gateway: $this->codeEnum(),
            instance: $this->instance(),
            phone: $phone,
            messageId: isset($response['id']) ? (string) $response['id'] : null,
            status: isset($response['status']) ? (string) $response['status'] : null,
            raw: $response,
        );

        $this->logSuccess($phone, $result);

        return $result;
    }
}
