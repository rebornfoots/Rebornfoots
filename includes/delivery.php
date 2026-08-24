<?php
declare(strict_types=1);

/**
 * Resolve a PIN code locally first. Unknown PIN codes are looked up once and
 * cached so checkout does not permanently depend on the remote directory.
 */
function lookupPostalLocation(mysqli $database, string $pincode): ?array
{
    $statement = $database->prepare(
        'SELECT district, state_name, area_name FROM postal_pincodes WHERE pincode = ? LIMIT 1'
    );
    $statement->bind_param('s', $pincode);
    $statement->execute();
    $location = $statement->get_result()->fetch_assoc();
    $statement->close();
    if ($location) {
        return $location;
    }

    $url = 'https://api.postalpincode.in/pincode/' . rawurlencode($pincode);
    $body = false;
    if (function_exists('curl_init')) {
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => 4,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        if ($status !== 200) {
            $body = false;
        }
    }
    if (!is_string($body) || $body === '') {
        return inferredPostalLocation($pincode);
    }

    try {
        $payload = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return inferredPostalLocation($pincode);
    }
    $office = $payload[0]['PostOffice'][0] ?? null;
    if (!is_array($office)) {
        return inferredPostalLocation($pincode);
    }
    $state = trim((string) ($office['State'] ?? ''));
    $district = trim((string) ($office['District'] ?? ''));
    $area = trim((string) ($office['Name'] ?? ''));
    if ($state === '') {
        return inferredPostalLocation($pincode);
    }

    $save = $database->prepare(
        'INSERT INTO postal_pincodes (pincode, district, state_name, area_name)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE district = VALUES(district), state_name = VALUES(state_name), area_name = VALUES(area_name)'
    );
    $save->bind_param('ssss', $pincode, $district, $state, $area);
    $save->execute();
    $save->close();
    return ['district' => $district, 'state_name' => $state, 'area_name' => $area];
}

/**
 * Postal-circle fallback for the specially priced southern states. It keeps
 * checkout operational when the remote directory is unavailable; every other
 * valid Indian PIN code safely receives the configured default rate.
 */
function inferredPostalLocation(string $pincode): array
{
    $three = (int) substr($pincode, 0, 3);
    $prefix = (int) substr($pincode, 0, 2);
    $state = match (true) {
        $three === 160 => 'Chandigarh',
        $three === 194 => 'Ladakh',
        $three === 403 => 'Goa',
        in_array($three, [396], true) => 'Gujarat',
        $three === 605 => 'Puducherry',
        $three === 682 && str_starts_with($pincode, '68255') => 'Lakshadweep',
        $three === 737 => 'Sikkim',
        $three >= 790 && $three <= 792 => 'Arunachal Pradesh',
        $three >= 793 && $three <= 794 => 'Meghalaya',
        $three === 795 => 'Manipur',
        $three === 796 => 'Mizoram',
        $three >= 797 && $three <= 798 => 'Nagaland',
        $three === 799 => 'Tripura',
        $three >= 814 && $three <= 835 => 'Jharkhand',
        $prefix === 11 => 'Delhi',
        $prefix >= 12 && $prefix <= 13 => 'Haryana',
        $prefix >= 14 && $prefix <= 16 => 'Punjab',
        $prefix === 17 => 'Himachal Pradesh',
        $prefix >= 18 && $prefix <= 19 => 'Jammu & Kashmir',
        in_array($three, [244, 246, 248, 249, 262, 263], true) => 'Uttarakhand',
        $prefix >= 20 && $prefix <= 28 => 'Uttar Pradesh',
        $prefix >= 30 && $prefix <= 34 => 'Rajasthan',
        $prefix >= 36 && $prefix <= 39 => 'Gujarat',
        $prefix >= 40 && $prefix <= 44 => 'Maharashtra',
        $prefix >= 45 && $prefix <= 48 => 'Madhya Pradesh',
        $prefix === 49 => 'Chhattisgarh',
        $prefix >= 60 && $prefix <= 66 => 'Tamil Nadu',
        $prefix >= 67 && $prefix <= 69 => 'Kerala',
        $prefix >= 56 && $prefix <= 59 => 'Karnataka',
        $prefix === 50 => 'Telangana',
        $prefix >= 51 && $prefix <= 53 => 'Andhra Pradesh',
        $prefix >= 70 && $prefix <= 74 => 'West Bengal',
        $prefix >= 75 && $prefix <= 77 => 'Odisha',
        $prefix === 78 => 'Assam',
        $prefix >= 80 && $prefix <= 85 => 'Bihar',
        default => 'India',
    };
    return ['district' => '', 'state_name' => $state, 'area_name' => ''];
}

function deliveryQuoteForPincode(mysqli $database, string $pincode): array
{
    $blockedStatement = $database->prepare(
        'SELECT reason FROM delivery_blocked_pincodes WHERE pincode = ? AND active = 1 LIMIT 1'
    );
    $blockedStatement->bind_param('s', $pincode);
    $blockedStatement->execute();
    $blocked = $blockedStatement->get_result()->fetch_assoc();
    $blockedStatement->close();
    if ($blocked) {
        throw new DomainException('Delivery is not currently available for this PIN code.');
    }

    $overrideStatement = $database->prepare(
        'SELECT area_name, delivery_fee, min_delivery_days, max_delivery_days
         FROM delivery_pincodes WHERE pincode = ? AND active = 1 LIMIT 1'
    );
    $overrideStatement->bind_param('s', $pincode);
    $overrideStatement->execute();
    $override = $overrideStatement->get_result()->fetch_assoc();
    $overrideStatement->close();

    $location = lookupPostalLocation($database, $pincode);
    if ($location === null) {
        throw new DomainException('We could not identify this PIN code. Check it and try again.');
    }
    $state = (string) $location['state_name'];

    if ($override) {
        return [
            'areaName' => (string) ($override['area_name'] ?: $location['area_name']),
            'district' => (string) $location['district'],
            'state' => $state,
            'deliveryFee' => (float) $override['delivery_fee'],
            'minDays' => (int) $override['min_delivery_days'],
            'maxDays' => (int) $override['max_delivery_days'],
        ];
    }

    $rateStatement = $database->prepare(
        'SELECT delivery_fee, min_delivery_days, max_delivery_days
         FROM delivery_state_rates
         WHERE active = 1 AND state_name IN (?, \'*\')
         ORDER BY state_name = ? DESC LIMIT 1'
    );
    $rateStatement->bind_param('ss', $state, $state);
    $rateStatement->execute();
    $rate = $rateStatement->get_result()->fetch_assoc();
    $rateStatement->close();
    if (!$rate) {
        throw new DomainException('Delivery is not configured for this state.');
    }

    return [
        'areaName' => (string) $location['area_name'],
        'district' => (string) $location['district'],
        'state' => $state,
        'deliveryFee' => (float) $rate['delivery_fee'],
        'minDays' => (int) $rate['min_delivery_days'],
        'maxDays' => (int) $rate['max_delivery_days'],
    ];
}

function deliveryDateAfterWorkingDays(int $workingDays, ?DateTimeImmutable $from = null): string
{
    $date = $from ?? new DateTimeImmutable('today');
    $remaining = max(0, $workingDays);
    while ($remaining > 0) {
        $date = $date->modify('+1 day');
        if ((int) $date->format('N') <= 5) {
            --$remaining;
        }
    }
    return $date->format('Y-m-d');
}
