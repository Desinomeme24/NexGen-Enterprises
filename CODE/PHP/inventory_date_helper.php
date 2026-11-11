<?php

if (!function_exists('nxValidateManufacturingDate')) {
    function nxValidateManufacturingDate(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            throw new InvalidArgumentException('Enter a valid manufacturing date.');
        }

        $date = trim((string)$value);
        if ($date === '') {
            return null;
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $dateErrors = DateTimeImmutable::getLastErrors();
        if (
            !$parsed
            || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))
            || $parsed->format('Y-m-d') !== $date
        ) {
            throw new InvalidArgumentException('Enter a valid manufacturing date.');
        }

        if ($parsed > new DateTimeImmutable('today')) {
            throw new InvalidArgumentException('Manufacturing date cannot be in the future.');
        }

        return $date;
    }
}

if (!function_exists('nxValidateExpiryDate')) {
    function nxValidateExpiryDate(mixed $value, bool $required): ?string
    {
        if (!is_scalar($value)) {
            throw new InvalidArgumentException('Enter a valid expiry date.');
        }

        $date = trim((string)$value);
        if ($date === '') {
            if ($required) {
                throw new InvalidArgumentException('Expiry date is required for this business type.');
            }
            return null;
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $dateErrors = DateTimeImmutable::getLastErrors();
        if (
            !$parsed
            || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))
            || $parsed->format('Y-m-d') !== $date
        ) {
            throw new InvalidArgumentException('Enter a valid expiry date.');
        }

        return $date;
    }
}

if (!function_exists('nxRequiresExpiryDate')) {
    function nxRequiresExpiryDate(?string $businessType, bool $otherExpiryRequired = false): bool
    {
        if (in_array($businessType, [
            'Mini Grocery / Sari-Sari Store',
            'Pharmacy / Drugstore',
        ], true)) {
            return true;
        }

        return in_array($businessType, ['Other', 'Others'], true) && $otherExpiryRequired;
    }
}
