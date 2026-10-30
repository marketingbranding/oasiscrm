<?php

namespace App\Support;

final class DanaTalanganMoney
{
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
}
