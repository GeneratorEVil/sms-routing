<?php

namespace SmsRouting\Gateways;

use SmsRouting\Enum\SmsGateway;
use SmsRouting\DTOs\SmsResultDTO;
use SmsRouting\Clients\LaafficClient;

class LaafficGateway extends AbstractSmsGateway
{
    public function __construct(private readonly LaafficClient $api) {}

    protected function codeEnum(): SmsGateway
    {
        return SmsGateway::LAAFFIC;
    }

    /**
     * @return array<string, string>
     */
    protected function credentialPaths(): array
    {
        return [
            'api_key' => 'services.laaffic.api_key',
            'app_id' => 'services.laaffic.app_id',
            'app_secret' => 'services.laaffic.app_secret',
        ];
    }

    protected function apiKey(): ?string
    {
        return $this->credential('api_key');
    }

    public function send(string $phone, string $text, ?string $sender = null): SmsResultDTO
    {
        try {
            $response = $this->api->sendSms($phone, $text, $sender ?? $this->sender(), null, $this->credential('api_key'), $this->credential('app_id'), $this->credential('app_secret'));
        } catch (\Throwable $e) {
            $this->logFailure($phone, $e->getMessage());

            throw $e;
        }

        $result = SmsResultDTO::success(
            gateway: $this->codeEnum(),
            instance: $this->instance(),
            phone: $phone,
            messageId: isset($response['id']) ? (string) $response['id'] : null,
            status: 'sent',
            raw: $response,
        );

        $this->logSuccess($phone, $result);

        return $result;
    }
}
