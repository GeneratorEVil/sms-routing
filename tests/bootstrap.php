<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

/*
 * Тесты пакета намеренно не зависят от laravel/framework: нужен только
 * минимум illuminate (container, config, log, events), который пакет
 * и так требует. Фреймворк даёт глобальные helpers config()/app()/event(),
 * поэтому ниже они объявлены вручную — ровно те же, что использует пакет.
 * Это изоляция тестов от фреймворка, а не изменение самого пакета.
 */

use SmsRouting\Tests\Support\TestContainer;

if ( ! function_exists('app')) {
    function app($abstract = null, array $parameters = [])
    {
        $container = TestContainer::current();

        return $abstract === null ? $container : $container->make($abstract, $parameters);
    }
}

if ( ! function_exists('config')) {
    function config($key = null, $default = null)
    {
        $repository = TestContainer::current()->make('config');

        return $key === null ? $repository : $repository->get($key, $default);
    }
}

if ( ! function_exists('event')) {
    function event(...$arguments)
    {
        return TestContainer::current()->make('events')->dispatch(...$arguments);
    }
}
