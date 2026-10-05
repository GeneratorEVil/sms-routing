<?php

namespace SmsRouting\Clients;

use SmsRouting\DTOs\Botto\SmsDetailDTO;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\RequestException;

class BottoClient
{
    protected $baseUrl;

    protected $username;

    protected $password;

    private $_logger;

    public function __construct()
    {
        $this->baseUrl = 'https://sms.botto.ai/';
        $this->username = config('services.smsbotto.username');
        $this->password = config('services.smsbotto.password');
        $this->_logger = Log::channel('smsbotto');
    }

    /**
     * Отправка SMS
     *
     * @param string $sender Отправитель SMS
     * @param string|array $recipient Номер(а) получателя
     * @param string $text Текст сообщения
     * @param bool $transliterate Переводить в транслит
     * @return array
     * @throws RequestException
     */
    public function sendSms(string $sender, $recipient, string $text, bool $transliterate = false, ?string $password = null): array
    {
        $recipients = is_array($recipient) ? implode(',', $recipient) : $recipient;

        $this->_logger->info('Отправка SMS', [
            'sender' => $sender,
            'recipients' => $recipients,
            'text' => $text,
            'transliterate' => $transliterate,
        ]);

        try {
            $response = Http::get("{$this->baseUrl}/sendsms.php", [
                'user' => $this->username,
                'pwd' => $password ?? $this->password,
                'sadr' => $sender,
                'dadr' => $recipients,
                'text' => $text,
                'translite' => $transliterate ? 1 : 0,
            ]);

            $response->throw();

            $this->_logger->info('SMS успешно отправлено', [
                'status' => $response->body(),
            ]);

            return [
                'status' => $response->body(),
            ];
        } catch (RequestException $e) {
            $this->_logger->error('Ошибка при отправке SMS', [
                'error' => $e->getMessage(),
                'recipients' => $recipients,
            ]);
            throw $e;
        }
    }

    /**
     * Проверка статуса SMS
     *
     * @param string $smsId ID SMS
     * @return array
     * @throws RequestException
     */
    public function checkSmsStatus(string $smsId): array
    {
        $this->_logger->info('Проверка статуса SMS', [
            'sms_id' => $smsId,
        ]);

        try {
            $response = Http::get("{$this->baseUrl}/sendsms.php", [
                'user' => $this->username,
                'pwd' => $this->password,
                'smsid' => $smsId,
            ]);

            $response->throw();

            $this->_logger->info('Статус SMS получен', [
                'sms_id' => $smsId,
                'status' => $response->body(),
            ]);

            return [
                'status' => $response->body(),
            ];
        } catch (RequestException $e) {
            $this->_logger->error('Ошибка при проверке статуса SMS', [
                'sms_id' => $smsId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Получение детальной информации о SMS
     *
     * @param string $smsId ID SMS
     * @return array
     * @throws RequestException
     */
    public function getSmsDetails(string $smsId): SmsDetailDTO
    {
        $this->_logger->info('Запрос детальной информации о SMS', [
            'sms_id' => $smsId,
        ]);

        try {
            $response = Http::get("{$this->baseUrl}/sendsms.php", [
                'user' => $this->username,
                'pwd' => $this->password,
                'smsid' => $smsId,
                'detail' => 1,
            ]);

            $response->throw();

            // Разбираем serialized PHP-массив
            $details = [];
            if ($response->body()) {
                parse_str($response->body(), $details);
            }

            $details = unserialize($response->body());

            $this->_logger->info('Детальная информация о SMS получена', [
                'sms_id' => $smsId,
                'details' => $details,
            ]);

            return new SmsDetailDTO($details);
        } catch (RequestException $e) {
            $this->_logger->error('Ошибка при получении детальной информации о SMS', [
                'sms_id' => $smsId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Проверка баланса
     *
     * @return array
     * @throws RequestException
     */
    public function checkBalance(): array
    {
        $this->_logger->info('Проверка баланса');

        try {
            $response = Http::get("{$this->baseUrl}/sendsms.php", [
                'user' => $this->username,
                'pwd' => $this->password,
                'balance' => 1,
            ]);

            $response->throw();

            $this->_logger->info('Баланс получен', [
                'balance' => $response->body(),
            ]);

            return [
                'balance' => $response->body(),
            ];
        } catch (RequestException $e) {
            $this->_logger->error('Ошибка при проверке баланса', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
