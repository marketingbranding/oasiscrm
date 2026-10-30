<?php

namespace App\Support;

final class DanaTalanganMoney
{
    public static function sum(iterable $values): string
    {
        $whole = '0';
        $fraction = '00';

        foreach ($values as $value) {
            $normalized = self::normalize($value);
            if ($normalized === null || str_starts_with($normalized, '-')) {
                continue;
            }

            [$valueWhole, $valueFraction] = array_pad(explode('.', $normalized, 2), 2, '00');
            [$whole, $fraction] = self::addPositive($whole, $fraction, $valueWhole, str_pad($valueFraction, 2, '0'));
        }

        return $whole.'.'.$fraction;
    }

    public static function format(mixed $value): string
    {
        $normalized = self::normalize($value);
        if ($normalized === null) {
            return '—';
        }

        $negative = str_starts_with($normalized, '-');
        $normalized = ltrim($normalized, '-');
        [$whole, $fraction] = array_pad(explode('.', $normalized, 2), 2, '00');
        $whole = preg_replace('/\B(?=(\d{3})+(?!\d))/', '.', ltrim($whole, '0') ?: '0');

        return ($negative ? '-' : '').'Rp'.$whole.','.str_pad($fraction, 2, '0');
    }

    public static function normalize(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        if (is_float($value)) {
            return number_format($value, 2, '.', '');
        }

        $value = trim((string) $value);
        $value = preg_replace('/\s+/u', '', $value) ?? '';
        $value = preg_replace('/^rp/i', '', $value) ?? '';
        if ($value === '' || ! preg_match('/^-?[0-9.,]+$/', $value)) {
            return null;
        }

        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '-');
        $lastComma = strrpos($value, ',');
        $lastDot = strrpos($value, '.');

        if ($lastComma !== false && $lastDot !== false) {
            if ($lastComma > $lastDot) {
                $value = str_replace('.', '', $value);
                $value = str_replace(',', '.', $value);
            } else {
                $value = str_replace(',', '', $value);
            }
        } elseif ($lastComma !== false) {
            $parts = explode(',', $value);
            $fractionLength = strlen((string) end($parts));
            $value = $fractionLength === 1 || $fractionLength === 2 || ($fractionLength > 2 && strlen((string) ($parts[0] ?? '')) >= 4)
                ? str_replace(',', '.', $value)
                : str_replace(',', '', $value);
        } elseif ($lastDot !== false) {
            $parts = explode('.', $value);
            $fractionLength = strlen((string) end($parts));
            $value = count($parts) === 2 && ($fractionLength === 1 || $fractionLength === 2 || ($fractionLength > 2 && strlen((string) ($parts[0] ?? '')) >= 4))
                ? $value
                : str_replace('.', '', $value);
        }

        if (! preg_match('/^\d+(?:\.\d+)?$/', $value)) {
            return null;
        }

        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        if (strlen($fraction) > 2) {
            $roundUp = ((int) $fraction[2]) >= 5;
            $fraction = substr($fraction, 0, 2);
            if ($roundUp) {
                if ($fraction === '99') {
                    $fraction = '00';
                    $whole = self::incrementInteger($whole);
                } else {
                    $fraction = str_pad((string) ((int) $fraction + 1), 2, '0', STR_PAD_LEFT);
                }
            }
        }
        $whole = ltrim($whole, '0') ?: '0';
        $fraction = str_pad(substr($fraction, 0, 2), 2, '0');

        return ($negative ? '-' : '').$whole.'.'.$fraction;
    }

    private static function incrementInteger(string $value): string
    {
        $digits = str_split(strrev($value));
        $carry = 1;
        foreach ($digits as $index => $digit) {
            $sum = (int) $digit + $carry;
            $digits[$index] = (string) ($sum % 10);
            $carry = intdiv($sum, 10);
            if ($carry === 0) {
                break;
            }
        }
        if ($carry > 0) {
            $digits[] = (string) $carry;
        }

        return strrev(implode('', $digits));
    }

    /** @return array{string, string} */
    private static function addPositive(string $leftWhole, string $leftFraction, string $rightWhole, string $rightFraction): array
    {
        $fractionValue = (int) $leftFraction + (int) $rightFraction;
        $carry = intdiv($fractionValue, 100);
        $fraction = str_pad((string) ($fractionValue % 100), 2, '0', STR_PAD_LEFT);

        $leftDigits = str_split(strrev($leftWhole));
        $rightDigits = str_split(strrev($rightWhole));
        $length = max(count($leftDigits), count($rightDigits));
        $digits = [];

        for ($index = 0; $index < $length; $index++) {
            $sum = (int) ($leftDigits[$index] ?? 0) + (int) ($rightDigits[$index] ?? 0) + $carry;
            $digits[] = (string) ($sum % 10);
            $carry = intdiv($sum, 10);
        }

        if ($carry > 0) {
            $digits[] = (string) $carry;
        }

        return [ltrim(strrev(implode('', $digits)), '0') ?: '0', $fraction];
    }
}
