<?php

namespace SmsRouting\Clients;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Client\RequestException;

class KarixClient
{
    protected $baseUrl;

    protected $apiKey;

    protected $sender;

    private $_logger;

    public function __construct()
    {
        $this->baseUrl = config('services.karix.base_url', 'https://japi.instaalerts.zone/httpapi/JsonReceiver');
        $this->apiKey = config('services.karix.api_key');
        $this->sender = config('services.karix.sender');
        $this->_logger = Log::channel('karix');
    }

    /**
     * Send SMS via Karix JSON API
     *
     * @param string $recipient Phone number (international format, no leading '+')
     * @param string $text Message text
     * @param string|null $senderId Sender ID/name (optional, defaults to configured sender)
     * @return array
     * @throws RequestException
     */
    public function sendSms(string $recipient, string $text, ?string $senderId = null, ?string $apiKey = null): array
    {
        $senderId = $senderId ?? $this->sender;

        $this->_logger->info('Sending SMS via Karix', [
            'recipient' => $recipient,
            'text' => $text,
            'sender' => $senderId,
        ]);

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
            ])->post($this->baseUrl, [
                'ver' => '1.0',
                'key' => $apiKey ?? $this->apiKey,
                'encrpt' => '0',
                'messages' => [
                    [
                        'dest' => [$recipient],
                        'send' => $senderId,
                        'text' => $text,
                    ],
                ],
            ]);

            $response->throw();

            $responseData = $response->json() ?? [];

            $this->_logger->info('Karix SMS sent successfully', [
                'recipient' => $recipient,
                'response' => $responseData,
            ]);

            return $responseData;
        } catch (RequestException $e) {
            $this->_logger->error('Error sending SMS via Karix', [
                'error' => $e->getMessage(),
                'recipient' => $recipient,
                'status_code' => $e->response?->status(),
                'body' => $e->response?->body(),
            ]);

            throw $e;
        }
    }
}
