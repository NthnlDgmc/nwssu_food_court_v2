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

$profileCheckStmt = $conn->prepare("SELECT email, contact_number FROM customers WHERE customer_id = ? LIMIT 1");
$profileCheckStmt->bind_param("i", $customerId);
$profileCheckStmt->execute();
$profileCheckRow = $profileCheckStmt->get_result()->fetch_assoc();
$profileCheckStmt->close();

if (!$profileCheckRow || empty($profileCheckRow['email']) || empty($profileCheckRow['contact_number'])) {
  header('Location: ../auth/complete-profile.php');
  exit;
}

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
$displayOrderId = '';
$displayAmount = 0.00;
$displayPaymentMethod = '';

if ($transactionId > 0) {
  $txnStmt = $conn->prepare("SELECT transaction_id, checkout_data, amount, status, paymongo_source_id, payment_method, order_ids FROM payment_transactions WHERE transaction_id = ? AND customer_id = ? LIMIT 1");
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

    if ($outcome === 'success') {
      $displayAmount = (float) $txn['amount'];
      $displayPaymentMethod = $txn['payment_method'] === 'gcash' ? 'GCash' : 'Maya';

      if (isset($newOrderIds) && !empty($newOrderIds)) {
        $rawOrderIds = $newOrderIds;
      } else {
        $rawOrderIds = array_map('intval', explode(',', $txn['order_ids']));
      }

      $formattedIds = array_map(function ($oid) {
        return 'ORD-' . date('Y') . '-' . str_pad($oid, 6, '0', STR_PAD_LEFT);
      }, $rawOrderIds);

      $displayOrderId = implode(', ', $formattedIds);
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
  <link rel="manifest" href="../manifest.json" />
  <meta name="apple-mobile-web-app-capable" content="yes" />
  <meta name="apple-mobile-web-app-status-bar-style" content="default" />
  <meta name="apple-mobile-web-app-title" content="Norwesso Eats" />
  <link rel="apple-touch-icon" href="../assets/images/icon-192.png" />
  <link href="../assets/css/tailwind.css" rel="stylesheet" />
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
      <div class="w-48 h-48 mb-4">
        <dotlottie-wc
          src="../assets/illustrations/success.lottie"
          style="width: 100%; height: 100%"
          autoplay>
        </dotlottie-wc>
      </div>
      <h1 class="text-xl font-bold text-gray-800">Payment Successful</h1>
      <p class="text-sm text-gray-500 mt-2 max-w-xs">
        Your payment of ₱<?php echo number_format($displayAmount, 2); ?> has been completed successfully
      </p>

      <div class="w-full max-w-xs border-t border-gray-100 my-4"></div>

      <div class="w-full max-w-xs space-y-3.5 text-left">
        <div class="flex items-center gap-3">
          <span class="w-8 h-8 bg-gray-100 flex items-center justify-center shrink-0 rounded-[3px]">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 text-gray-500">
              <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 0 0 2.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 0 0-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 0 0 .75-.75 2.25 2.25 0 0 0-.1-.664m-5.8 0A2.251 2.251 0 0 1 13.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25Z" />
            </svg>
          </span>
          <div class="min-w-0">
            <p class="text-[10px] text-gray-400">Order ID</p>
            <p class="text-xs font-medium text-gray-800 truncate"><?php echo htmlspecialchars($displayOrderId); ?></p>
          </div>
        </div>

        <div class="flex items-center gap-3">
          <span class="w-8 h-8 bg-gray-100 flex items-center justify-center shrink-0 rounded-[3px]">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 text-gray-500">
              <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5" />
            </svg>
          </span>
          <div class="min-w-0">
            <p class="text-[10px] text-gray-400">Date &amp; Time</p>
            <p class="text-xs font-medium text-gray-800"><?php echo date('M j, Y \\· g:i A'); ?></p>
          </div>
        </div>

        <div class="flex items-center gap-3">
          <span class="w-8 h-8 bg-gray-100 flex items-center justify-center shrink-0 rounded-[3px]">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 text-gray-500">
              <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25v10.5A2.25 2.25 0 0 0 4.5 19.5Z" />
            </svg>
          </span>
          <div class="min-w-0">
            <p class="text-[10px] text-gray-400">Payment Method</p>
            <p class="text-xs font-medium text-gray-800"><?php echo htmlspecialchars($displayPaymentMethod); ?></p>
          </div>
        </div>
      </div>

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
  <?php if ($outcome === 'success'): ?>
  <script>
    localStorage.removeItem("cart_orderType");
    localStorage.removeItem("cart_location");
    localStorage.removeItem("cart_paymentMethod");
  </script>
  <?php endif; ?>
</body>

</html>