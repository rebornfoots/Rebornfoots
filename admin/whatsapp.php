<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Method not allowed.');
}
if (!validCsrf($_POST['token'] ?? null)) {
    http_response_code(403);
    exit('Invalid WhatsApp request.');
}

$orderId = filter_var($_POST['order_id'] ?? null, FILTER_VALIDATE_INT);
$template = (string) ($_POST['template'] ?? '');
if ($orderId === false || $orderId < 1 || !preg_match('/^[a-z_]{3,40}$/', $template)) {
    http_response_code(422);
    exit('Invalid WhatsApp request.');
}

try {
    $statement = database()->prepare(
        'SELECT orders.id, orders.customer_name, orders.phone, orders.order_details, orders.total,
                orders.status, orders.payment_status, orders.courier_name, orders.tracking_number,
                orders.tracking_url, orders.estimated_delivery_date,
                payment_refunds.status AS refund_status
         FROM orders
         LEFT JOIN payment_refunds ON payment_refunds.order_id = orders.id
         WHERE orders.id = ? LIMIT 1'
    );
    $statement->bind_param('i', $orderId);
    $statement->execute();
    $order = $statement->get_result()->fetch_assoc();
    $statement->close();
    if (!$order) {
        throw new DomainException('The order was not found.');
    }
    $availableTemplates = whatsappMessageOptions($order);
    if (!isset($availableTemplates[$template])) {
        throw new DomainException('That message does not match the current order status.');
    }

    $phone = preg_replace('/\D/', '', (string) $order['phone']) ?? '';
    if (strlen($phone) === 12 && str_starts_with($phone, '91')) {
        $phone = substr($phone, 2);
    }
    if (!preg_match('/^[6-9]\d{9}$/', $phone)) {
        throw new DomainException('The customer phone number is invalid.');
    }

    $safeHost = preg_replace('/[^a-zA-Z0-9.:\-\[\]]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'your-domain.com'));
    $https = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $trackingUrl = ($https ? 'https://' : 'http://') . $safeHost . '/track-order/';
    $name = trim((string) $order['customer_name']);
    $orderNumber = '#' . $order['id'];
    $total = '₹' . number_format((float) $order['total'], 0);
    $greeting = "Hello {$name},";

    $messages = [
        'order_received' => "{$greeting}\n\nWe have received your InbornFood order {$orderNumber} for {$total}. We will confirm it shortly.\n\nTrack your order: {$trackingUrl}",
        'order_confirmed' => "{$greeting}\n\nYour InbornFood order {$orderNumber} has been confirmed. We will notify you when it is packed.\n\nTrack your order: {$trackingUrl}",
        'payment_confirmed' => "{$greeting}\n\nPayment of {$total} for your InbornFood order {$orderNumber} has been confirmed securely. Thank you!\n\nTrack your order: {$trackingUrl}",
        'order_packed' => "{$greeting}\n\nYour InbornFood order {$orderNumber} is packed and getting ready for dispatch.\n\nTrack your order: {$trackingUrl}",
        'order_delivered' => "{$greeting}\n\nYour InbornFood order {$orderNumber} has been delivered. Thank you for choosing InbornFood!",
        'order_cancelled' => "{$greeting}\n\nYour InbornFood order {$orderNumber} has been cancelled. If payment was collected, we will update you separately about the refund.",
        'refund_pending' => "{$greeting}\n\nYour refund of {$total} for InbornFood order {$orderNumber} has been initiated. We will notify you when Razorpay confirms completion.",
        'refund_processed' => "{$greeting}\n\nYour refund of {$total} for InbornFood order {$orderNumber} has been processed successfully. Your bank may take a few working days to reflect it.",
    ];
    if ($template === 'order_shipped') {
        $parts = [
            $greeting,
            '',
            "Your InbornFood order {$orderNumber} has been shipped.",
        ];
        if ((string) $order['courier_name'] !== '') {
            $parts[] = 'Courier: ' . $order['courier_name'];
        }
        if ((string) $order['tracking_number'] !== '') {
            $parts[] = 'Tracking number: ' . $order['tracking_number'];
        }
        if ((string) $order['tracking_url'] !== '') {
            $parts[] = 'Courier tracking: ' . $order['tracking_url'];
        }
        if ((string) $order['estimated_delivery_date'] !== '') {
            $parts[] = 'Estimated delivery: ' . date('d M Y', strtotime((string) $order['estimated_delivery_date']));
        }
        $parts[] = '';
        $parts[] = 'Order status: ' . $trackingUrl;
        $messages['order_shipped'] = implode("\n", $parts);
    }

    header('Location: https://wa.me/91' . $phone . '?text=' . rawurlencode($messages[$template]));
    exit;
} catch (DomainException $error) {
    http_response_code(409);
    exit(h($error->getMessage()));
} catch (Throwable $error) {
    error_log('InbornFood WhatsApp message error: ' . $error->getMessage());
    http_response_code(500);
    exit('The WhatsApp message could not be prepared.');
}
