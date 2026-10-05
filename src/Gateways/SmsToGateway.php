<?php

namespace SmsRouting\Gateways;

use SmsRouting\Enum\SmsGateway;
use SmsRouting\DTOs\SmsResultDTO;
use Intergo\SmsTo\Facades\SmsToSms;
use Intergo\SmsTo\Module\Sms\Message\SingleMessage;

/**
 * SMS.to SDK (пакет sms.to-laravel-lumen).
 */
class SmsToGateway extends AbstractSmsGateway
{
    public static function sdkClass(): ?string
    {
        return SmsToSms::class;
    }

    protected function codeEnum(): SmsGateway
    {
        return SmsGateway::SMSTO;
    }

    /**
     * @return array<string, string>
     */
    /**
     * Пакет sms.to-lumen читает ключ из config('smsto.*') внутри себя
     * и не даёт передать его на вызов, поэтому разделить аккаунты
     * на инстансы нельзя.
     */
    public function supportsInstanceCredentials(): bool
    {
        return false;
    }

    protected function credentialPaths(): array
    {
        return [
            'api_key' => 'smsto.api_key',
        ];
    }

    protected function apiKey(): ?string
    {
        return $this->credential('api_key');
    }

    public function send(string $phone, string $text, ?string $sender = null): SmsResultDTO
    {
        try {
            $message = new SingleMessage();
            $message->setTo($phone)->setMessage($text);

            $response = SmsToSms::send($message);
        } catch (\Throwable $e) {
            $this->logFailure($phone, $e->getMessage());

            throw $e;
        }

        $result = SmsResultDTO::success(
            gateway: $this->codeEnum(),
            instance: $this->instance(),
            phone: $phone,
            messageId: is_object($response) && isset($response->messageId) ? (string) $response->messageId : null,
            status: is_object($response) && isset($response->error) ? null : 'sent',
            raw: is_object($response) ? get_object_vars($response) : $response,
        );

        if ( ! $result->success) {
            $this->logFailure($phone, (string) ($result->error ?? 'unknown error'));
        }

        $this->logSuccess($phone, $result);

        return $result;
    }
}
