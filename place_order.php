<?php
header('Content-Type: application/json');

$allowedOrigin = "https://farmers2home.com/";

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$referer = $_SERVER['HTTP_REFERER'] ?? '';

if (
    ($origin && strpos($origin, $allowedOrigin) !== 0) &&
    ($referer && strpos($referer, $allowedOrigin) !== 0)
) {
    http_response_code(403);
    exit(json_encode([
        "status" => "error",
        "message" => "Access Denied"
    ]));
}

$conn = new mysqli(
    "MYSQL5044.site4now.net",
    "aa83bc_farm2ho",
    "farm2ho@001",
    "db_aa83bc_farm2ho"
);

function sendTelegram($message)
{
    $botToken = "8956076251:AAGdCs9M2SmTvVPr5--nGJSnbv_vDNl5G7E";
    $chatId   = "977136414";

    $url = "https://api.telegram.org/bot".$botToken."/sendMessage";

    $data = [
        "chat_id" => $chatId,
        "text" => $message,
        "parse_mode" => "HTML"
    ];

    $options = [
        "http" => [
            "header"  => "Content-type: application/x-www-form-urlencoded\r\n",
            "method"  => "POST",
            "content" => http_build_query($data)
        ]
    ];

    $context = stream_context_create($options);
    @file_get_contents($url, false, $context);
}

if ($conn->connect_error) {
    die(json_encode([
        "status"=>"error",
        "message"=>$conn->connect_error
    ]));
}

$name = $_POST['name'];
$phone = $_POST['phone'];
$address = $_POST['address'];
$payment = $_POST['payment'];

$cart = json_decode($_POST['cart'], true);

$total = 0;
$orderDetails = "";

foreach($cart as $item){

    $subtotal = $item['price'] * $item['qty'];

    $total += $subtotal;

    $orderDetails .=
        $item['name'] .
        " (" . $item['weight'] . ")" .
        " Qty:" . $item['qty'] .
        " ₹" . $subtotal . "\n";
}

$stmt = $conn->prepare("
INSERT INTO orders
(customer_name, phone, address, payment_method, order_details, total)
VALUES (?, ?, ?, ?, ?, ?)
");

if(!$stmt){
    die(json_encode([
        "status"=>"error",
        "message"=>$conn->error
    ]));
}

$stmt->bind_param(
    "sssssd",
    $name,
    $phone,
    $address,
    $payment,
    $orderDetails,
    $total
);

if($stmt->execute()){
$orderId = $conn->insert_id;
//================ TELEGRAM MESSAGE =================//

    $telegram = "";

    $telegram .= "🧈 <b>Farmers2Home ORDER</b>\n\n";

    $telegram .= "🆔 <b>Order ID :</b> ".$orderId."\n";
    $telegram .= "👤 <b>Name :</b> ".$name."\n";
    $telegram .= "📞 <b>Phone :</b> ".$phone."\n";
    $telegram .= "💳 <b>Payment :</b> ".$payment."\n\n";

    $telegram .= "📍 <b>Delivery Address</b>\n";
    $telegram .= $address."\n\n";

    $telegram .= "🛒 <b>Order Items</b>\n";
    $telegram .= "-----------------------------\n";

    foreach($cart as $item){

        $subtotal = $item['price'] * $item['qty'];

        $telegram .= "• ".$item['name']." (".$item['weight'].")\n";
        $telegram .= "   Qty : ".$item['qty']."\n";
        $telegram .= "   Price : ₹".$item['price']."\n";
        $telegram .= "   Total : ₹".$subtotal."\n\n";
    }

    $telegram .= "-----------------------------\n";
    $telegram .= "💰 <b>Grand Total : ₹".number_format($total,2)."</b>";

    sendTelegram($telegram);
    echo json_encode([
        "status"=>"success",
        "orderid"=>$orderId
    ]);

}else{

    echo json_encode([
        "status"=>"error",
        "message"=>$stmt->error
    ]);
}

$stmt->close();
$conn->close();

?>