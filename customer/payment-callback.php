<?php
session_start();
require_once '../config/database.php';
require_once '../config/paymongo.php';
require_once '../vendor/autoload.php';
require_once '../config/vapid.php';

if (!isset($_SESSION['customer_id'])) {
  header('Location: ../auth/login.php');
  exit;
}

$customerId = $_SESSION['customer_id'];
$transactionId = (int) ($_GET['txn'] ?? 0);
$status = $_GET['status'] ?? '';

function refValues($arr)
{
  $refs = [];
  foreach ($arr as $key => $value) {
    $refs[$key] = &$arr[$key];
  }
  return $refs;
}

function createNotification($conn, $userType, $userId, $title, $message, $link = null)
{
  $stmt = $conn->prepare("INSERT INTO notifications (user_type, user_id, title, message, link) VALUES (?, ?, ?, ?, ?)");
  $stmt->bind_param("sisss", $userType, $userId, $title, $message, $link);
  $stmt->execute();
  $stmt->close();

  sendPushNotification($conn, $userType, $userId, $title, $message, $link);
}

function sendPushNotification($conn, $userType, $userId, $title, $message, $link)
{
  $stmt = $conn->prepare("SELECT endpoint, p256dh_key, auth_key FROM push_subscriptions WHERE user_type = ? AND user_id = ?");
  $stmt->bind_param("si", $userType, $userId);
  $stmt->execute();
  $result = $stmt->get_result();

  $subscriptions = [];
  while ($row = $result->fetch_assoc()) {
    $subscriptions[] = $row;
  }
  $stmt->close();

  if (empty($subscriptions)) {
    return;
  }

  $auth = [
    'VAPID' => [
      'subject' => VAPID_SUBJECT,
      'publicKey' => VAPID_PUBLIC_KEY,
      'privateKey' => VAPID_PRIVATE_KEY,
    ],
  ];

  try {
    $webPush = new \Minishlink\WebPush\WebPush($auth);
    $payload = json_encode(['title' => $title, 'message' => $message, 'link' => $link]);

    foreach ($subscriptions as $sub) {
      $subscription = \Minishlink\WebPush\Subscription::create([
        'endpoint' => $sub['endpoint'],
        'publicKey' => $sub['p256dh_key'],
        'authToken' => $sub['auth_key'],
      ]);
      $webPush->queueNotification($subscription, $payload);
    }

    foreach ($webPush->flush() as $report) {
    }
  } catch (\Throwable $e) {
    return;
  }
}

function retrievePaymongoSource($sourceId)
{
  $ch = curl_init('https://api.paymongo.com/v1/sources/' . urlencode($sourceId));
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Accept: application/json',
    'Authorization: Basic ' . base64_encode(PAYMONGO_SECRET_KEY . ':'),
  ]);
  $response = curl_exec($ch);
  $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if ($httpCode >= 400) {
    return null;
  }

  return json_decode($response, true);
}

function retrievePaymongoPaymentIntent($paymentIntentId)
{
  $ch = curl_init('https://api.paymongo.com/v1/payment_intents/' . urlencode($paymentIntentId));
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Accept: application/json',
    'Authorization: Basic ' . base64_encode(PAYMONGO_SECRET_KEY . ':'),
  ]);
  $response = curl_exec($ch);
  $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if ($httpCode >= 400) {
    return null;
  }

  return json_decode($response, true);
}

function createPaymongoPayment($sourceId, $amount, $description)
{
  $amountInCentavos = (int) round($amount * 100);

  $payload = [
    'data' => [
      'attributes' => [
        'amount' => $amountInCentavos,
        'currency' => 'PHP',
        'description' => $description,
        'source' => [
          'id' => $sourceId,
          'type' => 'source',
        ],
      ],
    ],
  ];

  $ch = curl_init('https://api.paymongo.com/v1/payments');
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_POST, true);
  curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
  curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Accept: application/json',
    'Authorization: Basic ' . base64_encode(PAYMONGO_SECRET_KEY . ':'),
  ]);
  $response = curl_exec($ch);
  $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if ($httpCode >= 400) {
    return null;
  }

  return json_decode($response, true);
}

function createOrdersFromCheckoutData($conn, $customerId, $checkoutData)
{
  $orderType = $checkoutData['order_type'];
  $location = $checkoutData['location'];
  $paymentMethod = $checkoutData['payment_method'];
  $customerFullName = $checkoutData['customer_full_name'];

  $createdOrderIds = [];
  $allCartIds = [];

  foreach ($checkoutData['groups'] as $group) {
    $stmt = $conn->prepare("
            INSERT INTO orders
                (customer_id, stall_id, owner_id, staff_id, order_type, status, payment_method, payment_status,
                 total_amount, total_delivery_fee, grand_total, drop_off_location, note)
            VALUES (?, ?, ?, ?, ?, 'pending', ?, 'paid', ?, ?, ?, ?, ?)
        ");
    $stmt->bind_param(
      "iiiissdddss",
      $customerId,
      $group['stall_id'],
      $group['owner_id'],
      $group['staff_id'],
      $orderType,
      $paymentMethod,
      $group['total_amount'],
      $group['delivery_fee'],
      $group['grand_total'],
      $location,
      $group['note']
    );
    $stmt->execute();
    $newOrderId = $stmt->insert_id;
    $stmt->close();

    $createdOrderIds[] = $newOrderId;

    if ($group['owner_id'] !== null) {
      $friendlyOrderId = 'ORD-' . date('Y') . '-' . str_pad($newOrderId, 6, '0', STR_PAD_LEFT);
      createNotification(
        $conn,
        'stall_owner',
        $group['owner_id'],
        'New Order Received',
        $customerFullName . ' placed order ' . $friendlyOrderId . ' worth ₱' . number_format($group['grand_total'], 2) . '.',
        '../stall/orders.php'
      );
    }

    $itemStmt = $conn->prepare("
            INSERT INTO order_items (order_id, menu_item_id, item_name, unit_price, quantity)
            VALUES (?, ?, ?, ?, ?)
        ");
    foreach ($group['items'] as $item) {
      $itemStmt->bind_param(
        "iisdi",
        $newOrderId,
        $item['menu_item_id'],
        $item['item_name'],
        $item['price'],
        $item['quantity']
      );
      $itemStmt->execute();
    }
    $itemStmt->close();

    foreach ($group['cart_ids'] as $cid) {
      $allCartIds[] = $cid;
    }
  }

  if (!empty($allCartIds)) {
    $placeholders = implode(',', array_fill(0, count($allCartIds), '?'));
    $types = 'i' . str_repeat('i', count($allCartIds));
    $deleteStmt = $conn->prepare("DELETE FROM carts WHERE customer_id = ? AND cart_id IN ($placeholders)");
    $params = array_merge([$types, $customerId], $allCartIds);
    call_user_func_array([$deleteStmt, 'bind_param'], refValues($params));
    $deleteStmt->execute();
    $deleteStmt->close();
  }

  return $createdOrderIds;
}

$outcome = 'failed';
$orderCount = 0;

if ($transactionId > 0) {
  $txnStmt = $conn->prepare("SELECT transaction_id, checkout_data, amount, status, paymongo_source_id, payment_method FROM payment_transactions WHERE transaction_id = ? AND customer_id = ? LIMIT 1");
  $txnStmt->bind_param("ii", $transactionId, $customerId);
  $txnStmt->execute();
  $txn = $txnStmt->get_result()->fetch_assoc();
  $txnStmt->close();

  if ($txn) {
    $referenceId = $txn['paymongo_source_id'];
    $paymentVerified = false;
    $paymentId = null;

    if ($txn['status'] === 'paid') {
      $outcome = 'success';
      $checkoutData = json_decode($txn['checkout_data'], true);
      $orderCount = count($checkoutData['groups']);
    } elseif ($status === 'success' && $txn['payment_method'] === 'gcash') {
      $sourceData = retrievePaymongoSource($referenceId);

      if ($sourceData && ($sourceData['data']['attributes']['status'] ?? '') === 'chargeable') {
        $paymentResult = createPaymongoPayment($referenceId, $txn['amount'], 'NWSSU Food Court Order Payment');

        if ($paymentResult && isset($paymentResult['data']['id'])) {
          $paymentId = $paymentResult['data']['id'];
          $paymentVerified = true;
        }
      }
    } elseif ($status === 'success' && $txn['payment_method'] === 'paymaya') {
      $intentData = retrievePaymongoPaymentIntent($referenceId);
      $intentStatus = $intentData['data']['attributes']['status'] ?? '';

      $retriesLeft = 4;
      while ($intentStatus === 'processing' && $retriesLeft > 0) {
        sleep(1);
        $intentData = retrievePaymongoPaymentIntent($referenceId);
        $intentStatus = $intentData['data']['attributes']['status'] ?? '';
        $retriesLeft--;
      }

      if ($intentStatus === 'succeeded') {
        $paymentId = $intentData['data']['attributes']['payments'][0]['id'] ?? $referenceId;
        $paymentVerified = true;
      }
    }

    if ($paymentVerified) {
      $checkoutData = json_decode($txn['checkout_data'], true);
      $newOrderIds = createOrdersFromCheckoutData($conn, $customerId, $checkoutData);
      $orderIdsCsv = implode(',', $newOrderIds);
      $orderCount = count($newOrderIds);

      $updateTxnStmt = $conn->prepare("UPDATE payment_transactions SET paymongo_payment_id = ?, order_ids = ?, status = 'paid' WHERE transaction_id = ?");
      $updateTxnStmt->bind_param("ssi", $paymentId, $orderIdsCsv, $txn['transaction_id']);
      $updateTxnStmt->execute();
      $updateTxnStmt->close();

      $outcome = 'success';
    }

    if ($outcome === 'failed') {
      $updateTxnStmt = $conn->prepare("UPDATE payment_transactions SET status = 'failed' WHERE transaction_id = ? AND status = 'pending'");
      $updateTxnStmt->bind_param("i", $txn['transaction_id']);
      $updateTxnStmt->execute();
      $updateTxnStmt->close();
    }
  }
}

$conn->close();
?>
<!doctype html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Payment Status - NWSSU Food Court</title>
  <link rel="icon" href="../assets/images/nwssu-logo.png" type="image/png" />
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://unpkg.com/@lottiefiles/dotlottie-wc@0.9.4/dist/dotlottie-wc.js" type="module"></script>
  <style>
    @import url("https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap");

    * {
      font-family: "Poppins", sans-serif;
    }

    body {
      background: #ffffff;
      min-height: 100vh;
      margin: 0;
    }
  </style>
</head>

<body class="bg-white">
  <div class="flex flex-col items-center justify-center min-h-screen px-6 text-center">
    <?php if ($outcome === 'success'): ?>
      <div class="w-48 h-48 mb-6">
        <dotlottie-wc
          src="../assets/illustrations/success.lottie"
          style="width: 100%; height: 100%"
          autoplay>
        </dotlottie-wc>
      </div>
      <h1 class="text-xl font-bold text-gray-800">Payment Successful</h1>
      <p class="text-sm text-gray-500 mt-2 max-w-xs">
        <?php echo $orderCount > 1 ? "Your {$orderCount} orders have" : "Your order has"; ?> been sent to the stall. You can track its progress anytime.
      </p>
      <div class="w-full max-w-xs mt-8 flex gap-2">
        <a href="./order.php" class="flex-1 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold transition-colors rounded-[3px] flex items-center justify-center">
          View My Orders
        </a>
        <a href="./home.php" class="flex-1 py-2.5 border border-gray-200 text-gray-700 text-xs font-semibold hover:bg-gray-50 transition-colors rounded-[3px] flex items-center justify-center">
          Continue Shopping
        </a>
      </div>
    <?php else: ?>
      <div class="w-20 h-20 bg-red-50 flex items-center justify-center rounded-full mb-4">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-10 h-10 text-red-500">
          <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
        </svg>
      </div>
      <h1 class="text-xl font-bold text-gray-800">Payment Not Completed</h1>
      <p class="text-sm text-gray-500 mt-2 max-w-xs">
        Your payment was not completed, so no order was placed. Your cart items are still saved, and you can try checking out again.
      </p>
      <div class="w-full max-w-xs mt-8 flex gap-2">
        <a href="./cart.php" class="flex-1 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold transition-colors rounded-[3px] flex items-center justify-center">
          Back to Cart
        </a>
        <a href="./home.php" class="flex-1 py-2.5 border border-gray-200 text-gray-700 text-xs font-semibold hover:bg-gray-50 transition-colors rounded-[3px] flex items-center justify-center">
          Continue Shopping
        </a>
      </div>
    <?php endif; ?>
  </div>
</body>

</html>