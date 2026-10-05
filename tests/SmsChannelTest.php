<?php

declare(strict_types=1);

namespace SmsRouting\Tests;

use Illuminate\Notifications\Notification;
use InvalidArgumentException;
use SmsRouting\Channels\SmsChannel;
use SmsRouting\Contracts\HasSmsPayload;
use SmsRouting\Events\SmsSent;
use SmsRouting\Tests\Support\FakeGateway;
use SmsRouting\Tests\Support\TestCase;

/**
 * Канал уведомлений: заменяет одиннадцать отдельных каналов и знает
 * только про контракт HasSmsPayload.
 */
final class SmsChannelTest extends TestCase
{
    private const PHONE = '+380501234567';

    private function channel(): SmsChannel
    {
        return new SmsChannel($this->manager());
    }

    public function test_sends_plain_text_and_fires_event(): void
    {
        $this->smsSet('default', 'karix');
        $notification = new class extends Notification implements HasSmsPayload {
            public function toSmsPayload(mixed $notifiable): array
            {
                return ['phone' => '+380501234567', 'text' => 'Balance: 100'];
            }
        };

        $this->channel()->send(new \stdClass(), $notification);

        $this->assertSame('Balance: 100', $this->fake('karix')->lastText());

        $events = array_values(array_filter($this->dispatchedEvents, static fn(object $e): bool => $e instanceof SmsSent));
        $this->assertCount(1, $events, 'SmsSent must fire once');
        $this->assertSame('karix', $events[0]->result->gateway->value);
    }

    public function test_otp_notification_goes_through_send_otp(): void
    {
        $this->smsSet('default', 'karix');
        $this->smsSet('gateways.karix.otp_template', 'Code: %code');

        $notification = new class extends Notification implements HasSmsPayload {
            public function toSmsPayload(mixed $notifiable): array
            {
                return ['phone' => '+380501234567', 'text' => 'ignored', 'otp_code' => '1234'];
            }
        };

        $this->channel()->send(new \stdClass(), $notification);

        $this->assertSame('Code: 1234', $this->fake('karix')->lastText());
    }

    public function test_empty_otp_code_falls_back_to_plain_text(): void
    {
        $this->smsSet('default', 'karix');

        $notification = new class extends Notification implements HasSmsPayload {
            public function toSmsPayload(mixed $notifiable): array
            {
                return ['phone' => '+380501234567', 'text' => 'hello', 'otp_code' => '   '];
            }
        };

        $this->channel()->send(new \stdClass(), $notification);

        $this->assertSame('hello', $this->fake('karix')->lastText());
    }

    public function test_notification_without_payload_contract_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(HasSmsPayload::class);

        $this->channel()->send(new \stdClass(), new class extends Notification {});
    }

    public function test_missing_phone_is_rejected(): void
    {
        $notification = new class extends Notification implements HasSmsPayload {
            public function toSmsPayload(mixed $notifiable): array
            {
                return ['phone' => null, 'text' => 'hello'];
            }
        };

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('SMS phone is missing');

        $this->channel()->send(new \stdClass(), $notification);
    }

    public function test_missing_text_is_rejected(): void
    {
        $notification = new class extends Notification implements HasSmsPayload {
            public function toSmsPayload(mixed $notifiable): array
            {
                return ['phone' => '+380501234567'];
            }
        };

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('SMS text is missing');

        $this->channel()->send(new \stdClass(), $notification);
    }

    public function test_throttled_sms_is_not_an_error_and_fires_no_event(): void
    {
        $this->smsSet('default', 'karix');
        $this->smsSet('rate_limit_minutes', 1);
        $this->rateLimiter->throttled = true;

        $notification = new class extends Notification implements HasSmsPayload {
            public function toSmsPayload(mixed $notifiable): array
            {
                return ['phone' => '+380501234567', 'text' => 'hello'];
            }
        };

        $this->channel()->send(new \stdClass(), $notification);

        $this->assertSame(0, $this->fake('karix')->attemptsCount());
        $this->assertSame([], $this->dispatchedEvents);
        $this->assertNotEmpty($this->logsWith('SMS skipped'));
    }

    public function test_delivered_sms_is_logged(): void
    {
        $this->smsSet('default', 'karix');

        $notification = new class extends Notification implements HasSmsPayload {
            public function toSmsPayload(mixed $notifiable): array
            {
                return ['phone' => '+380501234567', 'text' => 'hello'];
            }
        };

        $this->channel()->send(new \stdClass(), $notification);

        $delivered = $this->logsWith('SMS delivered');
        $this->assertCount(1, $delivered);
        $this->assertSame('karix', $delivered[0]['context']['gateway'] ?? null);
        $this->assertSame('id-1', $delivered[0]['context']['message_id'] ?? null);
    }

    public function test_channel_passes_notifiable_to_the_rate_limiter(): void
    {
        $this->smsSet('default', 'karix');
        $this->smsSet('rate_limit_minutes', 3);

        $notifiable = new \stdClass();

        $notification = new class extends Notification implements HasSmsPayload {
            public function toSmsPayload(mixed $notifiable): array
            {
                return ['phone' => '+380501234567', 'text' => 'hello'];
            }
        };

        $this->channel()->send($notifiable, $notification);

        $this->assertSame([$notifiable], $this->rateLimiter->recorded, 'Channel must forward the notifiable as is');
        $this->assertInstanceOf(FakeGateway::class, $this->fake('karix'));
    }
}
