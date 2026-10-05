<?php

declare(strict_types=1);

namespace SmsRouting\Tests\Support;

use Illuminate\Container\Container;

/**
 * Контейнер текущего теста: helpers config()/app()/event() в bootstrap
 * обращаются к нему, как глобальные helpers Laravel — к контейнеру.
 */
final class TestContainer
{
    private static ?Container $container = null;

    public static function set(Container $container): void
    {
        self::$container = $container;
    }

    public static function current(): Container
    {
        if (self::$container === null) {
            throw new \RuntimeException('Test container is not initialised. Extend SmsRouting\Tests\Support\TestCase.');
        }

        return self::$container;
    }

    public static function forget(): void
    {
        self::$container = null;
    }
}
