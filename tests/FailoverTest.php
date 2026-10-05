<?php

declare(strict_types=1);

namespace SmsRouting\Tests;

use SmsRouting\Clients\SmsFlyClient;
use SmsRouting\Exceptions\SmsException;
use SmsRouting\Gateways\GreenSmsGateway;
use SmsRouting\Gateways\SmsFlyGateway;
use SmsRouting\Gateways\SmsToGateway;
use SmsRouting\SmsManager;
use SmsRouting\Tests\Support\FakeGateway;
use SmsRouting\Tests\Support\TestCase;

/**
 * Отправка и failover: мягкий отказ, исключение, недоступный шлюз,
 * неизвестный инстанс, отключённый failover.
 */
final class FailoverTest extends TestCase
{
    private const PHONE = '+380501234567';

    protected function setUp(): void
    {
        parent::setUp();

        $this->smsSet('default', 'karix,smsc');
        $this->smsSet('routes', []);
    }

    public function test_first_gateway_of_the_chain_sends(): void
    {
        $result = $this->manager()->send(self::PHONE, 'hello');

        $this->assertTrue($result->success);
        $this->assertSame('karix', $result->gateway->value);
        $this->assertSame('karix', $result->instance);
        $this->assertSame(1, $this->fake('karix')->attemptsCount());
        $this->assertSame(0, $this->fake('smsc')->attemptsCount());
    }

    public function test_soft_failure_falls_back_to_next_gateway(): void
    {
        $this->fake('karix')->behaviour = FakeGateway::BEHAVIOUR_FAILURE;

        $result = $this->manager()->send(self::PHONE, 'hello');

        $this->assertTrue($result->success);
        $this->assertSame('smsc', $result->gateway->value);
        $this->assertSame(1, $this->fake('karix')->attemptsCount());
        $this->assertSame(1, $this->fake('smsc')->attemptsCount());
    }

    public function test_exception_falls_back_to_next_gateway(): void
    {
        $this->fake('karix')->behaviour = FakeGateway::BEHAVIOUR_THROW;

        $result = $this->manager()->send(self::PHONE, 'hello');

        $this->assertTrue($result->success);
        $this->assertSame('smsc', $result->gateway->value);
        $this->assertNotEmpty($this->logsWith('trying next'));
    }

    public function test_gateway_without_credentials_is_skipped(): void
    {
        $this->fake('karix')->hasKey = false;

        $result = $this->manager()->send(self::PHONE, 'hello');

        $this->assertTrue($result->success);
        $this->assertSame('smsc', $result->gateway->value);
        $this->assertSame(0, $this->fake('karix')->attemptsCount());
    }

    public function test_chain_stops_after_first_success(): void
    {
        $this->smsSet('default', 'karix,smsc,botto');

        $this->manager()->send(self::PHONE, 'hello');

        $this->assertSame(1, $this->fake('karix')->attemptsCount());
        $this->assertSame(0, $this->fake('smsc')->attemptsCount());
        $this->assertSame(0, $this->fake('botto')->attemptsCount());
    }

    public function test_soft_failure_respects_disabled_failover(): void
    {
        // Регрессия: раньше break стоял только в catch, и при
        // SMS_FAILOVER_ENABLED=false мягкий отказ всё равно уходил
        // на второй шлюз.
        $this->smsSet('failover', false);
        $this->fake('karix')->behaviour = FakeGateway::BEHAVIOUR_FAILURE;

        try {
            $this->manager()->send(self::PHONE, 'hello');
            $this->fail('SmsException was expected');
        } catch (SmsException $e) {
            $this->assertCount(1, $e->failures());
            $this->assertSame(0, $this->fake('smsc')->attemptsCount());
        }
    }

    public function test_exception_respects_disabled_failover(): void
    {
        $this->smsSet('failover', false);
        $this->fake('karix')->behaviour = FakeGateway::BEHAVIOUR_THROW;

        try {
            $this->manager()->send(self::PHONE, 'hello');
            $this->fail('SmsException was expected');
        } catch (SmsException $e) {
            $this->assertSame(0, $this->fake('smsc')->attemptsCount());
        }
    }

    public function test_all_failures_are_reported(): void
    {
        $this->fake('karix')->behaviour = FakeGateway::BEHAVIOUR_FAILURE;
        $this->fake('smsc')->behaviour = FakeGateway::BEHAVIOUR_THROW;

        try {
            $this->manager()->send(self::PHONE, 'hello');
            $this->fail('SmsException was expected');
        } catch (SmsException $e) {
            $this->assertCount(2, $e->failures());
            $this->assertStringContainsString('karix: fake soft failure', $e->failures()[0]);
            $this->assertStringContainsString('smsc: fake hard failure', $e->failures()[1]);
            $this->assertSame(['karix', 'smsc'], array_map('strval', $e->gateways()));
            $this->assertInstanceOf(SmsException::class, $e->getPrevious());
            $this->assertNotEmpty($this->logsWith('failed on all gateways'));
        }
    }

    public function test_unknown_instance_is_reported_not_ignored(): void
    {
        $this->smsSet('default', 'typo,karix');

        $result = $this->manager()->send(self::PHONE, 'hello');

        $this->assertTrue($result->success, 'Sending must continue after an unknown instance');
        $this->assertSame('karix', $result->gateway->value);
    }

    public function test_unknown_instance_only_reports_failure_when_nothing_else_works(): void
    {
        $this->smsSet('default', 'typo');
        $this->fake('karix')->behaviour = FakeGateway::BEHAVIOUR_FAILURE;

        try {
            $this->manager()->send(self::PHONE, 'hello');
            $this->fail('SmsException was expected');
        } catch (SmsException $e) {
            $this->assertSame(['typo: instance not registered'], $e->failures());
        }
    }

    public function test_empty_phone_is_rejected(): void
    {
        $this->expectException(SmsException::class);
        $this->expectExceptionMessage('SMS phone is empty');

        $this->manager()->send('  ', 'hello');
    }

    public function test_empty_text_is_rejected(): void
    {
        $this->expectException(SmsException::class);
        $this->expectExceptionMessage('SMS text is empty');

        $this->manager()->send(self::PHONE, '');
    }

    public function test_empty_otp_code_is_rejected(): void
    {
        $this->expectException(SmsException::class);
        $this->expectExceptionMessage('SMS OTP code is empty');

        $this->manager()->sendOtp(self::PHONE, '  ');
    }

    public function test_otp_text_is_rendered_by_the_gateway_that_actually_sent(): void
    {
        // Ключевая причина, по которой OTP уходит кодом, а не текстом:
        // формулировка должна соответствовать шаблону отправившего шлюза.
        $this->fake('karix')->behaviour = FakeGateway::BEHAVIOUR_FAILURE;
        $this->smsSet('gateways.smsc.otp_template', 'Your SMS code is %code');

        $this->manager()->sendOtp(self::PHONE, '1234');

        $this->assertSame('Your SMS code is 1234', $this->fake('smsc')->lastText());
    }

    public function test_otp_falls_back_to_default_template(): void
    {
        $this->smsSet('default', 'smsc');
        $this->smsSet('gateways.smsc.otp_template', null);

        $this->manager()->sendOtp(self::PHONE, '4321');

        $this->assertSame(
            str_replace(SmsManager::OTP_PLACEHOLDER, '4321', SmsManager::DEFAULT_OTP_TEMPLATE),
            $this->fake('smsc')->lastText()
        );
    }

    public function test_otp_template_without_placeholder_is_logged(): void
    {
        $this->smsSet('default', 'smsc');
        $this->smsSet('gateways.smsc.otp_template', 'no placeholder here');

        $this->manager()->sendOtp(self::PHONE, '4321');

        $this->assertNotEmpty($this->logsWith('no %code placeholder'));
        $this->assertSame('no placeholder here', $this->fake('smsc')->lastText());
    }

    public function test_sender_defaults_to_gateway_sender(): void
    {
        $this->smsSet('gateways.karix.sender', 'FakeBrand');

        $this->manager()->send(self::PHONE, 'hello');

        $this->assertSame('FakeBrand', $this->fake('karix')->attempts[0]['sender']);
    }

    public function test_explicit_sender_wins(): void
    {
        $this->manager()->send(self::PHONE, 'hello', null, 'MyBrand');

        $this->assertSame('MyBrand', $this->fake('karix')->attempts[0]['sender']);
    }

    public function test_gates_with_optional_sdk_are_not_registered(): void
    {
        $manager = $this->manager();

        // Без установленного SDK адаптер нельзя собрать, поэтому он не
        // попадает в цепочку, а в лог уходит предупреждение.
        $this->assertNull($manager->instance('smsfly'));
        $this->assertNull($manager->instance('smsto'));
        $this->assertNull($manager->instance('greensms'));

        // Адаптеры на голом HTTP доступны всегда.
        $this->assertNotNull($manager->instance('karix'));
        $this->assertNotNull($manager->instance('smsc'));
        $this->assertNotNull($manager->instance('sigmasms'));

        $this->assertNotEmpty($this->logsWith('SDK is not installed'));
    }

    public function test_optional_adapters_are_loadable_without_their_sdk(): void
    {
        // Файл адаптера читается без установленного SDK, иначе "Class not
        // found" на старте уронил бы приложение из-за одного неиспользуемого
        // шлюза.
        $this->assertTrue(class_exists(SmsFlyGateway::class));
        $this->assertTrue(class_exists(SmsToGateway::class));
        $this->assertTrue(class_exists(GreenSmsGateway::class));

        $this->assertSame('Nekkoy\GatewaySmsfly\Services\SendMessageService', SmsFlyGateway::sdkClass());
        $this->assertSame('Intergo\SmsTo\Facades\SmsToSms', SmsToGateway::sdkClass());
        $this->assertSame('GreenSMS\GreenSMS', GreenSmsGateway::sdkClass());

        // И сам SDK не должен подтягиваться при чтении файлов адаптеров.
        $this->assertFalse(class_exists(SmsFlyClient::class, false), 'SmsFlyClient must stay unloaded without the SDK');
    }

    public function test_gateways_are_resolved_case_insensitively(): void
    {
        $this->assertSame('karix', $this->manager()->instance('  KaRiX ')?->instance());
    }
}
