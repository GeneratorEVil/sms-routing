<?php

namespace SmsRouting\Gateways;

use SmsRouting\Enum\SmsGateway;
use SmsRouting\DTOs\SmsResultDTO;
use SmsRouting\Clients\SigmaSmsClient;

class SigmasmsGateway extends AbstractSmsGateway
{
    public function __construct(private readonly SigmaSmsClient $api) {}

    protected function codeEnum(): SmsGateway
    {
        return SmsGateway::SIGMASMS;
    }

    /**
     * @return array<string, string>
     */
    protected function credentialPaths(): array
    {
        return [
            'token' => 'services.sigmasms.token',
        ];
    }

    protected function apiKey(): ?string
    {
        return $this->credential('token');
    }

    public function send(string $phone, string $text, ?string $sender = null): SmsResultDTO
    {
        try {
            $response = $this->api->sendSms($phone, $text, $sender ?? $this->sender(), $this->credential('token'));
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
