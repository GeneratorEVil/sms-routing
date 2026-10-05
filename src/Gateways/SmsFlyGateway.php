<?php

namespace SmsRouting\Gateways;

use SmsRouting\Enum\SmsGateway;
use SmsRouting\DTOs\SmsResultDTO;
use Nekkoy\GatewayAbstract\DTO\MessageDTO;
use Nekkoy\GatewaySmsfly\DTO\ConfigDTO;
use Nekkoy\GatewaySmsfly\Services\SendMessageService;

/**
 * SMSFly через пакет nekkoy/gateway-smsfly. Конфиг берётся из config/gateway-smsfly.php.
 */
class SmsFlyGateway extends AbstractSmsGateway
{
    public static function sdkClass(): ?string
    {
        return SendMessageService::class;
    }

    protected function codeEnum(): SmsGateway
    {
        return SmsGateway::SMSFLY;
    }

    /**
     * @return array<string, string>
     */
    protected function credentialPaths(): array
    {
        return [
            'api_key' => 'gateway-smsfly.api_key',
        ];
    }

    protected function apiKey(): ?string
    {
        return $this->credential('api_key');
    }

    public function send(string $phone, string $text, ?string $sender = null): SmsResultDTO
    {
        try {
            $rawConfig = (array) config('gateway-smsfly', []);
            $apiKey = $this->credential('api_key');

            if ($apiKey !== null) {
                $rawConfig['api_key'] = $apiKey;
            }

            $config = new ConfigDTO($rawConfig);

            // Пакет ждёт DTO с полями destination/text, а не массив.
            $message = new MessageDTO($text, $phone);

            $service = new SmsFlyClient($config, $message);
            $service->setApiUrl(config('gateway-smsfly.api_url'));

            $response = $service->send();
        } catch (\Throwable $e) {
            $this->logFailure($phone, $e->getMessage());

            throw $e;
        }

        $code = is_object($response) && isset($response->code) ? (int) $response->code : null;

        // Пакет не бросает исключений на бизнес-ошибку, отдаёт их в code.
        if ($code !== null && $code !== 0 && $code !== 200) {
            $message = is_object($response) && isset($response->message)
                ? (string) $response->message
                : 'SMSFly error code: ' . $code;

            $this->logFailure($phone, $message);

            return SmsResultDTO::failure($this->codeEnum(), $message, $phone, is_object($response) ? get_object_vars($response) : $response);
        }

        $result = SmsResultDTO::success(
            gateway: $this->codeEnum(),
            instance: $this->instance(),
            phone: $phone,
            status: 'sent',
            raw: is_object($response) ? get_object_vars($response) : $response,
        );

        $this->logSuccess($phone, $result);

        return $result;
    }
}
