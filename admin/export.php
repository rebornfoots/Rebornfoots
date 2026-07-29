<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
requireAdmin();

if (!validCsrf($_GET['token'] ?? null)) {
    http_response_code(403);
    exit('Invalid export request.');
}

$search = trim((string) ($_GET['q'] ?? ''));
$status = (string) ($_GET['status'] ?? '');
$status = in_array($status, ADMIN_STATUSES, true) ? $status : '';
$conditions = [];
$types = '';
$parameters = [];

if ($status !== '') {
    $conditions[] = 'status = ?';
    $types .= 's';
    $parameters[] = $status;
}
if ($search !== '') {
    $conditions[] = '(CAST(id AS CHAR) = ? OR customer_name LIKE ? OR phone LIKE ?)';
    $types .= 'sss';
    $parameters[] = ltrim($search, '#');
    $likeSearch = '%' . $search . '%';
    $parameters[] = $likeSearch;
    $parameters[] = $likeSearch;
}
$where = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);

function csvSafe(mixed $value): string
{
    $text = (string) $value;
    return preg_match('/^[=+\-@]/', $text) ? "'" . $text : $text;
}

try {
    $statement = database()->prepare(
        'SELECT id, customer_name, phone, address, postal_code, payment_method, payment_status,
                gateway_order_id, gateway_payment_id, order_details, subtotal, delivery_fee,
                coupon_code, coupon_discount, combo_discount, discount_total, total, status,
                courier_name, tracking_number, estimated_delivery_date, created_at, updated_at
         FROM orders' . $where . ' ORDER BY created_at DESC, id DESC LIMIT 10000'
    );
    if ($types !== '') {
        $statement->bind_param($types, ...$parameters);
    }
    $statement->execute();
    $result = $statement->get_result();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="inbornfoot-orders-' . date('Y-m-d-His') . '.csv"');
    echo "\xEF\xBB\xBF";
    $output = fopen('php://output', 'wb');
    fputcsv($output, ['Order ID', 'Customer', 'Phone', 'Address', 'PIN Code', 'Payment Method', 'Payment Status', 'Gateway Order', 'Gateway Payment', 'Items', 'Subtotal', 'Delivery Fee', 'Coupon', 'Coupon Discount', 'Combo Discount', 'Total Discount', 'Total', 'Order Status', 'Courier', 'Tracking Number', 'Estimated Delivery', 'Created', 'Updated']);

    while ($order = $result->fetch_assoc()) {
        $itemDescriptions = [];
        foreach (normalizedOrderItems((string) $order['order_details']) as $item) {
            $description = (string) ($item['name'] ?? 'Item');
            if (!empty($item['variant'])) {
                $description .= ' (' . $item['variant'] . ')';
            }
            if (($item['quantity'] ?? '') !== '') {
                $description .= ' x ' . $item['quantity'];
            }
            $itemDescriptions[] = $description;
        }
        fputcsv($output, array_map('csvSafe', [
            $order['id'],
            $order['customer_name'],
            $order['phone'],
            $order['address'],
            $order['postal_code'],
            $order['payment_method'],
            $order['payment_status'],
            $order['gateway_order_id'],
            $order['gateway_payment_id'],
            implode('; ', $itemDescriptions),
            $order['subtotal'],
            $order['delivery_fee'],
            $order['coupon_code'],
            $order['coupon_discount'],
            $order['combo_discount'],
            $order['discount_total'],
            $order['total'],
            $order['status'],
            $order['courier_name'],
            $order['tracking_number'],
            $order['estimated_delivery_date'],
            $order['created_at'],
            $order['updated_at'],
        ]));
    }
    fclose($output);
    $statement->close();
} catch (Throwable $error) {
    error_log('InbornFoot CSV export error: ' . $error->getMessage());
    http_response_code(500);
    exit('The export could not be generated.');
}
