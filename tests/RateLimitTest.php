<?php

declare(strict_types=1);

namespace SmsRouting\Tests;

use SmsRouting\RateLimit\NullSmsRateLimiter;
use SmsRouting\Tests\Support\FakeGateway;
use SmsRouting\Tests\Support\TestCase;

/**
 * Антифлуд: правило живёт в приложении (SmsRateLimiter), пакет только
 * спрашивает «слишком ли часто» и записывает факт успешной отправки.
 */
final class RateLimitTest extends TestCase
{
    private const PHONE = '+380501234567';

    private object $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->smsSet('default', 'karix');
        $this->user = new class {
            public function getKey(): int
            {
                return 42;
            }
        };
    }

    public function test_successful_send_is_recorded(): void
    {
        $this->manager()->send(self::PHONE, 'hello', $this->user);

        $this->assertSame([$this->user], $this->rateLimiter->recorded);
    }

    public function test_failed_send_is_not_recorded(): void
    {
        $this->fake('karix')->behaviour = FakeGateway::BEHAVIOUR_FAILURE;

        try {
            $this->manager()->send(self::PHONE, 'hello', $this->user);
        } catch (\SmsRouting\Exceptions\SmsException) {
            // ожидаемо
        }

        $this->assertSame([], $this->rateLimiter->recorded, 'A failed attempt must not start the cooldown');
    }

    public function test_throttled_recipient_is_skipped(): void
    {
        $this->smsSet('rate_limit_minutes', 1);
        $this->rateLimiter->throttled = true;

        $result = $this->manager()->send(self::PHONE, 'hello', $this->user);

        $this->assertTrue($result->isSkipped());
        $this->assertSame('rate limit', $result->error);
        $this->assertSame(0, $this->fake('karix')->attemptsCount(), 'Throttled SMS must not reach the gateway');
        $this->assertSame([], $this->rateLimiter->recorded);
        $this->assertNotEmpty($this->logsWith('skipped by rate limit'));
    }

    public function test_limiter_receives_configured_window(): void
    {
        $this->smsSet('rate_limit_minutes', 15);

        $this->manager()->send(self::PHONE, 'hello', $this->user);

        $this->assertCount(1, $this->rateLimiter->checks);
        $this->assertSame(15, $this->rateLimiter->checks[0]['minutes']);
        $this->assertSame($this->user, $this->rateLimiter->checks[0]['notifiable']);
    }

    public function test_zero_window_disables_the_check(): void
    {
        $this->smsSet('rate_limit_minutes', 0);
        $this->rateLimiter->throttled = true;

        $result = $this->manager()->send(self::PHONE, 'hello', $this->user);

        $this->assertTrue($result->success);
        $this->assertSame([], $this->rateLimiter->checks, 'Limiter must not be consulted when the window is 0');
    }

    public function test_send_without_notifiable_is_never_throttled(): void
    {
        $this->smsSet('rate_limit_minutes', 5);
        $this->rateLimiter->throttled = true;

        $result = $this->manager()->send(self::PHONE, 'hello');

        $this->assertTrue($result->success);
        $this->assertSame([], $this->rateLimiter->checks);
    }

    public function test_notifiable_key_is_logged_for_eloquent_like_objects(): void
    {
        $this->smsSet('rate_limit_minutes', 1);
        $this->rateLimiter->throttled = true;

        $this->manager()->send(self::PHONE, 'hello', $this->user);

        $skipped = $this->logsWith('skipped by rate limit');
        $this->assertSame(42, $skipped[0]['context']['notifiable'] ?? null);
        $this->assertSame($this->rateLimiter->lastSentAt, $skipped[0]['context']['last_sent_at'] ?? null);
    }

    public function test_null_limiter_never_throttles(): void
    {
        $limiter = new NullSmsRateLimiter();

        $this->assertFalse($limiter->isThrottled($this->user, 5));
        $this->assertNull($limiter->lastSentAt($this->user));

        $limiter->record($this->user);

        $this->assertFalse($limiter->isThrottled($this->user, 5), 'NullSmsRateLimiter must stay a no-op');
    }
}
