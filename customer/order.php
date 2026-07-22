<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['customer_id'])) {
  header('Location: ../auth/login.php');
  exit;
}

$customerId = $_SESSION['customer_id'];

require_once '../vendor/autoload.php';
require_once '../config/vapid.php';

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

function formatPaymentLabel($method, $orderType)
{
  if ($method === 'gcash') return 'GCash';
  if ($method === 'paymaya') return 'Maya';
  return $orderType === 'delivery' ? 'Cash on Delivery' : 'Cash on Pickup';
}

function refValues($arr)
{
  $refs = [];
  foreach ($arr as $key => $value) {
    $refs[$key] = &$arr[$key];
  }
  return $refs;
}

function fetchOrdersData($conn, $customerId)
{
  $stmt = $conn->prepare("
        SELECT o.order_id, o.stall_id, o.owner_id, o.staff_id, o.order_type, o.status,
               o.payment_method, o.total_amount, o.total_delivery_fee, o.grand_total,
               o.drop_off_location, o.note, o.cancel_reason, o.customer_confirmed,
               o.delivery_proof_image, o.created_at,
               s.stall_name,
               so.first_name AS owner_first_name, so.last_name AS owner_last_name,
               so.contact_number AS owner_contact, so.profile_image AS owner_profile_image,
               ds.first_name AS staff_first_name, ds.last_name AS staff_last_name,
               ds.contact_number AS staff_contact, ds.profile_image AS staff_profile_image
        FROM orders o
        JOIN stalls s ON o.stall_id = s.stall_id
        LEFT JOIN stall_owners so ON o.owner_id = so.owner_id
        LEFT JOIN delivery_staff ds ON o.staff_id = ds.staff_id
        WHERE o.customer_id = ?
        ORDER BY o.created_at DESC
    ");
  $stmt->bind_param("i", $customerId);
  $stmt->execute();
  $result = $stmt->get_result();

  $orders = [];
  $orderIds = [];

  while ($row = $result->fetch_assoc()) {
    $orderType = $row['order_type'];

    $stallOwner = null;
    if ($row['owner_first_name']) {
      $stallOwner = [
        'name' => trim($row['owner_first_name'] . ' ' . $row['owner_last_name']),
        'phone' => $row['owner_contact'],
        'image' => $row['owner_profile_image'] ? '../' . $row['owner_profile_image'] : null,
      ];
    }

    $deliveryStaff = null;
    if ($orderType === 'delivery' && $row['staff_first_name']) {
      $deliveryStaff = [
        'name' => trim($row['staff_first_name'] . ' ' . $row['staff_last_name']),
        'phone' => $row['staff_contact'],
        'image' => $row['staff_profile_image'] ? '../' . $row['staff_profile_image'] : null,
      ];
    }

    $orderIdRaw = (int) $row['order_id'];
    $orderIds[] = $orderIdRaw;

    $orders[$orderIdRaw] = [
      'orderIdRaw' => $orderIdRaw,
      'id' => 'FC-' . str_pad($orderIdRaw, 6, '0', STR_PAD_LEFT),
      'date' => date('M j, Y', strtotime($row['created_at'])) . ' · ' . date('g:i A', strtotime($row['created_at'])),
      'stall' => $row['stall_name'],
      'status' => $row['status'],
      'orderType' => $orderType,
      'location' => $row['drop_off_location'],
      'payment' => formatPaymentLabel($row['payment_method'], $orderType),
      'note' => $row['note'],
      'cancelReason' => $row['cancel_reason'],
      'customerConfirmed' => $row['customer_confirmed'],
      'proofImage' => $row['delivery_proof_image'] ? '../' . $row['delivery_proof_image'] : null,
      'stallOwner' => $stallOwner,
      'deliveryStaff' => $deliveryStaff,
      'items' => [],
      'deliveryFee' => (float) $row['total_delivery_fee'],
    ];
  }
  $stmt->close();

  if (empty($orderIds)) {
    return [];
  }

  $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
  $types = str_repeat('i', count($orderIds));

  $itemsStmt = $conn->prepare("
        SELECT oi.order_id, oi.item_name, oi.unit_price, oi.quantity, mi.image
        FROM order_items oi
        LEFT JOIN menu_items mi ON oi.menu_item_id = mi.menu_item_id
        WHERE oi.order_id IN ($placeholders)
        ORDER BY oi.order_item_id ASC
    ");
  $params = array_merge([$types], $orderIds);
  call_user_func_array([$itemsStmt, 'bind_param'], refValues($params));
  $itemsStmt->execute();
  $itemsResult = $itemsStmt->get_result();

  while ($itemRow = $itemsResult->fetch_assoc()) {
    $oid = (int) $itemRow['order_id'];
    if (isset($orders[$oid])) {
      $orders[$oid]['items'][] = [
        'name' => $itemRow['item_name'],
        'price' => (float) $itemRow['unit_price'],
        'qty' => (int) $itemRow['quantity'],
        'img' => $itemRow['image'] ? '../' . $itemRow['image'] : null,
      ];
    }
  }
  $itemsStmt->close();

  return array_values($orders);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
  header('Content-Type: application/json');
  $action = $_POST['action'];

  if ($action === 'get_orders') {
    echo json_encode(['success' => true, 'orders' => fetchOrdersData($conn, $customerId)]);
    $conn->close();
    exit;
  }

  if ($action === 'cancel_order') {
    $orderId = (int) ($_POST['order_id'] ?? 0);
    $reason = trim($_POST['reason'] ?? '');

    if ($orderId <= 0 || $reason === '') {
      echo json_encode(['success' => false, 'message' => 'Please select a reason for cancelling.']);
      $conn->close();
      exit;
    }

    $stmt = $conn->prepare("SELECT status, owner_id FROM orders WHERE order_id = ? AND customer_id = ? LIMIT 1");
    $stmt->bind_param("ii", $orderId, $customerId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
      echo json_encode(['success' => false, 'message' => 'Order not found.']);
      $conn->close();
      exit;
    }

    if ($row['status'] !== 'pending') {
      echo json_encode(['success' => false, 'message' => 'This order can no longer be cancelled.']);
      $conn->close();
      exit;
    }

    $stmt = $conn->prepare("UPDATE orders SET status = 'cancelled', cancel_reason = ?, cancelled_at = NOW() WHERE order_id = ? AND customer_id = ?");
    $stmt->bind_param("sii", $reason, $orderId, $customerId);
    $ok = $stmt->execute();
    $stmt->close();

    if ($ok) {
      $customerNameStmt = $conn->prepare("SELECT first_name, last_name FROM customers WHERE customer_id = ? LIMIT 1");
      $customerNameStmt->bind_param("i", $customerId);
      $customerNameStmt->execute();
      $customerNameRow = $customerNameStmt->get_result()->fetch_assoc();
      $customerNameStmt->close();
      $customerFullName = $customerNameRow ? trim($customerNameRow['first_name'] . ' ' . $customerNameRow['last_name']) : 'A customer';

      $friendlyOrderId = 'FC-' . str_pad($orderId, 6, '0', STR_PAD_LEFT);
      createNotification(
        $conn,
        'stall_owner',
        $row['owner_id'],
        'Order Cancelled by Customer',
        $customerFullName . ' cancelled order ' . $friendlyOrderId . '. Reason: ' . $reason,
        '../stall/orders.php'
      );
    }

    echo json_encode($ok
      ? ['success' => true, 'orders' => fetchOrdersData($conn, $customerId)]
      : ['success' => false, 'message' => 'Failed to cancel order.']);
    $conn->close();
    exit;
  }

  if ($action === 'update_location') {
    $orderId = (int) ($_POST['order_id'] ?? 0);
    $location = trim($_POST['location'] ?? '');

    if ($orderId <= 0 || $location === '') {
      echo json_encode(['success' => false, 'message' => 'Please enter a valid location.']);
      $conn->close();
      exit;
    }

    $stmt = $conn->prepare("SELECT status, order_type FROM orders WHERE order_id = ? AND customer_id = ? LIMIT 1");
    $stmt->bind_param("ii", $orderId, $customerId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
      echo json_encode(['success' => false, 'message' => 'Order not found.']);
      $conn->close();
      exit;
    }

    if ($row['status'] !== 'pending' || $row['order_type'] !== 'delivery') {
      echo json_encode(['success' => false, 'message' => 'This order can no longer be edited.']);
      $conn->close();
      exit;
    }

    $stmt = $conn->prepare("UPDATE orders SET drop_off_location = ? WHERE order_id = ? AND customer_id = ?");
    $stmt->bind_param("sii", $location, $orderId, $customerId);
    $ok = $stmt->execute();
    $stmt->close();

    echo json_encode($ok
      ? ['success' => true, 'orders' => fetchOrdersData($conn, $customerId)]
      : ['success' => false, 'message' => 'Failed to update location.']);
    $conn->close();
    exit;
  }

  if ($action === 'confirm_receipt') {
    $orderId = (int) ($_POST['order_id'] ?? 0);

    if ($orderId <= 0) {
      echo json_encode(['success' => false, 'message' => 'Invalid order.']);
      $conn->close();
      exit;
    }

    $stmt = $conn->prepare("SELECT status, customer_confirmed, owner_id FROM orders WHERE order_id = ? AND customer_id = ? LIMIT 1");
    $stmt->bind_param("ii", $orderId, $customerId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
      echo json_encode(['success' => false, 'message' => 'Order not found.']);
      $conn->close();
      exit;
    }

    if (!in_array($row['status'], ['completed', 'delivered'], true) || $row['customer_confirmed'] !== 'pending') {
      echo json_encode(['success' => false, 'message' => 'This order has already been reviewed.']);
      $conn->close();
      exit;
    }

    $stmt = $conn->prepare("UPDATE orders SET customer_confirmed = 'confirmed', customer_confirmed_at = NOW() WHERE order_id = ? AND customer_id = ?");
    $stmt->bind_param("ii", $orderId, $customerId);
    $ok = $stmt->execute();
    $stmt->close();

    if ($ok && $row['owner_id'] !== null) {
      $friendlyOrderId = 'FC-' . str_pad($orderId, 6, '0', STR_PAD_LEFT);
      createNotification(
        $conn,
        'stall_owner',
        $row['owner_id'],
        'Receipt Confirmed',
        'The customer confirmed receipt of order ' . $friendlyOrderId . '.',
        '../stall/orders.php'
      );
    }

    echo json_encode($ok
      ? ['success' => true, 'orders' => fetchOrdersData($conn, $customerId)]
      : ['success' => false, 'message' => 'Failed to confirm receipt.']);
    $conn->close();
    exit;
  }

  if ($action === 'report_issue') {
    $orderId = (int) ($_POST['order_id'] ?? 0);

    if ($orderId <= 0) {
      echo json_encode(['success' => false, 'message' => 'Invalid order.']);
      $conn->close();
      exit;
    }

    $stmt = $conn->prepare("SELECT status, customer_confirmed, owner_id, order_type, staff_id FROM orders WHERE order_id = ? AND customer_id = ? LIMIT 1");
    $stmt->bind_param("ii", $orderId, $customerId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
      echo json_encode(['success' => false, 'message' => 'Order not found.']);
      $conn->close();
      exit;
    }

    if (!in_array($row['status'], ['completed', 'delivered'], true) || $row['customer_confirmed'] !== 'pending') {
      echo json_encode(['success' => false, 'message' => 'This order has already been reviewed.']);
      $conn->close();
      exit;
    }

    $stmt = $conn->prepare("UPDATE orders SET customer_confirmed = 'issue', customer_confirmed_at = NOW() WHERE order_id = ? AND customer_id = ?");
    $stmt->bind_param("ii", $orderId, $customerId);
    $ok = $stmt->execute();
    $stmt->close();

    if ($ok) {
      $friendlyOrderId = 'FC-' . str_pad($orderId, 6, '0', STR_PAD_LEFT);

      if ($row['owner_id'] !== null) {
        createNotification(
          $conn,
          'stall_owner',
          $row['owner_id'],
          'Issue Reported',
          'The customer reported an issue with order ' . $friendlyOrderId . '.',
          '../stall/orders.php'
        );
      }

      if ($row['order_type'] === 'delivery' && $row['staff_id'] !== null) {
        createNotification(
          $conn,
          'delivery_staff',
          $row['staff_id'],
          'Issue Reported',
          'The customer reported an issue with order ' . $friendlyOrderId . '.',
          '../delivery/deliveries.php'
        );
      }
    }

    echo json_encode($ok
      ? ['success' => true, 'orders' => fetchOrdersData($conn, $customerId)]
      : ['success' => false, 'message' => 'Failed to report issue.']);
    $conn->close();
    exit;
  }

  if ($action === 'reorder') {
    $orderId = (int) ($_POST['order_id'] ?? 0);

    if ($orderId <= 0) {
      echo json_encode(['success' => false, 'message' => 'Invalid order.']);
      $conn->close();
      exit;
    }

    $stmt = $conn->prepare("SELECT order_id FROM orders WHERE order_id = ? AND customer_id = ? LIMIT 1");
    $stmt->bind_param("ii", $orderId, $customerId);
    $stmt->execute();
    $orderRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$orderRow) {
      echo json_encode(['success' => false, 'message' => 'Order not found.']);
      $conn->close();
      exit;
    }

    $stmt = $conn->prepare("SELECT menu_item_id, quantity FROM order_items WHERE order_id = ?");
    $stmt->bind_param("i", $orderId);
    $stmt->execute();
    $itemsResult = $stmt->get_result();
    $itemRows = [];
    while ($r = $itemsResult->fetch_assoc()) {
      $itemRows[] = $r;
    }
    $stmt->close();

    $addedCount = 0;
    $skippedCount = 0;

    foreach ($itemRows as $itemRow) {
      $menuItemId = (int) $itemRow['menu_item_id'];
      $qty = (int) $itemRow['quantity'];

      $checkStmt = $conn->prepare("
                SELECT mi.stall_id
                FROM menu_items mi
                JOIN stalls s ON mi.stall_id = s.stall_id
                WHERE mi.menu_item_id = ?
                  AND mi.status = 'available'
                  AND mi.owner_id = s.owner_id
                LIMIT 1
            ");
      $checkStmt->bind_param("i", $menuItemId);
      $checkStmt->execute();
      $validRow = $checkStmt->get_result()->fetch_assoc();
      $checkStmt->close();

      if (!$validRow) {
        $skippedCount++;
        continue;
      }

      $stallId = (int) $validRow['stall_id'];

      $cartStmt = $conn->prepare("
                INSERT INTO carts (customer_id, menu_item_id, stall_id, quantity)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)
            ");
      $cartStmt->bind_param("iiii", $customerId, $menuItemId, $stallId, $qty);
      $cartStmt->execute();
      $cartStmt->close();

      $addedCount++;
    }

    echo json_encode(['success' => true, 'added' => $addedCount, 'skipped' => $skippedCount]);
    $conn->close();
    exit;
  }

  echo json_encode(['success' => false, 'message' => 'Unknown action.']);
  $conn->close();
  exit;
}

$initialOrders = fetchOrdersData($conn, $customerId);
$conn->close();
?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <meta
      name="viewport"
      content="width=device-width, initial-scale=1.0, user-scalable=yes"
    />
    <title>Customer - My Orders</title>
    <link rel="icon" href="../assets/images/nwssu-logo.png" type="image/png" />
    <script src="https://cdn.tailwindcss.com"></script>
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
      .status-badge {
        display: inline-flex;
        align-items: center;
        padding: 3px 10px;
        font-size: 11px;
        font-weight: 600;
        background: #ffffff;
        border: 1px solid #d1d5db;
        color: #374151;
      }
      .status-cancelled {
        color: #6b7280;
        border-color: #d1d5db;
      }
      .step-done {
        background: #059669;
        border-color: #059669;
        color: white;
      }
      .step-active {
        background: white;
        border-color: #059669;
        color: #059669;
      }
      .step-idle {
        background: white;
        border-color: #d1d5db;
        color: #9ca3af;
      }
      .modal-overlay {
        background-color: rgba(0, 0, 0, 0.5);
      }
      .no-scrollbar::-webkit-scrollbar {
        display: none;
      }
      .no-scrollbar {
        -ms-overflow-style: none;
        scrollbar-width: none;
      }
      #cancelReasonsContainer {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
      }
      .reason-option {
        display: inline-flex;
      }
      .reason-option input[type="radio"] {
        display: none;
      }
      .reason-option label {
        display: inline-flex;
        align-items: center;
        padding: 7px 12px;
        border: 1px solid #e5e7eb;
        cursor: pointer;
        font-size: 12px;
        color: #4b5563;
        background: #f9fafb;
        line-height: 1.2;
      }
      .reason-option input[type="radio"]:checked + label {
        border-color: #059669;
        background: #059669;
        color: #fff;
        font-weight: 600;
      }
      .reason-option .radio-dot {
        display: none;
      }
      #otherReasonWrapper {
        width: 100%;
      }
      .status-tab-active {
        background: linear-gradient(135deg, #059669 0%, #047857 100%);
        border-color: #059669;
        color: #ffffff;
      }
    </style>
  </head>
  <body class="bg-white">
    <div class="flex flex-col h-screen">
      <div class="bg-white flex-shrink-0 fixed top-0 left-0 right-0 z-20">
        <div class="max-w-5xl mx-auto px-4 py-2 grid grid-cols-3 items-center">
          <button
            id="backButton"
            class="rounded-md p-1.5 bg-white border border-slate-200 hover:border-emerald-500 hover:bg-slate-50 transition-all justify-self-start flex items-center justify-center shrink-0"
            style="width: 34px; height: 34px"
            title="Go back"
          >
            <svg
              xmlns="http://www.w3.org/2000/svg"
              fill="none"
              viewBox="0 0 24 24"
              stroke-width="1.5"
              stroke="currentColor"
              class="w-5 h-5 text-gray-600"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                d="M15.75 19.5 8.25 12l7.5-7.5"
              />
            </svg>
          </button>
          <h1 class="text-base font-semibold text-emerald-600 text-center">
            My Orders
          </h1>
          <div class="justify-self-end" style="width: 34px"></div>
        </div>
      </div>

      <div class="flex-1 overflow-y-auto mt-12 mb-16" id="mainContent">
        <div class="max-w-5xl mx-auto px-4 pt-3 pb-4 space-y-3">
          <div class="rounded-md bg-white border border-gray-200 p-3 shadow-sm space-y-3">
            <div class="flex items-center gap-2">
              <div class="relative flex-1 min-w-0">
                <input
                  type="text"
                  id="searchOrders"
                  placeholder="Search orders..."
                  class="w-full pl-9 pr-9 py-2 bg-white border border-gray-200 text-xs text-gray-900 placeholder-gray-400 focus:outline-none focus:border-emerald-600 rounded-[3px]"
                />
                <div
                  class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"
                >
                  <svg
                    xmlns="http://www.w3.org/2000/svg"
                    fill="none"
                    viewBox="0 0 24 24"
                    stroke-width="1.5"
                    stroke="currentColor"
                    class="w-4 h-4"
                  >
                    <path
                      stroke-linecap="round"
                      stroke-linejoin="round"
                      d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z"
                    />
                  </svg>
                </div>
                <button
                  type="button"
                  id="clearSearchBtn"
                  class="hidden absolute right-2.5 top-1/2 -translate-y-1/2 p-0.5 text-gray-400 hover:text-gray-600 transition-colors rounded-[3px]"
                  title="Clear search"
                >
                  <svg
                    xmlns="http://www.w3.org/2000/svg"
                    fill="none"
                    viewBox="0 0 24 24"
                    stroke-width="2"
                    stroke="currentColor"
                    class="w-4 h-4"
                  >
                    <path
                      stroke-linecap="round"
                      stroke-linejoin="round"
                      d="M6 18 18 6M6 6l12 12"
                    />
                  </svg>
                </button>
              </div>
              <div class="relative inline-block shrink-0">
                <select
                  id="typeFilterSelect"
                  class="pl-2.5 pr-6 py-2 bg-white border border-gray-200 text-xs font-normal text-gray-700 focus:outline-none focus:border-emerald-600 appearance-none cursor-pointer rounded-[3px]"
                >
                  <option value="delivery">Delivery</option>
                  <option value="pickup">Pickup</option>
                </select>
                <span
                  id="typeFilterMeasure"
                  class="text-xs font-normal"
                  style="
                    position: absolute;
                    visibility: hidden;
                    white-space: pre;
                    left: -9999px;
                    top: -9999px;
                  "
                ></span>
                <div
                  class="absolute right-2 top-1/2 -translate-y-1/2 text-gray-400 pointer-events-none"
                >
                  <svg
                    xmlns="http://www.w3.org/2000/svg"
                    fill="none"
                    viewBox="0 0 24 24"
                    stroke-width="1.5"
                    stroke="currentColor"
                    class="w-4 h-4"
                  >
                    <path
                      stroke-linecap="round"
                      stroke-linejoin="round"
                      d="m19.5 8.25-7.5 7.5-7.5-7.5"
                    />
                  </svg>
                </div>
              </div>
            </div>
            <div
              id="statusTabsContainer"
              class="hidden flex items-center gap-2 overflow-x-auto no-scrollbar"
            ></div>
          </div>

          <div id="ordersContainer" class="space-y-3"></div>

          <div
            id="emptyView"
            class="hidden flex flex-col items-center justify-center py-16 text-center"
          >
            <div class="w-40 h-40 mb-4">
              <img src="../assets/illustrations/empty-orders.svg" alt="No orders found" class="w-full h-full" />
            </div>
            <h3 class="text-base font-semibold text-gray-800">
              No orders found
            </h3>
            <p class="text-gray-500 text-sm mt-1 mb-5">
              Try adjusting your filter or search.
            </p>
          </div>
        </div>
      </div>

      <div
        class="bg-white border-t border-gray-200 flex-shrink-0 fixed bottom-0 left-0 right-0 z-20"
      >
        <div class="max-w-5xl mx-auto px-4 flex justify-around py-2">
          <a
            href="./home.php"
            class="flex flex-col items-center justify-center py-2 px-3 transition-all duration-200 group text-gray-500 hover:text-gray-900 hover:bg-gray-50 rounded-[3px]"
          >
            <svg
              xmlns="http://www.w3.org/2000/svg"
              fill="none"
              viewBox="0 0 24 24"
              stroke-width="1.5"
              stroke="currentColor"
              class="w-5 h-5 transition-transform group-hover:scale-110"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                d="m2.25 12 8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25"
              />
            </svg>
            <span class="text-xs font-medium mt-1">Home</span>
          </a>
          <a
            href="./cart.php"
            class="flex flex-col items-center justify-center py-2 px-3 transition-all duration-200 group text-gray-500 hover:text-gray-900 hover:bg-gray-50 relative rounded-[3px]"
          >
            <svg
              xmlns="http://www.w3.org/2000/svg"
              fill="none"
              viewBox="0 0 24 24"
              stroke-width="1.5"
              stroke="currentColor"
              class="w-5 h-5 transition-transform group-hover:scale-110"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                d="M15.75 10.5V6a3.75 3.75 0 1 0-7.5 0v4.5m11.356-1.993 1.263 12c.07.665-.45 1.243-1.119 1.243H4.25a1.125 1.125 0 0 1-1.12-1.243l1.264-12A1.125 1.125 0 0 1 5.513 7.5h12.974c.576 0 1.059.435 1.119 1.007ZM8.625 10.5a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm7.5 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z"
              />
            </svg>
            <span class="text-xs font-medium mt-1">Cart</span>
          </a>
          <a
            href="./order.php"
            class="flex flex-col items-center justify-center py-2 px-3 transition-all duration-200 group text-emerald-600 bg-emerald-50 rounded-[3px]"
          >
            <svg
              xmlns="http://www.w3.org/2000/svg"
              fill="none"
              viewBox="0 0 24 24"
              stroke-width="1.5"
              stroke="currentColor"
              class="w-5 h-5 transition-transform group-hover:scale-110"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 0 0 2.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 0 0-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 0 0 .75-.75 2.25 2.25 0 0 0-.1-.664m-5.8 0A2.251 2.251 0 0 1 13.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25ZM6.75 12h.008v.008H6.75V12Zm0 3h.008v.008H6.75V15Zm0 3h.008v.008H6.75V18Z"
              />
            </svg>
            <span class="text-xs font-medium mt-1">Orders</span>
          </a>
          <a
            href="./chat.php"
            class="flex flex-col items-center justify-center py-2 px-3 transition-all duration-200 group text-gray-500 hover:text-gray-900 hover:bg-gray-50 relative rounded-[3px]"
          >
            <svg
              xmlns="http://www.w3.org/2000/svg"
              fill="none"
              viewBox="0 0 24 24"
              stroke-width="1.5"
              stroke="currentColor"
              class="w-5 h-5 transition-transform group-hover:scale-110"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                d="M20.25 8.511c.884.284 1.5 1.128 1.5 2.097v4.286c0 1.136-.847 2.1-1.98 2.193-.34.027-.68.052-1.02.072v3.091l-3-3c-1.354 0-2.694-.055-4.02-.163a2.115 2.115 0 0 1-.825-.242m9.345-8.334a2.126 2.126 0 0 0-.476-.095 48.64 48.64 0 0 0-8.048 0c-1.131.094-1.976 1.057-1.976 2.192v4.286c0 .837.46 1.58 1.155 1.951m9.345-8.334V6.637c0-1.621-1.152-3.026-2.76-3.235A48.455 48.455 0 0 0 11.25 3c-2.115 0-4.198.137-6.24.402-1.608.209-2.76 1.614-2.76 3.235v6.226c0 1.621 1.152 3.026 2.76 3.235.577.075 1.157.14 1.74.194V21l4.155-4.155"
              />
            </svg>
            <span class="text-xs font-medium mt-1">Chats</span>
          </a>
          <a
            href="./account.php"
            class="flex flex-col items-center justify-center py-2 px-3 transition-all duration-200 group text-gray-500 hover:text-gray-900 hover:bg-gray-50 rounded-[3px]"
          >
            <svg
              xmlns="http://www.w3.org/2000/svg"
              fill="none"
              viewBox="0 0 24 24"
              stroke-width="1.5"
              stroke="currentColor"
              class="w-5 h-5 transition-transform group-hover:scale-110"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z"
              />
            </svg>
            <span class="text-xs font-medium mt-1">Account</span>
          </a>
        </div>
      </div>
    </div>

    <div
      id="detailsModal"
      class="fixed inset-0 z-50 hidden flex items-center justify-center px-4"
    >
      <div class="modal-overlay absolute inset-0" id="closeModalOverlay"></div>
      <div
        class="bg-white w-full max-w-md max-h-[90vh] overflow-y-auto relative z-10 shadow-2xl rounded-md"
      >
        <div
          class="p-4 border-b border-gray-100 flex items-center justify-between sticky top-0 bg-white z-10"
        >
          <h2 class="font-bold text-gray-800">Order Details</h2>
          <button
            id="closeModalBtn"
            class="p-1 hover:bg-gray-100 rounded-[3px]"
          >
            <svg
              xmlns="http://www.w3.org/2000/svg"
              fill="none"
              viewBox="0 0 24 24"
              stroke-width="1.5"
              stroke="currentColor"
              class="w-6 h-6 text-gray-500"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                d="M6 18 18 6M6 6l12 12"
              />
            </svg>
          </button>
        </div>
        <div id="modalContent" class="p-4 space-y-6"></div>
      </div>
    </div>

    <div
      id="cancelModal"
      class="fixed inset-0 z-[60] hidden flex items-end justify-center sm:items-center sm:px-4"
    >
      <div class="modal-overlay absolute inset-0" id="cancelModalOverlay"></div>
      <div
        class="bg-white w-full sm:max-w-md relative z-10 shadow-2xl overflow-hidden rounded-md"
      >
        <div
          class="p-4 border-b border-gray-100 flex items-center justify-between"
        >
          <div class="flex items-center gap-2.5">
            <div
              class="w-8 h-8 bg-red-50 flex items-center justify-center rounded-[3px]"
            >
              <svg
                xmlns="http://www.w3.org/2000/svg"
                fill="none"
                viewBox="0 0 24 24"
                stroke-width="1.5"
                stroke="currentColor"
                class="w-4 h-4 text-red-500"
              >
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z"
                />
              </svg>
            </div>
            <h2 class="font-bold text-gray-800 text-sm">Cancel Order</h2>
          </div>
          <button
            id="closeCancelModalBtn"
            class="p-1 hover:bg-gray-100 rounded-[3px]"
          >
            <svg
              xmlns="http://www.w3.org/2000/svg"
              fill="none"
              viewBox="0 0 24 24"
              stroke-width="1.5"
              stroke="currentColor"
              class="w-5 h-5 text-gray-500"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                d="M6 18 18 6M6 6l12 12"
              />
            </svg>
          </button>
        </div>
        <div class="px-4 pt-3 pb-1">
          <p class="text-xs text-gray-500">
            Order
            <span
              id="cancelOrderIdLabel"
              class="font-semibold text-gray-700"
            ></span>
          </p>
          <p class="text-sm font-medium text-gray-800 mt-0.5">
            Why do you want to cancel?
          </p>
        </div>
        <div class="px-4 py-3" id="cancelReasonsContainer">
          <div class="reason-option">
            <input
              type="radio"
              name="cancelReason"
              id="reason1"
              value="I changed my mind"
            />
            <label for="reason1" class="rounded-full"
              ><span class="radio-dot"></span>I changed my mind</label
            >
          </div>
          <div class="reason-option">
            <input
              type="radio"
              name="cancelReason"
              id="reason2"
              value="I ordered the wrong item"
            />
            <label for="reason2" class="rounded-full"
              ><span class="radio-dot"></span>I ordered the wrong item</label
            >
          </div>
          <div class="reason-option">
            <input
              type="radio"
              name="cancelReason"
              id="reason3"
              value="I ordered from the wrong stall"
            />
            <label for="reason3" class="rounded-full"
              ><span class="radio-dot"></span>I ordered from the wrong
              stall</label
            >
          </div>
          <div class="reason-option">
            <input
              type="radio"
              name="cancelReason"
              id="reason4"
              value="I want to change my delivery location"
            />
            <label for="reason4" class="rounded-full"
              ><span class="radio-dot"></span>I want to change my delivery
              location</label
            >
          </div>
          <div class="reason-option">
            <input
              type="radio"
              name="cancelReason"
              id="reason5"
              value="I want to change my payment method"
            />
            <label for="reason5" class="rounded-full"
              ><span class="radio-dot"></span>I want to change my payment
              method</label
            >
          </div>
          <div class="reason-option">
            <input
              type="radio"
              name="cancelReason"
              id="reason6"
              value="The food takes too long"
            />
            <label for="reason6" class="rounded-full"
              ><span class="radio-dot"></span>The food takes too long</label
            >
          </div>
          <div class="reason-option">
            <input
              type="radio"
              name="cancelReason"
              id="reason7"
              value="I want to add more items"
            />
            <label for="reason7" class="rounded-full"
              ><span class="radio-dot"></span>I want to add more items</label
            >
          </div>
          <div class="reason-option">
            <input
              type="radio"
              name="cancelReason"
              id="reason8"
              value="other"
            />
            <label for="reason8" class="rounded-full"
              ><span class="radio-dot"></span>Other reason</label
            >
          </div>
          <div id="otherReasonWrapper" class="hidden pt-1">
            <textarea
              id="otherReasonText"
              rows="2"
              placeholder="Tell us more..."
              class="w-full px-3 py-2 text-xs border border-gray-200 focus:outline-none focus:border-emerald-600 resize-none text-gray-700 placeholder-gray-400 rounded-[3px]"
            ></textarea>
          </div>
        </div>
        <div class="px-4 pb-5 pt-2 flex gap-2.5">
          <button
            id="cancelModalKeepBtn"
            class="flex-1 py-2.5 border border-gray-200 text-gray-600 text-xs font-semibold rounded-[3px]"
          >
            Keep Order
          </button>
          <button
            id="cancelModalConfirmBtn"
            class="flex-1 py-2.5 bg-red-500 text-white text-xs font-semibold disabled:opacity-40 disabled:cursor-not-allowed rounded-[3px]"
            disabled
          >
            Confirm Cancel
          </button>
        </div>
      </div>
    </div>

    <div
      id="confirmReceiptModal"
      class="fixed inset-0 z-[60] hidden flex items-center justify-center px-4"
    >
      <div class="modal-overlay absolute inset-0" id="closeConfirmReceiptOverlay"></div>
      <div class="bg-white w-full max-w-sm relative z-10 shadow-2xl p-5 space-y-4 text-center rounded-md">
        <div class="w-12 h-12 bg-emerald-50 flex items-center justify-center mx-auto rounded-full">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6 text-emerald-600">
            <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
          </svg>
        </div>
        <div>
          <p class="text-sm font-bold text-gray-800">Confirm Receipt</p>
          <p class="text-xs text-gray-500 mt-1">Did you receive your order in good condition? This confirms the order is complete.</p>
        </div>
        <div class="flex gap-2 pt-1">
          <button id="confirmReceiptKeepBtn" class="flex-1 py-2.5 border border-gray-200 text-gray-700 text-xs font-semibold hover:bg-gray-50 transition-colors rounded-[3px]">
            Cancel
          </button>
          <button id="confirmReceiptConfirmBtn" class="flex-1 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold transition-colors rounded-[3px]">
            Yes, Confirm
          </button>
        </div>
      </div>
    </div>

    <div
      id="reportIssueModal"
      class="fixed inset-0 z-[60] hidden flex items-center justify-center px-4"
    >
      <div class="modal-overlay absolute inset-0" id="closeReportIssueOverlay"></div>
      <div class="bg-white w-full max-w-sm relative z-10 shadow-2xl p-5 space-y-4 text-center rounded-md">
        <div class="w-12 h-12 bg-red-50 flex items-center justify-center mx-auto rounded-full">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6 text-red-500">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
          </svg>
        </div>
        <div>
          <p class="text-sm font-bold text-gray-800">Report an Issue</p>
          <p class="text-xs text-gray-500 mt-1">Something wrong with this order? Our team will follow up with you about it.</p>
        </div>
        <div class="flex gap-2 pt-1">
          <button id="reportIssueKeepBtn" class="flex-1 py-2.5 border border-gray-200 text-gray-700 text-xs font-semibold hover:bg-gray-50 transition-colors rounded-[3px]">
            Cancel
          </button>
          <button id="reportIssueConfirmBtn" class="flex-1 py-2.5 bg-red-500 hover:bg-red-600 text-white text-xs font-semibold transition-colors rounded-[3px]">
            Report Issue
          </button>
        </div>
      </div>
    </div>

    <div id="imageLightbox" class="fixed inset-0 z-[70] hidden flex items-center justify-center px-4">
      <div class="absolute inset-0 bg-black/80" id="closeLightboxOverlay"></div>
      <button id="closeLightboxBtn" class="absolute top-4 right-4 z-10 p-2 bg-white/10 hover:bg-white/20 transition-colors rounded-full">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6 text-white">
          <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
        </svg>
      </button>
      <img id="lightboxImage" src="" alt="" class="relative z-10 max-w-full max-h-[85vh] object-contain rounded-[6px]" />
    </div>

    <script>
      const STATUS_META = {
        pending: { label: "Pending", cls: "" },
        preparing: { label: "Preparing", cls: "" },
        ready_for_pickup: { label: "Ready for Pickup", cls: "" },
        ready_for_dispatch: { label: "Ready for Dispatch", cls: "" },
        collected: { label: "Collected", cls: "" },
        out_for_delivery: { label: "Out for Delivery", cls: "" },
        completed: { label: "Completed", cls: "" },
        delivered: { label: "Delivered", cls: "" },
        cancelled: { label: "Cancelled", cls: "status-cancelled" },
      };

      const STEPS = [
        "pending",
        "preparing",
        "ready_for_dispatch",
        "collected",
        "out_for_delivery",
        "delivered",
      ];

      const PICKUP_STATUS_TABS = [
        { value: "all", label: "All" },
        { value: "pending", label: "Pending" },
        { value: "preparing", label: "Preparing" },
        { value: "ready_for_pickup", label: "Ready for Pickup" },
        { value: "completed", label: "Completed" },
        { value: "cancelled", label: "Cancelled" },
      ];

      const DELIVERY_STATUS_TABS = [
        { value: "all", label: "All" },
        { value: "pending", label: "Pending" },
        { value: "ready_for_dispatch", label: "Ready" },
        { value: "collected", label: "Collected" },
        { value: "out_for_delivery", label: "Out for Delivery" },
        { value: "delivered", label: "Delivered" },
        { value: "cancelled", label: "Cancelled" },
      ];

      const CUSTOMER_TYPE_MAP = {
        student: { label: "Student", cls: "bg-sky-50 text-sky-700 border-sky-200" },
        faculty: { label: "Faculty", cls: "bg-violet-50 text-violet-700 border-violet-200" },
        staff: { label: "Staff", cls: "bg-teal-50 text-teal-700 border-teal-200" },
      };

      let ALL_ORDERS = <?php echo json_encode($initialOrders); ?>;

      let searchQuery = "";
      let currentTypeFilter = "delivery";
      let currentStatusFilter = "all";
      let pendingCancelId = null;
      let pendingConfirmReceiptId = null;
      let pendingReportIssueId = null;

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
          return { success: false, message: "Something went wrong. Please try again." };
        }
      }

      function escapeHtml(str) {
        if (!str) return "";
        return str
          .replace(/&/g, "&amp;")
          .replace(/</g, "&lt;")
          .replace(/>/g, "&gt;")
          .replace(/"/g, "&quot;")
          .replace(/'/g, "&#039;");
      }

      function getInitials(name) {
        return String(name || "")
          .split(" ")
          .filter(Boolean)
          .map((w) => w[0])
          .join("")
          .slice(0, 2)
          .toUpperCase();
      }

      function personAvatarHtml(imageUrl, name, sizeCls) {
        if (imageUrl) {
          return `<img src="${escapeHtml(imageUrl)}" alt="${escapeHtml(name)}" class="${sizeCls} object-cover shrink-0 rounded-full" />`;
        }
        return `<div class="${sizeCls} bg-gradient-to-br from-emerald-500 to-emerald-700 flex items-center justify-center text-white text-xs font-bold shrink-0 rounded-full">${getInitials(name)}</div>`;
      }

      function getStatusLabel(status) {
        return STATUS_META[status] ? STATUS_META[status].label : status;
      }

      function isReviewableStatus(status) {
        return status === "completed" || status === "delivered";
      }

      function calcTotal(order) {
        return (
          order.items.reduce((s, i) => s + i.price * i.qty, 0) +
          order.deliveryFee
        );
      }

      function copyToClipboard(text, iconEl) {
        if (iconEl.dataset.copyTimeout) {
          clearTimeout(parseInt(iconEl.dataset.copyTimeout));
          iconEl.dataset.copyTimeout = "";
          resetIcon(iconEl);
        }
        if (navigator.clipboard && window.isSecureContext) {
          navigator.clipboard
            .writeText(text)
            .then(() => showCopiedIcon(iconEl));
        } else {
          const textArea = document.createElement("textarea");
          textArea.value = text;
          textArea.style.position = "fixed";
          textArea.style.left = "-999999px";
          textArea.style.top = "-999999px";
          document.body.appendChild(textArea);
          textArea.focus();
          textArea.select();
          try {
            document.execCommand("copy");
            showCopiedIcon(iconEl);
          } catch (err) {
            console.error("Fallback copy failed", err);
          }
          document.body.removeChild(textArea);
        }
      }

      function resetIcon(iconEl) {
        if (iconEl.dataset.originalHTML) {
          iconEl.innerHTML = iconEl.dataset.originalHTML;
          iconEl.dataset.originalHTML = "";
          iconEl.dataset.copyTimeout = "";
        }
      }

      function showCopiedIcon(iconEl) {
        if (!iconEl.dataset.originalHTML)
          iconEl.dataset.originalHTML = iconEl.innerHTML;
        iconEl.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="3" stroke="currentColor" class="w-3 h-3 -translate-y-px"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>`;
        const timeoutId = setTimeout(() => resetIcon(iconEl), 2000);
        iconEl.dataset.copyTimeout = timeoutId.toString();
      }

      function buildOrderCard(order) {
        const meta = STATUS_META[order.status];
        const statusLabel = getStatusLabel(order.status);
        const total = calcTotal(order);
        const subtotal = order.items.reduce((s, i) => s + i.price * i.qty, 0);
        const reviewable = isReviewableStatus(order.status);
        const card = document.createElement("div");
        card.className =
          "rounded-md bg-white border border-gray-200 overflow-hidden shadow-sm";
        card.setAttribute("data-order-id", order.orderIdRaw);
        const itemsHTML = order.items
          .map(
            (item) => `
          <div class="flex items-center gap-3">
            ${item.img ? `<img src="${item.img}" alt="${escapeHtml(item.name)}" class="w-10 h-10 object-cover bg-gray-100 shrink-0 rounded-[3px]" />` : `<div class="w-10 h-10 bg-gray-100 shrink-0 rounded-[3px]"></div>`}
            <div class="flex-1 flex items-center justify-between">
              <div class="flex flex-col">
                <span class="text-xs text-gray-600">${escapeHtml(item.name)} x${item.qty}</span>
                <span class="text-[10px] text-gray-400">₱${item.price.toFixed(2)} each</span>
              </div>
              <span class="text-xs font-medium text-gray-700">₱${(item.price * item.qty).toFixed(2)}</span>
            </div>
          </div>
        `,
          )
          .join("");

        let reviewNote = "";
        if (reviewable && order.customerConfirmed === "confirmed") {
          reviewNote = `<p class="text-[10px] text-emerald-600 font-medium mt-0.5">You confirmed this order</p>`;
        } else if (reviewable && order.customerConfirmed === "issue") {
          reviewNote = `<p class="text-[10px] text-red-500 font-medium mt-0.5">You reported an issue</p>`;
        }

        let actionButtonsHTML = `
            <button class="view-details-btn flex-1 py-2 border border-gray-200 text-gray-700 text-xs font-semibold hover:bg-gray-50 transition-colors flex items-center justify-center gap-1.5 rounded-[3px]" data-id="${order.orderIdRaw}">
              View Details
            </button>
          `;

        if (order.status === "pending") {
          actionButtonsHTML += `<button class="cancel-btn flex-1 py-2 border border-red-200 text-red-600 text-xs font-semibold hover:bg-red-50 transition-colors flex items-center justify-center gap-1.5 rounded-[3px]" data-id="${order.orderIdRaw}">Cancel Order</button>`;
        } else if (["preparing", "ready_for_pickup", "ready_for_dispatch", "collected", "out_for_delivery"].includes(order.status)) {
          actionButtonsHTML += `<button class="flex-1 py-2 border border-gray-200 text-gray-400 text-xs font-semibold cursor-not-allowed opacity-60 flex items-center justify-center gap-1.5 rounded-[3px]" disabled title="Cancellation is not allowed after preparation has started">Cancel Order</button>`;
        } else if (reviewable && order.customerConfirmed === "pending") {
          actionButtonsHTML += `<button class="confirm-receipt-btn flex-1 py-2 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold transition-colors flex items-center justify-center gap-1.5 rounded-[3px]" data-id="${order.orderIdRaw}">Confirm</button>`;
          actionButtonsHTML += `<button class="report-issue-btn flex-1 py-2 border border-red-200 text-red-600 text-xs font-semibold hover:bg-red-50 transition-colors flex items-center justify-center gap-1.5 rounded-[3px]" data-id="${order.orderIdRaw}">Report Issue</button>`;
        } else if (reviewable) {
          actionButtonsHTML += `<button class="reorder-btn flex-1 py-2 bg-gradient-to-r from-emerald-600 to-emerald-700 text-white text-xs font-semibold hover:from-emerald-700 hover:to-emerald-800 transition-colors flex items-center justify-center gap-1.5 rounded-[3px]" data-id="${order.orderIdRaw}">Reorder</button>`;
        } else if (order.status === "cancelled") {
          actionButtonsHTML += `<button class="flex-1 py-2 border border-gray-200 text-gray-400 text-xs font-semibold cursor-not-allowed opacity-60 flex items-center justify-center gap-1.5 rounded-[3px]" disabled>Order Cancelled</button>`;
        }

        card.innerHTML = `
          <div class="p-4 border-b border-gray-100">
            <div class="flex items-start justify-between gap-3">
              <div>
                <div class="flex items-center gap-1.5 cursor-pointer hover:text-emerald-600 transition-colors copy-id-btn" data-id="${escapeHtml(order.id)}">
                  <p class="text-xs font-semibold text-gray-800 inherit-color">${escapeHtml(order.id)}</p>
                  <span class="copy-icon w-3 h-3 text-gray-400 flex items-center justify-center shrink-0">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-3 h-3"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 17.25v3.375c0 .621-.504 1.125-1.125 1.125h-9.75a1.125 1.125 0 0 1-1.125-1.125V7.875c0-.621.504-1.125 1.125-1.125H6.75a9.06 9.06 0 0 1 1.5.124m7.5 10.376h3.375c.621 0 1.125-.504 1.125-1.125V11.25c0-4.46-3.243-8.161-7.5-8.876a9.06 9.06 0 0 0-1.5-.124H9.375c-.621 0-1.125.504-1.125 1.125v3.5m7.5 10.375H9.375a1.125 1.125 0 0 1-1.125-1.125v-9.25m12 6.625v-1.875a3.375 3.375 0 0 0-3.375-3.375h-1.5a1.125 1.125 0 0 1-1.125-1.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H9.75" /></svg>
                  </span>
                </div>
                <p class="text-[11px] text-gray-500 mt-0.5">${escapeHtml(order.date)}</p>
                <p class="text-[11px] text-emerald-600 font-medium mt-0.5">${escapeHtml(order.stall)}</p>
                ${reviewNote}
              </div>
              <span class="status-badge rounded-[3px] ${meta.cls} shrink-0">${statusLabel}</span>
            </div>
          </div>
          <div class="px-4 py-3 border-b border-gray-100 space-y-3">
            ${itemsHTML}
            <div class="flex items-center justify-between pt-1.5 border-t border-gray-100 mt-1">
              <span class="text-xs text-gray-500">Subtotal</span>
              <span class="text-xs text-gray-600">₱${subtotal.toFixed(2)}</span>
            </div>
            <div class="flex items-center justify-between">
              <span class="text-xs text-gray-500">${order.orderType === "pickup" ? "Pickup fee" : "Delivery fee"}</span>
              ${order.orderType === "pickup" ? `<span class="text-xs text-emerald-600 font-semibold">Free</span>` : `<span class="text-xs text-gray-600">₱${order.deliveryFee.toFixed(2)}</span>`}
            </div>
            <div class="flex items-center justify-between pt-1.5 border-t border-gray-100">
              <span class="text-xs font-semibold text-gray-700">Total</span>
              <span class="text-sm font-bold text-emerald-600">₱${total.toFixed(2)}</span>
            </div>
          </div>
          <div class="px-4 py-3 flex gap-2">
            ${actionButtonsHTML}
          </div>
        `;
        return card;
      }

      function buildTrackerHTML(order) {
        if (order.status === "cancelled") return "";
        const steps =
          order.orderType === "pickup"
            ? ["pending", "preparing", "ready_for_pickup", "completed"]
            : STEPS;
        const stepLabelMap = {
          pending: "Pending",
          preparing: "Preparing",
          ready_for_pickup: "Ready for Pickup",
          completed: "Completed",
          ready_for_dispatch: "Ready for Dispatch",
          collected: "Collected",
          out_for_delivery: "Out for Delivery",
          delivered: "Delivered",
        };
        const si = steps.indexOf(order.status);
        const win =
          si === 0
            ? [0, 1, Math.min(2, steps.length - 1)]
            : si === steps.length - 1
              ? [
                  Math.max(0, steps.length - 3),
                  steps.length - 2,
                  steps.length - 1,
                ]
              : [si - 1, si, si + 1];
        let html = `<div class="flex items-start w-full mt-3">`;
        win.forEach((i, pos) => {
          const done = i < si;
          const active = i === si;
          const stepCls = done
            ? "step-done"
            : active
              ? "step-active"
              : "step-idle";
          const textCls = active
            ? "text-emerald-700 font-semibold"
            : done
              ? "text-gray-400"
              : "text-gray-300";
          html += `
            <div class="flex flex-col items-center flex-1 min-w-0">
              <div class="w-7 h-7 border-2 ${stepCls} flex items-center justify-center text-[10px] font-bold shrink-0 rounded-[3px]">
                ${done ? `<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" class="w-3.5 h-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>` : `${i + 1}`}
              </div>
              <p class="text-[9px] mt-1 text-center ${textCls} leading-tight px-0.5 w-full" style="word-break:break-word;">${stepLabelMap[steps[i]]}</p>
            </div>`;
          if (pos < win.length - 1) {
            const lineCls = done ? "bg-emerald-400" : "bg-gray-200";
            html += `<div class="flex-1 h-0.5 ${lineCls} mt-3.5 mx-1 shrink-0" style="min-width:6px;"></div>`;
          }
        });
        html += `</div>`;
        return html;
      }

      function showOrderDetails(orderIdRaw) {
        const order = ALL_ORDERS.find((o) => o.orderIdRaw === orderIdRaw);
        if (!order) return;
        const modal = document.getElementById("detailsModal");
        const content = document.getElementById("modalContent");
        const total = calcTotal(order);
        const subtotal = order.items.reduce((s, i) => s + i.price * i.qty, 0);
        const showStallOwner =
          order.stallOwner &&
          (order.orderType === "pickup" ||
            ["pending", "preparing", "cancelled"].includes(order.status));
        const showDeliveryStaff =
          order.deliveryStaff &&
          order.orderType === "delivery" &&
          ["ready_for_dispatch", "collected", "out_for_delivery", "delivered"].includes(
            order.status,
          );
        content.innerHTML = `
          <div>
            <h3 class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-2">Order Progress</h3>
            <div class="border border-gray-100 p-4 rounded-md">
              ${order.status === "cancelled" ? `<div class="text-center py-2"><span class="status-badge rounded-[3px] status-cancelled">Order Cancelled</span>${order.cancelReason ? `<p class="text-[11px] text-gray-500 mt-2">${escapeHtml(order.cancelReason)}</p>` : ""}</div>` : buildTrackerHTML(order)}
            </div>
          </div>
          <div class="space-y-4">
            <div>
              <h3 class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-2">${order.orderType === "pickup" ? "Pickup Information" : "Delivery Information"}</h3>
              <div class="border border-gray-100 p-3 space-y-3 rounded-md">
                ${
                  order.orderType === "pickup"
                    ? `
                <div class="flex items-start gap-3">
                  <span class="w-8 h-8 bg-gray-100 flex items-center justify-center shrink-0 rounded-[3px]">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 text-gray-500"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 21v-7.5a.75.75 0 0 1 .75-.75h3a.75.75 0 0 1 .75.75V21m-4.5 0H2.36m11.14 0H18m0 0h3.64m-1.39 0V9.349M3.75 21V9.349M3.75 9.349a48.96 48.96 0 0 1 16.5 0M3.75 9.349a48.95 48.95 0 0 0 16.5 0M3.75 9.349v9.652m16.5-9.652v9.652m0 0c0 .621-.504 1.125-1.125 1.125H4.875c-.621 0-1.125-.504-1.125-1.125" /></svg>
                  </span>
                  <div>
                    <p class="text-[10px] text-gray-500">Pickup at</p>
                    <p class="text-xs font-medium text-gray-800">${escapeHtml(order.stall)}</p>
                    <p class="text-[10px] text-gray-400 mt-0.5">Head to the stall when your order is ready</p>
                  </div>
                </div>`
                    : `
                <div class="flex items-start gap-3">
                  <span class="w-8 h-8 bg-gray-100 flex items-center justify-center shrink-0 rounded-[3px]">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 text-gray-500"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z" /></svg>
                  </span>
                  <div class="flex-1">
                    <div class="flex items-center justify-between">
                      <p class="text-[10px] text-gray-500">Drop-off Location</p>
                      ${order.status === "pending" ? `<button class="edit-location-btn text-[10px] text-emerald-600 font-bold hover:text-emerald-700" data-id="${order.orderIdRaw}">Edit</button>` : ""}
                    </div>
                    <p class="text-xs font-medium text-gray-800" id="displayLocation">${escapeHtml(order.location)}</p>
                    <div id="editLocationArea" class="hidden mt-2 space-y-2">
                      <input type="text" id="newLocationInput" value="${escapeHtml(order.location)}" class="w-full px-2 py-1.5 text-xs border border-gray-300 focus:outline-none focus:border-emerald-600 rounded-[3px]" />
                      <div class="flex gap-2">
                        <button class="save-location-btn px-3 py-1 bg-emerald-600 text-white text-[10px] font-bold rounded-[3px]" data-id="${order.orderIdRaw}">Save</button>
                        <button class="cancel-edit-btn px-3 py-1 bg-gray-200 text-gray-600 text-[10px] font-bold rounded-[3px]">Cancel</button>
                      </div>
                    </div>
                  </div>
                </div>`
                }
                <div class="flex items-start gap-3">
                  <span class="w-8 h-8 bg-gray-100 flex items-center justify-center shrink-0 rounded-[3px]">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 text-gray-500"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25v10.5A2.25 2.25 0 0 0 4.5 19.5Z" /></svg>
                  </span>
                  <div>
                    <p class="text-[10px] text-gray-500">Payment Method</p>
                    <p class="text-xs font-medium text-gray-800">${escapeHtml(order.payment)}</p>
                  </div>
                </div>
              </div>
            </div>
            ${
              showStallOwner
                ? `
            <div>
              <h3 class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-2">Stall Owner</h3>
              <div class="border border-gray-100 p-3 space-y-3 rounded-md">
                <div class="flex items-center gap-3">
                  ${personAvatarHtml(order.stallOwner.image, order.stallOwner.name, "w-9 h-9")}
                  <div class="flex-1 min-w-0">
                    <p class="text-xs font-semibold text-gray-800 truncate">${escapeHtml(order.stallOwner.name)}</p>
                    <p class="text-[10px] text-gray-400 mt-0.5">${escapeHtml(order.stallOwner.phone)}</p>
                  </div>
                </div>
                <div class="flex gap-2">
                  <a href="tel:${escapeHtml(order.stallOwner.phone)}" class="flex-1 flex items-center justify-center gap-2 py-2 bg-white border border-emerald-600 text-emerald-600 text-[11px] font-semibold hover:bg-emerald-50 transition-colors rounded-[3px]">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-3.5 h-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 0 0 2.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 0 1-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 0 0-1.091-.852H4.5A2.25 2.25 0 0 0 2.25 4.5v2.25Z" /></svg>
                    Call
                  </a>
                  <a href="./chat.php" class="flex-1 flex items-center justify-center gap-2 py-2 bg-emerald-600 text-white text-[11px] font-semibold hover:bg-emerald-700 transition-colors rounded-[3px]">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-3.5 h-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="M7.5 8.25h9m-9 3H12m-9.75 1.51c0 1.6 1.123 2.994 2.707 3.227 1.129.166 2.27.293 3.423.379.35.026.67.21.865.501L12 21l2.755-4.133a1.14 1.14 0 0 1 .865-.501 48.172 48.172 0 0 0 3.423-.379c1.584-.233 2.707-1.626 2.707-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0 0 12 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018Z" /></svg>
                    Message
                  </a>
                </div>
              </div>
            </div>`
                : ""
            }
            ${
              showDeliveryStaff
                ? `
            <div>
              <h3 class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-2">Delivery Staff</h3>
              <div class="border border-gray-100 p-3 space-y-3 rounded-md">
                <div class="flex items-center gap-3">
                  ${personAvatarHtml(order.deliveryStaff.image, order.deliveryStaff.name, "w-9 h-9")}
                  <div class="flex-1 min-w-0">
                    <p class="text-xs font-semibold text-gray-800 truncate">${escapeHtml(order.deliveryStaff.name)}</p>
                    <p class="text-[10px] text-gray-400 mt-0.5">${escapeHtml(order.deliveryStaff.phone)}</p>
                  </div>
                </div>
                <div class="flex gap-2">
                  <a href="tel:${escapeHtml(order.deliveryStaff.phone)}" class="flex-1 flex items-center justify-center gap-2 py-2 bg-white border border-emerald-600 text-emerald-600 text-[11px] font-semibold hover:bg-emerald-50 transition-colors rounded-[3px]">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-3.5 h-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 0 0 2.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 0 1-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 0 0-1.091-.852H4.5A2.25 2.25 0 0 0 2.25 4.5v2.25Z" /></svg>
                    Call
                  </a>
                  <a href="./chat.php" class="flex-1 flex items-center justify-center gap-2 py-2 bg-emerald-600 text-white text-[11px] font-semibold hover:bg-emerald-700 transition-colors rounded-[3px]">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-3.5 h-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="M7.5 8.25h9m-9 3H12m-9.75 1.51c0 1.6 1.123 2.994 2.707 3.227 1.129.166 2.27.293 3.423.379.35.026.67.21.865.501L12 21l2.755-4.133a1.14 1.14 0 0 1 .865-.501 48.172 48.172 0 0 0 3.423-.379c1.584-.233 2.707-1.626 2.707-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0 0 12 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018Z" /></svg>
                    Message
                  </a>
                </div>
              </div>
            </div>`
                : ""
            }
            <div>
              <h3 class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-2">Order Summary</h3>
              <div class="border border-gray-100 p-3 space-y-3 rounded-md">
                ${order.items
                  .map(
                    (item) => `
                  <div class="flex items-center gap-3">
                    ${item.img ? `<img src="${item.img}" alt="${escapeHtml(item.name)}" class="w-12 h-12 object-cover bg-gray-100 shrink-0 rounded-[3px]" />` : `<div class="w-12 h-12 bg-gray-100 shrink-0 rounded-[3px]"></div>`}
                    <div class="flex-1 flex justify-between">
                      <div class="flex flex-col">
                        <span class="text-xs text-gray-600">${escapeHtml(item.name)} x${item.qty}</span>
                        <span class="text-[10px] text-gray-400">₱${item.price.toFixed(2)} each</span>
                      </div>
                      <span class="text-xs font-medium text-gray-700">₱${(item.price * item.qty).toFixed(2)}</span>
                    </div>
                  </div>
                `,
                  )
                  .join("")}
                <div class="pt-2 border-t border-gray-200 mt-2 space-y-1">
                  <div class="flex justify-between">
                    <span class="text-[11px] text-gray-500">Subtotal</span>
                    <span class="text-[11px] text-gray-700">₱${subtotal.toFixed(2)}</span>
                  </div>
                  <div class="flex justify-between">
                    <span class="text-[11px] text-gray-500">${order.orderType === "pickup" ? "Pickup Fee" : "Delivery Fee"}</span>
                    ${order.orderType === "pickup" ? `<span class="text-[11px] text-emerald-600 font-semibold">Free</span>` : `<span class="text-[11px] text-gray-700">₱${order.deliveryFee.toFixed(2)}</span>`}
                  </div>
                  <div class="flex justify-between pt-1">
                    <span class="text-xs font-bold text-gray-800">Total</span>
                    <span class="text-sm font-bold text-emerald-600">₱${total.toFixed(2)}</span>
                  </div>
                </div>
              </div>
            </div>
            ${
              order.note
                ? `<div>
                <h3 class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-2">Order Note</h3>
                <div class="border border-gray-100 p-3 rounded-md">
                  <p class="text-xs text-gray-600 italic">"${escapeHtml(order.note)}"</p>
                </div>
              </div>`
                : ""
            }
            ${
              order.proofImage
                ? `<div>
                <h3 class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-2">Proof of Handover</h3>
                <div class="border border-gray-100 p-3 rounded-md">
                  <img src="${escapeHtml(order.proofImage)}" alt="Proof of handover" class="proof-image-view w-full h-40 object-cover rounded-[6px] cursor-pointer" />
                </div>
              </div>`
                : ""
            }
          </div>
        `;
        modal.classList.remove("hidden");
        document.body.style.overflow = "hidden";
        bindModalEvents();

        const proofImg = content.querySelector(".proof-image-view");
        if (proofImg) {
          proofImg.addEventListener("click", () => openLightbox(proofImg.src));
        }
      }

      function openLightbox(src) {
        document.getElementById("lightboxImage").src = src;
        document.getElementById("imageLightbox").classList.remove("hidden");
      }

      function closeLightbox() {
        document.getElementById("imageLightbox").classList.add("hidden");
        document.getElementById("lightboxImage").src = "";
      }

      function bindModalEvents() {
        const editBtn = document.querySelector(".edit-location-btn");
        if (editBtn) {
          editBtn.addEventListener("click", () => {
            document.getElementById("displayLocation").classList.add("hidden");
            editBtn.classList.add("hidden");
            document
              .getElementById("editLocationArea")
              .classList.remove("hidden");
          });
        }
        const cancelBtn = document.querySelector(".cancel-edit-btn");
        if (cancelBtn) {
          cancelBtn.addEventListener("click", () => {
            document
              .getElementById("displayLocation")
              .classList.remove("hidden");
            document
              .querySelector(".edit-location-btn")
              .classList.remove("hidden");
            document.getElementById("editLocationArea").classList.add("hidden");
          });
        }
        const saveBtn = document.querySelector(".save-location-btn");
        if (saveBtn) {
          saveBtn.addEventListener("click", async () => {
            const orderIdRaw = parseInt(saveBtn.getAttribute("data-id"));
            const newLoc = document.getElementById("newLocationInput").value.trim();
            if (!newLoc) return;
            saveBtn.disabled = true;
            const res = await postAction("update_location", {
              order_id: orderIdRaw,
              location: newLoc,
            });
            saveBtn.disabled = false;
            if (res.success) {
              ALL_ORDERS = res.orders;
              document.getElementById("displayLocation").textContent = newLoc;
              document
                .getElementById("displayLocation")
                .classList.remove("hidden");
              document
                .querySelector(".edit-location-btn")
                .classList.remove("hidden");
              document
                .getElementById("editLocationArea")
                .classList.add("hidden");
              renderOrders();
            } else {
              alert(res.message || "Something went wrong. Please try again.");
            }
          });
        }
      }

      function closeModal() {
        document.getElementById("detailsModal").classList.add("hidden");
        document.body.style.overflow = "";
      }

      function openCancelModal(orderIdRaw) {
        pendingCancelId = orderIdRaw;
        const order = ALL_ORDERS.find((o) => o.orderIdRaw === orderIdRaw);
        document.getElementById("cancelOrderIdLabel").textContent = order ? order.id : "";
        document
          .querySelectorAll("input[name='cancelReason']")
          .forEach((r) => (r.checked = false));
        document.getElementById("otherReasonText").value = "";
        document.getElementById("otherReasonWrapper").classList.add("hidden");
        document.getElementById("cancelModalConfirmBtn").disabled = true;
        document.getElementById("cancelModal").classList.remove("hidden");
        document.body.style.overflow = "hidden";
      }

      function closeCancelModal() {
        document.getElementById("cancelModal").classList.add("hidden");
        if (
          !document.getElementById("detailsModal").classList.contains("hidden")
        )
          return;
        document.body.style.overflow = "";
        pendingCancelId = null;
      }

      function openConfirmReceiptModal(orderIdRaw) {
        pendingConfirmReceiptId = orderIdRaw;
        document.getElementById("confirmReceiptModal").classList.remove("hidden");
        document.body.style.overflow = "hidden";
      }

      function closeConfirmReceiptModal() {
        document.getElementById("confirmReceiptModal").classList.add("hidden");
        if (
          !document.getElementById("detailsModal").classList.contains("hidden")
        )
          return;
        document.body.style.overflow = "";
        pendingConfirmReceiptId = null;
      }

      function openReportIssueModal(orderIdRaw) {
        pendingReportIssueId = orderIdRaw;
        document.getElementById("reportIssueModal").classList.remove("hidden");
        document.body.style.overflow = "hidden";
      }

      function closeReportIssueModal() {
        document.getElementById("reportIssueModal").classList.add("hidden");
        if (
          !document.getElementById("detailsModal").classList.contains("hidden")
        )
          return;
        document.body.style.overflow = "";
        pendingReportIssueId = null;
      }

      function getStatusTabs(type) {
        return type === "pickup" ? PICKUP_STATUS_TABS : DELIVERY_STATUS_TABS;
      }

      function renderStatusTabs() {
        const container = document.getElementById("statusTabsContainer");
        container.classList.remove("hidden");
        const tabs = getStatusTabs(currentTypeFilter);
        container.innerHTML = tabs
          .map(
            (t) => `
          <button
            type="button"
            class="status-tab-btn rounded-[3px] shrink-0 px-3 py-1.5 text-[11px] font-semibold border whitespace-nowrap transition-colors ${currentStatusFilter === t.value ? "status-tab-active" : "bg-white border-gray-200 text-gray-500 hover:border-emerald-400 hover:text-emerald-600 hover:bg-emerald-50"}"
            data-value="${t.value}"
          >${t.label}</button>
        `,
          )
          .join("");
        container.querySelectorAll(".status-tab-btn").forEach((btn) => {
          btn.addEventListener("click", () => {
            currentStatusFilter = btn.getAttribute("data-value");
            renderStatusTabs();
            renderOrders();
          });
        });
      }

      function updateTypeFilterWidth() {
        const selectEl = document.getElementById("typeFilterSelect");
        const measureEl = document.getElementById("typeFilterMeasure");
        if (!selectEl || !measureEl) return;
        const selectedText = selectEl.options[selectEl.selectedIndex].text;
        measureEl.textContent = selectedText;
        const textWidth = measureEl.offsetWidth;
        selectEl.style.width = textWidth + 38 + "px";
      }

      function renderOrders() {
        const container = document.getElementById("ordersContainer");
        const emptyView = document.getElementById("emptyView");
        container.innerHTML = "";
        const filtered = ALL_ORDERS.filter((o) => {
          const matchesSearch =
            o.id.toLowerCase().includes(searchQuery.toLowerCase()) ||
            o.stall.toLowerCase().includes(searchQuery.toLowerCase()) ||
            o.items.some((i) =>
              i.name.toLowerCase().includes(searchQuery.toLowerCase()),
            );
          const matchesType = o.orderType === currentTypeFilter;
          const matchesStatus =
            currentStatusFilter === "all" || o.status === currentStatusFilter;
          return matchesSearch && matchesType && matchesStatus;
        });
        if (filtered.length === 0) {
          emptyView.classList.remove("hidden");
        } else {
          emptyView.classList.add("hidden");
          filtered.forEach((o) => container.appendChild(buildOrderCard(o)));
        }
        bindEvents();
      }

      function bindEvents() {
        document.querySelectorAll(".view-details-btn").forEach((btn) => {
          btn.addEventListener("click", () =>
            showOrderDetails(parseInt(btn.getAttribute("data-id"))),
          );
        });
        document.querySelectorAll(".copy-id-btn").forEach((btn) => {
          btn.addEventListener("click", (e) => {
            e.stopPropagation();
            const iconEl = btn.querySelector(".copy-icon");
            copyToClipboard(btn.getAttribute("data-id"), iconEl);
          });
        });
        document.querySelectorAll(".cancel-btn").forEach((btn) => {
          btn.addEventListener("click", () =>
            openCancelModal(parseInt(btn.getAttribute("data-id"))),
          );
        });
        document.querySelectorAll(".confirm-receipt-btn").forEach((btn) => {
          btn.addEventListener("click", () => {
            if (btn.disabled) return;
            openConfirmReceiptModal(parseInt(btn.getAttribute("data-id")));
          });
        });
        document.querySelectorAll(".report-issue-btn").forEach((btn) => {
          btn.addEventListener("click", () => {
            if (btn.disabled) return;
            openReportIssueModal(parseInt(btn.getAttribute("data-id")));
          });
        });
        document.querySelectorAll(".reorder-btn").forEach((btn) => {
          btn.addEventListener("click", async () => {
            if (btn.disabled) return;
            btn.disabled = true;
            const orderIdRaw = parseInt(btn.getAttribute("data-id"));
            const originalText = btn.textContent;
            const res = await postAction("reorder", { order_id: orderIdRaw });
            if (res.success) {
              btn.textContent = "Added!";
              if (res.skipped > 0) {
                alert(`${res.added} item(s) added to cart. ${res.skipped} item(s) are no longer available.`);
              }
              setTimeout(() => {
                btn.textContent = originalText;
                btn.disabled = false;
              }, 1500);
            } else {
              alert(res.message || "Something went wrong. Please try again.");
              btn.disabled = false;
            }
          });
        });
      }

      function setupSearch() {
        document
          .getElementById("searchOrders")
          .addEventListener("input", (e) => {
            searchQuery = e.target.value;
            document
              .getElementById("clearSearchBtn")
              .classList.toggle("hidden", searchQuery.length === 0);
            renderOrders();
          });

        document
          .getElementById("clearSearchBtn")
          .addEventListener("click", () => {
            const input = document.getElementById("searchOrders");
            input.value = "";
            searchQuery = "";
            document.getElementById("clearSearchBtn").classList.add("hidden");
            input.focus();
            renderOrders();
          });
      }

      function setupTypeFilter() {
        document
          .getElementById("typeFilterSelect")
          .addEventListener("change", (e) => {
            currentTypeFilter = e.target.value;
            currentStatusFilter = "all";
            updateTypeFilterWidth();
            renderStatusTabs();
            renderOrders();
          });
      }

      function setupBackButton() {
        document
          .getElementById("backButton")
          .addEventListener("click", () => window.history.back());
      }

      function setupDetailsModal() {
        document
          .getElementById("closeModalBtn")
          .addEventListener("click", closeModal);
        document
          .getElementById("closeModalOverlay")
          .addEventListener("click", closeModal);
      }

      function setupLightbox() {
        document
          .getElementById("closeLightboxBtn")
          .addEventListener("click", closeLightbox);
        document
          .getElementById("closeLightboxOverlay")
          .addEventListener("click", closeLightbox);
      }

      function setupCancelModal() {
        document
          .getElementById("closeCancelModalBtn")
          .addEventListener("click", closeCancelModal);
        document
          .getElementById("cancelModalOverlay")
          .addEventListener("click", closeCancelModal);
        document
          .getElementById("cancelModalKeepBtn")
          .addEventListener("click", closeCancelModal);

        document
          .querySelectorAll("input[name='cancelReason']")
          .forEach((radio) => {
            radio.addEventListener("change", () => {
              const isOther = radio.value === "other";
              document
                .getElementById("otherReasonWrapper")
                .classList.toggle("hidden", !isOther);
              document.getElementById("cancelModalConfirmBtn").disabled = false;
            });
          });

        document
          .getElementById("otherReasonText")
          .addEventListener("input", (e) => {
            const otherSelected =
              document.querySelector("input[name='cancelReason']:checked")
                ?.value === "other";
            document.getElementById("cancelModalConfirmBtn").disabled =
              otherSelected && e.target.value.trim() === "";
          });

        document
          .getElementById("cancelModalConfirmBtn")
          .addEventListener("click", async () => {
            const selected = document.querySelector(
              "input[name='cancelReason']:checked",
            );
            if (!selected) return;
            let reason = selected.value;
            if (reason === "other") {
              const text = document
                .getElementById("otherReasonText")
                .value.trim();
              if (!text) return;
              reason = text;
            }
            const confirmBtn = document.getElementById("cancelModalConfirmBtn");
            confirmBtn.disabled = true;
            const res = await postAction("cancel_order", {
              order_id: pendingCancelId,
              reason: reason,
            });
            confirmBtn.disabled = false;
            if (res.success) {
              ALL_ORDERS = res.orders;
              closeCancelModal();
              renderOrders();
            } else {
              alert(res.message || "Something went wrong. Please try again.");
            }
          });
      }

      function setupConfirmReceiptModal() {
        document
          .getElementById("closeConfirmReceiptOverlay")
          .addEventListener("click", closeConfirmReceiptModal);
        document
          .getElementById("confirmReceiptKeepBtn")
          .addEventListener("click", closeConfirmReceiptModal);
        document
          .getElementById("confirmReceiptConfirmBtn")
          .addEventListener("click", async () => {
            const confirmBtn = document.getElementById("confirmReceiptConfirmBtn");
            confirmBtn.disabled = true;
            const res = await postAction("confirm_receipt", {
              order_id: pendingConfirmReceiptId,
            });
            confirmBtn.disabled = false;
            if (res.success) {
              ALL_ORDERS = res.orders;
              closeConfirmReceiptModal();
              renderOrders();
            } else {
              alert(res.message || "Something went wrong. Please try again.");
            }
          });
      }

      function setupReportIssueModal() {
        document
          .getElementById("closeReportIssueOverlay")
          .addEventListener("click", closeReportIssueModal);
        document
          .getElementById("reportIssueKeepBtn")
          .addEventListener("click", closeReportIssueModal);
        document
          .getElementById("reportIssueConfirmBtn")
          .addEventListener("click", async () => {
            const confirmBtn = document.getElementById("reportIssueConfirmBtn");
            confirmBtn.disabled = true;
            const res = await postAction("report_issue", {
              order_id: pendingReportIssueId,
            });
            confirmBtn.disabled = false;
            if (res.success) {
              ALL_ORDERS = res.orders;
              closeReportIssueModal();
              renderOrders();
            } else {
              alert(res.message || "Something went wrong. Please try again.");
            }
          });
      }

      function init() {
        setupSearch();
        setupTypeFilter();
        setupBackButton();
        setupDetailsModal();
        setupLightbox();
        setupCancelModal();
        setupConfirmReceiptModal();
        setupReportIssueModal();
        updateTypeFilterWidth();
        renderStatusTabs();
        renderOrders();
      }

      window.addEventListener("load", init);
    </script>
  </body>
</html>