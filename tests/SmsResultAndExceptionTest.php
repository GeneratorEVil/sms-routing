<?php

declare(strict_types=1);

namespace SmsRouting\Tests;

use SmsRouting\DTOs\SmsResultDTO;
use SmsRouting\Enum\SmsGateway;
use SmsRouting\Exceptions\SmsException;
use SmsRouting\Tests\Support\TestCase;

/**
 * DTO результата и исключение пакета: контракт, на который опирается
 * приложение (вебхук, SmsSent, алерты).
 */
final class SmsResultAndExceptionTest extends TestCase
{
    public function test_success_factory_fills_normalised_fields(): void
    {
        $result = SmsResultDTO::success(
            gateway: SmsGateway::SIGMASMS,
            phone: '+380501234567',
            messageId: 'abc-123',
            status: 'delivered',
            cost: 0.35,
            raw: ['id' => 7],
            instance: 'sigmasms_kz',
        );

        $this->assertTrue($result->success);
        $this->assertSame(SmsGateway::SIGMASMS, $result->gateway);
        $this->assertSame('abc-123', $result->messageId);
        $this->assertSame(0.35, $result->cost);
        $this->assertSame('sigmasms_kz', $result->instance);
        $this->assertNull($result->error);
        $this->assertFalse($result->isSkipped());
    }

    public function test_failure_factory_keeps_error_and_raw_payload(): void
    {
        $result = SmsResultDTO::failure(SmsGateway::KARIX, 'HTTP 500', '+380501234567', ['body' => 'oops']);

        $this->assertFalse($result->success);
        $this->assertSame('HTTP 500', $result->error);
        $this->assertSame(['body' => 'oops'], $result->raw);
        $this->assertNull($result->messageId);
    }

    public function test_skipped_is_not_a_failure_state(): void
    {
        $result = SmsResultDTO::skipped(SmsGateway::KARIX, 'rate limit', '+380501234567');

        $this->assertFalse($result->success, 'Skipped SMS did not fail');
        $this->assertTrue($result->isSkipped());
        $this->assertSame('skipped', $result->status);
        $this->assertSame('rate limit', $result->error);
    }

    public function test_to_array_is_json_serialisable_and_uses_enum_value(): void
    {
        $array = SmsResultDTO::success(gateway: SmsGateway::SMSC, messageId: '1')->toArray();

        $this->assertSame('smsc', $array['gateway']);
        $this->assertArrayHasKey('instance', $array);
        $this->assertIsString(json_encode($array));
    }

    public function test_exception_exposes_every_failure(): void
    {
        $previous = new \RuntimeException('connection reset');

        $exception = new SmsException('Failed to send SMS via karix, smsc', [
            'karix: HTTP 500',
            'smsc: connection reset',
        ], 0, $previous);

        $this->assertCount(2, $exception->failures());
        $this->assertSame($previous, $exception->getPrevious());
        $this->assertStringContainsString('karix, smsc', $exception->getMessage());
    }

    public function test_exception_gateways_lists_attempted_gateways(): void
    {
        $exception = new SmsException('nope', [
            'karix: not available (disabled or no credentials)',
            'smsc: HTTP 500',
            'typo: instance not registered',
        ]);

        // Название метода историческое: возвращает строки-имена инстансов.
        $this->assertSame(['karix', 'smsc', 'typo'], array_map('strval', $exception->gateways()));
    }

    public function test_exception_without_failures_is_allowed(): void
    {
        $exception = new SmsException('boom');

        $this->assertSame([], $exception->failures());
        $this->assertSame([], $exception->gateways());
    }

    public function test_enum_parses_config_strings_safely(): void
    {
        $this->assertSame(SmsGateway::KARIX, SmsGateway::tryFromString('  KARIx '));
        $this->assertNull(SmsGateway::tryFromString('smsk'), 'Typo in .env must not crash the process');
        $this->assertNull(SmsGateway::tryFromString(''));
        $this->assertNull(SmsGateway::tryFromString(null));
        $this->assertContains('smsc', SmsGateway::values());
    }
}
