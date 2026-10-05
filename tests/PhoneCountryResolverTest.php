<?php

declare(strict_types=1);

namespace SmsRouting\Tests;

use SmsRouting\PhoneCountryResolver;
use SmsRouting\Tests\Support\TestCase;

/**
 * Определение страны по номеру: страна берётся из префикса номера,
 * а не из GeoIP, потому что SMS уходит туда, где номер зарегистрирован.
 */
final class PhoneCountryResolverTest extends TestCase
{
    private function resolver(): PhoneCountryResolver
    {
        return $this->app->make(PhoneCountryResolver::class);
    }

    public function test_resolves_country_by_number_prefix(): void
    {
        $cases = [
            '+380501234567' => 'UA',
            '+380671234567' => 'UA',
            '+375291234567' => 'BY',
            '+77021234567' => 'KZ',
            '+996501234567' => 'KG',
            '+74951234567' => 'RU',
            '+12025550123' => 'US',
            '+4915112345678' => 'DE',
            '+551145678901' => 'BR',
        ];

        foreach ($cases as $phone => $expected) {
            $this->assertSame($expected, $this->resolver()->resolve((string) $phone), sprintf('Wrong country for %s', $phone));
        }
    }

    public function test_ignores_formatting(): void
    {
        $resolver = $this->resolver();

        $this->assertSame('UA', $resolver->resolve('+380 (501) 234-567'));
        $this->assertSame('UA', $resolver->resolve('380501234567'));
        $this->assertSame('DE', $resolver->resolve('+49 151 1234 5678'));
    }

    public function test_returns_null_when_country_is_unknown(): void
    {
        $resolver = $this->resolver();

        $this->assertNull($resolver->resolve(null));
        $this->assertNull($resolver->resolve(''));
        $this->assertNull($resolver->resolve('   '));
        $this->assertNull($resolver->resolve('123'), 'Too short to be a phone number');
        $this->assertNull($resolver->resolve('+0123456789012345'), 'Too long to be a phone number');
    }

    public function test_non_geographic_numbers_have_no_country(): void
    {
        $resolver = $this->resolver();

        // 800 — негеографический calling code, страна не определяется,
        // иначе такие номера попадали бы в маршрут случайной страны.
        $this->assertNull($resolver->resolve('+80012345678'));
    }

    public function test_prefix_fallback_never_invents_a_country(): void
    {
        // Запасной разбор по префиксу не имеет права выдумывать страну
        // для негеографических кодов: иначе 800/808/870 уехали бы в
        // маршрут случайной страны.
        foreach (['+80012345678', '+80812345678', '+87012345678', '+99912345678'] as $phone) {
            $this->assertNull($this->resolver()->resolve($phone), sprintf('%s must not resolve to a country', $phone));
        }
    }

    public function test_normalize_returns_digits_with_plus(): void
    {
        $resolver = $this->resolver();

        $this->assertSame('+380501234567', $resolver->normalize('+380 (501) 234-567'));
        $this->assertSame('+380501234567', $resolver->normalize('380501234567'));
        $this->assertNull($resolver->normalize('abc'));
        $this->assertNull($resolver->normalize(null));
    }

    public function test_calling_code_is_exposed_for_logs(): void
    {
        $resolver = $this->resolver();

        $this->assertSame(380, $resolver->callingCode('+380501234567'));
        $this->assertSame(1, $resolver->callingCode('+12025550123'));
        $this->assertNull($resolver->callingCode('abc'));
    }

    public function test_unresolvable_number_is_logged(): void
    {
        $this->resolver()->resolve('+80012345678');

        $this->assertNotEmpty($this->logsWith('country not resolved'));
    }
}
