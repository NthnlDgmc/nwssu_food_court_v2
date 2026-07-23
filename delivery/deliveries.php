<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['staff_id'])) {
  header('Location: ../auth/login.php');
  exit;
}

$staffId = $_SESSION['staff_id'];

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
}

function refValues($arr)
{
  $refs = [];
  foreach ($arr as $key => $value) {
    $refs[$key] = &$arr[$key];
  }
  return $refs;
}

function formatPaymentLabel($method)
{
  if ($method === 'gcash') return 'GCash';
  if ($method === 'paymaya') return 'Maya';
  return 'Cash on Delivery';
}

function formatRelativeTime($datetime)
{
  $ts = strtotime($datetime);
  $diff = time() - $ts;
  if ($diff < 60) return 'Just now';
  if ($diff < 3600) return floor($diff / 60) . ' mins ago';
  if ($diff < 86400) return floor($diff / 3600) . ' hours ago';
  if ($diff < 172800) return 'Yesterday';
  return date('M j, Y', $ts);
}

function saveProofImage($base64Data)
{
  if (!preg_match('/^data:image\/(\w+);base64,(.+)$/', $base64Data, $matches)) {
    return null;
  }

  $ext = strtolower($matches[1]);
  if ($ext === 'jpeg') $ext = 'jpg';
  $allowed = ['jpg', 'png', 'gif', 'webp'];
  if (!in_array($ext, $allowed, true)) {
    $ext = 'jpg';
  }

  $data = base64_decode($matches[2]);
  if ($data === false) {
    return null;
  }

  $uploadDirFs = __DIR__ . '/../uploads/order_proofs/';
  if (!is_dir($uploadDirFs)) {
    mkdir($uploadDirFs, 0755, true);
  }

  $filename = 'proof_' . uniqid() . '_' . time() . '.' . $ext;
  file_put_contents($uploadDirFs . $filename, $data);

  return 'uploads/order_proofs/' . $filename;
}

function fetchDeliveriesData($conn, $staffId)
{
  $stmt = $conn->prepare("
        SELECT o.order_id, o.stall_id, o.owner_id, o.staff_id, o.order_type, o.status,
               o.payment_method, o.total_amount, o.total_delivery_fee, o.grand_total,
               o.drop_off_location, o.note, o.cancel_reason, o.customer_confirmed,
               o.delivery_proof_image, o.created_at,
               s.stall_name,
               c.first_name AS cust_first_name, c.last_name AS cust_last_name,
               c.contact_number AS cust_contact, c.customer_type, c.profile_image AS cust_profile_image,
               so.first_name AS owner_first_name, so.last_name AS owner_last_name,
               so.contact_number AS owner_contact, so.profile_image AS owner_profile_image
        FROM orders o
        JOIN customers c ON o.customer_id = c.customer_id
        JOIN stalls s ON o.stall_id = s.stall_id
        LEFT JOIN stall_owners so ON o.owner_id = so.owner_id
        WHERE o.order_type = 'delivery'
          AND o.status NOT IN ('pending', 'preparing')
          AND (o.staff_id = ? OR (o.staff_id IS NULL AND o.status = 'ready_for_dispatch'))
        ORDER BY o.created_at DESC
    ");
  $stmt->bind_param("s", $staffId);
  $stmt->execute();
  $result = $stmt->get_result();

  $orders = [];
  $orderIds = [];

  while ($row = $result->fetch_assoc()) {
    $orderIdRaw = (int) $row['order_id'];
    $orderIds[] = $orderIdRaw;

    $stallOwner = null;
    if ($row['owner_first_name']) {
      $stallOwner = [
        'name' => trim($row['owner_first_name'] . ' ' . $row['owner_last_name']),
        'phone' => $row['owner_contact'],
        'image' => $row['owner_profile_image'] ? '../' . $row['owner_profile_image'] : null,
      ];
    }

    $orders[$orderIdRaw] = [
      'orderIdRaw' => $orderIdRaw,
      'id' => 'FC-' . str_pad($orderIdRaw, 6, '0', STR_PAD_LEFT),
      'date' => date('M j, Y', strtotime($row['created_at'])) . ' · ' . date('g:i A', strtotime($row['created_at'])),
      'time' => formatRelativeTime($row['created_at']),
      'customerName' => trim($row['cust_first_name'] . ' ' . $row['cust_last_name']),
      'customerType' => $row['customer_type'],
      'customerContact' => $row['cust_contact'],
      'customerImage' => $row['cust_profile_image'] ? '../' . $row['cust_profile_image'] : null,
      'stall' => $row['stall_name'],
      'stallOwner' => $stallOwner,
      'location' => $row['drop_off_location'],
      'payment' => formatPaymentLabel($row['payment_method']),
      'status' => $row['status'],
      'assignedToMe' => $row['staff_id'] !== null && (string) $row['staff_id'] === (string) $staffId,
      'customerConfirmed' => $row['customer_confirmed'],
      'proofImage' => $row['delivery_proof_image'] ? '../' . $row['delivery_proof_image'] : null,
      'note' => $row['note'],
      'cancelReason' => $row['cancel_reason'],
      'deliveryFee' => (float) $row['total_delivery_fee'],
      'items' => [],
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

  if ($action === 'get_deliveries') {
    echo json_encode(['success' => true, 'deliveries' => fetchDeliveriesData($conn, $staffId)]);
    $conn->close();
    exit;
  }

  if ($action === 'accept_delivery') {
    $orderId = (int) ($_POST['order_id'] ?? 0);

    if ($orderId <= 0) {
      echo json_encode(['success' => false, 'message' => 'Invalid order.']);
      $conn->close();
      exit;
    }

    $stmt = $conn->prepare("SELECT status, staff_id, order_type FROM orders WHERE order_id = ? LIMIT 1");
    $stmt->bind_param("i", $orderId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
      echo json_encode(['success' => false, 'message' => 'Order not found.']);
      $conn->close();
      exit;
    }

    if ($row['order_type'] !== 'delivery' || $row['status'] !== 'ready_for_dispatch' || $row['staff_id'] !== null) {
      echo json_encode(['success' => false, 'message' => 'This order is no longer available.']);
      $conn->close();
      exit;
    }

    $stmt = $conn->prepare("UPDATE orders SET staff_id = ? WHERE order_id = ? AND staff_id IS NULL");
    $stmt->bind_param("si", $staffId, $orderId);
    $ok = $stmt->execute();
    $stmt->close();

    echo json_encode($ok
      ? ['success' => true, 'deliveries' => fetchDeliveriesData($conn, $staffId)]
      : ['success' => false, 'message' => 'Failed to accept delivery.']);
    $conn->close();
    exit;
  }

  if ($action === 'mark_collected') {
    $orderId = (int) ($_POST['order_id'] ?? 0);

    if ($orderId <= 0) {
      echo json_encode(['success' => false, 'message' => 'Invalid order.']);
      $conn->close();
      exit;
    }

    $stmt = $conn->prepare("SELECT status, staff_id, customer_id FROM orders WHERE order_id = ? AND order_type = 'delivery' LIMIT 1");
    $stmt->bind_param("i", $orderId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
      echo json_encode(['success' => false, 'message' => 'Order not found.']);
      $conn->close();
      exit;
    }

    if ((string) $row['staff_id'] !== (string) $staffId || $row['status'] !== 'ready_for_dispatch') {
      echo json_encode(['success' => false, 'message' => 'This order cannot be marked as picked up.']);
      $conn->close();
      exit;
    }

    $stmt = $conn->prepare("UPDATE orders SET status = 'collected' WHERE order_id = ? AND staff_id = ?");
    $stmt->bind_param("is", $orderId, $staffId);
    $ok = $stmt->execute();
    $stmt->close();

    if ($ok) {
      $friendlyOrderId = 'FC-' . str_pad($orderId, 6, '0', STR_PAD_LEFT);
      createNotification(
        $conn,
        'customer',
        $row['customer_id'],
        'Order Picked Up',
        'Your order ' . $friendlyOrderId . ' has been picked up by our delivery staff!',
        '../customer/order.php'
      );
    }

    echo json_encode($ok
      ? ['success' => true, 'deliveries' => fetchDeliveriesData($conn, $staffId)]
      : ['success' => false, 'message' => 'Failed to update order.']);
    $conn->close();
    exit;
  }

  if ($action === 'mark_out_for_delivery') {
    $orderId = (int) ($_POST['order_id'] ?? 0);

    if ($orderId <= 0) {
      echo json_encode(['success' => false, 'message' => 'Invalid order.']);
      $conn->close();
      exit;
    }

    $stmt = $conn->prepare("SELECT status, staff_id, customer_id FROM orders WHERE order_id = ? AND order_type = 'delivery' LIMIT 1");
    $stmt->bind_param("i", $orderId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
      echo json_encode(['success' => false, 'message' => 'Order not found.']);
      $conn->close();
      exit;
    }

    if ((string) $row['staff_id'] !== (string) $staffId || $row['status'] !== 'collected') {
      echo json_encode(['success' => false, 'message' => 'This order cannot be marked as out for delivery.']);
      $conn->close();
      exit;
    }

    $stmt = $conn->prepare("UPDATE orders SET status = 'out_for_delivery' WHERE order_id = ? AND staff_id = ?");
    $stmt->bind_param("is", $orderId, $staffId);
    $ok = $stmt->execute();
    $stmt->close();

    if ($ok) {
      $friendlyOrderId = 'FC-' . str_pad($orderId, 6, '0', STR_PAD_LEFT);
      createNotification(
        $conn,
        'customer',
        $row['customer_id'],
        'Order Out for Delivery',
        'Your order ' . $friendlyOrderId . ' is out for delivery!',
        '../customer/order.php'
      );
    }

    echo json_encode($ok
      ? ['success' => true, 'deliveries' => fetchDeliveriesData($conn, $staffId)]
      : ['success' => false, 'message' => 'Failed to update order.']);
    $conn->close();
    exit;
  }

  if ($action === 'mark_delivered') {
    $orderId = (int) ($_POST['order_id'] ?? 0);
    $photoData = $_POST['photo_data'] ?? '';

    if ($orderId <= 0) {
      echo json_encode(['success' => false, 'message' => 'Invalid order.']);
      $conn->close();
      exit;
    }

    if (strpos($photoData, 'data:image') !== 0) {
      echo json_encode(['success' => false, 'message' => 'Please take a photo of the handover before marking as delivered.']);
      $conn->close();
      exit;
    }

    $stmt = $conn->prepare("SELECT status, staff_id, customer_id, owner_id FROM orders WHERE order_id = ? AND order_type = 'delivery' LIMIT 1");
    $stmt->bind_param("i", $orderId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
      echo json_encode(['success' => false, 'message' => 'Order not found.']);
      $conn->close();
      exit;
    }

    if ((string) $row['staff_id'] !== (string) $staffId || $row['status'] !== 'out_for_delivery') {
      echo json_encode(['success' => false, 'message' => 'This order cannot be marked as delivered yet.']);
      $conn->close();
      exit;
    }

    $proofImage = saveProofImage($photoData);
    if (!$proofImage) {
      echo json_encode(['success' => false, 'message' => 'Failed to process photo. Please try again.']);
      $conn->close();
      exit;
    }

    $stmt = $conn->prepare("UPDATE orders SET status = 'delivered', delivery_proof_image = ?, proof_captured_at = NOW(), customer_confirmed = 'pending' WHERE order_id = ? AND staff_id = ?");
    $stmt->bind_param("sis", $proofImage, $orderId, $staffId);
    $ok = $stmt->execute();
    $stmt->close();

    if ($ok) {
      $friendlyOrderId = 'FC-' . str_pad($orderId, 6, '0', STR_PAD_LEFT);
      createNotification(
        $conn,
        'customer',
        $row['customer_id'],
        'Order Delivered',
        'Your order ' . $friendlyOrderId . ' has been delivered! Please confirm receipt.',
        '../customer/order.php'
      );

      if ($row['owner_id'] !== null) {
        createNotification(
          $conn,
          'stall_owner',
          $row['owner_id'],
          'Order Delivered',
          'Order ' . $friendlyOrderId . ' has been delivered to the customer.',
          '../stall/orders.php'
        );
      }
    }

    echo json_encode($ok
      ? ['success' => true, 'deliveries' => fetchDeliveriesData($conn, $staffId)]
      : ['success' => false, 'message' => 'Failed to update order.']);
    $conn->close();
    exit;
  }

  if ($action === 'cancel_delivery') {
    $orderId = (int) ($_POST['order_id'] ?? 0);
    $reason = trim($_POST['reason'] ?? '');

    if ($orderId <= 0 || $reason === '') {
      echo json_encode(['success' => false, 'message' => 'Please provide a reason for cancelling this delivery.']);
      $conn->close();
      exit;
    }

    $stmt = $conn->prepare("SELECT status, staff_id, customer_id, owner_id FROM orders WHERE order_id = ? AND order_type = 'delivery' LIMIT 1");
    $stmt->bind_param("i", $orderId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
      echo json_encode(['success' => false, 'message' => 'Order not found.']);
      $conn->close();
      exit;
    }

    if ((string) $row['staff_id'] !== (string) $staffId || $row['status'] !== 'out_for_delivery') {
      echo json_encode(['success' => false, 'message' => 'This delivery cannot be cancelled.']);
      $conn->close();
      exit;
    }

    $stmt = $conn->prepare("UPDATE orders SET status = 'cancelled', cancel_reason = ?, cancelled_at = NOW() WHERE order_id = ? AND staff_id = ?");
    $stmt->bind_param("sis", $reason, $orderId, $staffId);
    $ok = $stmt->execute();
    $stmt->close();

    if ($ok) {
      $friendlyOrderId = 'FC-' . str_pad($orderId, 6, '0', STR_PAD_LEFT);
      createNotification(
        $conn,
        'customer',
        $row['customer_id'],
        'Delivery Cancelled',
        'Your order ' . $friendlyOrderId . ' was cancelled by our delivery staff. Reason: ' . $reason,
        '../customer/order.php'
      );

      if ($row['owner_id'] !== null) {
        createNotification(
          $conn,
          'stall_owner',
          $row['owner_id'],
          'Delivery Cancelled',
          'Order ' . $friendlyOrderId . ' was cancelled by the delivery staff. Reason: ' . $reason,
          '../stall/orders.php'
        );
      }
    }

    echo json_encode($ok
      ? ['success' => true, 'deliveries' => fetchDeliveriesData($conn, $staffId)]
      : ['success' => false, 'message' => 'Failed to cancel delivery.']);
    $conn->close();
    exit;
  }

  echo json_encode(['success' => false, 'message' => 'Unknown action.']);
  $conn->close();
  exit;
}

$initialDeliveries = fetchDeliveriesData($conn, $staffId);
$conn->close();

$stallOptions = [];
foreach ($initialDeliveries as $d) {
  if ($d['stall'] && !in_array($d['stall'], $stallOptions, true)) {
    $stallOptions[] = $d['stall'];
  }
}
sort($stallOptions);
?>
<!doctype html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta
    name="viewport"
    content="width=device-width, initial-scale=1.0, user-scalable=yes" />
  <title>Delivery Staff - Deliveries</title>
  <link rel="icon" href="../assets/images/nwssu-logo.png" type="image/png" />
  <link rel="manifest" href="../manifest.json" />
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
          class="p-1.5 bg-white border border-gray-200 hover:border-emerald-500 hover:bg-slate-50 transition-all justify-self-start flex items-center justify-center shrink-0"
          style="width: 34px; height: 34px; border-radius: 6px"
          title="Go back">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 text-gray-600">
            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" />
          </svg>
        </button>
        <h1 class="text-base font-semibold text-emerald-600 text-center">
          Deliveries
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
                id="searchDeliveries"
                placeholder="Search deliveries..."
                class="w-full pl-9 pr-9 py-2 bg-white border border-gray-200 text-xs text-gray-900 placeholder-gray-400 focus:outline-none focus:border-emerald-600 rounded-[3px]" />
              <div class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4">
                  <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
                </svg>
              </div>
              <button
                type="button"
                id="clearSearchBtn"
                class="hidden absolute right-2.5 top-1/2 -translate-y-1/2 p-0.5 text-gray-400 hover:text-gray-600 transition-colors rounded-[3px]"
                title="Clear search">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-4 h-4">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                </svg>
              </button>
            </div>
            <div class="relative inline-block shrink-0">
              <select
                id="stallFilterSelect"
                class="pl-2.5 pr-6 py-2 bg-white border border-gray-200 text-xs font-normal text-gray-700 focus:outline-none focus:border-emerald-600 appearance-none cursor-pointer rounded-[3px]">
                <option value="all">All Stalls</option>
                <?php foreach ($stallOptions as $stallName): ?>
                  <option value="<?php echo htmlspecialchars($stallName); ?>"><?php echo htmlspecialchars($stallName); ?></option>
                <?php endforeach; ?>
              </select>
              <span id="stallFilterMeasure" class="text-xs font-normal" style="position: absolute; visibility: hidden; white-space: pre; left: -9999px; top: -9999px;"></span>
              <div class="absolute right-2 top-1/2 -translate-y-1/2 text-gray-400 pointer-events-none">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4">
                  <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                </svg>
              </div>
            </div>
          </div>
          <div id="statusTabsContainer" class="flex items-center gap-2 overflow-x-auto no-scrollbar"></div>
        </div>

        <div id="deliveriesContainer" class="space-y-3"></div>

        <div id="emptyView" class="hidden flex flex-col items-center justify-center py-16 text-center">
          <div class="w-40 h-40 mb-4">
            <img src="../assets/illustrations/empty-orders.svg" alt="No deliveries found" class="w-full h-full" />
          </div>
          <h3 class="text-base font-semibold text-gray-800">No deliveries found</h3>
          <p class="text-gray-500 text-sm mt-1 mb-5">Try adjusting your filter or search.</p>
        </div>
      </div>
    </div>

    <div class="bg-white border-t border-gray-200 flex-shrink-0 fixed bottom-0 left-0 right-0 z-20">
      <div class="max-w-5xl mx-auto px-4 flex justify-around py-2">
        <a
          href="./dashboard.php"
          class="flex flex-col items-center justify-center py-2 px-3 transition-all duration-200 group text-gray-500 hover:text-gray-900 hover:bg-gray-50"
          style="border-radius: 3px">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 transition-transform group-hover:scale-110">
            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 0 1 6 3.75h2.25A2.25 2.25 0 0 1 10.5 6v2.25a2.25 2.25 0 0 1-2.25 2.25H6a2.25 2.25 0 0 1-2.25-2.25V6ZM3.75 15.75A2.25 2.25 0 0 1 6 13.5h2.25a2.25 2.25 0 0 1 2.25 2.25V18a2.25 2.25 0 0 1-2.25 2.25H6A2.25 2.25 0 0 1 3.75 18v-2.25ZM13.5 6a2.25 2.25 0 0 1 2.25-2.25H18A2.25 2.25 0 0 1 20.25 6v2.25A2.25 2.25 0 0 1 18 10.5h-2.25a2.25 2.25 0 0 1-2.25-2.25V6ZM13.5 15.75a2.25 2.25 0 0 1 2.25-2.25H18a2.25 2.25 0 0 1 2.25 2.25V18A2.25 2.25 0 0 1 18 20.25h-2.25A2.25 2.25 0 0 1 13.5 18v-2.25Z" />
          </svg>
          <span class="text-xs font-medium mt-1">Dashboard</span>
        </a>
        <a
          href="./deliveries.php"
          class="flex flex-col items-center justify-center py-2 px-3 transition-all duration-200 group text-emerald-600 bg-emerald-50"
          style="border-radius: 3px">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5">
            <path stroke-linecap="round" stroke-linejoin="round" d="m21 7.5-9-5.25L3 7.5m18 0-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9" />
          </svg>
          <span class="text-xs font-medium mt-1">Deliveries</span>
        </a>
        <a
          href="./history.php"
          class="flex flex-col items-center justify-center py-2 px-3 transition-all duration-200 group text-gray-500 hover:text-gray-900 hover:bg-gray-50"
          style="border-radius: 3px">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 transition-transform group-hover:scale-110">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
          </svg>
          <span class="text-xs font-medium mt-1">History</span>
        </a>
        <a
          href="./account.php"
          class="flex flex-col items-center justify-center py-2 px-3 transition-all duration-200 group text-gray-500 hover:text-gray-900 hover:bg-gray-50"
          style="border-radius: 3px">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 transition-transform group-hover:scale-110">
            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" />
          </svg>
          <span class="text-xs font-medium mt-1">Account</span>
        </a>
      </div>
    </div>
  </div>

  <div id="detailsModal" class="fixed inset-0 z-50 hidden flex items-center justify-center px-4">
    <div class="modal-overlay absolute inset-0" id="closeModalOverlay"></div>
    <div class="bg-white w-full max-w-md max-h-[90vh] overflow-y-auto relative z-10 shadow-2xl rounded-md">
      <div class="p-4 border-b border-gray-100 flex items-center justify-between sticky top-0 bg-white z-10">
        <h2 class="font-bold text-gray-800 text-sm">Delivery Details</h2>
        <button id="closeModalBtn" class="p-1 hover:bg-gray-100 rounded-[3px]">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 text-gray-500">
            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
          </svg>
        </button>
      </div>
      <div id="modalContent" class="p-4 space-y-4"></div>
    </div>
  </div>

  <div id="acceptModal" class="fixed inset-0 z-[60] hidden flex items-center justify-center px-4">
    <div class="modal-overlay absolute inset-0" id="closeAcceptOverlay"></div>
    <div class="bg-white w-full max-w-sm relative z-10 shadow-2xl p-5 space-y-4 text-center rounded-md">
      <div class="w-12 h-12 bg-emerald-50 flex items-center justify-center mx-auto rounded-full">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6 text-emerald-600">
          <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
        </svg>
      </div>
      <div>
        <p class="text-sm font-bold text-gray-800">Accept Delivery</p>
        <p class="text-xs text-gray-500 mt-1">Order <span id="acceptOrderIdLabel" class="font-semibold text-gray-700"></span> will be assigned to you. Continue?</p>
      </div>
      <div class="flex gap-2 pt-1">
        <button id="acceptKeepBtn" class="flex-1 py-2.5 border border-gray-200 text-gray-700 text-xs font-semibold hover:bg-gray-50 transition-colors rounded-[3px]">
          Cancel
        </button>
        <button id="acceptConfirmBtn" class="flex-1 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold transition-colors rounded-[3px]">
          Yes, Accept
        </button>
      </div>
    </div>
  </div>

  <div id="collectedModal" class="fixed inset-0 z-[60] hidden flex items-center justify-center px-4">
    <div class="modal-overlay absolute inset-0" id="closeCollectedOverlay"></div>
    <div class="bg-white w-full max-w-sm relative z-10 shadow-2xl p-5 space-y-4 text-center rounded-md">
      <div class="w-12 h-12 bg-emerald-50 flex items-center justify-center mx-auto rounded-full">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6 text-emerald-600">
          <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
        </svg>
      </div>
      <div>
        <p class="text-sm font-bold text-gray-800">Mark as Collected</p>
        <p class="text-xs text-gray-500 mt-1">Order <span id="collectedOrderIdLabel" class="font-semibold text-gray-700"></span> will be marked as collected from the stall. Continue?</p>
      </div>
      <div class="flex gap-2 pt-1">
        <button id="collectedKeepBtn" class="flex-1 py-2.5 border border-gray-200 text-gray-700 text-xs font-semibold hover:bg-gray-50 transition-colors rounded-[3px]">
          Cancel
        </button>
        <button id="collectedConfirmBtn" class="flex-1 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold transition-colors rounded-[3px]">
          Yes, Mark Collected
        </button>
      </div>
    </div>
  </div>

  <div id="startDeliveryModal" class="fixed inset-0 z-[60] hidden flex items-center justify-center px-4">
    <div class="modal-overlay absolute inset-0" id="closeStartDeliveryOverlay"></div>
    <div class="bg-white w-full max-w-sm relative z-10 shadow-2xl p-5 space-y-4 text-center rounded-md">
      <div class="w-12 h-12 bg-emerald-50 flex items-center justify-center mx-auto rounded-full">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6 text-emerald-600">
          <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
        </svg>
      </div>
      <div>
        <p class="text-sm font-bold text-gray-800">Start Delivery</p>
        <p class="text-xs text-gray-500 mt-1">Order <span id="startDeliveryOrderIdLabel" class="font-semibold text-gray-700"></span> will be marked out for delivery. Continue?</p>
      </div>
      <div class="flex gap-2 pt-1">
        <button id="startDeliveryKeepBtn" class="flex-1 py-2.5 border border-gray-200 text-gray-700 text-xs font-semibold hover:bg-gray-50 transition-colors rounded-[3px]">
          Cancel
        </button>
        <button id="startDeliveryConfirmBtn" class="flex-1 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold transition-colors rounded-[3px]">
          Yes, Start Delivery
        </button>
      </div>
    </div>
  </div>

  <div id="deliverProofModal" class="fixed inset-0 z-[60] hidden flex items-center justify-center px-4">
    <div class="modal-overlay absolute inset-0" id="closeDeliverProofOverlay"></div>
    <div class="bg-white w-full max-w-sm relative z-10 shadow-2xl rounded-md">
      <div class="p-4 border-b border-gray-100 flex items-center justify-between">
        <div class="flex items-center gap-2.5">
          <div class="w-8 h-8 bg-emerald-50 flex items-center justify-center rounded-[3px]">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 text-emerald-600">
              <path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 0 0 1.5-1.5V6a1.5 1.5 0 0 0-1.5-1.5H3.75A1.5 1.5 0 0 0 2.25 6v12a1.5 1.5 0 0 0 1.5 1.5Zm10.5-11.25h.008v.008h-.008V8.25Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z" />
            </svg>
          </div>
          <h2 class="font-bold text-gray-800 text-sm">Mark as Delivered</h2>
        </div>
        <button id="closeDeliverProofModalBtn" class="p-1 hover:bg-gray-100 rounded-[3px]">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 text-gray-500">
            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
          </svg>
        </button>
      </div>
      <div class="p-4 space-y-3">
        <p class="text-xs text-gray-500">Order <span id="deliverOrderIdLabel" class="font-semibold text-gray-700"></span></p>
        <div>
          <label class="block text-[11px] font-semibold text-gray-500 uppercase tracking-wide mb-1.5">Photo of Handover</label>
          <div
            id="proofImageDropzone"
            class="relative w-full h-36 bg-gray-50 border border-dashed border-gray-300 hover:border-emerald-500 transition-all cursor-pointer overflow-hidden flex items-center justify-center rounded-[6px]">
            <img id="proofImagePreview" src="" alt="" class="hidden w-full h-full object-cover" />
            <div id="proofImagePlaceholder" class="flex flex-col items-center gap-1.5 text-gray-400 px-4 text-center">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-7 h-7">
                <path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 0 0 1.5-1.5V6a1.5 1.5 0 0 0-1.5-1.5H3.75A1.5 1.5 0 0 0 2.25 6v12a1.5 1.5 0 0 0 1.5 1.5Zm10.5-11.25h.008v.008h-.008V8.25Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z" />
              </svg>
              <p class="text-[11px] font-medium">Tap to take or upload a photo</p>
              <p class="text-[10px] text-gray-300">Required before marking as delivered</p>
            </div>
          </div>
          <input type="file" id="proofImageInput" accept="image/*" capture="environment" class="hidden" />
        </div>
        <div id="deliverProofError" class="hidden text-[10px] text-red-500 font-medium"></div>
      </div>
      <div class="px-4 pb-4 flex gap-2">
        <button id="deliverProofCancelBtn" class="flex-1 py-2.5 border border-gray-200 text-gray-600 text-xs font-semibold hover:bg-gray-50 transition-colors rounded-[3px]">
          Cancel
        </button>
        <button id="deliverProofConfirmBtn" class="flex-1 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold transition-colors rounded-[3px] disabled:opacity-40 disabled:cursor-not-allowed" disabled>
          Mark Delivered
        </button>
      </div>
    </div>
  </div>

  <div id="cancelDeliveryModal" class="fixed inset-0 z-[60] hidden flex items-center justify-center px-4">
    <div class="modal-overlay absolute inset-0" id="closeCancelDeliveryOverlay"></div>
    <div class="bg-white w-full max-w-sm relative z-10 shadow-2xl rounded-md">
      <div class="p-4 border-b border-gray-100 flex items-center justify-between">
        <div class="flex items-center gap-2.5">
          <div class="w-8 h-8 bg-red-50 flex items-center justify-center rounded-[3px]">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 text-red-500">
              <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
            </svg>
          </div>
          <h2 class="font-bold text-gray-800 text-sm">Cancel Delivery</h2>
        </div>
        <button id="closeCancelDeliveryModalBtn" class="p-1 hover:bg-gray-100 rounded-[3px]">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 text-gray-500">
            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
          </svg>
        </button>
      </div>
      <div class="p-4 space-y-3">
        <p class="text-xs text-gray-500">Order <span id="cancelDeliveryOrderIdLabel" class="font-semibold text-gray-700"></span> — please tell us why you're cancelling this delivery.</p>
        <textarea
          id="cancelDeliveryReasonInput"
          rows="3"
          placeholder="e.g. Customer not answering, wrong address..."
          class="w-full px-3 py-2.5 bg-white border border-gray-200 text-xs text-gray-900 placeholder-gray-400 focus:outline-none focus:border-emerald-600 rounded-[3px] resize-none"></textarea>
        <p id="cancelDeliveryError" class="hidden text-[10px] text-red-500 font-medium"></p>
      </div>
      <div class="px-4 pb-4 flex gap-2">
        <button id="cancelDeliveryKeepBtn" class="flex-1 py-2.5 border border-gray-200 text-gray-700 text-xs font-semibold hover:bg-gray-50 transition-colors rounded-[3px]">
          Keep Delivery
        </button>
        <button id="cancelDeliveryConfirmBtn" class="flex-1 py-2.5 bg-red-600 hover:bg-red-700 text-white text-xs font-semibold transition-colors rounded-[3px]">
          Cancel Delivery
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
      ready_for_dispatch: { label: "Ready for Dispatch", cls: "bg-indigo-50 text-indigo-700 border-indigo-200" },
      collected: { label: "Collected", cls: "bg-blue-50 text-blue-700 border-blue-200" },
      out_for_delivery: { label: "Out for Delivery", cls: "bg-blue-50 text-blue-700 border-blue-200" },
      delivered: { label: "Delivered", cls: "bg-emerald-50 text-emerald-700 border-emerald-200" },
      cancelled: { label: "Cancelled", cls: "bg-gray-100 text-gray-500 border-gray-200" },
    };

    const STATUS_TABS = [
      { value: "all", label: "All" },
      { value: "ready_for_dispatch", label: "Ready for Dispatch" },
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

    const MAX_PROOF_DIMENSION = 700;
    const PROOF_IMAGE_QUALITY = 0.78;
    const MAX_PROOF_SOURCE_MB = 15;

    let ALL_DELIVERIES = <?php echo json_encode($initialDeliveries); ?>;

    let searchQuery = "";
    let currentStatusFilter = "all";
    let currentStallFilter = "all";
    let pendingAcceptId = null;
    let pendingCollectedId = null;
    let pendingStartDeliveryId = null;
    let pendingDeliverId = null;
    let pendingCancelDeliveryId = null;
    let capturedProofImage = null;

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
      return String(str)
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

    function updateStallFilterWidth() {
      const selectEl = document.getElementById("stallFilterSelect");
      const measureEl = document.getElementById("stallFilterMeasure");
      if (!selectEl || !measureEl) return;
      const selectedText = selectEl.options[selectEl.selectedIndex].text;
      measureEl.textContent = selectedText;
      const textWidth = measureEl.offsetWidth;
      selectEl.style.width = (textWidth + 38) + "px";
    }

    function calcTotal(delivery) {
      return delivery.items.reduce((s, i) => s + i.price * i.qty, 0) + delivery.deliveryFee;
    }

    function statusBadgeHtml(status) {
      const meta = STATUS_META[status] || STATUS_META.ready_for_dispatch;
      return `<span class="text-[10px] font-semibold px-2 py-0.5 border ${meta.cls} shrink-0" style="border-radius:3px">${meta.label}</span>`;
    }

    function customerTypeBadgeHtml(type) {
      const t = CUSTOMER_TYPE_MAP[type];
      if (!t) return "";
      return `<span class="text-[9px] font-semibold px-1.5 py-0.5 border ${t.cls} shrink-0" style="border-radius:3px">${t.label}</span>`;
    }

    function copyToClipboard(text, iconEl) {
      if (iconEl.dataset.copyTimeout) {
        clearTimeout(parseInt(iconEl.dataset.copyTimeout));
        iconEl.dataset.copyTimeout = "";
        resetIcon(iconEl);
      }
      if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(text).then(() => showCopiedIcon(iconEl));
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
      if (!iconEl.dataset.originalHTML) iconEl.dataset.originalHTML = iconEl.innerHTML;
      iconEl.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="3" stroke="currentColor" class="w-3 h-3 -translate-y-px"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>`;
      const timeoutId = setTimeout(() => resetIcon(iconEl), 2000);
      iconEl.dataset.copyTimeout = timeoutId.toString();
    }

    function resizeImageFile(file) {
      return new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.onload = (e) => {
          const img = new Image();
          img.onload = () => {
            let width = img.naturalWidth;
            let height = img.naturalHeight;
            const maxSide = Math.max(width, height);
            if (maxSide > MAX_PROOF_DIMENSION) {
              const scale = MAX_PROOF_DIMENSION / maxSide;
              width = Math.round(width * scale);
              height = Math.round(height * scale);
            }
            const canvas = document.createElement("canvas");
            canvas.width = width;
            canvas.height = height;
            const ctx = canvas.getContext("2d");
            ctx.fillStyle = "#ffffff";
            ctx.fillRect(0, 0, width, height);
            ctx.drawImage(img, 0, 0, width, height);
            resolve(canvas.toDataURL("image/jpeg", PROOF_IMAGE_QUALITY));
          };
          img.onerror = () => reject(new Error("Unable to read image."));
          img.src = e.target.result;
        };
        reader.onerror = () => reject(new Error("Unable to read file."));
        reader.readAsDataURL(file);
      });
    }

    function buildDeliveryCard(delivery) {
      const total = calcTotal(delivery);
      const subtotal = delivery.items.reduce((s, i) => s + i.price * i.qty, 0);
      const isMine = delivery.assignedToMe;
      const card = document.createElement("div");
      card.className = "rounded-md bg-white border border-gray-200 overflow-hidden shadow-sm";
      card.setAttribute("data-order-id", delivery.orderIdRaw);

      const itemsHTML = delivery.items
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
      if (delivery.status === "delivered" && delivery.customerConfirmed === "confirmed") {
        reviewNote = `<p class="text-[10px] text-emerald-600 font-medium mt-0.5">Confirmed by customer</p>`;
      } else if (delivery.status === "delivered" && delivery.customerConfirmed === "issue") {
        reviewNote = `<p class="text-[10px] text-red-500 font-medium mt-0.5">Customer reported an issue</p>`;
      }

      let actionButtonsHTML = `
          <button class="view-details-btn flex-1 py-2 border border-gray-200 text-gray-700 text-xs font-semibold hover:bg-gray-50 transition-colors flex items-center justify-center gap-1.5 rounded-[3px]" data-id="${delivery.orderIdRaw}">
            View Details
          </button>
        `;

      if (delivery.status === "ready_for_dispatch" && !isMine) {
        actionButtonsHTML += `<button class="accept-btn flex-1 py-2 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold transition-colors flex items-center justify-center gap-1.5 rounded-[3px]" data-id="${delivery.orderIdRaw}">Accept Delivery</button>`;
      } else if (delivery.status === "ready_for_dispatch" && isMine) {
        actionButtonsHTML += `<button class="collected-btn flex-1 py-2 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold transition-colors flex items-center justify-center gap-1.5 rounded-[3px]" data-id="${delivery.orderIdRaw}">Mark Collected</button>`;
      } else if (delivery.status === "collected") {
        actionButtonsHTML += `<button class="start-delivery-btn flex-1 py-2 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold transition-colors flex items-center justify-center gap-1.5 rounded-[3px]" data-id="${delivery.orderIdRaw}">Start Delivery</button>`;
      } else if (delivery.status === "out_for_delivery") {
        actionButtonsHTML += `<button class="mark-delivered-btn flex-1 py-2 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold transition-colors flex items-center justify-center gap-1.5 rounded-[3px]" data-id="${delivery.orderIdRaw}">Mark Delivered</button>`;
        actionButtonsHTML += `<button class="cancel-delivery-btn flex-1 py-2 border border-red-200 text-red-600 text-xs font-semibold hover:bg-red-50 transition-colors flex items-center justify-center gap-1.5 rounded-[3px]" data-id="${delivery.orderIdRaw}">Cancel</button>`;
      } else if (delivery.status === "delivered") {
        actionButtonsHTML += `<button class="flex-1 py-2 border border-gray-200 text-gray-400 text-xs font-semibold cursor-not-allowed opacity-60 flex items-center justify-center gap-1.5 rounded-[3px]" disabled>Delivered</button>`;
      } else if (delivery.status === "cancelled") {
        actionButtonsHTML += `<button class="flex-1 py-2 border border-gray-200 text-gray-400 text-xs font-semibold cursor-not-allowed opacity-60 flex items-center justify-center gap-1.5 rounded-[3px]" disabled>Order Cancelled</button>`;
      }

      card.innerHTML = `
        <div class="p-4 border-b border-gray-100">
          <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
              <div class="flex items-center gap-1.5 cursor-pointer hover:text-emerald-600 transition-colors copy-id-btn" data-id="${escapeHtml(delivery.id)}">
                <p class="text-xs font-semibold text-gray-800 inherit-color">${escapeHtml(delivery.id)}</p>
                <span class="copy-icon w-3 h-3 text-gray-400 flex items-center justify-center shrink-0">
                  <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-3 h-3"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 17.25v3.375c0 .621-.504 1.125-1.125 1.125h-9.75a1.125 1.125 0 0 1-1.125-1.125V7.875c0-.621.504-1.125 1.125-1.125H6.75a9.06 9.06 0 0 1 1.5.124m7.5 10.376h3.375c.621 0 1.125-.504 1.125-1.125V11.25c0-4.46-3.243-8.161-7.5-8.876a9.06 9.06 0 0 0-1.5-.124H9.375c-.621 0-1.125.504-1.125 1.125v3.5m7.5 10.375H9.375a1.125 1.125 0 0 1-1.125-1.125v-9.25m12 6.625v-1.875a3.375 3.375 0 0 0-3.375-3.375h-1.5a1.125 1.125 0 0 1-1.125-1.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H9.75" /></svg>
                </span>
              </div>
              <p class="text-[11px] text-gray-500 mt-0.5">${escapeHtml(delivery.date)}</p>
              <p class="text-xs font-medium text-gray-700 mt-1 flex items-center gap-1.5">
                <span class="truncate min-w-0">${escapeHtml(delivery.customerName)}</span>
                ${customerTypeBadgeHtml(delivery.customerType)}
              </p>
              <p class="text-[10px] text-emerald-600 font-medium mt-0.5">${escapeHtml(delivery.stall)}</p>
              ${!isMine ? `<p class="text-[10px] text-amber-600 font-semibold mt-0.5">Unassigned</p>` : ""}
              ${reviewNote}
            </div>
            ${statusBadgeHtml(delivery.status)}
          </div>
        </div>
        <div class="px-4 py-3 border-b border-gray-100 space-y-3">
          ${itemsHTML}
          <div class="flex items-center justify-between pt-1.5 border-t border-gray-100 mt-1">
            <span class="text-xs text-gray-500">Subtotal</span>
            <span class="text-xs text-gray-600">₱${subtotal.toFixed(2)}</span>
          </div>
          <div class="flex items-center justify-between">
            <span class="text-xs text-gray-500">Delivery Fee</span>
            <span class="text-xs text-gray-700 text-right">₱${delivery.deliveryFee.toFixed(2)}</span>
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

    function showDeliveryDetails(orderIdRaw) {
      const delivery = ALL_DELIVERIES.find((d) => d.orderIdRaw === orderIdRaw);
      if (!delivery) return;
      const modal = document.getElementById("detailsModal");
      const content = document.getElementById("modalContent");
      const total = calcTotal(delivery);
      const subtotal = delivery.items.reduce((s, i) => s + i.price * i.qty, 0);

      content.innerHTML = `
        <div>
          <h3 class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-2">Customer</h3>
          <div class="border border-gray-100 p-3 space-y-3 rounded-md">
            <div class="flex items-center gap-3">
              ${personAvatarHtml(delivery.customerImage, delivery.customerName, "w-9 h-9")}
              <div class="flex-1 min-w-0">
                <p class="text-xs font-semibold text-gray-800 flex items-center gap-1.5">
                  <span class="truncate min-w-0">${escapeHtml(delivery.customerName)}</span>
                  ${customerTypeBadgeHtml(delivery.customerType)}
                </p>
                <p class="text-[10px] text-gray-400 mt-0.5">${escapeHtml(delivery.customerContact)}</p>
              </div>
            </div>
            <div class="flex gap-2">
              <a href="tel:${escapeHtml(delivery.customerContact)}" class="flex-1 flex items-center justify-center gap-2 py-2 bg-white border border-emerald-600 text-emerald-600 text-[11px] font-semibold hover:bg-emerald-50 transition-colors rounded-[3px]">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-3.5 h-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 0 0 2.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 0 1-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 0 0-1.091-.852H4.5A2.25 2.25 0 0 0 2.25 4.5v2.25Z" /></svg>
                Call
              </a>
              <a href="./chat.php" class="flex-1 flex items-center justify-center gap-2 py-2 bg-emerald-600 text-white text-[11px] font-semibold hover:bg-emerald-700 transition-colors rounded-[3px]">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-3.5 h-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="M7.5 8.25h9m-9 3H12m-9.75 1.51c0 1.6 1.123 2.994 2.707 3.227 1.129.166 2.27.293 3.423.379.35.026.67.21.865.501L12 21l2.755-4.133a1.14 1.14 0 0 1 .865-.501 48.172 48.172 0 0 0 3.423-.379c1.584-.233 2.707-1.626 2.707-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0 0 12 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018Z" /></svg>
                Message
              </a>
            </div>
          </div>
        </div>
        <div>
          <h3 class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-2">Pickup From</h3>
          <div class="border border-gray-100 p-3 space-y-3 rounded-md">
            <div class="flex items-center gap-3">
              ${delivery.stallOwner ? personAvatarHtml(delivery.stallOwner.image, delivery.stallOwner.name, "w-9 h-9") : ""}
              <div class="flex-1 min-w-0">
                <p class="text-xs font-semibold text-gray-800 truncate">${escapeHtml(delivery.stall)}</p>
                ${delivery.stallOwner ? `<p class="text-[10px] text-gray-400 mt-0.5">${escapeHtml(delivery.stallOwner.name)} &middot; ${escapeHtml(delivery.stallOwner.phone)}</p>` : ""}
              </div>
            </div>
            ${
              delivery.stallOwner
                ? `<div class="flex gap-2">
              <a href="tel:${escapeHtml(delivery.stallOwner.phone)}" class="flex-1 flex items-center justify-center gap-2 py-2 bg-white border border-emerald-600 text-emerald-600 text-[11px] font-semibold hover:bg-emerald-50 transition-colors rounded-[3px]">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-3.5 h-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 0 0 2.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 0 1-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 0 0-1.091-.852H4.5A2.25 2.25 0 0 0 2.25 4.5v2.25Z" /></svg>
                Call
              </a>
              <a href="./chat.php" class="flex-1 flex items-center justify-center gap-2 py-2 bg-emerald-600 text-white text-[11px] font-semibold hover:bg-emerald-700 transition-colors rounded-[3px]">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-3.5 h-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="M7.5 8.25h9m-9 3H12m-9.75 1.51c0 1.6 1.123 2.994 2.707 3.227 1.129.166 2.27.293 3.423.379.35.026.67.21.865.501L12 21l2.755-4.133a1.14 1.14 0 0 1 .865-.501 48.172 48.172 0 0 0 3.423-.379c1.584-.233 2.707-1.626 2.707-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0 0 12 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018Z" /></svg>
                Message
              </a>
            </div>`
                : ""
            }
          </div>
        </div>
        <div>
          <h3 class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-2">Delivery Information</h3>
          <div class="border border-gray-100 p-3 space-y-2 rounded-md">
            <div class="flex items-center justify-between">
              <span class="text-[10px] text-gray-500">Drop-off Location</span>
              <span class="text-xs font-medium text-gray-800 text-right">${escapeHtml(delivery.location)}</span>
            </div>
            <div class="flex items-center justify-between">
              <span class="text-[10px] text-gray-500">Payment Method</span>
              <span class="text-xs font-medium text-gray-800">${escapeHtml(delivery.payment)}</span>
            </div>
            <div class="flex items-center justify-between">
              <span class="text-[10px] text-gray-500">Delivery Fee</span>
              <span class="text-xs font-medium text-emerald-600">₱${delivery.deliveryFee.toFixed(2)}</span>
            </div>
          </div>
        </div>
        <div>
          <h3 class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-2">Order Summary</h3>
          <div class="border border-gray-100 p-3 space-y-3 rounded-md">
            ${delivery.items
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
                <span class="text-[11px] text-gray-500">Delivery Fee</span>
                <span class="text-[11px] text-gray-700">₱${delivery.deliveryFee.toFixed(2)}</span>
              </div>
              <div class="flex justify-between pt-1">
                <span class="text-xs font-bold text-gray-800">Total</span>
                <span class="text-sm font-bold text-emerald-600">₱${total.toFixed(2)}</span>
              </div>
            </div>
          </div>
        </div>
        ${
          delivery.note
            ? `<div>
                <h3 class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-2">Order Note</h3>
                <div class="border border-gray-100 p-3 rounded-md">
                  <p class="text-xs text-gray-600 italic">"${escapeHtml(delivery.note)}"</p>
                </div>
              </div>`
            : ""
        }
        ${
          delivery.proofImage
            ? `<div>
                <h3 class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-2">Proof of Handover</h3>
                <div class="border border-gray-100 p-3 rounded-md">
                  <img src="${escapeHtml(delivery.proofImage)}" alt="Proof of handover" class="proof-image-view w-full h-40 object-cover rounded-[6px] cursor-pointer" />
                </div>
              </div>`
            : ""
        }
        ${
          delivery.status === "cancelled" && delivery.cancelReason
            ? `<div>
                <h3 class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-2">Cancellation Reason</h3>
                <div class="border border-gray-100 p-3 rounded-md">
                  <p class="text-xs text-gray-600 italic">"${escapeHtml(delivery.cancelReason)}"</p>
                </div>
              </div>`
            : ""
        }
      `;

      modal.classList.remove("hidden");
      document.body.style.overflow = "hidden";

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

    function closeModal() {
      document.getElementById("detailsModal").classList.add("hidden");
      document.body.style.overflow = "";
    }

    function openAcceptModal(orderIdRaw) {
      pendingAcceptId = orderIdRaw;
      const delivery = ALL_DELIVERIES.find((d) => d.orderIdRaw === orderIdRaw);
      document.getElementById("acceptOrderIdLabel").textContent = delivery ? delivery.id : "";
      document.getElementById("acceptModal").classList.remove("hidden");
      document.body.style.overflow = "hidden";
    }

    function closeAcceptModal() {
      document.getElementById("acceptModal").classList.add("hidden");
      document.body.style.overflow = "";
      pendingAcceptId = null;
    }

    function openCancelDeliveryModal(orderIdRaw) {
      pendingCancelDeliveryId = orderIdRaw;
      const delivery = ALL_DELIVERIES.find((d) => d.orderIdRaw === orderIdRaw);
      document.getElementById("cancelDeliveryOrderIdLabel").textContent = delivery ? delivery.id : "";
      document.getElementById("cancelDeliveryReasonInput").value = "";
      document.getElementById("cancelDeliveryError").classList.add("hidden");
      document.getElementById("cancelDeliveryModal").classList.remove("hidden");
      document.body.style.overflow = "hidden";
    }

    function closeCancelDeliveryModal() {
      document.getElementById("cancelDeliveryModal").classList.add("hidden");
      document.body.style.overflow = "";
      pendingCancelDeliveryId = null;
    }

    function openCollectedModal(orderIdRaw) {
      pendingCollectedId = orderIdRaw;
      const delivery = ALL_DELIVERIES.find((d) => d.orderIdRaw === orderIdRaw);
      document.getElementById("collectedOrderIdLabel").textContent = delivery ? delivery.id : "";
      document.getElementById("collectedModal").classList.remove("hidden");
      document.body.style.overflow = "hidden";
    }

    function closeCollectedModal() {
      document.getElementById("collectedModal").classList.add("hidden");
      document.body.style.overflow = "";
      pendingCollectedId = null;
    }

    function openStartDeliveryModal(orderIdRaw) {
      pendingStartDeliveryId = orderIdRaw;
      const delivery = ALL_DELIVERIES.find((d) => d.orderIdRaw === orderIdRaw);
      document.getElementById("startDeliveryOrderIdLabel").textContent = delivery ? delivery.id : "";
      document.getElementById("startDeliveryModal").classList.remove("hidden");
      document.body.style.overflow = "hidden";
    }

    function closeStartDeliveryModal() {
      document.getElementById("startDeliveryModal").classList.add("hidden");
      document.body.style.overflow = "";
      pendingStartDeliveryId = null;
    }

    function updateProofImagePreview(src) {
      const img = document.getElementById("proofImagePreview");
      const placeholder = document.getElementById("proofImagePlaceholder");
      if (src) {
        img.src = src;
        img.classList.remove("hidden");
        placeholder.classList.add("hidden");
      } else {
        img.src = "";
        img.classList.add("hidden");
        placeholder.classList.remove("hidden");
      }
    }

    function openDeliverProofModal(orderIdRaw) {
      pendingDeliverId = orderIdRaw;
      capturedProofImage = null;
      const delivery = ALL_DELIVERIES.find((d) => d.orderIdRaw === orderIdRaw);
      document.getElementById("deliverOrderIdLabel").textContent = delivery ? delivery.id : "";
      document.getElementById("proofImageInput").value = "";
      document.getElementById("deliverProofError").classList.add("hidden");
      document.getElementById("deliverProofConfirmBtn").disabled = true;
      updateProofImagePreview(null);
      document.getElementById("deliverProofModal").classList.remove("hidden");
      document.body.style.overflow = "hidden";
    }

    function closeDeliverProofModal() {
      document.getElementById("deliverProofModal").classList.add("hidden");
      document.body.style.overflow = "";
      pendingDeliverId = null;
      capturedProofImage = null;
    }

    function renderStatusTabs() {
      const container = document.getElementById("statusTabsContainer");
      container.innerHTML = STATUS_TABS.map(
        (t) => `
          <button
            type="button"
            class="status-tab-btn rounded-[3px] shrink-0 px-3 py-1.5 text-[11px] font-semibold border whitespace-nowrap transition-colors ${currentStatusFilter === t.value ? "status-tab-active" : "bg-white border-gray-200 text-gray-500 hover:border-emerald-400 hover:text-emerald-600 hover:bg-emerald-50"}"
            data-value="${t.value}"
          >${t.label}</button>
        `,
      ).join("");
      container.querySelectorAll(".status-tab-btn").forEach((btn) => {
        btn.addEventListener("click", () => {
          currentStatusFilter = btn.getAttribute("data-value");
          renderStatusTabs();
          renderDeliveries();
        });
      });
    }

    function renderDeliveries() {
      const container = document.getElementById("deliveriesContainer");
      const emptyView = document.getElementById("emptyView");
      container.innerHTML = "";

      const filtered = ALL_DELIVERIES.filter((d) => {
        const q = searchQuery.toLowerCase();
        const matchesSearch =
          !q ||
          d.id.toLowerCase().includes(q) ||
          d.customerName.toLowerCase().includes(q) ||
          d.stall.toLowerCase().includes(q) ||
          d.items.some((i) => i.name.toLowerCase().includes(q));

        const isMine = d.assignedToMe;
        const matchesStall = currentStallFilter === "all" || d.stall === currentStallFilter;

        let matchesStatus;
        if (currentStatusFilter === "all") {
          matchesStatus = isMine || (d.status === "ready_for_dispatch" && !isMine);
        } else {
          matchesStatus = isMine && d.status === currentStatusFilter;
        }

        return matchesSearch && matchesStatus && matchesStall;
      });

      if (filtered.length === 0) {
        emptyView.classList.remove("hidden");
      } else {
        emptyView.classList.add("hidden");
        filtered.forEach((d) => container.appendChild(buildDeliveryCard(d)));
      }
      bindEvents();
    }

    function bindEvents() {
      document.querySelectorAll(".view-details-btn").forEach((btn) => {
        btn.addEventListener("click", () => showDeliveryDetails(parseInt(btn.getAttribute("data-id"))));
      });

      document.querySelectorAll(".copy-id-btn").forEach((btn) => {
        btn.addEventListener("click", (e) => {
          e.stopPropagation();
          const iconEl = btn.querySelector(".copy-icon");
          copyToClipboard(btn.getAttribute("data-id"), iconEl);
        });
      });

      document.querySelectorAll(".accept-btn").forEach((btn) => {
        btn.addEventListener("click", () => openAcceptModal(parseInt(btn.getAttribute("data-id"))));
      });

      document.querySelectorAll(".collected-btn").forEach((btn) => {
        btn.addEventListener("click", () => openCollectedModal(parseInt(btn.getAttribute("data-id"))));
      });

      document.querySelectorAll(".start-delivery-btn").forEach((btn) => {
        btn.addEventListener("click", () => openStartDeliveryModal(parseInt(btn.getAttribute("data-id"))));
      });

      document.querySelectorAll(".mark-delivered-btn").forEach((btn) => {
        btn.addEventListener("click", () => openDeliverProofModal(parseInt(btn.getAttribute("data-id"))));
      });

      document.querySelectorAll(".cancel-delivery-btn").forEach((btn) => {
        btn.addEventListener("click", () => openCancelDeliveryModal(parseInt(btn.getAttribute("data-id"))));
      });
    }

    function setupBackButton() {
      document.getElementById("backButton").addEventListener("click", () => window.history.back());
    }

    function setupSearch() {
      document.getElementById("searchDeliveries").addEventListener("input", (e) => {
        searchQuery = e.target.value;
        document.getElementById("clearSearchBtn").classList.toggle("hidden", searchQuery.length === 0);
        renderDeliveries();
      });

      document.getElementById("clearSearchBtn").addEventListener("click", () => {
        const input = document.getElementById("searchDeliveries");
        input.value = "";
        searchQuery = "";
        document.getElementById("clearSearchBtn").classList.add("hidden");
        input.focus();
        renderDeliveries();
      });
    }

    function setupStallFilter() {
      document.getElementById("stallFilterSelect").addEventListener("change", (e) => {
        currentStallFilter = e.target.value;
        updateStallFilterWidth();
        renderDeliveries();
      });
    }

    function setupDetailsModal() {
      document.getElementById("closeModalBtn").addEventListener("click", closeModal);
      document.getElementById("closeModalOverlay").addEventListener("click", closeModal);
    }

    function setupLightbox() {
      document.getElementById("closeLightboxBtn").addEventListener("click", closeLightbox);
      document.getElementById("closeLightboxOverlay").addEventListener("click", closeLightbox);
    }

    function setupAcceptModal() {
      document.getElementById("closeAcceptOverlay").addEventListener("click", closeAcceptModal);
      document.getElementById("acceptKeepBtn").addEventListener("click", closeAcceptModal);
      document.getElementById("acceptConfirmBtn").addEventListener("click", async () => {
        const confirmBtn = document.getElementById("acceptConfirmBtn");
        confirmBtn.disabled = true;
        const res = await postAction("accept_delivery", { order_id: pendingAcceptId });
        confirmBtn.disabled = false;
        if (res.success) {
          ALL_DELIVERIES = res.deliveries;
          closeAcceptModal();
          renderDeliveries();
        } else {
          alert(res.message || "Something went wrong. Please try again.");
        }
      });
    }

    function setupCollectedModal() {
      document.getElementById("closeCollectedOverlay").addEventListener("click", closeCollectedModal);
      document.getElementById("collectedKeepBtn").addEventListener("click", closeCollectedModal);
      document.getElementById("collectedConfirmBtn").addEventListener("click", async () => {
        const confirmBtn = document.getElementById("collectedConfirmBtn");
        confirmBtn.disabled = true;
        const res = await postAction("mark_collected", { order_id: pendingCollectedId });
        confirmBtn.disabled = false;
        if (res.success) {
          ALL_DELIVERIES = res.deliveries;
          closeCollectedModal();
          renderDeliveries();
        } else {
          alert(res.message || "Something went wrong. Please try again.");
        }
      });
    }

    function setupStartDeliveryModal() {
      document.getElementById("closeStartDeliveryOverlay").addEventListener("click", closeStartDeliveryModal);
      document.getElementById("startDeliveryKeepBtn").addEventListener("click", closeStartDeliveryModal);
      document.getElementById("startDeliveryConfirmBtn").addEventListener("click", async () => {
        const confirmBtn = document.getElementById("startDeliveryConfirmBtn");
        confirmBtn.disabled = true;
        const res = await postAction("mark_out_for_delivery", { order_id: pendingStartDeliveryId });
        confirmBtn.disabled = false;
        if (res.success) {
          ALL_DELIVERIES = res.deliveries;
          closeStartDeliveryModal();
          renderDeliveries();
        } else {
          alert(res.message || "Something went wrong. Please try again.");
        }
      });
    }

    function setupDeliverProofModal() {
      document.getElementById("closeDeliverProofModalBtn").addEventListener("click", closeDeliverProofModal);
      document.getElementById("closeDeliverProofOverlay").addEventListener("click", closeDeliverProofModal);
      document.getElementById("deliverProofCancelBtn").addEventListener("click", closeDeliverProofModal);

      document.getElementById("proofImageDropzone").addEventListener("click", () =>
        document.getElementById("proofImageInput").click(),
      );

      document.getElementById("proofImageInput").addEventListener("change", async (e) => {
        const file = e.target.files[0];
        if (!file) return;

        const errEl = document.getElementById("deliverProofError");
        errEl.classList.add("hidden");

        if (!file.type.startsWith("image/")) {
          errEl.textContent = "Please select an image file.";
          errEl.classList.remove("hidden");
          document.getElementById("proofImageInput").value = "";
          return;
        }

        const sizeMB = file.size / (1024 * 1024);
        if (sizeMB > MAX_PROOF_SOURCE_MB) {
          errEl.textContent = `This photo is too large (${sizeMB.toFixed(1)}MB). Please choose a photo under ${MAX_PROOF_SOURCE_MB}MB.`;
          errEl.classList.remove("hidden");
          document.getElementById("proofImageInput").value = "";
          return;
        }

        try {
          const resizedDataUrl = await resizeImageFile(file);
          capturedProofImage = resizedDataUrl;
          updateProofImagePreview(capturedProofImage);
          document.getElementById("deliverProofConfirmBtn").disabled = false;
        } catch (err) {
          errEl.textContent = "Something went wrong processing that photo. Please try another.";
          errEl.classList.remove("hidden");
          document.getElementById("proofImageInput").value = "";
        }
      });

      document.getElementById("deliverProofConfirmBtn").addEventListener("click", async () => {
        const errEl = document.getElementById("deliverProofError");
        if (!capturedProofImage) {
          errEl.textContent = "Please take a photo of the handover before marking as delivered.";
          errEl.classList.remove("hidden");
          return;
        }
        errEl.classList.add("hidden");

        const confirmBtn = document.getElementById("deliverProofConfirmBtn");
        confirmBtn.disabled = true;
        const res = await postAction("mark_delivered", {
          order_id: pendingDeliverId,
          photo_data: capturedProofImage,
        });
        confirmBtn.disabled = false;

        if (res.success) {
          ALL_DELIVERIES = res.deliveries;
          closeDeliverProofModal();
          renderDeliveries();
        } else {
          errEl.textContent = res.message || "Something went wrong. Please try again.";
          errEl.classList.remove("hidden");
        }
      });
    }

    function setupCancelDeliveryModal() {
      document.getElementById("closeCancelDeliveryOverlay").addEventListener("click", closeCancelDeliveryModal);
      document.getElementById("closeCancelDeliveryModalBtn").addEventListener("click", closeCancelDeliveryModal);
      document.getElementById("cancelDeliveryKeepBtn").addEventListener("click", closeCancelDeliveryModal);
      document.getElementById("cancelDeliveryConfirmBtn").addEventListener("click", async () => {
        const reasonInput = document.getElementById("cancelDeliveryReasonInput");
        const errEl = document.getElementById("cancelDeliveryError");
        const reason = reasonInput.value.trim();

        if (reason === "") {
          errEl.textContent = "Please provide a reason for cancelling this delivery.";
          errEl.classList.remove("hidden");
          return;
        }
        errEl.classList.add("hidden");

        const confirmBtn = document.getElementById("cancelDeliveryConfirmBtn");
        confirmBtn.disabled = true;
        const res = await postAction("cancel_delivery", {
          order_id: pendingCancelDeliveryId,
          reason: reason,
        });
        confirmBtn.disabled = false;

        if (res.success) {
          ALL_DELIVERIES = res.deliveries;
          closeCancelDeliveryModal();
          renderDeliveries();
        } else {
          errEl.textContent = res.message || "Something went wrong. Please try again.";
          errEl.classList.remove("hidden");
        }
      });
    }

    function init() {
      renderStatusTabs();
      renderDeliveries();
      updateStallFilterWidth();
      setupBackButton();
      setupSearch();
      setupStallFilter();
      setupDetailsModal();
      setupLightbox();
      setupAcceptModal();
      setupCollectedModal();
      setupStartDeliveryModal();
      setupDeliverProofModal();
      setupCancelDeliveryModal();
    }

    window.addEventListener("load", init);
  </script>
</body>

</html>