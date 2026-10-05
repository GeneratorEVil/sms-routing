<?php

namespace SmsRouting\Clients;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\RequestException;

class LaafficClient
{
    protected $baseUrl;

    protected $apiKey;

    protected $apiSecret;

    protected $appId;

    private $_logger;

    public function __construct()
    {
        $this->baseUrl = config('services.laaffic.base_url', 'https://api.laaffic.com/v3/');
        $this->apiKey = config('services.laaffic.api_key');
        $this->appId = config('services.laaffic.app_id');
        $this->apiSecret = config('services.laaffic.api_secret');
        $this->_logger = Log::channel('laaffic');
    }

    /**
     * Generate signature for API authentication
     *
     * @param int $timestamp
     * @return string
     */
    private function generateSignature(int $timestamp, ?string $apiKey = null, ?string $appSecret = null): string
    {
        $signatureString = ($apiKey ?? $this->apiKey) . ($appSecret ?? $this->apiSecret) . $timestamp;

        return md5($signatureString);
    }

    /**
     * Send SMS
     *
     * @param string $recipient Phone number to send SMS to (multiple numbers separated by comma)
     * @param string $text Message text
     * @param string|null $senderId Sender ID/name (optional)
     * @param string|null $orderId Customer order ID (optional)
     * @return array
     * @throws RequestException
     */
    public function sendSms(
        string $recipient,
        string $text,
        ?string $senderId = null,
        ?string $orderId = null,
        ?string $apiKey = null,
        ?string $appId = null,
        ?string $appSecret = null,
    ): array {
        $timestamp = time();
        $signature = $this->generateSignature($timestamp, $apiKey, $appSecret);

        $this->_logger->info('Sending SMS via Laaffic', [
            'recipient' => $recipient,
            'text' => $text,
            'senderId' => $senderId,
            'orderId' => $orderId,
        ]);

        $data = [
            'appId' => $appId ?? $this->appId,
            'numbers' => $recipient,
            'content' => $text,
        ];

        if ($senderId) {
            $data['senderId'] = $senderId;
        }

        if ($orderId) {
            $data['orderId'] = $orderId;
        }

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json;charset=UTF-8',
                'Sign' => $signature,
                'Timestamp' => $timestamp,
                'Api-Key' => $apiKey ?? $this->apiKey,
            ])->post($this->baseUrl . 'sendSms', $data);

            $response->throw();

            $responseData = $response->json();

            $this->_logger->info('SMS sent successfully', [
                'response' => $responseData,
            ]);

            if ($responseData['status'] !== '0') {
                $this->_logger->error('Laaffic API error', [
                    'status' => $responseData['status'],
                    'reason' => $responseData['reason'] ?? 'Unknown error',
                ]);

                throw new \Exception('Laaffic API error: ' . ($responseData['reason'] ?? 'Unknown error'));
            }

            return $responseData;
        } catch (RequestException $e) {
            $this->_logger->error('Error sending SMS via Laaffic', [
                'error' => $e->getMessage(),
                'recipient' => $recipient,
            ]);

            throw $e;
        } catch (\Exception $e) {
            $this->_logger->error('Error sending SMS via Laaffic', [
                'error' => $e->getMessage(),
                'recipient' => $recipient,
            ]);

            throw $e;
        }
    }

    /**
     * Get SMS report by msgId
     *
     * @param string $msgIds Message IDs to query (multiple IDs separated by comma)
     * @return array
     * @throws RequestException
     */
    public function getReport(string $msgIds): array
    {
        $timestamp = time();
        $signature = $this->generateSignature($timestamp);

        $this->_logger->info('Getting SMS report', [
            'msg_ids' => $msgIds,
        ]);

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json;charset=UTF-8',
                'Sign' => $signature,
                'Timestamp' => $timestamp,
                'Api-Key' => $this->apiKey,
            ])->get($this->baseUrl . 'getReport', [
                'appId' => $this->appId,
                'msgIds' => $msgIds,
            ]);

            $response->throw();

            $responseData = $response->json();

            $this->_logger->info('SMS report retrieved', [
                'msg_ids' => $msgIds,
                'response' => $responseData,
            ]);

            if ($responseData['status'] !== '0') {
                $this->_logger->error('Laaffic API error', [
                    'status' => $responseData['status'],
                    'reason' => $responseData['reason'] ?? 'Unknown error',
                ]);

                throw new \Exception('Laaffic API error: ' . ($responseData['reason'] ?? 'Unknown error'));
            }

            return $responseData;
        } catch (RequestException $e) {
            $this->_logger->error('Error getting SMS report via Laaffic', [
                'error' => $e->getMessage(),
                'msg_ids' => $msgIds,
            ]);

            throw $e;
        } catch (\Exception $e) {
            $this->_logger->error('Error getting SMS report via Laaffic', [
                'error' => $e->getMessage(),
                'msg_ids' => $msgIds,
            ]);

            throw $e;
        }
    }

    /**
     * Check SMS status (convenience method that wraps getReport)
     *
     * @param string $smsId ID of the SMS
     * @return array
     * @throws RequestException
     */
    public function checkSmsStatus(string $smsId): array
    {
        return $this->getReport($smsId);
    }

    /**
     * Get SMS report by time slot
     *
     * @param string $startTime Beginning time of query, ISO8601 standard time format (e.g. '2021-02-12T00:00:00+08:00')
     * @param string $endTime Ending time of query, ISO8601 standard time format (e.g. '2021-02-12T23:59:59+08:00')
     * @param int $startIndex The starting subscript of the query (default 0)
     * @return array
     * @throws RequestException
     */
    public function getSendRcd(string $startTime, string $endTime, int $startIndex = 0): array
    {
        $timestamp = time();
        $signature = $this->generateSignature($timestamp);

        $this->_logger->info('Getting SMS report by time slot', [
            'start_time' => $startTime,
            'end_time' => $endTime,
            'start_index' => $startIndex,
        ]);

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json;charset=UTF-8',
                'Sign' => $signature,
                'Timestamp' => $timestamp,
                'Api-Key' => $this->apiKey,
            ])->get($this->baseUrl . 'getSentRcd', [
                'appId' => $this->appId,
                'startTime' => urlencode($startTime),
                'endTime' => urlencode($endTime),
                'startIndex' => $startIndex,
            ]);

            $response->throw();

            $responseData = $response->json();

            $this->_logger->info('SMS report by time slot retrieved', [
                'start_time' => $startTime,
                'end_time' => $endTime,
                'response' => $responseData,
            ]);

            if ($responseData['status'] !== '0') {
                $this->_logger->error('Laaffic API error', [
                    'status' => $responseData['status'],
                    'reason' => $responseData['reason'] ?? 'Unknown error',
                ]);

                throw new \Exception('Laaffic API error: ' . ($responseData['reason'] ?? 'Unknown error'));
            }

            return $responseData;
        } catch (RequestException $e) {
            $this->_logger->error('Error getting SMS report by time slot via Laaffic', [
                'error' => $e->getMessage(),
                'start_time' => $startTime,
                'end_time' => $endTime,
            ]);

            throw $e;
        } catch (\Exception $e) {
            $this->_logger->error('Error getting SMS report by time slot via Laaffic', [
                'error' => $e->getMessage(),
                'start_time' => $startTime,
                'end_time' => $endTime,
            ]);

            throw $e;
        }
    }

    /**
     * Get account balance
     *
     * @return array
     * @throws RequestException
     */
    public function checkBalance(): array
    {
        $timestamp = time();
        $signature = $this->generateSignature($timestamp);

        $this->_logger->info('Checking account balance');

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json;charset=UTF-8',
                'Sign' => $signature,
                'Timestamp' => $timestamp,
                'Api-Key' => $this->apiKey,
            ])->get($this->baseUrl . 'getBalance');

            $response->throw();

            $responseData = $response->json();

            $this->_logger->info('Account balance checked', [
                'response' => $responseData,
            ]);

            if ($responseData['status'] !== '0') {
                $this->_logger->error('Laaffic API error', [
                    'status' => $responseData['status'],
                    'reason' => $responseData['reason'] ?? 'Unknown error',
                ]);

                throw new \Exception('Laaffic API error: ' . ($responseData['reason'] ?? 'Unknown error'));
            }

            return $responseData;
        } catch (RequestException $e) {
            $this->_logger->error('Error checking account balance via Laaffic', [
                'error' => $e->getMessage(),
            ]);

            throw $e;
        } catch (\Exception $e) {
            $this->_logger->error('Error checking account balance via Laaffic', [
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
