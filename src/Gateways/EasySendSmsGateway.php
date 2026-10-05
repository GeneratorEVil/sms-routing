<?php

namespace SmsRouting\Gateways;

use SmsRouting\Enum\SmsGateway;
use SmsRouting\DTOs\SmsResultDTO;
use Illuminate\Support\Facades\Http;

/**
 * EasySendSMS (restapi.easysendsms.app).
 *
 * Намеренно самодостаточный: app/Services/EasySendSMSApiService.php пока
 * существует только на ветке origin/EasySendSMS. Когда ветка будет влита,
 * адаптер можно перевести на общий сервис.
 */
class EasySendSmsGateway extends AbstractSmsGateway
{
    protected const BASE_URL = 'https://restapi.easysendsms.app/v1/rest';

    protected function codeEnum(): SmsGateway
    {
        return SmsGateway::EASYSENDSMS;
    }

    /**
     * @return array<string, string>
     */
    protected function credentialPaths(): array
    {
        return [
            'api_key' => 'services.easysendsms.api_key',
        ];
    }

    protected function apiKey(): ?string
    {
        return $this->credential('api_key');
    }

    public function send(string $phone, string $text, ?string $sender = null): SmsResultDTO
    {
        $data = [
            'from' => $sender ?? $this->sender() ?? '',
            'to' => $this->normalizePhone($phone),
            'text' => $text,
            // 0 — plain text, 1 — Unicode.
            'type' => $this->isUnicode($text) ? '1' : '0',
        ];

        try {
            $response = Http::withHeaders([
                'apikey' => (string) $this->apiKey(),
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])
                ->timeout($this->timeout())
                ->post(self::BASE_URL . '/sms/send', $data);

            $response->throw();

            $payload = $response->json() ?? [];
        } catch (\Throwable $e) {
            $this->logFailure($phone, $e->getMessage());

            throw $e;
        }

        $result = SmsResultDTO::success(
            gateway: $this->codeEnum(),
            instance: $this->instance(),
            phone: $phone,
            messageId: isset($payload['id']) ? (string) $payload['id'] : null,
            status: 'sent',
            raw: $payload,
        );

        $this->logSuccess($phone, $result);

        return $result;
    }

    /**
     * EasySendSMS принимает номера без "+" и без префикса "00".
     */
    private function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        return $digits;
    }

    private function isUnicode(string $text): bool
    {
        return (bool) preg_match('/[^\x00-\x7F]/', $text);
    }
}
