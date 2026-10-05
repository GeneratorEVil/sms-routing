<?php

namespace SmsRouting;

use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberUtil;
use Illuminate\Support\Facades\Log;

/**
 * Определяет ISO2-код страны по номеру телефона.
 *
 * Страна берётся из префикса номера, а не из GeoIP: игрок может регистрироваться
 * из другой страны, а купленный номер вообще может быть зарубежным. Маршрут SMS
 * должен зависеть от того, куда реально уходит сообщение.
 */
class PhoneCountryResolver
{
    private const LOG_CHANNEL = 'sms';

    /**
     * Служебные значения libphonenumber: ZZ — код не существует,
     * 001 — сервисные/негеографические calling code (800, 808, 870 ...).
     * Оба не являются страной и не должны попадать в маршрут.
     */
    private const NON_COUNTRY_CODES = ['ZZ', '001', '0011'];

    /**
     * ISO2-код страны по номеру. null, если определить не удалось —
     * вызывающий код обязан обработать это маршрутом по умолчанию.
     */
    public function resolve(?string $phone): ?string
    {
        $normalized = $this->normalize($phone);

        if ($normalized === null) {
            return null;
        }

        $phoneNumberUtil = PhoneNumberUtil::getInstance();

        try {
            $parsed = $phoneNumberUtil->parse($normalized, null);

            $region = $phoneNumberUtil->getRegionCodeForNumber($parsed);

            if ($this->isCountry($region)) {
                return strtoupper($region);
            }
        } catch (NumberParseException $e) {
            Log::channel(self::LOG_CHANNEL)->debug('PhoneCountryResolver: parse failed', [
                'phone' => $normalized,
                'error' => $e->getMessage(),
            ]);
        } catch (\Throwable $e) {
            Log::channel(self::LOG_CHANNEL)->warning('PhoneCountryResolver: unexpected error', [
                'phone' => $normalized,
                'error' => $e->getMessage(),
            ]);
        }

        return $this->resolveByCallingCodePrefix($normalized);
    }

    /**
     * Номер телефона в формате, понятном libphonenumber: только цифры с ведущим "+".
     */
    public function normalize(?string $phone): ?string
    {
        if ( ! is_string($phone)) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if (strlen($digits) < 5 || strlen($digits) > 15) {
            return null;
        }

        return '+' . $digits;
    }

    /**
     * Определяет calling code (код страны, напр. 380 для UA) — для логов и дебага.
     */
    public function callingCode(?string $phone): ?int
    {
        $normalized = $this->normalize($phone);

        if ($normalized === null) {
            return null;
        }

        try {
            $parsed = PhoneNumberUtil::getInstance()->parse($normalized, null);

            return $parsed->getCountryCode() ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Запасной путь: сопоставляет номер с префиксом calling code по таблице
     * libphonenumber. Используется, когда номер не проходит полный парсинг
     * (например обрезан или содержит невалидные символы), но префикс читаем.
     */
    private function resolveByCallingCodePrefix(string $normalized): ?string
    {
        $phoneNumberUtil = PhoneNumberUtil::getInstance();
        $digits = substr($normalized, 1);

        // От длинных кодов к коротким: 3 цифры (напр. 380) раньше 2 (44) и 1 (7).
        foreach ([3, 2, 1] as $length) {
            if (strlen($digits) <= $length) {
                continue;
            }

            $prefix = (int) substr($digits, 0, $length);

            try {
                $region = $phoneNumberUtil->getRegionCodeForCountryCode($prefix);
            } catch (\Throwable $e) {
                continue;
            }

            if ( ! $this->isCountry($region)) {
                continue;
            }

            $region = strtoupper($region);

            Log::channel(self::LOG_CHANNEL)->info('PhoneCountryResolver: resolved by calling code prefix', [
                'phone' => $normalized,
                'calling_code' => $prefix,
                'country' => $region,
            ]);

            return $region;
        }

        Log::channel(self::LOG_CHANNEL)->warning('PhoneCountryResolver: country not resolved', [
            'phone' => $normalized,
        ]);

        return null;
    }

    /**
     * libphonenumber возвращает ZZ / 001 для негеографических номеров.
     * Такие значения считаем "страна не определена".
     */
    private function isCountry(mixed $region): bool
    {
        if ( ! is_string($region) || $region === '') {
            return false;
        }

        return ! in_array(strtoupper($region), self::NON_COUNTRY_CODES, true);
    }
}
