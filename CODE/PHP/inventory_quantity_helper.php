<?php

if (!function_exists('nxValidateWholeQuantity')) {
    function nxValidateWholeQuantity(mixed $value, string $label, bool $allowZero = true): int
    {
        if (!is_string($value) && !is_int($value)) {
            throw new InvalidArgumentException($label . ' must be a whole number.');
        }

        $rawValue = (string)$value;
        if ($rawValue === '' || !preg_match('/^\d+$/D', $rawValue)) {
            throw new InvalidArgumentException($label . ' must be a whole number.');
        }

        $normalizedValue = ltrim($rawValue, '0');
        $quantity = filter_var($normalizedValue === '' ? '0' : $normalizedValue, FILTER_VALIDATE_INT);
        if ($quantity === false || (!$allowZero && $quantity < 1)) {
            throw new InvalidArgumentException(
                $allowZero ? $label . ' is outside the supported range.' : $label . ' must be a whole number greater than 0.'
            );
        }

        return $quantity;
    }
}
