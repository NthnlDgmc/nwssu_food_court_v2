<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['customer_id'])) {
  header('Location: ../auth/login.php');
  exit;
}

$customerId = $_SESSION['customer_id'];

$statusCheckStmt = $conn->prepare("SELECT status, customer_type, email, contact_number FROM customers WHERE customer_id = ? LIMIT 1");
$statusCheckStmt->bind_param("i", $customerId);
$statusCheckStmt->execute();
$statusCheckRow = $statusCheckStmt->get_result()->fetch_assoc();
$statusCheckStmt->close();

if (!$statusCheckRow || $statusCheckRow['status'] === 'inactive') {
  session_destroy();
  header('Location: ../auth/login.php?deactivated=1');
  exit;
}

if (empty($statusCheckRow['email']) || empty($statusCheckRow['contact_number'])) {
  header('Location: ../auth/complete-profile.php');
  exit;
}

$isGuestCustomer = $statusCheckRow['customer_type'] === 'guest';

require_once '../vendor/autoload.php';
require_once '../config/vapid.php';
require_once '../config/paymongo.php';

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

function refValues($arr)
{
  $refs = [];
  foreach ($arr as $key => $value) {
    $refs[$key] = &$arr[$key];
  }
  return $refs;
}

function generateOrderId()
{
  $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
  $random = '';

  for ($i = 0; $i < 8; $i++) {
    $random .= $alphabet[random_int(0, strlen($alphabet) - 1)];
  }

  return 'ORD-' . date('Y') . '-' . $random;
}

function createPaymongoSource($amount, $type, $successUrl, $failedUrl, $billingName = '', $billingEmail = '')
{
  $amountInCentavos = (int) round($amount * 100);

  $payload = [
    'data' => [
      'attributes' => [
        'amount' => $amountInCentavos,
        'redirect' => [
          'success' => $successUrl,
          'failed' => $failedUrl,
        ],
        'type' => $type,
        'currency' => 'PHP',
        'billing' => [
          'name' => $billingName,
          'email' => $billingEmail,
        ],
      ],
    ],
  ];

  $ch = curl_init('https://api.paymongo.com/v1/sources');
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
  $curlError = curl_error($ch);
  curl_close($ch);

  if ($curlError || $httpCode >= 400) {
    error_log('PayMongo createSource failed. cURL error: ' . $curlError . ' | HTTP code: ' . $httpCode . ' | Response: ' . $response);
    return ['error' => true, 'curlError' => $curlError, 'httpCode' => $httpCode, 'response' => $response];
  }

  $decoded = json_decode($response, true);
  if (!isset($decoded['data']['id'])) {
    error_log('PayMongo createSource unexpected response: ' . $response);
    return ['error' => true, 'curlError' => '', 'httpCode' => $httpCode, 'response' => $response];
  }

  return [
    'sourceId' => $decoded['data']['id'],
    'checkoutUrl' => $decoded['data']['attributes']['redirect']['checkout_url'],
  ];
}

function paymongoApiCall($url, $payload)
{
  $ch = curl_init($url);
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
  $curlError = curl_error($ch);
  curl_close($ch);

  if ($curlError || $httpCode >= 400) {
    error_log('PayMongo API call failed (' . $url . '). cURL: ' . $curlError . ' | HTTP: ' . $httpCode . ' | Response: ' . $response);
    return ['error' => true, 'curlError' => $curlError, 'httpCode' => $httpCode, 'response' => $response];
  }

  return json_decode($response, true);
}

function createMayaPaymentIntent($amount, $returnUrl, $billingName = '', $billingEmail = '')
{
  $amountInCentavos = (int) round($amount * 100);

  $intentResult = paymongoApiCall('https://api.paymongo.com/v1/payment_intents', [
    'data' => [
      'attributes' => [
        'amount' => $amountInCentavos,
        'payment_method_allowed' => ['paymaya'],
        'payment_method_options' => ['paymaya' => new stdClass()],
        'currency' => 'PHP',
        'capture_type' => 'automatic',
      ],
    ],
  ]);

  if (!empty($intentResult['error']) || !isset($intentResult['data']['id'])) {
    return $intentResult['error'] ?? ['error' => true, 'curlError' => '', 'httpCode' => 0, 'response' => json_encode($intentResult)];
  }

  $paymentIntentId = $intentResult['data']['id'];

  $methodResult = paymongoApiCall('https://api.paymongo.com/v1/payment_methods', [
    'data' => [
      'attributes' => [
        'type' => 'paymaya',
        'billing' => [
          'name' => $billingName,
          'email' => $billingEmail,
        ],
      ],
    ],
  ]);

  if (!empty($methodResult['error']) || !isset($methodResult['data']['id'])) {
    return $methodResult['error'] ?? ['error' => true, 'curlError' => '', 'httpCode' => 0, 'response' => json_encode($methodResult)];
  }

  $paymentMethodId = $methodResult['data']['id'];

  $attachResult = paymongoApiCall('https://api.paymongo.com/v1/payment_intents/' . $paymentIntentId . '/attach', [
    'data' => [
      'attributes' => [
        'payment_method' => $paymentMethodId,
        'return_url' => $returnUrl,
      ],
    ],
  ]);

  if (!empty($attachResult['error'])) {
    return $attachResult['error'];
  }

  $redirectUrl = $attachResult['data']['attributes']['next_action']['redirect']['url'] ?? null;

  if (!$redirectUrl) {
    return ['error' => true, 'curlError' => '', 'httpCode' => 0, 'response' => json_encode($attachResult)];
  }

  return [
    'paymentIntentId' => $paymentIntentId,
    'checkoutUrl' => $redirectUrl,
  ];
}

function pruneStaleCartItems($conn, $customerId)
{
  $stmt = $conn->prepare("
        DELETE c FROM carts c
        JOIN menu_items mi ON c.menu_item_id = mi.menu_item_id
        JOIN stalls s ON c.stall_id = s.stall_id
        WHERE c.customer_id = ?
          AND (s.owner_id IS NULL OR mi.owner_id <> s.owner_id)
    ");
  $stmt->bind_param("i", $customerId);
  $stmt->execute();
  $removedCount = $stmt->affected_rows;
  $stmt->close();
  return $removedCount;
}

function fetchCartData($conn, $customerId)
{
  $stmt = $conn->prepare("
        SELECT c.cart_id, c.quantity, c.note,
               mi.menu_item_id, mi.item_name, mi.price, mi.image, mi.status AS item_status,
               s.stall_id, s.stall_name,
               COALESCE(so.delivery_fee, 0.00) AS delivery_fee
        FROM carts c
        JOIN menu_items mi ON c.menu_item_id = mi.menu_item_id
        JOIN stalls s ON c.stall_id = s.stall_id
        LEFT JOIN stall_owners so ON s.owner_id = so.owner_id
        WHERE c.customer_id = ?
        ORDER BY s.stall_name ASC, c.created_at ASC
    ");
  $stmt->bind_param("i", $customerId);
  $stmt->execute();
  $result = $stmt->get_result();
  $items = [];
  while ($row = $result->fetch_assoc()) {
    $items[] = [
      'cart_id'            => (int) $row['cart_id'],
      'quantity'           => (int) $row['quantity'],
      'note'               => $row['note'],
      'menu_item_id'       => (int) $row['menu_item_id'],
      'item_name'          => $row['item_name'],
      'price'              => (float) $row['price'],
      'image'              => $row['image'] ? '../' . $row['image'] : null,
      'is_available'       => $row['item_status'] === 'available',
      'stall_id'           => (int) $row['stall_id'],
      'stall_name'         => $row['stall_name'],
      'stall_delivery_fee' => (float) $row['delivery_fee'],
    ];
  }
  $stmt->close();
  return $items;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
  header('Content-Type: application/json');
  $action = $_POST['action'];

  if ($action === 'update_quantity') {
    $cartId   = (int) ($_POST['cart_id'] ?? 0);
    $quantity = (int) ($_POST['quantity'] ?? 1);

    if ($cartId <= 0 || $quantity < 1) {
      echo json_encode(['success' => false, 'message' => 'Invalid data.']);
      $conn->close();
      exit;
    }

    pruneStaleCartItems($conn, $customerId);

    $stmt = $conn->prepare("UPDATE carts SET quantity = ? WHERE cart_id = ? AND customer_id = ?");
    $stmt->bind_param("iii", $quantity, $cartId, $customerId);
    $ok = $stmt->execute();
    $stmt->close();

    if (!$ok) {
      echo json_encode(['success' => false, 'message' => 'Failed to update quantity.']);
      $conn->close();
      exit;
    }

    echo json_encode(['success' => true, 'cart' => fetchCartData($conn, $customerId)]);
    $conn->close();
    exit;
  }

  if ($action === 'remove_item') {
    $cartId = (int) ($_POST['cart_id'] ?? 0);

    if ($cartId <= 0) {
      echo json_encode(['success' => false, 'message' => 'Invalid item.']);
      $conn->close();
      exit;
    }

    pruneStaleCartItems($conn, $customerId);

    $stmt = $conn->prepare("DELETE FROM carts WHERE cart_id = ? AND customer_id = ?");
    $stmt->bind_param("ii", $cartId, $customerId);
    $ok = $stmt->execute();
    $stmt->close();

    if (!$ok) {
      echo json_encode(['success' => false, 'message' => 'Failed to remove item.']);
      $conn->close();
      exit;
    }

    echo json_encode(['success' => true, 'cart' => fetchCartData($conn, $customerId)]);
    $conn->close();
    exit;
  }

  if ($action === 'clear_cart') {
    $stmt = $conn->prepare("DELETE FROM carts WHERE customer_id = ?");
    $stmt->bind_param("i", $customerId);
    $ok = $stmt->execute();
    $stmt->close();

    echo json_encode(['success' => $ok, 'cart' => []]);
    $conn->close();
    exit;
  }

  if ($action === 'update_stall_note') {
    $stallId = (int) ($_POST['stall_id'] ?? 0);
    $note    = trim($_POST['note'] ?? '');

    if ($stallId <= 0) {
      echo json_encode(['success' => false, 'message' => 'Invalid stall.']);
      $conn->close();
      exit;
    }

    pruneStaleCartItems($conn, $customerId);

    $noteVal = $note !== '' ? $note : null;

    $stmt = $conn->prepare("UPDATE carts SET note = ? WHERE customer_id = ? AND stall_id = ?");
    $stmt->bind_param("sii", $noteVal, $customerId, $stallId);
    $ok = $stmt->execute();
    $stmt->close();

    echo json_encode(['success' => $ok]);
    $conn->close();
    exit;
  }

  if ($action === 'get_cart') {
    $removedCount = pruneStaleCartItems($conn, $customerId);
    echo json_encode([
      'success' => true,
      'cart' => fetchCartData($conn, $customerId),
      'removed_stale_count' => $removedCount,
    ]);
    $conn->close();
    exit;
  }

  if ($action === 'place_order') {
    $orderType = ($_POST['order_type'] ?? 'delivery') === 'pickup' ? 'pickup' : 'delivery';
    if ($isGuestCustomer && $orderType === 'delivery') {
      echo json_encode(['success' => false, 'message' => 'Delivery is not available for guest accounts. Please choose Pickup.']);
      $conn->close();
      exit;
    }
    $paymentMethod = $_POST['payment_method'] ?? 'cash';
    if (!in_array($paymentMethod, ['cash', 'gcash', 'paymaya'], true)) {
      $paymentMethod = 'cash';
    }
    if ($isGuestCustomer && $paymentMethod === 'cash') {
      echo json_encode(['success' => false, 'message' => 'Cash on Delivery is not available for guest accounts. Please choose GCash or PayMaya.']);
      $conn->close();
      exit;
    }
    $location = trim($_POST['location'] ?? '');

    if ($orderType === 'delivery' && $location === '') {
      echo json_encode(['success' => false, 'message' => 'Please set a drop-off location first.']);
      $conn->close();
      exit;
    }

    pruneStaleCartItems($conn, $customerId);

    $customerNameStmt = $conn->prepare("SELECT first_name, last_name, email FROM customers WHERE customer_id = ? LIMIT 1");
    $customerNameStmt->bind_param("i", $customerId);
    $customerNameStmt->execute();
    $customerNameRow = $customerNameStmt->get_result()->fetch_assoc();
    $customerNameStmt->close();
    $customerFullName = $customerNameRow ? trim($customerNameRow['first_name'] . ' ' . $customerNameRow['last_name']) : 'A customer';
    $customerEmail = $customerNameRow ? ($customerNameRow['email'] ?: '') : '';

    $stmt = $conn->prepare("
            SELECT c.cart_id, c.menu_item_id, c.stall_id, c.quantity, c.note,
                   mi.item_name, mi.price, mi.status AS item_status,
                   s.owner_id, s.staff_id,
                   COALESCE(so.delivery_fee, 0.00) AS delivery_fee
            FROM carts c
            JOIN menu_items mi ON c.menu_item_id = mi.menu_item_id
            JOIN stalls s ON c.stall_id = s.stall_id
            LEFT JOIN stall_owners so ON s.owner_id = so.owner_id
            WHERE c.customer_id = ?
            ORDER BY c.stall_id ASC
        ");
    $stmt->bind_param("i", $customerId);
    $stmt->execute();
    $result = $stmt->get_result();

    $groups = [];
    $hasUnavailableItem = false;
    while ($row = $result->fetch_assoc()) {
      if ($row['item_status'] !== 'available') {
        $hasUnavailableItem = true;
        continue;
      }
      $sid = (int) $row['stall_id'];
      if (!isset($groups[$sid])) {
        $groups[$sid] = [
          'owner_id' => $row['owner_id'] !== null ? (int) $row['owner_id'] : null,
          'staff_id' => $row['staff_id'] !== null ? (int) $row['staff_id'] : null,
          'delivery_fee' => (float) $row['delivery_fee'],
          'note' => $row['note'],
          'items' => [],
          'cart_ids' => [],
        ];
      }
      $groups[$sid]['items'][] = [
        'menu_item_id' => (int) $row['menu_item_id'],
        'item_name' => $row['item_name'],
        'price' => (float) $row['price'],
        'quantity' => (int) $row['quantity'],
      ];
      $groups[$sid]['cart_ids'][] = (int) $row['cart_id'];
    }
    $stmt->close();

    if ($hasUnavailableItem) {
      echo json_encode(['success' => false, 'message' => 'Remove out of stock items first']);
      $conn->close();
      exit;
    }

    if (empty($groups)) {
      echo json_encode(['success' => false, 'message' => 'Your cart is empty.']);
      $conn->close();
      exit;
    }

    if ($paymentMethod === 'gcash' || $paymentMethod === 'paymaya') {
      if ($customerEmail === '') {
        echo json_encode(['success' => false, 'message' => 'Please add an email address to your profile before using GCash or PayMaya.']);
        $conn->close();
        exit;
      }

      $overallGrandTotal = 0.00;
      $checkoutGroups = [];

      foreach ($groups as $stallId => $group) {
        $totalAmount = 0.00;
        foreach ($group['items'] as $item) {
          $totalAmount += $item['price'] * $item['quantity'];
        }
        $deliveryFee = $orderType === 'delivery' ? $group['delivery_fee'] : 0.00;
        $grandTotal = $totalAmount + $deliveryFee;
        $overallGrandTotal += $grandTotal;

        $checkoutGroups[] = [
          'stall_id' => $stallId,
          'owner_id' => $group['owner_id'],
          'staff_id' => $orderType === 'delivery' ? $group['staff_id'] : null,
          'note' => $group['note'],
          'items' => $group['items'],
          'cart_ids' => $group['cart_ids'],
          'total_amount' => $totalAmount,
          'delivery_fee' => $deliveryFee,
          'grand_total' => $grandTotal,
        ];
      }

      $checkoutData = json_encode([
        'order_type' => $orderType,
        'location' => $orderType === 'delivery' ? $location : null,
        'payment_method' => $paymentMethod,
        'customer_full_name' => $customerFullName,
        'groups' => $checkoutGroups,
      ]);

      $txnStmt = $conn->prepare("INSERT INTO payment_transactions (customer_id, checkout_data, payment_method, amount, status) VALUES (?, ?, ?, ?, 'pending')");
      $txnStmt->bind_param("issd", $customerId, $checkoutData, $paymentMethod, $overallGrandTotal);
      $txnStmt->execute();
      $transactionId = $txnStmt->insert_id;
      $txnStmt->close();

      $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
      $baseUrl = $protocol . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['SCRIPT_NAME']);
      $successUrl = $baseUrl . '/payment-callback.php?txn=' . $transactionId . '&status=success';
      $failedUrl = $baseUrl . '/payment-callback.php?txn=' . $transactionId . '&status=failed';

      if ($paymentMethod === 'gcash') {
        $result = createPaymongoSource($overallGrandTotal, $paymentMethod, $successUrl, $failedUrl, $customerFullName, $customerEmail);
        $referenceId = $result['sourceId'] ?? null;
      } else {
        $result = createMayaPaymentIntent($overallGrandTotal, $successUrl, $customerFullName, $customerEmail);
        $referenceId = $result['paymentIntentId'] ?? null;
      }

      if (!$result || !empty($result['error']) || !$referenceId) {
        $failStmt = $conn->prepare("UPDATE payment_transactions SET status = 'failed' WHERE transaction_id = ?");
        $failStmt->bind_param("i", $transactionId);
        $failStmt->execute();
        $failStmt->close();

        $debugDetail = $result
          ? ('cURL: ' . ($result['curlError'] ?? '') . ' | HTTP: ' . ($result['httpCode'] ?? '') . ' | Response: ' . ($result['response'] ?? ''))
          : 'Unknown error';

        echo json_encode([
          'success' => false,
          'message' => 'Failed to initialize online payment. Please try again or choose a different payment method.',
          'debug' => $debugDetail,
        ]);
        $conn->close();
        exit;
      }

      $updateSourceStmt = $conn->prepare("UPDATE payment_transactions SET paymongo_source_id = ? WHERE transaction_id = ?");
      $updateSourceStmt->bind_param("si", $referenceId, $transactionId);
      $updateSourceStmt->execute();
      $updateSourceStmt->close();

      echo json_encode([
        'success' => true,
        'requires_redirect' => true,
        'checkout_url' => $result['checkoutUrl'],
      ]);
      $conn->close();
      exit;
    }

    $createdOrderIds = [];
    $allCartIds = [];
    $cashOverallTotal = 0.00;

    foreach ($groups as $stallId => $group) {
      $totalAmount = 0.00;
      foreach ($group['items'] as $item) {
        $totalAmount += $item['price'] * $item['quantity'];
      }
      $deliveryFee = $orderType === 'delivery' ? $group['delivery_fee'] : 0.00;
      $grandTotal = $totalAmount + $deliveryFee;
      $cashOverallTotal += $grandTotal;
      $dropOffLocation = $orderType === 'delivery' ? $location : null;
      $note = $group['note'];
      $staffId = $orderType === 'delivery' ? $group['staff_id'] : null;

      $newOrderId = generateOrderId();
      $ownerId = $group['owner_id'];

      $stmt = $conn->prepare("
                INSERT INTO orders
                    (order_id, customer_id, stall_id, owner_id, staff_id, order_type, status, payment_method,
                     total_amount, total_delivery_fee, grand_total, drop_off_location, note)
                VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?, ?, ?, ?)
            ");
      $stmt->bind_param(
        "siiiissdddss",
        $newOrderId,
        $customerId,
        $stallId,
        $ownerId,
        $staffId,
        $orderType,
        $paymentMethod,
        $totalAmount,
        $deliveryFee,
        $grandTotal,
        $dropOffLocation,
        $note
      );
      $stmt->execute();
      $stmt->close();

      $createdOrderIds[] = $newOrderId;

      if ($ownerId !== null) {
        createNotification(
          $conn,
          'stall_owner',
          $ownerId,
          'New Order Received',
          $customerFullName . ' placed order ' . $newOrderId . ' worth ₱' . number_format($grandTotal, 2) . '.',
          '../stall/orders.php'
        );
      }

      $itemStmt = $conn->prepare("
                INSERT INTO order_items (order_id, menu_item_id, item_name, unit_price, quantity)
                VALUES (?, ?, ?, ?, ?)
            ");
            foreach ($group['items'] as $item) {
        $menuItemId = $item['menu_item_id'];
        $itemName = $item['item_name'];
        $itemPrice = $item['price'];
        $itemQuantity = $item['quantity'];

        $itemStmt->bind_param(
          "sisdi",
          $newOrderId,
          $menuItemId,
          $itemName,
          $itemPrice,
          $itemQuantity
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

        echo json_encode([
      'success' => true,
      'order_count' => count($createdOrderIds),
      'order_ids' => $createdOrderIds,

      'total' => $cashOverallTotal,
    ]);
    $conn->close();
    exit;
  }

  echo json_encode(['success' => false, 'message' => 'Unknown action.']);
  $conn->close();
  exit;
}

pruneStaleCartItems($conn, $customerId);
$initialCart = fetchCartData($conn, $customerId);
$conn->close();
?>
<!doctype html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta
    name="viewport"
    content="width=device-width, initial-scale=1.0, user-scalable=yes" />
  <title>Customer - My Cart</title>
  <link rel="icon" href="../assets/images/nwssu-logo.png" type="image/png" />
  <link rel="manifest" href="../manifest.json" />
  <meta name="apple-mobile-web-app-capable" content="yes" />
  <meta name="apple-mobile-web-app-status-bar-style" content="default" />
  <meta name="apple-mobile-web-app-title" content="Norwesso Eats" />
  <link rel="apple-touch-icon" href="../assets/images/icon-192.png" />
  <link href="../assets/css/tailwind.css" rel="stylesheet" />
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

    #mainContent::-webkit-scrollbar {
      width: 5px;
    }

    #mainContent::-webkit-scrollbar-track {
      background: #e2e8f0;
      border-radius: 3px;
    }

    #mainContent::-webkit-scrollbar-thumb {
      background: #059669;
      border-radius: 3px;
    }

    .modal-overlay {
      background-color: rgba(0, 0, 0, 0.5);
    }

    input[type="number"]::-webkit-inner-spin-button,
    input[type="number"]::-webkit-outer-spin-button {
      display: none;
    }

    input[type="number"] {
      appearance: textfield;
      -moz-appearance: textfield;
    }

    .filter-input:focus {
      outline: none;
      border-color: #059669;
      background: #fff;
    }

    .skeleton-bg {
      animation: skeleton-loading 1.5s infinite;
    }

    @keyframes skeleton-loading {

      0%,
      100% {
        background-color: #e5e7eb;
      }

      50% {
        background-color: #f3f4f6;
      }
    }

    .item-image-fade {
      opacity: 0;
      transition: opacity 0.35s ease;
    }

    .item-image-fade.loaded {
      opacity: 1;
    }

  </style>
</head>

<body class="bg-white">
  <div class="flex flex-col h-screen">
    <div class="bg-white flex-shrink-0 fixed top-0 left-0 right-0 z-20">
      <div class="max-w-5xl mx-auto px-4 py-2 grid grid-cols-3 items-center">
        <button
          id="backButton"
          class="p-1.5 bg-white border border-gray-200 hover:border-emerald-500 hover:bg-slate-50 transition-all justify-self-start flex items-center justify-center shrink-0"
          style="width: 34px; height: 34px; border-radius: 6px">
          <svg
            xmlns="http://www.w3.org/2000/svg"
            fill="none"
            viewBox="0 0 24 24"
            stroke-width="1.5"
            stroke="currentColor"
            class="w-5 h-5 text-gray-600">
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              d="M15.75 19.5 8.25 12l7.5-7.5" />
          </svg>
        </button>
        <h1 class="text-base font-semibold text-emerald-600 text-center">
          My Cart
        </h1>
        <button
          id="clearCartBtn"
          class="p-1.5 bg-white border border-gray-200 hover:border-emerald-500 hover:bg-slate-50 transition-all justify-self-end flex items-center justify-center shrink-0"
          style="width: 34px; height: 34px; border-radius: 6px"
          title="Clear cart">
          <svg
            xmlns="http://www.w3.org/2000/svg"
            fill="none"
            viewBox="0 0 24 24"
            stroke-width="1.5"
            stroke="currentColor"
            class="w-5 h-5 text-gray-600">
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
          </svg>
        </button>
      </div>
    </div>

    <div class="flex-1 overflow-y-auto mt-12 mb-16" id="mainContent">
      <div class="max-w-5xl mx-auto px-4 pt-3 pb-4">

        <div id="cartSkeleton">
          <div class="flex flex-col lg:flex-row gap-4 items-start">
            <div class="w-full lg:w-2/3 space-y-3">
              <div class="rounded-md bg-white border border-gray-200 overflow-hidden">
                <div class="p-3 border-b border-gray-100 flex items-center justify-between">
                  <div class="space-y-1.5">
                    <div class="h-3 w-20 skeleton-bg rounded-[3px]"></div>
                    <div class="h-2 w-12 skeleton-bg rounded-[3px]"></div>
                  </div>
                  <div class="h-6 w-16 skeleton-bg rounded-[3px]"></div>
                </div>
                <div class="flex items-center gap-3 px-4 py-3 border-b border-gray-100">
                  <div class="w-10 h-10 skeleton-bg shrink-0 rounded-[3px]"></div>
                  <div class="flex-1 space-y-1.5">
                    <div class="h-3 w-2/3 skeleton-bg rounded-[3px]"></div>
                    <div class="h-2 w-1/3 skeleton-bg rounded-[3px]"></div>
                  </div>
                  <div class="h-4 w-10 skeleton-bg shrink-0 rounded-[3px]"></div>
                </div>
                <div class="flex items-center gap-3 px-4 py-3">
                  <div class="w-10 h-10 skeleton-bg shrink-0 rounded-[3px]"></div>
                  <div class="flex-1 space-y-1.5">
                    <div class="h-3 w-1/2 skeleton-bg rounded-[3px]"></div>
                    <div class="h-2 w-1/4 skeleton-bg rounded-[3px]"></div>
                  </div>
                  <div class="h-4 w-10 skeleton-bg shrink-0 rounded-[3px]"></div>
                </div>
              </div>
              <div class="rounded-md bg-white border border-gray-200 overflow-hidden">
                <div class="p-3 border-b border-gray-100 flex items-center justify-between">
                  <div class="space-y-1.5">
                    <div class="h-3 w-20 skeleton-bg rounded-[3px]"></div>
                    <div class="h-2 w-12 skeleton-bg rounded-[3px]"></div>
                  </div>
                  <div class="h-6 w-16 skeleton-bg rounded-[3px]"></div>
                </div>
                <div class="flex items-center gap-3 px-4 py-3">
                  <div class="w-10 h-10 skeleton-bg shrink-0 rounded-[3px]"></div>
                  <div class="flex-1 space-y-1.5">
                    <div class="h-3 w-2/3 skeleton-bg rounded-[3px]"></div>
                    <div class="h-2 w-1/3 skeleton-bg rounded-[3px]"></div>
                  </div>
                  <div class="h-4 w-10 skeleton-bg shrink-0 rounded-[3px]"></div>
                </div>
              </div>
            </div>
            <div class="rounded-md w-full lg:w-1/3 bg-white border border-gray-200">
              <div class="p-4 border-b border-gray-100">
                <div class="h-3 w-24 skeleton-bg rounded-[3px]"></div>
              </div>
              <div class="p-4 space-y-4">
                <div class="h-8 w-full skeleton-bg rounded-[3px]"></div>
                <div class="h-14 w-full skeleton-bg rounded-[3px]"></div>
                <div class="h-24 w-full skeleton-bg rounded-[3px]"></div>
                <div class="h-10 w-full skeleton-bg rounded-[3px]"></div>
              </div>
            </div>
          </div>
        </div>

        <div
          id="emptyCartState"
          class="hidden flex flex-col items-center justify-center py-16 text-center">
          <div class="w-40 h-40 mb-4">
            <img src="../assets/illustrations/empty-cart.svg" alt="Your cart is empty" class="w-full h-full" />
          </div>
          <h3 class="text-base font-semibold text-gray-800">
            Your cart is empty
          </h3>
          <p class="text-gray-500 text-sm mt-1 mb-5">
            Add some items from the menu.
          </p>
          <a
            href="./home.php"
            class="px-6 py-2.5 bg-emerald-600 text-white font-medium text-sm hover:bg-emerald-700 transition rounded-[3px]">Browse Menu</a>
        </div>

        <div
          id="cartWithItems"
          class="hidden flex flex-col lg:flex-row gap-4 items-start">
          <div
            id="groupedItemsContainer"
            class="w-full lg:w-2/3 space-y-3"></div>

          <div
            id="orderSummaryCard"
            class="rounded-md w-full lg:w-1/3 bg-white border border-gray-200 lg:sticky lg:top-4">
            <div class="p-4 border-b border-gray-100">
              <h3 class="text-xs font-bold text-gray-700">Order Summary</h3>
            </div>
            <div class="p-4 space-y-4">
              <div>
                <p
                  class="text-[11px] font-semibold text-gray-500 uppercase tracking-wide mb-2">
                  Order Type
                </p>
                <?php if ($isGuestCustomer): ?>
                <div class="flex bg-gray-100 p-0.5 gap-0.5 rounded-[3px]">
                  <button
                    id="orderTypeDelivery"
                    disabled
                    title="Delivery is not available for guest accounts"
                    class="flex-1 py-1.5 text-[11px] font-semibold transition-all text-gray-300 cursor-not-allowed rounded-[3px]">
                    Delivery
                  </button>
                  <button
                    id="orderTypePickup"
                    onclick="setOrderType('pickup')"
                    class="flex-1 py-1.5 text-[11px] font-semibold transition-all bg-emerald-600 text-white rounded-[3px]">
                    Pickup
                  </button>
                </div>
                <p class="text-[10px] text-gray-400 mt-1.5 leading-relaxed">
                  Delivery is not available for guest accounts. Please head to the stall to pick up your order.
                </p>
                <?php else: ?>
                <div class="flex bg-gray-100 p-0.5 gap-0.5 rounded-[3px]">
                  <button
                    id="orderTypeDelivery"
                    onclick="setOrderType('delivery')"
                    class="flex-1 py-1.5 text-[11px] font-semibold transition-all bg-emerald-600 text-white rounded-[3px]">
                    Delivery
                  </button>
                  <button
                    id="orderTypePickup"
                    onclick="setOrderType('pickup')"
                    class="flex-1 py-1.5 text-[11px] font-semibold transition-all text-gray-500 rounded-[3px]">
                    Pickup
                  </button>
                </div>
                <?php endif; ?>
              </div>

              <div id="locationSection" class="<?php echo $isGuestCustomer ? 'hidden' : ''; ?>">
                <p
                  class="text-[11px] font-semibold text-gray-500 uppercase tracking-wide mb-2">
                  Drop-off Location
                </p>
                <button id="openLocationModal" class="w-full text-left">
                  <div
                    class="flex items-center gap-3 p-2.5 border border-gray-200 hover:border-emerald-500 hover:bg-emerald-50/40 transition-all rounded-[3px]">
                    <div
                      class="w-9 h-9 bg-gray-100 flex items-center justify-center shrink-0 rounded-[3px]">
                      <svg
                        xmlns="http://www.w3.org/2000/svg"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke-width="1.5"
                        stroke="currentColor"
                        class="w-4 h-4 text-gray-500">
                        <path
                          stroke-linecap="round"
                          stroke-linejoin="round"
                          d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                        <path
                          stroke-linecap="round"
                          stroke-linejoin="round"
                          d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z" />
                      </svg>
                    </div>
                    <div class="flex-1 min-w-0">
                      <p class="text-[10px] text-gray-400 mb-0.5">
                        Deliver to
                      </p>
                      <p
                        id="locationBtnText"
                        class="text-xs font-medium text-gray-400 truncate">
                        Set drop-off location
                      </p>
                    </div>
                    <svg
                      xmlns="http://www.w3.org/2000/svg"
                      fill="none"
                      viewBox="0 0 24 24"
                      stroke-width="1.5"
                      stroke="currentColor"
                      class="w-4 h-4 text-gray-300 shrink-0">
                      <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        d="m8.25 4.5 7.5 7.5-7.5 7.5" />
                    </svg>
                  </div>
                </button>
              </div>

              <div id="pickupSection" class="<?php echo $isGuestCustomer ? '' : 'hidden'; ?>">
                <p
                  class="text-[11px] font-semibold text-gray-500 uppercase tracking-wide mb-2">
                  Pickup Location
                </p>
                <div
                  class="flex items-center gap-3 p-2.5 border border-gray-200 rounded-[3px]">
                  <div
                    class="w-9 h-9 bg-gray-100 flex items-center justify-center shrink-0 rounded-[3px]">
                    <svg
                      xmlns="http://www.w3.org/2000/svg"
                      fill="none"
                      viewBox="0 0 24 24"
                      stroke-width="1.5"
                      stroke="currentColor"
                      class="w-4 h-4 text-gray-500">
                      <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        d="M13.5 21v-7.5a.75.75 0 0 1 .75-.75h3a.75.75 0 0 1 .75.75V21m-4.5 0H2.36m11.14 0H18m0 0h3.64m-1.39 0V9.349m-16.5 11.65V9.35m0 0a3.001 3.001 0 0 0 3.75-.615A2.993 2.993 0 0 0 9.75 9.75c.896 0 1.7-.393 2.25-1.016a2.993 2.993 0 0 0 2.25 1.016c.896 0 1.7-.393 2.25-1.015a3.001 3.001 0 0 0 3.75.614m-16.5 0a3.004 3.004 0 0 1-.621-4.72l1.189-1.19A1.5 1.5 0 0 1 5.378 3h13.243a1.5 1.5 0 0 1 1.06.44l1.19 1.189a3 3 0 0 1-.621 4.72m-13.5 8.65h3.75a.75.75 0 0 0 .75-.75V13.5a.75.75 0 0 0-.75-.75H6.75a.75.75 0 0 0-.75.75v3.75c0 .415.336.75.75.75Z" />
                    </svg>
                  </div>
                  <div class="flex-1 min-w-0">
                    <p class="text-[10px] text-gray-400 mb-0.5">
                      Pickup order
                    </p>
                    <p class="text-xs font-medium text-gray-700 truncate">
                      Head to the stall when ready
                    </p>
                  </div>
                </div>
                <p class="text-[10px] text-gray-400 mt-1.5 leading-relaxed">
                  No delivery fee applies for pickup orders.
                </p>
              </div>

              <div>
                <p
                  class="text-[11px] font-semibold text-gray-500 uppercase tracking-wide mb-2">
                  Payment Method
                </p>
                <div
                  class="border border-gray-200 divide-y divide-gray-100 overflow-hidden rounded-[3px]">
                  <?php if (!$isGuestCustomer): ?>
                  <label
                    class="flex items-center gap-3 px-3 py-2.5 cursor-pointer hover:bg-gray-50 transition-colors">
                    <input
                      type="radio"
                      name="paymentMethod"
                      value="cash"
                      checked
                      class="accent-emerald-600 w-3.5 h-3.5 shrink-0" />
                    <div
                      class="w-9 h-9 bg-gray-100 flex items-center justify-center shrink-0 rounded-[3px]">
                      <div class="w-5 h-5 bg-emerald-600 flex items-center justify-center shrink-0 rounded-[2px]">
                        <span class="text-[7px] font-bold text-white tracking-tight leading-none">COD</span>
                      </div>
                    </div>
                    <div class="flex-1 min-w-0">
                      <p class="text-xs font-medium text-gray-800">
                        Cash on Delivery
                      </p>
                      <p class="text-[10px] text-gray-400 mt-0.5">
                        Pay when your order arrives
                      </p>
                    </div>
                  </label>
                  <?php endif; ?>
                  <label
                    class="flex items-center gap-3 px-3 py-2.5 cursor-pointer hover:bg-gray-50 transition-colors">
                    <input
                      type="radio"
                      name="paymentMethod"
                      value="gcash"
                      <?php echo $isGuestCustomer ? 'checked' : ''; ?>
                      class="accent-emerald-600 w-3.5 h-3.5 shrink-0" />
                    <div
                      class="w-9 h-9 bg-gray-100 flex items-center justify-center shrink-0 rounded-[3px]">
                      <img
                        src="../assets/icons/gcash-logo.jpeg"
                        alt="GCash"
                        class="w-5 h-5 object-contain shrink-0" />
                    </div>
                    <div class="flex-1 min-w-0">
                      <p class="text-xs font-medium text-gray-800">GCash</p>
                      <p class="text-[10px] text-gray-400 mt-0.5">
                        Pay securely via GCash
                      </p>
                    </div>
                  </label>
                  <label
                    class="flex items-center gap-3 px-3 py-2.5 cursor-pointer hover:bg-gray-50 transition-colors">
                    <input
                      type="radio"
                      name="paymentMethod"
                      value="paymaya"
                      class="accent-emerald-600 w-3.5 h-3.5 shrink-0" />
                    <div
                      class="w-9 h-9 bg-gray-100 flex items-center justify-center shrink-0 rounded-[3px]">
                      <img
                        src="../assets/icons/maya-logo.jpeg"
                        alt="Maya"
                        class="w-5 h-5 object-contain shrink-0" />
                    </div>
                    <div class="flex-1 min-w-0">
                      <p class="text-xs font-medium text-gray-800">Maya</p>
                      <p class="text-[10px] text-gray-400 mt-0.5">
                        Pay securely via Maya
                      </p>
                    </div>
                  </label>
                </div>
              </div>

              <div class="pt-1 space-y-1.5 border-t border-gray-100">
                <div class="flex items-center justify-between">
                  <span class="text-xs text-gray-500">Subtotal</span>
                  <span
                    class="text-xs font-medium text-gray-700"
                    id="subtotalAmount">&#8369;0.00</span>
                </div>
                <div id="deliveryFeesList" class="space-y-1"></div>
                <div
                  class="flex items-center justify-between pt-1.5 border-t border-gray-100">
                  <span class="text-xs font-semibold text-gray-700">Total</span>
                  <span
                    class="text-sm font-bold text-emerald-600"
                    id="totalAmount">&#8369;0.00</span>
                </div>
              </div>

              <button
                id="checkoutBtn"
                class="w-full py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-semibold transition-colors flex items-center justify-center gap-2 rounded-[3px]">
                <svg
                  xmlns="http://www.w3.org/2000/svg"
                  fill="none"
                  viewBox="0 0 24 24"
                  stroke-width="1.5"
                  stroke="currentColor"
                  class="w-4 h-4">
                  <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                </svg>
                Place Order
              </button>
            </div>
          </div>
        </div>

      </div>
    </div>

    <div
      class="bg-white border-t border-gray-200 flex-shrink-0 fixed bottom-0 left-0 right-0 z-20">
      <div class="max-w-5xl mx-auto px-4 flex justify-around py-2">
        <a
          href="./home.php"
          class="flex flex-col items-center justify-center py-2 px-3 transition-all duration-200 group text-gray-500 hover:text-gray-900 hover:bg-gray-50 rounded-[3px]">
          <svg
            xmlns="http://www.w3.org/2000/svg"
            fill="none"
            viewBox="0 0 24 24"
            stroke-width="1.5"
            stroke="currentColor"
            class="w-5 h-5 transition-transform group-hover:scale-110">
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              d="m2.25 12 8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" />
          </svg>
          <span class="text-xs font-medium mt-1">Home</span>
        </a>
        <a
          href="./cart.php"
          class="flex flex-col items-center justify-center py-2 px-3 transition-all duration-200 group text-emerald-600 bg-emerald-50 relative rounded-[3px]">
          <svg
            xmlns="http://www.w3.org/2000/svg"
            fill="none"
            viewBox="0 0 24 24"
            stroke-width="1.5"
            stroke="currentColor"
            class="w-5 h-5">
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              d="M2.25 3h1.386c.51 0 .955.343 1.087.836l.383 1.437M7.5 14.25a3 3 0 0 0-3 3h15.75m-12.75-3h11.218c1.121-2.3 1.994-4.694 2.602-7.152.126-.51-.26-1.006-.786-1.006H5.106M7.5 14.25 5.106 5.272M6 20.25a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Zm12.75 0a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Z" />
          </svg>
          <span class="text-xs font-medium mt-1">Cart</span>
        </a>
        <a
          href="./order.php"
          class="flex flex-col items-center justify-center py-2 px-3 transition-all duration-200 group text-gray-500 hover:text-gray-900 hover:bg-gray-50 rounded-[3px]">
          <svg
            xmlns="http://www.w3.org/2000/svg"
            fill="none"
            viewBox="0 0 24 24"
            stroke-width="1.5"
            stroke="currentColor"
            class="w-5 h-5 transition-transform group-hover:scale-110">
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 0 0 2.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 0 0-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 0 0 .75-.75 2.25 2.25 0 0 0-.1-.664m-5.8 0A2.251 2.251 0 0 1 13.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25Z" />
          </svg>
          <span class="text-xs font-medium mt-1">Orders</span>
        </a>
        <a
          href="./chat.php"
          class="flex flex-col items-center justify-center py-2 px-3 transition-all duration-200 group text-gray-500 hover:text-gray-900 hover:bg-gray-50 relative rounded-[3px]">
          <svg
            xmlns="http://www.w3.org/2000/svg"
            fill="none"
            viewBox="0 0 24 24"
            stroke-width="1.5"
            stroke="currentColor"
            class="w-5 h-5 transition-transform group-hover:scale-110">
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              d="M20.25 8.511c.884.284 1.5 1.128 1.5 2.097v4.286c0 1.136-.847 2.1-1.98 2.193-.34.027-.68.052-1.02.072v3.091l-3-3c-1.354 0-2.694-.055-4.02-.163a2.115 2.115 0 0 1-.825-.242m9.345-8.334a2.126 2.126 0 0 0-.476-.095 48.64 48.64 0 0 0-8.048 0c-1.131.094-1.976 1.057-1.976 2.192v4.286c0 .837.46 1.58 1.155 1.951m9.345-8.334V6.637c0-1.621-1.152-3.026-2.76-3.235A48.455 48.455 0 0 0 11.25 3c-2.115 0-4.198.137-6.24.402-1.608.209-2.76 1.614-2.76 3.235v6.226c0 1.621 1.152 3.026 2.76 3.235.577.075 1.157.14 1.74.194V21l4.155-4.155" />
          </svg>
          <span class="text-xs font-medium mt-1">Chats</span>
        </a>
        <a
          href="./account.php"
          class="flex flex-col items-center justify-center py-2 px-3 transition-all duration-200 group text-gray-500 hover:text-gray-900 hover:bg-gray-50 rounded-[3px]">
          <svg
            xmlns="http://www.w3.org/2000/svg"
            fill="none"
            viewBox="0 0 24 24"
            stroke-width="1.5"
            stroke="currentColor"
            class="w-5 h-5 transition-transform group-hover:scale-110">
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" />
          </svg>
          <span class="text-xs font-medium mt-1">Account</span>
        </a>
      </div>
    </div>
  </div>

  <div
    id="locationModal"
    class="fixed inset-0 z-50 hidden flex items-center justify-center px-4">
    <div
      class="modal-overlay absolute inset-0"
      id="closeLocationOverlay"></div>
    <div
      class="bg-white w-full max-w-md max-h-[90vh] overflow-y-auto relative z-10 shadow-2xl rounded-md">
      <div
        class="p-4 border-b border-gray-100 flex items-center justify-between sticky top-0 bg-white z-10">
        <h2 class="font-bold text-gray-800 text-sm">Set Drop-off Location</h2>
        <button
          id="closeLocationModalBtn"
          class="p-1 hover:bg-gray-100 rounded-[3px]">
          <svg
            xmlns="http://www.w3.org/2000/svg"
            fill="none"
            viewBox="0 0 24 24"
            stroke-width="1.5"
            stroke="currentColor"
            class="w-5 h-5 text-gray-500">
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              d="M6 18 18 6M6 6l12 12" />
          </svg>
        </button>
      </div>
      <div class="p-4 space-y-4">
        <div>
          <label
            class="block text-[11px] font-semibold text-gray-500 uppercase tracking-wide mb-1.5">Location</label>
          <input
            type="text"
            id="locationModalInput"
            maxlength="50"
            placeholder="e.g., CCIS 2nd Floor, Lab 3"
            class="filter-input w-full px-4 py-2.5 bg-white border border-gray-200 text-sm text-gray-900 placeholder-gray-500 transition-all rounded-[3px]" />
          <div class="flex justify-between mt-1">
            <p class="text-[10px] text-gray-400">
              Be specific for faster delivery
            </p>
            <span
              id="locationModalCharCount"
              class="text-[10px] text-gray-400">0/50</span>
          </div>
        </div>
        <div>
          <p
            class="text-[11px] font-semibold text-gray-500 uppercase tracking-wide mb-2">
            Quick Select
          </p>
          <div class="grid grid-cols-2 gap-2" id="quickLocationGrid"></div>
        </div>
      </div>
      <div class="p-4 pt-0 flex gap-2">
        <button
          id="locationModalCancel"
          class="flex-1 py-2.5 border border-gray-200 text-gray-700 text-xs font-semibold hover:bg-gray-50 transition-colors rounded-[3px]">
          Cancel
        </button>
        <button
          id="locationModalSave"
          class="flex-1 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold transition-colors rounded-[3px]">
          Confirm
        </button>
      </div>
    </div>
  </div>

  <div
    id="noteModal"
    class="fixed inset-0 z-50 hidden flex items-center justify-center px-4">
    <div class="modal-overlay absolute inset-0" id="closeNoteOverlay"></div>
    <div
      class="bg-white w-full max-w-md relative z-10 shadow-2xl rounded-md">
      <div
        class="p-4 border-b border-gray-100 flex items-center justify-between">
        <h2 class="font-bold text-gray-800 text-sm">
          Note for <span id="modalStallName" class="text-emerald-600"></span>
        </h2>
        <button
          id="closeNoteModalBtn"
          class="p-1 hover:bg-gray-100 rounded-[3px]">
          <svg
            xmlns="http://www.w3.org/2000/svg"
            fill="none"
            viewBox="0 0 24 24"
            stroke-width="1.5"
            stroke="currentColor"
            class="w-5 h-5 text-gray-500">
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              d="M6 18 18 6M6 6l12 12" />
          </svg>
        </button>
      </div>
      <div class="p-4">
        <label
          class="block text-[11px] font-semibold text-gray-500 uppercase tracking-wide mb-1.5">Note</label>
        <textarea
          id="modalNoteInput"
          rows="4"
          maxlength="100"
          class="filter-input w-full px-4 py-2.5 bg-white border border-gray-200 text-sm text-gray-900 placeholder-gray-500 resize-none transition-all rounded-[3px]"
          placeholder="e.g., Extra sauce, no onions, call upon arrival..."></textarea>
        <div class="flex justify-between items-center mt-1">
          <button
            id="modalClearBtn"
            class="text-[10px] text-red-500 hover:text-red-600 font-medium transition-colors">
            Clear
          </button>
          <span class="text-[10px] text-gray-400" id="modalCharCounterDisplay">0/100</span>
        </div>
      </div>
      <div class="p-4 pt-0 flex gap-2">
        <button
          id="modalCancelBtn"
          class="flex-1 py-2.5 border border-gray-200 text-gray-700 text-xs font-semibold hover:bg-gray-50 transition-colors rounded-[3px]">
          Cancel
        </button>
        <button
          id="modalSaveBtn"
          disabled
          class="flex-1 py-2.5 bg-emerald-600 hover:bg-emerald-700 disabled:opacity-70 disabled:cursor-not-allowed text-white text-xs font-semibold transition-colors rounded-[3px]">
          Save Note
        </button>
      </div>
    </div>
  </div>

  <div
    id="clearCartModal"
    class="fixed inset-0 z-[60] hidden flex items-center justify-center px-4">
    <div class="modal-overlay absolute inset-0" id="closeClearCartOverlay"></div>
    <div
      class="bg-white w-full max-w-sm relative z-10 shadow-2xl p-5 space-y-4 text-center rounded-md">
      <div class="w-12 h-12 bg-red-50 flex items-center justify-center mx-auto rounded-full">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6 text-red-500">
          <path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
        </svg>
      </div>
      <div>
        <p class="text-sm font-bold text-gray-800">Clear Cart</p>
        <p class="text-xs text-gray-500 mt-1">Remove all items from your cart? This cannot be undone.</p>
      </div>
      <div class="flex gap-2 pt-1">
        <button id="clearCartKeepBtn" class="flex-1 py-2.5 border border-gray-200 text-gray-700 text-xs font-semibold hover:bg-gray-50 transition-colors rounded-[3px]">
          Cancel
        </button>
        <button id="clearCartConfirmBtn" class="flex-1 py-2.5 bg-red-500 hover:bg-red-600 text-white text-xs font-semibold transition-colors rounded-[3px]">
          Clear Cart
        </button>
      </div>
    </div>
  </div>

  <div
    id="toast"
    class="hidden items-center gap-2 fixed left-1/2 bottom-20 z-40 -translate-x-1/2 max-w-[calc(100%-2rem)] bg-gray-900 text-white text-xs font-medium px-4 py-2.5 shadow-lg rounded-[6px]">
    <svg id="toastIconSvg" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-4 h-4 text-emerald-400 shrink-0">
      <path id="toastIconPath" stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
    </svg>
    <span id="toastMessage" class="truncate"></span>
  </div>

  <script>
    let cartItems = <?php echo json_encode($initialCart); ?>;
    let savedLocation = "";
    let globalOrderType = <?php echo $isGuestCustomer ? '"pickup"' : '"delivery"'; ?>;
    let currentModalStallId = null;
    let currentModalStall = null;
    let initialNoteValue = "";

    function checkForNoteChanges() {
      const current = document.getElementById("modalNoteInput").value;
      document.getElementById("modalSaveBtn").disabled = current === initialNoteValue;
    }
    let activeQtyKey = null;

    async function postAction(action, data = {}) {
      const formData = new FormData();
      formData.append("action", action);
      for (const key in data) {
        const val = data[key];
        formData.append(key, val === null || val === undefined ? "" : val);
      }
      try {
        const response = await fetch(window.location.href, {
          method: "POST",
          body: formData,
        });
        return await response.json();
      } catch (err) {
        return {
          success: false,
          message: "Something went wrong. Please try again."
        };
      }
    }

    function escapeHtml(str) {
      if (!str) return "";
      return String(str)
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
    }

    let toastHideTimeout = null;

    function showToast(message, type = "success") {
      const toast = document.getElementById("toast");
      const toastMessage = document.getElementById("toastMessage");
      const iconSvg = document.getElementById("toastIconSvg");
      const iconPath = document.getElementById("toastIconPath");
      toastMessage.textContent = message;

      if (type === "warning") {
        iconSvg.classList.remove("text-emerald-400");
        iconSvg.classList.add("text-amber-400");
        iconPath.setAttribute("d", "M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z");
      } else {
        iconSvg.classList.remove("text-amber-400");
        iconSvg.classList.add("text-emerald-400");
        iconPath.setAttribute("d", "M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z");
      }

      if (toastHideTimeout) clearTimeout(toastHideTimeout);

      toast.classList.remove("hidden");
      toast.classList.add("flex");

      toastHideTimeout = setTimeout(() => {
        toast.classList.add("hidden");
        toast.classList.remove("flex");
      }, 2000);
    }

    function groupItemsByStall(items) {
      const groups = {};
      items.forEach((item) => {
        if (!groups[item.stall_id]) {
          groups[item.stall_id] = {
            stall_id: item.stall_id,
            stallName: item.stall_name,
            deliveryFee: item.stall_delivery_fee || 0,
            items: [],
          };
        }
        groups[item.stall_id].items.push({
          ...item
        });
      });
      return groups;
    }

    function getStallNote(stallId) {
      const item = cartItems.find(i => i.stall_id === stallId && i.note);
      return item ? item.note : "";
    }

    function setOrderType(type) {
      globalOrderType = type;
      localStorage.setItem("cart_orderType", type);
      const deliveryBtn = document.getElementById("orderTypeDelivery");
      const pickupBtn = document.getElementById("orderTypePickup");
      if (type === "delivery") {
        deliveryBtn.style.background = "#059669";
        deliveryBtn.style.color = "#fff";
        pickupBtn.style.background = "transparent";
        pickupBtn.style.color = "#6b7280";
      } else {
        pickupBtn.style.background = "#059669";
        pickupBtn.style.color = "#fff";
        deliveryBtn.style.background = "transparent";
        deliveryBtn.style.color = "#6b7280";
      }
      document
        .getElementById("locationSection")
        .classList.toggle("hidden", type === "pickup");
      document
        .getElementById("pickupSection")
        .classList.toggle("hidden", type === "delivery");
      updateOrderSummary();
    }

    function calculateTotals() {
      const availableItems = cartItems.filter((item) => item.is_available);
      const subtotal = availableItems.reduce(
        (sum, item) => sum + item.price * item.quantity,
        0
      );
      const groups = groupItemsByStall(availableItems);
      const stallFees = Object.values(groups).map((g) => ({
        stallName: g.stallName,
        fee: g.deliveryFee,
      }));
      const deliveryFee =
        globalOrderType === "delivery" ?
        stallFees.reduce((sum, s) => sum + s.fee, 0) :
        0;
      return {
        subtotal,
        deliveryFee,
        total: subtotal + deliveryFee,
        stallFees,
      };
    }

    function updateOrderSummary() {
      const {
        subtotal,
        total,
        stallFees
      } = calculateTotals();
      document.getElementById("subtotalAmount").textContent =
        "₱" + subtotal.toFixed(2);
      document.getElementById("totalAmount").textContent =
        "₱" + total.toFixed(2);
      const feesList = document.getElementById("deliveryFeesList");
      if (globalOrderType === "delivery") {
        feesList.innerHTML = stallFees
          .map(
            (s) =>
            '<div class="flex items-center justify-between"><span class="text-xs text-gray-500">' +
            escapeHtml(s.stallName) +
            ' delivery fee</span><span class="text-xs text-gray-600">&#8369;' +
            s.fee.toFixed(2) +
            "</span></div>"
          )
          .join("");
      } else {
        feesList.innerHTML = stallFees
          .map(
            (s) =>
            '<div class="flex items-center justify-between"><span class="text-xs text-gray-500">' +
            escapeHtml(s.stallName) +
            ' delivery fee</span><span class="text-xs text-emerald-600 font-semibold">Free</span></div>'
          )
          .join("");
      }
    }

    function updateLocationButton() {
      const el = document.getElementById("locationBtnText");
      if (savedLocation) {
        el.textContent = savedLocation;
        el.classList.remove("text-gray-400");
        el.classList.add("text-gray-800");
      } else {
        el.textContent = "Set drop-off location";
        el.classList.remove("text-gray-800");
        el.classList.add("text-gray-400");
      }
    }

    function applyActiveQtyState() {
      document
        .querySelectorAll(".decrement-qty, .increment-qty")
        .forEach((btn) => {
          btn.classList.remove("border-emerald-500", "text-emerald-600");
          btn.classList.add("border-gray-300", "text-gray-500");
        });

      if (!activeQtyKey) return;

      const [cartIdStr, type] = activeQtyKey.split(":");
      const selector = type === "inc" ? ".increment-qty" : ".decrement-qty";
      const btn = document.querySelector(
        `${selector}[data-cart-id="${cartIdStr}"]`
      );
      if (btn) {
        btn.classList.remove("border-gray-300", "text-gray-500");
        btn.classList.add("border-emerald-500", "text-emerald-600");
      }
    }

    function openLocationModal() {
      document.getElementById("locationModalInput").value = savedLocation;
      updateLocationModalCharCount();
      buildQuickLocations();
      document.getElementById("locationModal").classList.remove("hidden");
      document.body.style.overflow = "hidden";
      setTimeout(
        () => document.getElementById("locationModalInput").focus(),
        100
      );
    }

    function closeLocationModal() {
      document.getElementById("locationModal").classList.add("hidden");
      document.body.style.overflow = "";
    }

    function updateLocationModalCharCount() {
      const len = document.getElementById("locationModalInput").value.length;
      const el = document.getElementById("locationModalCharCount");
      el.textContent = len + "/50";
      el.classList.toggle("text-red-500", len >= 50);
      el.classList.toggle("text-gray-400", len < 50);
    }

    function buildQuickLocations() {
      const grid = document.getElementById("quickLocationGrid");
      const locs = [
        "CAT DEPARTMENT",
        "CEA DEPARTMENT",
        "COM DEPARTMENT",
        "CON DEPARTMENT",
        "COED DEPARTMENT",
        "CCIS DEPARTMENT",
        "CCJS DEPARTMENT",
        "LIBRARY BUILDING",
      ];
      grid.innerHTML = locs
        .map(
          (loc) =>
          '<button type="button" class="quick-loc-btn px-2 py-2 bg-gray-50 border border-gray-200 text-[10px] font-medium text-gray-700 hover:border-emerald-600 hover:bg-emerald-50 hover:text-emerald-700 transition-all text-left rounded-[3px]" data-loc="' +
          escapeHtml(loc) +
          '">' +
          escapeHtml(loc) +
          "</button>"
        )
        .join("");
      grid.querySelectorAll(".quick-loc-btn").forEach((btn) => {
        btn.addEventListener("click", () => {
          document.getElementById("locationModalInput").value =
            btn.getAttribute("data-loc");
          updateLocationModalCharCount();
        });
      });
    }

    function updateModalCharCount() {
      const len = document.getElementById("modalNoteInput").value.length;
      const el = document.getElementById("modalCharCounterDisplay");
      el.textContent = len + "/100";
      el.classList.toggle("text-red-500", len >= 100);
      el.classList.toggle("text-gray-400", len < 100);
    }

    function openNoteModal(stallId, stallName, currentNote) {
      currentModalStallId = stallId;
      currentModalStall = stallName;
      document.getElementById("modalStallName").textContent = stallName;
      document.getElementById("modalNoteInput").value = currentNote || "";
      initialNoteValue = currentNote || "";
      document.getElementById("modalSaveBtn").disabled = true;
      updateModalCharCount();
      document.getElementById("noteModal").classList.remove("hidden");
      document.body.style.overflow = "hidden";
      setTimeout(
        () => document.getElementById("modalNoteInput").focus(),
        100
      );
    }

    function closeNoteModal() {
      document.getElementById("noteModal").classList.add("hidden");
      document.body.style.overflow = "";
      currentModalStallId = null;
      currentModalStall = null;
    }

    async function saveNote() {
      if (!currentModalStallId) return;
      const note = document.getElementById("modalNoteInput").value.trim();
      const saveBtn = document.getElementById("modalSaveBtn");
      saveBtn.disabled = true;
      const res = await postAction("update_stall_note", {
        stall_id: currentModalStallId,
        note: note,
      });
      saveBtn.disabled = false;
      if (res.success) {
        cartItems.forEach((item) => {
          if (item.stall_id === currentModalStallId) {
            item.note = note !== "" ? note : null;
          }
        });
        closeNoteModal();
        renderCart();
        showToast("Note saved");
      }
    }

    function renderCart() {
      const emptyDiv = document.getElementById("emptyCartState");
      const cartDiv = document.getElementById("cartWithItems");
      const container = document.getElementById("groupedItemsContainer");

      if (cartItems.length === 0) {
        emptyDiv.classList.remove("hidden");
        cartDiv.classList.add("hidden");
        return;
      }

      emptyDiv.classList.add("hidden");
      cartDiv.classList.remove("hidden");
      container.innerHTML = "";

      const groups = groupItemsByStall(cartItems);

      Object.values(groups).forEach((stallData) => {
        const stallNote = getStallNote(stallData.stall_id);
        const hasNote = stallNote && stallNote.trim() !== "";

        const card = document.createElement("div");
        card.className =
          "rounded-md bg-white border border-gray-200 overflow-hidden";

        const itemsHTML = stallData.items
          .map(
            (item) => `
            <div class="flex items-center gap-3 px-4 py-3 border-b border-gray-100 last:border-b-0 ${item.is_available ? "" : "opacity-50"}">
              <img src="${escapeHtml(item.image)}" alt="${escapeHtml(item.item_name)}" class="w-10 h-10 object-cover bg-gray-100 shrink-0 rounded-[3px] item-image-fade" loading="lazy" onload="this.classList.add('loaded')" />
              <div class="flex-1 flex items-center gap-2 min-w-0">
                <div class="flex flex-col flex-1 min-w-0">
                  <span class="text-xs text-gray-600 truncate">${escapeHtml(item.item_name)}</span>
                  ${
                    item.is_available
                      ? `<span class="text-[10px] text-gray-400">&#8369;${item.price.toFixed(2)} each</span>`
                      : `<span class="text-[10px] text-red-500 font-semibold">Out of Stock</span>`
                  }
                </div>
                <div class="flex items-center gap-0.5 shrink-0">
                  <button class="decrement-qty w-6 h-6 flex items-center justify-center border border-gray-300 text-gray-500 hover:border-emerald-500 hover:text-emerald-600 transition-colors text-xs font-bold leading-none rounded-[3px] disabled:opacity-40 disabled:cursor-not-allowed" data-cart-id="${item.cart_id}" data-qty="${item.quantity}" ${item.is_available ? "" : "disabled"}>&#8722;</button>
                  <input type="number" class="qty-input w-7 text-center text-xs font-semibold text-gray-800 bg-transparent border-none focus:outline-none p-0 block" value="${item.quantity}" min="1" data-cart-id="${item.cart_id}" ${item.is_available ? "" : "disabled"} />
                  <button class="increment-qty w-6 h-6 flex items-center justify-center border border-gray-300 text-gray-500 hover:border-emerald-500 hover:text-emerald-600 transition-colors text-xs font-bold leading-none rounded-[3px] disabled:opacity-40 disabled:cursor-not-allowed" data-cart-id="${item.cart_id}" data-qty="${item.quantity}" ${item.is_available ? "" : "disabled"}>+</button>
                </div>
                <span class="text-xs font-medium text-gray-700 shrink-0 w-14 text-right">&#8369;${(item.price * item.quantity).toFixed(2)}</span>
                <button class="remove-item-btn p-1 text-gray-300 hover:text-red-500 transition-colors shrink-0" data-cart-id="${item.cart_id}" title="Remove item">
                  <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                  </svg>
                </button>
              </div>
            </div>
          `
          )
          .join("");

        card.innerHTML = `
            <div class="p-3 border-b border-gray-100">
              <div class="flex items-center justify-between gap-2">
                <div>
                  <p class="text-xs font-bold text-emerald-600">${escapeHtml(stallData.stallName)}</p>
                  <p class="text-[10px] text-gray-400 mt-0.5">${stallData.items.length} item${stallData.items.length > 1 ? "s" : ""}</p>
                </div>
                <button class="add-note-btn py-1 px-2.5 border ${hasNote ? "border-emerald-500 text-emerald-600" : "border-gray-300 text-gray-500 hover:border-emerald-500 hover:text-emerald-600"} text-[10px] font-semibold transition-colors shrink-0 rounded-[3px]" data-stall-id="${stallData.stall_id}" data-stall-name="${escapeHtml(stallData.stallName)}">
                  ${hasNote ? "Edit Note" : "Add Note"}
                </button>
              </div>
            </div>
            ${itemsHTML}
          `;

        container.appendChild(card);

        card.querySelector(".add-note-btn").addEventListener("click", (e) => {
          const btn = e.currentTarget;
          openNoteModal(
            parseInt(btn.getAttribute("data-stall-id")),
            btn.getAttribute("data-stall-name"),
            getStallNote(parseInt(btn.getAttribute("data-stall-id")))
          );
        });
      });

      container.querySelectorAll(".decrement-qty").forEach((btn) => {
        btn.addEventListener("click", async () => {
          const cartId = parseInt(btn.getAttribute("data-cart-id"));
          const item = cartItems.find((i) => i.cart_id === cartId);
          if (!item || item.quantity <= 1) return;

          activeQtyKey = cartId + ":dec";
          applyActiveQtyState();

          btn.disabled = true;
          const res = await postAction("update_quantity", {
            cart_id: cartId,
            quantity: item.quantity - 1,
          });
          btn.disabled = false;
          if (res.success) {
            cartItems = res.cart;
            renderCart();
          }
        });
      });

      container.querySelectorAll(".increment-qty").forEach((btn) => {
        btn.addEventListener("click", async () => {
          const cartId = parseInt(btn.getAttribute("data-cart-id"));
          const item = cartItems.find((i) => i.cart_id === cartId);
          if (!item) return;

          activeQtyKey = cartId + ":inc";
          applyActiveQtyState();

          btn.disabled = true;
          const res = await postAction("update_quantity", {
            cart_id: cartId,
            quantity: item.quantity + 1,
          });
          btn.disabled = false;
          if (res.success) {
            cartItems = res.cart;
            renderCart();
          }
        });
      });

      container.querySelectorAll(".qty-input").forEach((input) => {
        input.addEventListener("change", async () => {
          const cartId = parseInt(input.getAttribute("data-cart-id"));
          let val = parseInt(input.value);
          if (isNaN(val) || val < 1) val = 1;
          const item = cartItems.find((i) => i.cart_id === cartId);
          if (!item) return;
          const res = await postAction("update_quantity", {
            cart_id: cartId,
            quantity: val,
          });
          if (res.success) {
            cartItems = res.cart;
            renderCart();
          }
        });
      });

      container.querySelectorAll(".remove-item-btn").forEach((btn) => {
        btn.addEventListener("click", async () => {
          const cartId = parseInt(btn.getAttribute("data-cart-id"));
          const removedItem = cartItems.find((i) => i.cart_id === cartId);
          const removedItemName = removedItem ? removedItem.item_name : "Item";
          btn.disabled = true;
          const res = await postAction("remove_item", {
            cart_id: cartId
          });
          btn.disabled = false;
          if (res.success) {
            cartItems = res.cart;
            renderCart();
            showToast(removedItemName + " removed from cart");
          }
        });
      });

      updateOrderSummary();
      updateLocationButton();
      applyActiveQtyState();
    }

    function loadCart() {
      document.getElementById("cartSkeleton").classList.add("hidden");
      renderCart();
    }

    function setupLocationModal() {
      document
        .getElementById("openLocationModal")
        .addEventListener("click", openLocationModal);
      document
        .getElementById("closeLocationModalBtn")
        .addEventListener("click", closeLocationModal);
      document
        .getElementById("closeLocationOverlay")
        .addEventListener("click", closeLocationModal);
      document
        .getElementById("locationModalCancel")
        .addEventListener("click", closeLocationModal);
      document
        .getElementById("locationModalSave")
        .addEventListener("click", () => {
          savedLocation = document
            .getElementById("locationModalInput")
            .value.trim();
          localStorage.setItem("cart_location", savedLocation);
          updateLocationButton();
          closeLocationModal();
        });
      document
        .getElementById("locationModalInput")
        .addEventListener("input", updateLocationModalCharCount);
    }

    function setupNoteModal() {
      document
        .getElementById("closeNoteModalBtn")
        .addEventListener("click", closeNoteModal);
      document
        .getElementById("closeNoteOverlay")
        .addEventListener("click", closeNoteModal);
      document
        .getElementById("modalCancelBtn")
        .addEventListener("click", closeNoteModal);
      document
        .getElementById("modalSaveBtn")
        .addEventListener("click", saveNote);
      document
        .getElementById("modalClearBtn")
        .addEventListener("click", () => {
          document.getElementById("modalNoteInput").value = "";
          updateModalCharCount();
          checkForNoteChanges();
        });
      document
        .getElementById("modalNoteInput")
        .addEventListener("input", () => {
          const el = document.getElementById("modalNoteInput");
          if (el.value.length > 100) el.value = el.value.slice(0, 100);
          updateModalCharCount();
          checkForNoteChanges();
        });
    }

    function openClearCartModal() {
      document.getElementById("clearCartModal").classList.remove("hidden");
      document.body.style.overflow = "hidden";
    }

    function closeClearCartModal() {
      document.getElementById("clearCartModal").classList.add("hidden");
      document.body.style.overflow = "";
    }

    function setupClearCart() {
      document
        .getElementById("clearCartBtn")
        .addEventListener("click", () => {
          if (cartItems.length > 0) {
            openClearCartModal();
          }
        });

      document
        .getElementById("closeClearCartOverlay")
        .addEventListener("click", closeClearCartModal);
      document
        .getElementById("clearCartKeepBtn")
        .addEventListener("click", closeClearCartModal);

      document
        .getElementById("clearCartConfirmBtn")
        .addEventListener("click", async () => {
          const confirmBtn = document.getElementById("clearCartConfirmBtn");
          confirmBtn.disabled = true;
          const res = await postAction("clear_cart");
          confirmBtn.disabled = false;
          if (res.success) {
            cartItems = [];
            renderCart();
            closeClearCartModal();
            showToast("Cart cleared");
          }
        });
    }

    function setupPaymentMethod() {
      document.querySelectorAll('input[name="paymentMethod"]').forEach((radio) => {
        radio.addEventListener("change", () => {
          localStorage.setItem("cart_paymentMethod", radio.value);
        });
      });
    }

    function setupCheckout() {
      document
        .getElementById("checkoutBtn")
        .addEventListener("click", async () => {
          if (globalOrderType === "delivery" && !savedLocation) {
            showToast("Please set your drop-off location first", "warning");
            return;
          }
          if (cartItems.length === 0) return;

          if (cartItems.some((item) => !item.is_available)) {
            showToast("Remove out of stock items first", "warning");
            return;
          }

          const paymentMethod = document.querySelector(
            "input[name='paymentMethod']:checked",
          ).value;

          const checkoutBtn = document.getElementById("checkoutBtn");
          checkoutBtn.disabled = true;

          const res = await postAction("place_order", {
            order_type: globalOrderType,
            payment_method: paymentMethod,
            location: globalOrderType === "delivery" ? savedLocation : "",
          });

          checkoutBtn.disabled = false;

          if (res.success) {
            if (res.requires_redirect) {
              window.location.href = res.checkout_url;
              return;
            }
            const orderIdsParam = encodeURIComponent(res.order_ids.join(","));
            window.location.href =
              "order-success.php?count=" + encodeURIComponent(res.order_count) +
              "&ids=" + orderIdsParam +
              "&total=" + encodeURIComponent(res.total);
          } else {
            if (res.debug) console.log("DEBUG:", res.debug);
            showToast(res.message || "Something went wrong. Please try again.", "warning");
          }
        });
    }

    function setupBackButton() {
      document
        .getElementById("backButton")
        .addEventListener("click", () => window.history.back());
    }

    function setupQtyDeselectOnOutsideClick() {
      document.addEventListener("click", (e) => {
        if (
          activeQtyKey &&
          !e.target.closest(".decrement-qty") &&
          !e.target.closest(".increment-qty")
        ) {
          activeQtyKey = null;
          applyActiveQtyState();
        }
      });
    }

    function loadSavedPreferences() {
      const savedOrderType = localStorage.getItem("cart_orderType");
      if (savedOrderType === "delivery" || savedOrderType === "pickup") {
        setOrderType(savedOrderType);
      }

      const savedLoc = localStorage.getItem("cart_location");
      if (savedLoc) {
        savedLocation = savedLoc;
        updateLocationButton();
      }

      const savedPayment = localStorage.getItem("cart_paymentMethod");
      if (savedPayment) {
        const radio = document.querySelector(
          'input[name="paymentMethod"][value="' + savedPayment + '"]'
        );
        if (radio) radio.checked = true;
      }
    }

    function init() {
      setupLocationModal();
      setupNoteModal();
      setupClearCart();
      setupCheckout();
      setupPaymentMethod();
      setupBackButton();
      setupQtyDeselectOnOutsideClick();
      loadSavedPreferences();
      setTimeout(() => loadCart(), 200);
    }

    window.addEventListener("load", init);
  </script>
</body>

</html>