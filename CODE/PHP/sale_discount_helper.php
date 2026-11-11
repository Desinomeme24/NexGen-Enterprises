<?php

if (!function_exists('nxPrepareSaleDiscountTotals')) {
    function nxPrepareSaleDiscountTotals(array $items, string $discountType, mixed $rawDiscountValue): array
    {
        $allowedTypes = ['none', 'senior', 'pwd', 'custom_percent'];
        if (!in_array($discountType, $allowedTypes, true)) {
            throw new InvalidArgumentException('Choose a valid sale discount.');
        }

        $normalizedItems = [];
        $subtotalCents = 0;
        foreach ($items as $item) {
            $quantity = (float)($item['quantity'] ?? 0);
            $unitPrice = (float)($item['unit_price'] ?? 0);
            if (!is_finite($quantity) || !is_finite($unitPrice) || $quantity <= 0 || $unitPrice < 0) {
                throw new InvalidArgumentException('Invalid product quantity or price.');
            }

            $lineCents = (int)round($quantity * $unitPrice * 100);
            $normalizedItems[] = $item + ['discount_percent' => 0.0];
            $normalizedItems[array_key_last($normalizedItems)]['subtotal'] = $lineCents / 100;
            $normalizedItems[array_key_last($normalizedItems)]['_gross_cents'] = $lineCents;
            $subtotalCents += $lineCents;
        }

        $discountValue = 0.0;
        if ($discountType === 'senior' || $discountType === 'pwd') {
            $discountValue = 20.0;
        } elseif ($discountType === 'custom_percent') {
            $discountValueInput = is_scalar($rawDiscountValue)
                ? trim((string)$rawDiscountValue)
                : '';
            if (!preg_match('/^(?:\d+(?:\.\d{1,2})?|\.\d{1,2})$/D', $discountValueInput)) {
                throw new InvalidArgumentException('Enter a valid discount value.');
            }
            $discountValue = (float)$discountValueInput;
            if (!is_finite($discountValue) || $discountValue < 0) {
                throw new InvalidArgumentException('Discount value cannot be negative.');
            }
            if ($discountType === 'custom_percent' && $discountValue > 100) {
                throw new InvalidArgumentException('Percentage discount cannot exceed 100%.');
            }
        }

        $discountCents = match ($discountType) {
            'senior', 'pwd', 'custom_percent' => (int)round($subtotalCents * $discountValue / 100),
            default => 0,
        };
        if ($discountCents > $subtotalCents) {
            throw new InvalidArgumentException('Fixed discount cannot exceed the sale subtotal.');
        }

        $remainingDiscountCents = $discountCents;
        $remainingGrossCents = $subtotalCents;
        foreach ($normalizedItems as $index => &$item) {
            $lineCents = $item['_gross_cents'];
            $isLastDiscountedLine = $lineCents > 0 && $remainingGrossCents === $lineCents;
            $lineDiscountCents = $lineCents > 0
                ? ($isLastDiscountedLine
                    ? $remainingDiscountCents
                    : (int)floor($discountCents * ($lineCents / max($subtotalCents, 1))))
                : 0;
            $lineDiscountCents = min($lineCents, max(0, $lineDiscountCents));
            $remainingDiscountCents -= $lineDiscountCents;
            $remainingGrossCents -= $lineCents;
            $item['discount_percent'] = $lineCents > 0
                ? round($lineDiscountCents / $lineCents * 100, 2)
                : 0.0;
            $item['subtotal'] = ($lineCents - $lineDiscountCents) / 100;
            unset($item['_gross_cents']);
        }
        unset($item);

        $subtotalAmount = $subtotalCents / 100;
        $discountAmount = $discountCents / 100;

        return [
            'items' => $normalizedItems,
            'subtotal_amount' => $subtotalAmount,
            'discount_type' => $discountType,
            'discount_value' => $discountValue,
            'discount_amount' => $discountAmount,
            'total_amount' => ($subtotalCents - $discountCents) / 100,
        ];
    }
}
