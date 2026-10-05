<?php

declare(strict_types=1);

namespace SmsRouting\Tests;

use InvalidArgumentException;
use SmsRouting\Enum\SmsGateway;
use SmsRouting\Tests\Support\FakeGateway;
use SmsRouting\Tests\Support\TestCase;

/**
 * Инстансы: один провайдер — несколько аккаунтов. Маршруты ссылаются
 * на ключ инстанса ("sigmasms_ru"), а не на код провайдера.
 */
final class GatewayInstanceTest extends TestCase
{
    private const PHONE = '+380501234567';

    public function test_default_instance_key_equals_provider_code(): void
    {
        // Обратная совместимость: маршруты, написанные до инстансов,
        // работают как раньше.
        foreach (SmsGateway::values() as $code) {
            if ($code === 'smsfly' || $code === 'smsto' || $code === 'greensms') {
                continue; // опциональные SDK не установлены в тестовом окружении
            }

            $this->assertNotNull($this->manager()->instance($code), sprintf('Instance "%s" must exist', $code));
        }
    }

    public function test_configured_instance_is_registered(): void
    {
        $this->assertNotNull($this->manager()->instance('sigmasms_ru'));
        $this->assertSame('sigmasms_ru', $this->manager()->instance('sigmasms_ru')?->instance());
    }

    public function test_instance_inherits_provider_and_applies_overrides(): void
    {
        $this->smsSet('instances', [
            'sigmasms_kz' => [
                'provider' => 'sigmasms',
                'sender' => 'KZBrand',
                'otp_template' => 'Kod: %code',
            ],
        ]);
        $this->smsSet('default', 'sigmasms_kz');

        $gateway = $this->manager()->instance('sigmasms_kz');

        $this->assertSame('sigmasms', $gateway?->code()->value, 'Instance keeps the provider code');
        $this->assertSame('KZBrand', $gateway?->sender(), 'Instance sender overrides the provider one');
        $this->assertSame('Kod: %code', $gateway?->otpTemplate());
    }

    public function test_empty_override_does_not_shadow_provider_settings(): void
    {
        // Закомментированная переменная в .env не должна молча
        // затирать настройки провайдера.
        $this->smsSet('gateways.sigmasms.sender', 'ProviderBrand');
        $this->smsSet('instances', [
            'sigmasms_kz' => ['provider' => 'sigmasms', 'sender' => ''],
        ]);

        $this->assertSame('ProviderBrand', $this->manager()->instance('sigmasms_kz')?->sender());
    }

    public function test_instance_can_send_on_its_own_account(): void
    {
        $this->smsSet('instances', [
            'sigmasms_kz' => ['provider' => 'sigmasms', 'sender' => 'KZBrand'],
        ]);
        $this->smsSet('default', 'sigmasms_kz');

        $result = $this->manager()->send(self::PHONE, 'hello');

        $this->assertTrue($result->success);
        $this->assertSame('sigmasms', $result->gateway->value, 'Provider is still sigmasms');
        $this->assertSame('sigmasms_kz', $result->instance, 'But the account is the KZ one');
    }

    public function test_instance_name_colliding_with_provider_code_is_rejected(): void
    {
        $this->smsSet('instances', ['karix' => ['provider' => 'sigmasms']]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('collides with an already registered instance');

        $this->manager();
    }

    public function test_credentials_for_provider_without_instance_support_are_rejected(): void
    {
        $this->fakeWithoutInstanceCredentials('smsc');
        $this->smsSet('instances', [
            'smsc_backup' => ['provider' => 'smsc', 'credentials' => ['login' => 'x', 'password' => 'y']],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot use per-instance ones');

        $this->manager();
    }

    public function test_instance_without_credentials_is_allowed_for_singleton_provider(): void
    {
        $this->smsSet('instances', ['smsc_backup' => ['provider' => 'smsc']]);

        $this->assertNotNull($this->manager()->instance('smsc_backup'));
    }

    public function test_unknown_provider_is_a_warning_not_an_exception(): void
    {
        // Опечатка в .env не должна ронять весь процесс отправки.
        $this->smsSet('instances', ['broken' => ['provider' => 'smsk']]);

        $manager = $this->manager();

        $this->assertNull($manager->instance('broken'));
        $this->assertNotEmpty($this->logsWith('unknown provider'));
    }

    public function test_instance_of_provider_without_installed_sdk_is_skipped(): void
    {
        $this->smsSet('instances', ['fly' => ['provider' => 'smsfly']]);

        $manager = $this->manager();

        $this->assertNull($manager->instance('fly'));
        $this->assertNotEmpty($this->logsWith('no registered adapter'));
    }

    public function test_malformed_instance_definition_is_skipped(): void
    {
        $this->smsSet('instances', ['broken' => 'not-an-array', '' => ['provider' => 'sigmasms']]);

        $this->assertNull($this->manager()->instance('broken'));
        $this->assertNotEmpty($this->logsWith('malformed definition'));
    }

    public function test_failover_between_two_instances_of_one_provider(): void
    {
        $this->smsSet('instances', [
            'sigmasms_ru' => ['provider' => 'sigmasms'],
            'sigmasms_kz' => ['provider' => 'sigmasms'],
        ]);
        $this->smsSet('default', 'sigmasms_ru,sigmasms_kz');

        $manager = $this->manager();
        $ru = $manager->instance('sigmasms_ru');
        $kz = $manager->instance('sigmasms_kz');

        // Оба инстанса — клоны одного адаптера, но состояние у них своё:
     // сбой одного аккаунта не должен ломать второй.
        $this->assertNotSame($ru, $kz);
        $this->assertInstanceOf(FakeGateway::class, $ru);
        $ru->behaviour = FakeGateway::BEHAVIOUR_FAILURE;

        $result = $manager->send(self::PHONE, 'hello');

        $this->assertTrue($result->success);
        $this->assertSame('sigmasms_kz', $result->instance);
        $this->assertSame(1, $ru->attemptsCount(), 'Failed instance still tried');
        $this->assertSame(1, $kz->attemptsCount());
    }
}
