<?php

namespace SmsRouting\Gateways;

use SmsRouting\Enum\SmsGateway;
use SmsRouting\DTOs\SmsResultDTO;
use SmsRouting\Clients\KarixClient;

class KarixGateway extends AbstractSmsGateway
{
    public function __construct(private readonly KarixClient $api) {}

    protected function codeEnum(): SmsGateway
    {
        return SmsGateway::KARIX;
    }

    /**
     * @return array<string, string>
     */
    protected function credentialPaths(): array
    {
        return [
            'api_key' => 'services.karix.api_key',
        ];
    }

    protected function apiKey(): ?string
    {
        return $this->credential('api_key');
    }

    public function send(string $phone, string $text, ?string $sender = null): SmsResultDTO
    {
        try {
            $response = $this->api->sendSms($phone, $text, $sender ?? $this->sender(), $this->credential('api_key'));
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
