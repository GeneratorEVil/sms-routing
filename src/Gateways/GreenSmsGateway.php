<?php

namespace SmsRouting\Gateways;

use SmsRouting\Enum\SmsGateway;
use SmsRouting\DTOs\SmsResultDTO;
use GreenSMS\GreenSMS;

/**
 * GreenSMS SDK. Пакет greensms/greensms, экземпляр создаётся на каждый вызов.
 */
class GreenSmsGateway extends AbstractSmsGateway
{
    public static function sdkClass(): ?string
    {
        return GreenSMS::class;
    }

    protected function codeEnum(): SmsGateway
    {
        return SmsGateway::GREENSMS;
    }

    /**
     * @return array<string, string>
     */
    protected function credentialPaths(): array
    {
        return [
            'token' => 'services.greensms.token',
        ];
    }

    protected function apiKey(): ?string
    {
        return $this->credential('token');
    }

    public function send(string $phone, string $text, ?string $sender = null): SmsResultDTO
    {
        // GreenSMS ожидает номер без ведущего "+".
        $normalized = ltrim($phone, '+');

        try {
            $client = new GreenSMS(['token' => (string) $this->apiKey()]);

            $response = $client->sms->send([
                'to' => $normalized,
                'txt' => $text,
            ]);
        } catch (\Throwable $e) {
            $this->logFailure($phone, $e->getMessage());

            throw $e;
        }

        $result = SmsResultDTO::success(
            gateway: $this->codeEnum(),
            instance: $this->instance(),
            phone: $phone,
            messageId: isset($response->id) ? (string) $response->id : null,
            status: isset($response->status) ? (string) $response->status : 'sent',
            raw: is_object($response) ? get_object_vars($response) : $response,
        );

        $this->logSuccess($phone, $result);

        return $result;
    }
}
