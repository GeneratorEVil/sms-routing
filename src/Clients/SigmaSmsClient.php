<?php

namespace SmsRouting\Clients;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Client\RequestException;

class SigmaSmsClient
{
    protected $baseUrl;

    protected $token;

    protected $sender;

    private $_logger;

    public function __construct()
    {
        $this->baseUrl = 'https://user.sigmasms.ru/api';
        $this->token = config('services.sigmasms.token');
        $this->sender = config('services.sigmasms.sender');
        $this->_logger = Log::channel('sigmasms');
    }

    /**
     * Отправка SMS
     *
     * @param string $recipient Номер получателя
     * @param string $text Текст сообщения
     * @param string|null $sender Имя отправителя (опционально)
     * @return array
     * @throws RequestException
     */
    public function sendSms(string $recipient, string $text, ?string $sender = null, ?string $token = null): array
    {
        $sender = $sender ?? $this->sender;

        $this->_logger->info('Отправка SMS', [
            'recipient' => $recipient,
            'text' => $text,
            'sender' => $sender,
        ]);

        try {
            $payload = [
                'recipient' => $recipient,
                'type' => 'sms',
                'payload' => [
                    'sender' => $sender,
                    'text' => $text,
                ],
            ];

            $this->_logger->info('Payload:', $payload);

            $headers = [
                'Authorization' => $token ?? $this->token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ];
            $response = Http::withHeaders($headers)->post("{$this->baseUrl}/sendings", $payload);

            $response->throw();

            $data = $response->json();

            $this->_logger->info('SMS успешно отправлено', [
                'id' => $data['id'] ?? null,
                'status' => $data['status'] ?? null,
                'headers' => $headers,
            ]);

            return [
                'id' => $data['id'] ?? null,
                'status' => $data['status'] ?? null,
                'error' => $data['error'] ?? null,
            ];
        } catch (RequestException $e) {
            $this->_logger->error('Ошибка при отправке SMS', [
                'error' => $e->getMessage(),
                'recipient' => $recipient,
                'status_code' => $e->response?->status(),
                'body' => $e->response?->body(),
            ]);
            throw $e;
        }
    }

    /**
     * Проверка статуса SMS
     *
     * @param string $messageId ID сообщения
     * @return array
     * @throws RequestException
     */
    public function getMessageStatus(string $messageId): array
    {
        $this->_logger->info('Проверка статуса SMS', [
            'message_id' => $messageId,
        ]);

        try {
            $response = Http::withHeaders([
                'Authorization' => $this->token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->get("{$this->baseUrl}/sendings/{$messageId}");

            $response->throw();

            $data = $response->json();

            $this->_logger->info('Статус SMS получен', [
                'message_id' => $messageId,
                'status' => $data['state']['status'] ?? null,
            ]);

            return [
                'id' => $data['id'] ?? null,
                'status' => $data['state']['status'] ?? null,
                'error' => $data['state']['error'] ?? null,
                'moderation' => $data['state']['moderation'] ?? null,
            ];
        } catch (RequestException $e) {
            $this->_logger->error('Ошибка при проверке статуса SMS', [
                'message_id' => $messageId,
                'error' => $e->getMessage(),
                'status_code' => $e->response?->status(),
            ]);
            throw $e;
        }
    }

    /**
     * Получение баланса
     *
     * @return float|null
     * @throws RequestException
     */
    public function getBalance(): ?float
    {
        $this->_logger->info('Проверка баланса');

        try {
            $response = Http::withHeaders([
                'Authorization' => $this->token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->get("{$this->baseUrl}/users/me");

            $response->throw();

            $data = $response->json();
            $balance = $data['balance'] ?? null;

            $this->_logger->info('Баланс получен', [
                'balance' => $balance,
            ]);

            return $balance;
        } catch (RequestException $e) {
            $this->_logger->error('Ошибка при проверке баланса', [
                'error' => $e->getMessage(),
                'status_code' => $e->response?->status(),
            ]);
            throw $e;
        }
    }
}
