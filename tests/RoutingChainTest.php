<?php

declare(strict_types=1);

namespace SmsRouting\Tests;

use SmsRouting\Tests\Support\TestCase;

/**
 * Выбор цепочки шлюзов: страна → регион-группа → маршрут по умолчанию.
 */
final class RoutingChainTest extends TestCase
{
    /**
     * Регрессия: у маршрутов и регион-групп НЕТ захардкоженных цепочек.
     * Если в config/sms.php вернётся `env('SMS_ROUTE_CIS', 'smsc,karix')`,
     * группа начнёт перехватывать SMS раньше SMS_ROUTE_DEFAULT — именно
     * этот баг и ловится здесь.
     */
    public function test_no_route_or_group_has_hardcoded_chain(): void
    {
        foreach ((array) config('sms.routes') as $iso => $chain) {
            $this->assertNull($chain, sprintf('Route "%s" must be null by default, got %s', $iso, var_export($chain, true)));
        }

        foreach ((array) config('sms.groups') as $group => $definition) {
            $definition = (array) $definition;

            $this->assertArrayHasKey('chain', $definition, sprintf('Group "%s" must declare a chain key', $group));
            $this->assertNull(
                $definition['chain'],
                sprintf('Group "%s" must be null by default, got %s', $group, var_export($definition['chain'], true))
            );
        }
    }

    public function test_only_default_route_serves_every_country(): void
    {
        $this->smsSet('default', 'karix,smsc');

        // Ни маршрута страны, ни регион-группы не задано — работает только default.
        $this->assertChain(['karix', 'smsc'], 'UA');
        $this->assertChain(['karix', 'smsc'], 'RU');
        $this->assertChain(['karix', 'smsc'], 'KZ');
        $this->assertChain(['karix', 'smsc'], 'DE');
        $this->assertChain(['karix', 'smsc'], 'BR');
        $this->assertChain(['karix', 'smsc'], 'US');
        $this->assertChain(['karix', 'smsc'], 'QQ');
        $this->assertChain(['karix', 'smsc'], null);
    }

    public function test_exact_country_route_wins_over_group(): void
    {
        $this->smsSet('routes.UA', 'karix_ua,smsc');
        $this->smsSet('groups.cis.chain', 'sigmasms');
        $this->smsSet('default', 'botto');

        $this->assertChain(['karix_ua', 'smsc'], 'UA');
        $this->assertChain(['sigmasms'], 'RU');
        $this->assertChain(['botto'], 'DE');
    }

    public function test_group_is_used_when_country_route_is_absent(): void
    {
        $this->smsSet('groups.cis.chain', 'smsc,karix');
        $this->smsSet('default', 'botto');

        $this->assertChain(['smsc', 'karix'], 'RU');
        $this->assertChain(['smsc', 'karix'], 'KZ');
        $this->assertChain(['botto'], 'DE');
    }

    public function test_empty_country_route_falls_through_to_group(): void
    {
        $this->smsSet('routes.KZ', '');
        $this->smsSet('groups.cis.chain', 'smsc');
        $this->smsSet('default', 'botto');

        $this->assertChain(['smsc'], 'KZ');
    }

    public function test_first_matching_group_wins(): void
    {
        $this->smsSet('groups.cis.chain', 'smsc');
        $this->smsSet('groups.europe.chain', 'sigmasms');
        $this->smsSet('groups.latam.chain', 'karix');

        // KZ входит в cis; порядок групп в конфиге задаёт приоритет.
        $this->assertChain(['smsc'], 'KZ');

        // DE входит в europe, но не в cis.
        $this->assertChain(['sigmasms'], 'DE');
    }

    public function test_unknown_country_falls_back_to_default(): void
    {
        $this->smsSet('routes.UA', 'karix');
        $this->smsSet('default', 'botto');

        $this->assertChain(['botto'], 'XX');
    }

    public function test_default_gateway_used_when_default_chain_is_empty(): void
    {
        $this->smsSet('default', '');
        $this->smsSet('default_gateway', 'sigmasms');

        $this->assertChain(['sigmasms'], 'UA');
        $this->assertChain(['sigmasms'], null);
    }

    public function test_chain_is_trimmed_lowercased_and_deduplicated(): void
    {
        $this->smsSet('routes.UA', ' Karix , SMSC , karix ,, smsc ');

        $this->assertChain(['karix', 'smsc'], 'UA');
    }

    public function test_unknown_instance_name_survives_parsing(): void
    {
        // Опечатку нельзя молча выкинуть: иначе SMS уйдёт не тем аккаунтом.
        $this->smsSet('routes.UA', 'karix,karixx');

        $this->assertChain(['karix', 'karixx'], 'UA');
    }

    public function test_route_order_is_preserved(): void
    {
        $this->smsSet('routes.UA', 'smsc,karix,botto');

        $this->assertChain(['smsc', 'karix', 'botto'], 'UA');
    }

    public function test_route_reads_env_value(): void
    {
        // Проверяем именно связку env() -> config, а не подстановку в тесте.
        putenv('SMS_ROUTE_UA=karix,smsc');

        try {
            $config = require __DIR__ . '/../config/sms.php';
        } finally {
            putenv('SMS_ROUTE_UA');
        }

        $this->assertSame('karix,smsc', $config['routes']['UA'] ?? null);
    }

    public function test_group_reads_env_value(): void
    {
        putenv('SMS_ROUTE_CIS= Karix , smsc ');

        try {
            $config = require __DIR__ . '/../config/sms.php';
        } finally {
            putenv('SMS_ROUTE_CIS');
        }

        $this->assertSame(' Karix , smsc ', $config['groups']['cis']['chain'] ?? null);
    }
}
