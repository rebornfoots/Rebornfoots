<?php
declare(strict_types=1);

function normalizedCouponCode(mixed $value): string
{
    return strtoupper(trim(is_string($value) ? $value : ''));
}

function promotionDiscount(string $type, float $value, float $base, ?float $cap = null): float
{
    $discount = $type === 'percent' ? $base * min($value, 100) / 100 : $value;
    if ($cap !== null) {
        $discount = min($discount, $cap);
    }
    return round(max(0, min($discount, $base)), 2);
}

function calculatePromotions(mysqli $database, array $items, float $subtotal, string $couponCode): array
{
    $remaining = [];
    foreach ($items as $productId => $item) {
        $remaining[$productId] = (int) $item['quantity'];
    }

    $combos = [];
    $comboResult = $database->query(
        "SELECT combo_offers.id, combo_offers.name, combo_offers.discount_type,
                combo_offers.discount_value, combo_offers.priority,
                combo_offer_items.product_id, combo_offer_items.quantity
         FROM combo_offers
         JOIN combo_offer_items ON combo_offer_items.combo_id = combo_offers.id
         WHERE combo_offers.active = 1
           AND (combo_offers.starts_at IS NULL OR combo_offers.starts_at <= NOW())
           AND (combo_offers.ends_at IS NULL OR combo_offers.ends_at >= NOW())
         ORDER BY combo_offers.priority, combo_offers.id, combo_offer_items.product_id"
    );
    while ($row = $comboResult->fetch_assoc()) {
        $id = (int) $row['id'];
        if (!isset($combos[$id])) {
            $combos[$id] = [
                'id' => $id,
                'name' => (string) $row['name'],
                'type' => (string) $row['discount_type'],
                'value' => (float) $row['discount_value'],
                'items' => [],
            ];
        }
        $combos[$id]['items'][(string) $row['product_id']] = (int) $row['quantity'];
    }

    $comboDiscount = 0.0;
    $appliedCombos = [];
    foreach ($combos as $combo) {
        $applications = PHP_INT_MAX;
        $comboBase = 0.0;
        foreach ($combo['items'] as $productId => $requiredQuantity) {
            if (!isset($items[$productId]) || $requiredQuantity < 1) {
                $applications = 0;
                break;
            }
            $applications = min($applications, intdiv($remaining[$productId] ?? 0, $requiredQuantity));
            $comboBase += (float) $items[$productId]['price'] * $requiredQuantity;
        }
        if ($applications < 1 || $applications === PHP_INT_MAX) {
            continue;
        }
        foreach ($combo['items'] as $productId => $requiredQuantity) {
            $remaining[$productId] -= $requiredQuantity * $applications;
        }
        $discountPerCombo = promotionDiscount($combo['type'], $combo['value'], $comboBase);
        $discount = round($discountPerCombo * $applications, 2);
        $comboDiscount += $discount;
        $appliedCombos[] = [
            'id' => $combo['id'],
            'name' => $combo['name'],
            'quantity' => $applications,
            'discount' => $discount,
        ];
    }
    $comboDiscount = round(min($comboDiscount, $subtotal), 2);

    $couponDiscount = 0.0;
    $appliedCoupon = null;
    if ($couponCode !== '') {
        if (!preg_match('/^[A-Z0-9_-]{3,30}$/', $couponCode)) {
            throw new DomainException('Enter a valid coupon code.');
        }
        $statement = $database->prepare(
            "SELECT code, description, discount_type, discount_value, min_subtotal,
                    max_discount, usage_limit, used_count
             FROM coupons
             WHERE code = ? AND active = 1
               AND (starts_at IS NULL OR starts_at <= NOW())
               AND (ends_at IS NULL OR ends_at >= NOW())
             LIMIT 1"
        );
        $statement->bind_param('s', $couponCode);
        $statement->execute();
        $coupon = $statement->get_result()->fetch_assoc();
        $statement->close();
        if (!$coupon) {
            throw new DomainException('This coupon is invalid or inactive.');
        }
        if ($coupon['usage_limit'] !== null && (int) $coupon['used_count'] >= (int) $coupon['usage_limit']) {
            throw new DomainException('This coupon has reached its usage limit.');
        }
        if ($subtotal < (float) $coupon['min_subtotal']) {
            throw new DomainException(
                'This coupon requires an item subtotal of ₹'
                . number_format((float) $coupon['min_subtotal'], 0) . '.'
            );
        }
        $couponBase = max(0, $subtotal - $comboDiscount);
        $couponDiscount = promotionDiscount(
            (string) $coupon['discount_type'],
            (float) $coupon['discount_value'],
            $couponBase,
            $coupon['max_discount'] === null ? null : (float) $coupon['max_discount']
        );
        $appliedCoupon = [
            'code' => (string) $coupon['code'],
            'description' => (string) $coupon['description'],
            'discount' => $couponDiscount,
        ];
    }

    $discountTotal = round(min($subtotal, $comboDiscount + $couponDiscount), 2);
    return [
        'couponCode' => $appliedCoupon['code'] ?? '',
        'couponDiscount' => $couponDiscount,
        'comboDiscount' => $comboDiscount,
        'discountTotal' => $discountTotal,
        'combos' => $appliedCombos,
        'coupon' => $appliedCoupon,
    ];
}
