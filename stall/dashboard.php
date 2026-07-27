<?php
date_default_timezone_set('Asia/Manila');
session_start();
require_once '../config/database.php';
require_once '../config/vapid.php';

if (!isset($_SESSION['owner_id'])) {
  header('Location: ../auth/login.php');
  exit;
}

$ownerId = $_SESSION['owner_id'];

$stmt = $conn->prepare("SELECT first_name, last_name, profile_image FROM stall_owners WHERE owner_id = ? LIMIT 1");
$stmt->bind_param("i", $ownerId);
$stmt->execute();
$result = $stmt->get_result();
$owner = $result->fetch_assoc();
$stmt->close();

if (!$owner) {
  $conn->close();
  header('Location: ../auth/login.php');
  exit;
}

$ownerFirstName = $owner['first_name'];
$ownerLastName = $owner['last_name'];
$ownerFullName = $ownerFirstName . ' ' . $ownerLastName;
$ownerProfileImage = $owner['profile_image'] ? '../' . $owner['profile_image'] : null;

function getOwnerInitials($first, $last)
{
  $f = mb_substr(trim($first), 0, 1);
  $l = mb_substr(trim($last), 0, 1);
  return mb_strtoupper($f . $l);
}

$ownerInitials = getOwnerInitials($ownerFirstName, $ownerLastName);

$stallStmt = $conn->prepare("SELECT stall_id, status FROM stalls WHERE owner_id = ? LIMIT 1");
$stallStmt->bind_param("i", $ownerId);
$stallStmt->execute();
$myStall = $stallStmt->get_result()->fetch_assoc();
$stallStmt->close();

function refValues($arr)
{
  $refs = [];
  foreach ($arr as $key => $value) {
    $refs[$key] = &$arr[$key];
  }
  return $refs;
}

function formatRelativeTime($datetime)
{
  $now = time();
  $then = strtotime($datetime);
  $diff = $now - $then;

  if ($diff < 60) {
    return 'Just now';
  }
  if ($diff < 3600) {
    $mins = (int) floor($diff / 60);
    return $mins . ' min' . ($mins > 1 ? 's' : '') . ' ago';
  }
  if ($diff < 86400) {
    $hours = (int) floor($diff / 3600);
    return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
  }

  $yesterday = date('Y-m-d', strtotime('-1 day'));
  $dateOnly = date('Y-m-d', $then);
  if ($dateOnly === $yesterday) {
    return 'Yesterday';
  }

  return date('M j', $then);
}

function fetchDashboardStats($conn, $ownerId)
{
  $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM orders WHERE owner_id = ? AND status = 'pending'");
  $stmt->bind_param("i", $ownerId);
  $stmt->execute();
  $pendingOrders = (int) $stmt->get_result()->fetch_assoc()['cnt'];
  $stmt->close();

  $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM orders WHERE owner_id = ? AND DATE(created_at) = CURDATE()");
  $stmt->bind_param("i", $ownerId);
  $stmt->execute();
  $todaysOrders = (int) $stmt->get_result()->fetch_assoc()['cnt'];
  $stmt->close();

  $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM menu_items WHERE owner_id = ?");
  $stmt->bind_param("i", $ownerId);
  $stmt->execute();
  $menuItemsCount = (int) $stmt->get_result()->fetch_assoc()['cnt'];
  $stmt->close();

  return [
    'pendingOrders' => $pendingOrders,
    'todaysOrders' => $todaysOrders,
    'menuItemsCount' => $menuItemsCount,
  ];
}

function fetchRecentOrders($conn, $ownerId, $limit = 5)
{
  $stmt = $conn->prepare("
        SELECT o.order_id, o.status, o.grand_total, o.created_at,
               c.first_name, c.last_name, c.customer_type, c.profile_image
        FROM orders o
        JOIN customers c ON o.customer_id = c.customer_id
        WHERE o.owner_id = ?
        ORDER BY o.created_at DESC
        LIMIT ?
    ");
  $stmt->bind_param("ii", $ownerId, $limit);
  $stmt->execute();
  $result = $stmt->get_result();

  $orders = [];
  $orderIds = [];

  while ($row = $result->fetch_assoc()) {
    $orderIdRaw = (int) $row['order_id'];
    $orderIds[] = $orderIdRaw;
    $orders[$orderIdRaw] = [
      'code' => 'FC-' . str_pad($orderIdRaw, 6, '0', STR_PAD_LEFT),
      'customer' => trim($row['first_name'] . ' ' . $row['last_name']),
      'customerType' => $row['customer_type'],
      'customerImage' => $row['profile_image'] ? '../' . $row['profile_image'] : null,
      'items' => '',
      'total' => (float) $row['grand_total'],
      'status' => $row['status'],
      'time' => formatRelativeTime($row['created_at']),
    ];
  }
  $stmt->close();

  if (empty($orderIds)) {
    return [];
  }

  $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
  $types = str_repeat('i', count($orderIds));

  $itemsStmt = $conn->prepare("
        SELECT order_id, item_name, quantity
        FROM order_items
        WHERE order_id IN ($placeholders)
        ORDER BY order_item_id ASC
    ");
  $params = array_merge([$types], $orderIds);
  call_user_func_array([$itemsStmt, 'bind_param'], refValues($params));
  $itemsStmt->execute();
  $itemsResult = $itemsStmt->get_result();

  $itemsByOrder = [];
  while ($itemRow = $itemsResult->fetch_assoc()) {
    $oid = (int) $itemRow['order_id'];
    if (!isset($itemsByOrder[$oid])) {
      $itemsByOrder[$oid] = [];
    }
    $itemsByOrder[$oid][] = $itemRow['quantity'] . 'x ' . $itemRow['item_name'];
  }
  $itemsStmt->close();

  foreach ($orders as $oid => $data) {
    $orders[$oid]['items'] = isset($itemsByOrder[$oid]) ? implode(', ', $itemsByOrder[$oid]) : '';
  }

  return array_values($orders);
}

function fetchBestSellingItems($conn, $ownerId, $limit = 3)
{
  $stmt = $conn->prepare("
        SELECT oi.menu_item_id, MAX(oi.item_name) AS item_name, SUM(oi.quantity) AS total_qty,
               mi.image, c.category_name
        FROM order_items oi
        JOIN orders o ON oi.order_id = o.order_id
        JOIN menu_items mi ON oi.menu_item_id = mi.menu_item_id
        JOIN categories c ON mi.category_id = c.category_id
        WHERE o.owner_id = ? AND o.status = 'completed'
          AND MONTH(o.created_at) = MONTH(CURDATE()) AND YEAR(o.created_at) = YEAR(CURDATE())
        GROUP BY oi.menu_item_id
        ORDER BY total_qty DESC
        LIMIT ?
    ");
  $stmt->bind_param("ii", $ownerId, $limit);
  $stmt->execute();
  $result = $stmt->get_result();

  $items = [];
  while ($row = $result->fetch_assoc()) {
    $items[] = [
      'name' => $row['item_name'],
      'category' => $row['category_name'],
      'orders' => (int) $row['total_qty'],
      'img' => $row['image'] ? '../' . $row['image'] : null,
    ];
  }
  $stmt->close();

  return $items;
}

function fetchTopCustomers($conn, $ownerId, $limit = 3)
{
  $stmt = $conn->prepare("
        SELECT o.customer_id, c.first_name, c.last_name, c.customer_type, c.profile_image,
               COUNT(*) AS order_count, SUM(o.grand_total) AS total_spend
        FROM orders o
        JOIN customers c ON o.customer_id = c.customer_id
        WHERE o.owner_id = ? AND o.status = 'completed'
          AND MONTH(o.created_at) = MONTH(CURDATE()) AND YEAR(o.created_at) = YEAR(CURDATE())
        GROUP BY o.customer_id
        ORDER BY total_spend DESC
        LIMIT ?
    ");
  $stmt->bind_param("ii", $ownerId, $limit);
  $stmt->execute();
  $result = $stmt->get_result();

  $customers = [];
  while ($row = $result->fetch_assoc()) {
    $customers[] = [
      'name' => trim($row['first_name'] . ' ' . $row['last_name']),
      'customerType' => $row['customer_type'],
      'customerImage' => $row['profile_image'] ? '../' . $row['profile_image'] : null,
      'orders' => (int) $row['order_count'],
      'totalSpend' => (float) $row['total_spend'],
    ];
  }
  $stmt->close();

  return $customers;
}

function fetchOrderStatusBreakdown($conn, $ownerId, $dateCondition)
{
  $breakdown = [
    'delivery' => [
      'pending' => 0,
      'preparing' => 0,
      'ready_for_dispatch' => 0,
      'collected' => 0,
      'out_for_delivery' => 0,
      'delivered' => 0,
      'cancelled' => 0,
    ],
    'pickup' => [
      'pending' => 0,
      'preparing' => 0,
      'ready_for_pickup' => 0,
      'completed' => 0,
      'cancelled' => 0,
    ],
  ];

  $stmt = $conn->prepare("SELECT order_type, status, COUNT(*) AS cnt FROM orders WHERE owner_id = ? AND $dateCondition GROUP BY order_type, status");
  $stmt->bind_param("i", $ownerId);
  $stmt->execute();
  $result = $stmt->get_result();

  while ($row = $result->fetch_assoc()) {
    $type = $row['order_type'];
    $status = $row['status'];
    if (isset($breakdown[$type]) && array_key_exists($status, $breakdown[$type])) {
      $breakdown[$type][$status] = (int) $row['cnt'];
    }
  }
  $stmt->close();

  return $breakdown;
}

function fetchNotifications($conn, $userType, $userId, $limit = 10)
{
  $stmt = $conn->prepare("SELECT notification_id, title, message, link, is_read, created_at FROM notifications WHERE user_type = ? AND user_id = ? ORDER BY created_at DESC LIMIT ?");
  $stmt->bind_param("sii", $userType, $userId, $limit);
  $stmt->execute();
  $result = $stmt->get_result();

  $notifications = [];
  while ($row = $result->fetch_assoc()) {
    $notifications[] = [
      'id' => (int) $row['notification_id'],
      'title' => $row['title'],
      'message' => $row['message'],
      'link' => $row['link'],
      'isRead' => (bool) $row['is_read'],
      'time' => formatRelativeTime($row['created_at']),
    ];
  }
  $stmt->close();

  return $notifications;
}

function countUnreadNotifications($conn, $userType, $userId)
{
  $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM notifications WHERE user_type = ? AND user_id = ? AND is_read = 0");
  $stmt->bind_param("si", $userType, $userId);
  $stmt->execute();
  $count = (int) $stmt->get_result()->fetch_assoc()['cnt'];
  $stmt->close();

  return $count;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
  header('Content-Type: application/json');
  $action = $_POST['action'];

  if ($action === 'toggle_stall_status') {
    $checkStmt = $conn->prepare("SELECT stall_id FROM stalls WHERE owner_id = ? LIMIT 1");
    $checkStmt->bind_param("i", $ownerId);
    $checkStmt->execute();
    $stallRow = $checkStmt->get_result()->fetch_assoc();
    $checkStmt->close();

    if (!$stallRow) {
      echo json_encode(['success' => false, 'message' => 'You do not have an assigned stall yet.']);
      $conn->close();
      exit;
    }

    $newStatus = ($_POST['status'] ?? '') === 'closed' ? 'closed' : 'open';

    $stmt = $conn->prepare("UPDATE stalls SET status = ? WHERE owner_id = ?");
    $stmt->bind_param("si", $newStatus, $ownerId);
    $ok = $stmt->execute();
    $stmt->close();

    echo json_encode($ok
      ? ['success' => true, 'status' => $newStatus]
      : ['success' => false, 'message' => 'Failed to update stall status.']);
    $conn->close();
    exit;
  }

  if ($action === 'mark_notification_read') {
    $notifId = (int) ($_POST['notification_id'] ?? 0);

    $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE notification_id = ? AND user_type = 'stall_owner' AND user_id = ?");
    $stmt->bind_param("ii", $notifId, $ownerId);
    $stmt->execute();
    $stmt->close();

    echo json_encode([
      'success' => true,
      'notifications' => fetchNotifications($conn, 'stall_owner', $ownerId, 10),
      'unreadCount' => countUnreadNotifications($conn, 'stall_owner', $ownerId),
    ]);
    $conn->close();
    exit;
  }

  if ($action === 'mark_all_notifications_read') {
    $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE user_type = 'stall_owner' AND user_id = ?");
    $stmt->bind_param("i", $ownerId);
    $stmt->execute();
    $stmt->close();

    echo json_encode([
      'success' => true,
      'notifications' => fetchNotifications($conn, 'stall_owner', $ownerId, 10),
      'unreadCount' => 0,
    ]);
    $conn->close();
    exit;
  }

  echo json_encode(['success' => false, 'message' => 'Unknown action.']);
  $conn->close();
  exit;
}

$dashboardStats = fetchDashboardStats($conn, $ownerId);
$recentOrders = fetchRecentOrders($conn, $ownerId, 5);
$bestSellingItems = fetchBestSellingItems($conn, $ownerId, 3);
$topCustomers = fetchTopCustomers($conn, $ownerId, 3);
$orderStatusBreakdown = fetchOrderStatusBreakdown($conn, $ownerId, 'DATE(created_at) = CURDATE()');
$notifications = fetchNotifications($conn, 'stall_owner', $ownerId, 10);
$unreadNotifCount = countUnreadNotifications($conn, 'stall_owner', $ownerId);
$conn->close();
?>
<!doctype html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta
    name="viewport"
    content="width=device-width, initial-scale=1.0, user-scalable=yes" />
  <title>Stall - Dashboard</title>
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

    .donut-segment {
      transition: stroke-dasharray 0.8s cubic-bezier(0.16, 1, 0.3, 1);
    }

    .status-legend-row {
      transition: opacity 0.3s ease;
    }
  </style>
</head>

<body class="bg-white">
  <div class="flex flex-col h-screen">
    <div class="bg-white flex-shrink-0 fixed top-0 left-0 right-0 z-20">
      <div class="max-w-5xl mx-auto px-4 py-2 flex items-center justify-between gap-3">
        <div class="flex items-center gap-2.5 min-w-0">
          <a
            href="./account.php"
            class="w-9 h-9 bg-gradient-to-br from-emerald-500 to-emerald-700 flex items-center justify-center text-white font-bold text-xs shrink-0 overflow-hidden rounded-full"
            title="Account">
            <?php if ($ownerProfileImage): ?>
              <img
                src="<?php echo htmlspecialchars($ownerProfileImage); ?>"
                alt="<?php echo htmlspecialchars($ownerFullName); ?>"
                class="w-full h-full object-cover" />
            <?php else: ?>
              <?php echo htmlspecialchars($ownerInitials); ?>
            <?php endif; ?>
          </a>
          <div class="min-w-0">
            <p class="text-sm font-bold text-gray-800 leading-tight truncate">
              Hello, <span class="text-emerald-600"><?php echo htmlspecialchars($ownerFirstName); ?></span>! 👋
            </p>
            <p class="text-[10px] text-gray-400 leading-none mt-0.5">Ready to serve today's orders?</p>
          </div>
        </div>
        <div class="flex items-center gap-2 shrink-0">
          <div class="relative">
            <button
              id="notifBtn"
              class="relative p-1.5 bg-white border border-gray-200 hover:border-emerald-500 hover:bg-slate-50 transition-all"
              style="border-radius: 6px"
              title="Notifications">
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
                  d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0" />
              </svg>
              <span id="notifBadge" class="hidden absolute -top-1 -right-1 min-w-[16px] h-4 px-1 bg-red-500 text-white text-[9px] font-bold flex items-center justify-center rounded-full"></span>
            </button>
            <div id="notifDropdown" class="hidden fixed left-4 right-4 top-16 sm:absolute sm:left-auto sm:right-0 sm:top-auto sm:mt-2 sm:w-80 sm:max-w-[90vw] bg-white border border-gray-200 shadow-lg z-30 rounded-md">
              <div class="p-3 border-b border-gray-100 flex items-center justify-between">
                <p class="text-xs font-bold text-gray-800">Notifications</p>
                <button id="markAllReadBtn" class="text-[11px] font-semibold text-emerald-600 hover:text-emerald-700">Mark all as read</button>
              </div>
              <div id="notifList" class="max-h-80 overflow-y-auto divide-y divide-gray-100"></div>
            </div>
          </div>
          <button
            id="messageBtn"
            class="relative p-1.5 bg-white border border-gray-200 hover:border-emerald-500 hover:bg-slate-50 transition-all"
            style="border-radius: 6px"
            title="Messages">
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
                d="M12 20.25c4.97 0 9-3.694 9-8.25s-4.03-8.25-9-8.25S3 7.444 3 12c0 2.104.859 4.023 2.273 5.48.432.447.74 1.04.586 1.641a4.483 4.483 0 0 1-.923 1.785A5.969 5.969 0 0 0 6 21c1.282 0 2.47-.402 3.445-1.087.81.22 1.668.337 2.555.337Z" />
            </svg>
            <span class="absolute top-0.5 right-0.5 w-2 h-2 bg-emerald-500 rounded-full"></span>
          </button>
        </div>
      </div>
    </div>

    <div class="flex-1 overflow-y-auto mt-12 mb-16" id="mainContent">
      <div class="max-w-5xl mx-auto px-4 pt-3 pb-4 flex flex-col gap-4" id="dashboardContent">
        <?php if ($myStall): ?>
          <div class="bg-white border border-gray-200 shadow-sm rounded-md p-3 flex items-center justify-between gap-3">
            <div class="flex items-center gap-2.5 min-w-0">
              <span id="stallStatusDot" class="w-2.5 h-2.5 rounded-full <?php echo $myStall['status'] === 'open' ? 'bg-emerald-500' : 'bg-gray-300'; ?> shrink-0"></span>
              <div class="min-w-0">
                <p class="text-xs font-semibold text-gray-800">Stall Status</p>
                <p id="stallStatusLabel" class="text-[10px] text-gray-400 truncate">
                  <?php echo $myStall['status'] === 'open' ? 'Open — visible to customers' : 'Closed — hidden from customers'; ?>
                </p>
              </div>
            </div>
            <label class="relative inline-flex items-center cursor-pointer shrink-0">
              <input
                type="checkbox"
                id="stallStatusToggle"
                class="sr-only peer"
                <?php echo $myStall['status'] === 'open' ? 'checked' : ''; ?> />
              <div class="w-11 h-6 bg-gray-300 peer-checked:bg-emerald-500 rounded-full transition-colors"></div>
              <div class="absolute left-1 top-1 w-4 h-4 bg-white rounded-full transition-transform peer-checked:translate-x-5 shadow"></div>
            </label>
          </div>
        <?php endif; ?>

        <div id="pushPromptBanner" class="hidden bg-emerald-50 border border-emerald-200 p-3 rounded-md">
          <div class="flex items-center gap-2.5">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 text-emerald-600 shrink-0">
              <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0" />
            </svg>
            <div class="flex-1 min-w-0">
              <p class="text-xs font-semibold text-gray-800">Stay updated on new orders</p>
              <p class="text-[10px] text-gray-500 mt-0.5">Turn on notifications so you never miss an order.</p>
            </div>
            <button id="pushEnableBtn" class="shrink-0 px-3 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white text-[11px] font-semibold rounded-[3px]">Enable</button>
            <button id="pushDismissBtn" class="shrink-0 p-1 hover:bg-emerald-100 rounded-[3px]">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 text-gray-400">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
              </svg>
            </button>
          </div>
        </div>

        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3" id="statsGrid"></div>

        <div class="bg-white border border-gray-200 shadow-sm overflow-hidden" style="border-radius:6px">
          <div class="p-4 border-b border-gray-100">
            <p class="text-[11px] font-bold text-gray-500 uppercase tracking-wide">Quick Actions</p>
            <p class="text-[10px] text-gray-400 mt-0.5">Manage your stall in one tap</p>
          </div>
          <div class="p-3 grid grid-cols-3 gap-1.5">
            <a
              href="./menu.php"
              class="flex flex-col items-center justify-center gap-1 py-2 border border-gray-200 hover:border-emerald-500 hover:bg-emerald-50/40 transition-all rounded-[6px]">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 text-emerald-600">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
              </svg>
              <span class="text-[10px] font-semibold text-gray-600 text-center">Add Item</span>
            </a>
            <a
              href="./menu.php"
              class="flex flex-col items-center justify-center gap-1 py-2 border border-gray-200 hover:border-emerald-500 hover:bg-emerald-50/40 transition-all rounded-[6px]">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 text-emerald-600">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 12h16.5m-16.5 3.75h16.5M3.75 19.5h16.5M5.625 4.5h12.75a1.875 1.875 0 0 1 0 3.75H5.625a1.875 1.875 0 0 1 0-3.75Z" />
              </svg>
              <span class="text-[10px] font-semibold text-gray-600 text-center">View Menu</span>
            </a>
            <a
              href="./orders.php"
              class="flex flex-col items-center justify-center gap-1 py-2 border border-gray-200 hover:border-emerald-500 hover:bg-emerald-50/40 transition-all rounded-[6px]">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 text-emerald-600">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 0 0 2.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 0 0-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 0 0 .75-.75 2.25 2.25 0 0 0-.1-.664m-5.8 0A2.251 2.251 0 0 1 13.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25Z" />
              </svg>
              <span class="text-[10px] font-semibold text-gray-600 text-center">View Orders</span>
            </a>
            <a
              href="./report.php"
              class="flex flex-col items-center justify-center gap-1 py-2 border border-gray-200 hover:border-emerald-500 hover:bg-emerald-50/40 transition-all rounded-[6px]">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 text-emerald-600">
                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" />
              </svg>
              <span class="text-[10px] font-semibold text-gray-600 text-center">Report</span>
            </a>
            <a
              href="./analytics.php"
              class="flex flex-col items-center justify-center gap-1 py-2 border border-gray-200 hover:border-emerald-500 hover:bg-emerald-50/40 transition-all rounded-[6px]">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 text-emerald-600">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z" />
              </svg>
              <span class="text-[10px] font-semibold text-gray-600 text-center">Analytics</span>
            </a>
            <a
              href="./delivery-fee.php"
              class="flex flex-col items-center justify-center gap-1 py-2 border border-gray-200 hover:border-emerald-500 hover:bg-emerald-50/40 transition-all rounded-[6px]">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 text-emerald-600">
                <path stroke-linecap="round" stroke-linejoin="round" d="m20.25 7.5-.625 10.632a2.25 2.25 0 0 1-2.247 2.118H6.622a2.25 2.25 0 0 1-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125Z" />
              </svg>
              <span class="text-[10px] font-semibold text-gray-600 text-center">Delivery Fee</span>
            </a>
          </div>
        </div>

        <div class="bg-white border border-gray-200 shadow-sm overflow-hidden" style="border-radius:6px">
          <div class="p-4 border-b border-gray-100">
            <p class="text-[11px] font-bold text-gray-500 uppercase tracking-wide">Order Status Breakdown</p>
            <p class="text-[10px] text-gray-400 mt-0.5" id="statusOverviewSubtitle">Delivery orders by status</p>
          </div>
          <div class="p-4">
            <div class="relative flex bg-gray-100 p-1 rounded-full mb-4 max-w-[200px]" id="statusTypeTabs">
              <div
                id="statusTypeIndicator"
                class="absolute top-1 bottom-1 bg-white shadow-sm rounded-full transition-all duration-300 ease-out"></div>
              <button type="button" data-type="delivery" class="status-type-tab relative z-10 flex-1 py-1.5 text-[11px] font-semibold rounded-full transition-colors duration-200">Delivery</button>
              <button type="button" data-type="pickup" class="status-type-tab relative z-10 flex-1 py-1.5 text-[11px] font-semibold rounded-full transition-colors duration-200">Pickup</button>
            </div>

            <div class="flex items-center gap-5" id="statusChartRow">
              <div class="relative shrink-0" style="width: 132px; height: 132px">
                <svg viewBox="0 0 120 120" class="w-full h-full -rotate-90" id="statusDonutSvg"></svg>
                <div class="absolute inset-0 flex flex-col items-center justify-center">
                  <p class="text-xl font-bold text-gray-800" id="statusDonutTotal">0</p>
                  <p class="text-[9px] text-gray-400 uppercase tracking-wide">Orders</p>
                </div>
              </div>
              <div class="flex-1 min-w-0 space-y-2" id="statusLegendList"></div>
            </div>
          </div>
        </div>

        <div class="bg-white border border-gray-200 shadow-sm overflow-hidden" style="border-radius:6px">
          <div class="p-4 border-b border-gray-100 flex items-center justify-between gap-3">
            <div class="min-w-0">
              <p class="text-[11px] font-bold text-gray-500 uppercase tracking-wide">Recent Orders</p>
              <p class="text-[10px] text-gray-400 mt-0.5">Your latest incoming orders</p>
            </div>
            <a href="./orders.php" class="text-[11px] font-semibold text-emerald-600 shrink-0">View All</a>
          </div>
          <div class="divide-y divide-gray-100" id="recentOrdersList"></div>
        </div>

        <div class="bg-white border border-gray-200 shadow-sm overflow-hidden" style="border-radius:6px">
          <div class="p-4 border-b border-gray-100 flex items-center justify-between gap-3">
            <div class="min-w-0">
              <p class="text-[11px] font-bold text-gray-500 uppercase tracking-wide">Best Selling Items</p>
              <p class="text-[10px] text-gray-400 mt-0.5">Most ordered this month</p>
            </div>
            <a href="./report.php" class="text-[11px] font-semibold text-emerald-600 shrink-0">View All</a>
          </div>
          <div class="divide-y divide-gray-100" id="topItemsList"></div>
        </div>

        <div class="bg-white border border-gray-200 shadow-sm overflow-hidden" style="border-radius:6px">
          <div class="p-4 border-b border-gray-100">
            <p class="text-[11px] font-bold text-gray-500 uppercase tracking-wide">Top Customers</p>
            <p class="text-[10px] text-gray-400 mt-0.5">Based on total spend this month</p>
          </div>
          <div class="divide-y divide-gray-100" id="topCustomersList"></div>
        </div>
      </div>
    </div>

    <div
      class="bg-white border-t border-gray-200 flex-shrink-0 fixed bottom-0 left-0 right-0 z-20">
      <div class="max-w-5xl mx-auto px-4 flex justify-around py-2">
        <a
          href="./dashboard.php"
          class="flex flex-col items-center justify-center py-2 px-3 transition-all duration-200 group text-emerald-600 bg-emerald-50"
          style="border-radius: 3px">
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
              d="M3.75 6A2.25 2.25 0 0 1 6 3.75h2.25A2.25 2.25 0 0 1 10.5 6v2.25a2.25 2.25 0 0 1-2.25 2.25H6a2.25 2.25 0 0 1-2.25-2.25V6ZM3.75 15.75A2.25 2.25 0 0 1 6 13.5h2.25a2.25 2.25 0 0 1 2.25 2.25V18a2.25 2.25 0 0 1-2.25 2.25H6A2.25 2.25 0 0 1 3.75 18v-2.25ZM13.5 6a2.25 2.25 0 0 1 2.25-2.25H18A2.25 2.25 0 0 1 20.25 6v2.25A2.25 2.25 0 0 1 18 10.5h-2.25a2.25 2.25 0 0 1-2.25-2.25V6ZM13.5 15.75a2.25 2.25 0 0 1 2.25-2.25H18a2.25 2.25 0 0 1 2.25 2.25V18A2.25 2.25 0 0 1 18 20.25h-2.25A2.25 2.25 0 0 1 13.5 18v-2.25Z" />
          </svg>
          <span class="text-xs font-medium mt-1">Dashboard</span>
        </a>
        <a
          href="./menu.php"
          class="flex flex-col items-center justify-center py-2 px-3 transition-all duration-200 group text-gray-500 hover:text-gray-900 hover:bg-gray-50"
          style="border-radius: 3px">
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
              d="M3.75 12h16.5m-16.5 3.75h16.5M3.75 19.5h16.5M5.625 4.5h12.75a1.875 1.875 0 0 1 0 3.75H5.625a1.875 1.875 0 0 1 0-3.75Z" />
          </svg>
          <span class="text-xs font-medium mt-1">Menu</span>
        </a>
        <a
          href="./orders.php"
          class="flex flex-col items-center justify-center py-2 px-3 transition-all duration-200 group text-gray-500 hover:text-gray-900 hover:bg-gray-50"
          style="border-radius: 3px">
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
          href="./account.php"
          class="flex flex-col items-center justify-center py-2 px-3 transition-all duration-200 group text-gray-500 hover:text-gray-900 hover:bg-gray-50"
          style="border-radius: 3px">
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

  <script>
    const dashboardStats = <?php echo json_encode($dashboardStats); ?>;
    const ORDER_STATUS_BREAKDOWN = <?php echo json_encode($orderStatusBreakdown); ?>;
    const RECENT_ORDERS = <?php echo json_encode($recentOrders); ?>;
    const TOP_CUSTOMERS = <?php echo json_encode($topCustomers); ?>;
    const TOP_ITEMS = <?php echo json_encode($bestSellingItems); ?>;
    const VAPID_PUBLIC_KEY = "<?php echo VAPID_PUBLIC_KEY; ?>";

    let ALL_NOTIFICATIONS = <?php echo json_encode($notifications); ?>;
    let notifUnreadCount = <?php echo (int) $unreadNotifCount; ?>;
    let currentStatusType = "delivery";

    const DASHBOARD_STATS = [{
        label: "Today's Orders",
        value: String(dashboardStats.todaysOrders),
        accent: "blue",
        href: "./orders.php",
        icon: '<path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 0 0 2.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 0 0-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 0 0 .75-.75 2.25 2.25 0 0 0-.1-.664m-5.8 0A2.251 2.251 0 0 1 13.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25Z" />',
      },
      {
        label: "Pending Orders",
        value: String(dashboardStats.pendingOrders),
        accent: "amber",
        href: "./orders.php",
        icon: '<path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />',
      },
      {
        label: "Today's Sales",
        value: "₱3,250.00",
        accent: "emerald",
        href: "./report.php",
        icon: '<path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25v10.5A2.25 2.25 0 0 0 4.5 19.5Z" />',
      },
      {
        label: "Menu Items",
        value: String(dashboardStats.menuItemsCount),
        accent: "purple",
        href: "./menu.php",
        icon: '<path stroke-linecap="round" stroke-linejoin="round" d="M3.75 12h16.5m-16.5 3.75h16.5M3.75 19.5h16.5M5.625 4.5h12.75a1.875 1.875 0 0 1 0 3.75H5.625a1.875 1.875 0 0 1 0-3.75Z" />',
      },
    ];

    const ACCENT_MAP = {
      emerald: {
        card: "bg-white",
        border: "border-gray-200",
        chip: "bg-emerald-50",
        icon: "text-emerald-600",
        value: "text-gray-800",
        label: "text-gray-400"
      },
      amber: {
        card: "bg-white",
        border: "border-gray-200",
        chip: "bg-amber-50",
        icon: "text-amber-600",
        value: "text-gray-800",
        label: "text-gray-400"
      },
      blue: {
        card: "bg-white",
        border: "border-gray-200",
        chip: "bg-blue-50",
        icon: "text-blue-600",
        value: "text-gray-800",
        label: "text-gray-400"
      },
      purple: {
        card: "bg-white",
        border: "border-gray-200",
        chip: "bg-purple-50",
        icon: "text-purple-600",
        value: "text-gray-800",
        label: "text-gray-400"
      },
    };

    const CUSTOMER_TYPE_MAP = {
      student: {
        label: "Student",
        cls: "bg-sky-50 text-sky-700 border-sky-200"
      },
      faculty: {
        label: "Faculty",
        cls: "bg-violet-50 text-violet-700 border-violet-200"
      },
      staff: {
        label: "Staff",
        cls: "bg-teal-50 text-teal-700 border-teal-200"
      },
      guest: {
        label: "Guest",
        cls: "bg-zinc-100 text-zinc-600 border-zinc-200"
      },
    };

    const ORDER_STATUS_MAP = {
      pending: {
        label: "Pending",
        cls: "bg-amber-50 text-amber-700 border-amber-200"
      },
      preparing: {
        label: "Preparing",
        cls: "bg-blue-50 text-blue-700 border-blue-200"
      },
      ready_for_pickup: {
        label: "Ready",
        cls: "bg-indigo-50 text-indigo-700 border-indigo-200"
      },
      ready_for_dispatch: {
        label: "Ready for Dispatch",
        cls: "bg-indigo-50 text-indigo-700 border-indigo-200"
      },
      collected: {
        label: "Collected",
        cls: "bg-blue-50 text-blue-700 border-blue-200"
      },
      out_for_delivery: {
        label: "Out for Delivery",
        cls: "bg-blue-50 text-blue-700 border-blue-200"
      },
      completed: {
        label: "Completed",
        cls: "bg-emerald-50 text-emerald-700 border-emerald-200"
      },
      delivered: {
        label: "Delivered",
        cls: "bg-emerald-50 text-emerald-700 border-emerald-200"
      },
      cancelled: {
        label: "Cancelled",
        cls: "bg-gray-100 text-gray-500 border-gray-200"
      },
    };

    const RANK_BADGE_CLS = [
      "bg-amber-400 text-amber-900",
      "bg-gray-300 text-gray-700",
      "bg-orange-300 text-orange-900",
    ];

    const STATUS_CHART_META = {
      pending: { label: "Pending", hex: "#f59e0b", dot: "bg-amber-500" },
      preparing: { label: "Preparing", hex: "#3b82f6", dot: "bg-blue-500" },
      ready_for_dispatch: { label: "Ready for Dispatch", hex: "#6366f1", dot: "bg-indigo-500" },
      ready_for_pickup: { label: "Ready for Pickup", hex: "#6366f1", dot: "bg-indigo-500" },
      collected: { label: "Collected", hex: "#0ea5e9", dot: "bg-sky-500" },
      out_for_delivery: { label: "Out for Delivery", hex: "#0284c7", dot: "bg-sky-600" },
      delivered: { label: "Delivered", hex: "#10b981", dot: "bg-emerald-500" },
      completed: { label: "Completed", hex: "#10b981", dot: "bg-emerald-500" },
      cancelled: { label: "Cancelled", hex: "#d1d5db", dot: "bg-gray-300" },
    };

    function escapeHtml(str) {
      if (!str) return "";
      return String(str)
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;");
    }

    async function postAction(action, data = {}) {
      const formData = new FormData();
      formData.append("action", action);
      for (const key in data) {
        formData.append(key, data[key] === null || data[key] === undefined ? "" : data[key]);
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

    async function toggleStallStatus() {
      const toggle = document.getElementById("stallStatusToggle");
      const dot = document.getElementById("stallStatusDot");
      const label = document.getElementById("stallStatusLabel");
      const newStatus = toggle.checked ? "open" : "closed";

      toggle.disabled = true;
      const res = await postAction("toggle_stall_status", { status: newStatus });
      toggle.disabled = false;

      if (!res.success) {
        toggle.checked = !toggle.checked;
        alert(res.message || "Something went wrong. Please try again.");
        return;
      }

      if (res.status === "open") {
        dot.classList.remove("bg-gray-300");
        dot.classList.add("bg-emerald-500");
        label.textContent = "Open — visible to customers";
      } else {
        dot.classList.remove("bg-emerald-500");
        dot.classList.add("bg-gray-300");
        label.textContent = "Closed — hidden from customers";
      }
    }

    function setupStallStatusToggle() {
      const toggle = document.getElementById("stallStatusToggle");
      if (toggle) {
        toggle.addEventListener("change", toggleStallStatus);
      }
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
      return `<div class="${sizeCls} bg-gradient-to-br from-emerald-500 to-emerald-700 flex items-center justify-center text-white text-[11px] sm:text-xs font-bold shrink-0 rounded-full">${getInitials(name)}</div>`;
    }

    function customerTypeBadge(type) {
      const t = CUSTOMER_TYPE_MAP[type];
      if (!t) return "";
      return `<span class="text-[10px] font-semibold px-2 py-0.5 border rounded-[3px] ${t.cls}">${t.label}</span>`;
    }

    function orderStatusBadge(status) {
      const s = ORDER_STATUS_MAP[status] || ORDER_STATUS_MAP.pending;
      return `<span class="text-[10px] font-semibold px-2 py-0.5 border ${s.cls}" style="border-radius:3px">${s.label}</span>`;
    }

    function renderStats() {
      const grid = document.getElementById("statsGrid");
      grid.innerHTML = DASHBOARD_STATS.map((stat) => {
        const a = ACCENT_MAP[stat.accent];
        return `
            <a href="${stat.href}" class="${a.card} border ${a.border} shadow-sm p-3 flex items-center gap-2.5 transition-all hover:shadow-md hover:-translate-y-0.5" style="border-radius:6px">
              <div class="w-9 h-9 ${a.chip} flex items-center justify-center shrink-0" style="border-radius:3px">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 ${a.icon}">
                  ${stat.icon}
                </svg>
              </div>
              <div class="min-w-0 flex-1">
                <p class="text-sm sm:text-lg font-bold ${a.value} leading-tight truncate">${stat.value}</p>
                <p class="text-[10px] sm:text-[11px] ${a.label} mt-0.5 truncate">${stat.label}</p>
              </div>
            </a>
          `;
      }).join("");
    }

    function renderRecentOrders() {
      const list = document.getElementById("recentOrdersList");
      if (!RECENT_ORDERS.length) {
        list.innerHTML = `
            <div class="px-4 py-8 text-center">
              <p class="text-xs text-gray-400">No orders yet today.</p>
            </div>
          `;
        return;
      }
      list.innerHTML = RECENT_ORDERS.map(
        (o) => `
            <a href="./orders.php" class="flex items-center gap-2.5 px-3 py-2.5 sm:px-4 sm:py-3 hover:bg-gray-50">
              ${personAvatarHtml(o.customerImage, o.customer, "w-8 h-8 sm:w-9 sm:h-9")}
              <div class="flex-1 min-w-0">
                <p class="text-xs font-semibold text-gray-800 truncate flex items-center gap-1.5">
                  <span class="truncate">${escapeHtml(o.customer)}</span>
                  ${customerTypeBadge(o.customerType)}
                </p>
                <p class="text-[10px] text-gray-400 truncate mt-0.5">${escapeHtml(o.items)}</p>
              </div>
              <div class="flex flex-col items-end gap-1 shrink-0">
                ${orderStatusBadge(o.status)}
                <span class="text-[10px] text-gray-400 whitespace-nowrap">&#8369;${o.total.toFixed(2)} &middot; ${escapeHtml(o.time)}</span>
              </div>
            </a>
          `,
      ).join('<div class="border-t border-gray-100"></div>');
    }

    function renderTopItems() {
      const list = document.getElementById("topItemsList");
      if (!TOP_ITEMS.length) {
        list.innerHTML = `
            <div class="px-4 py-8 text-center">
              <p class="text-xs text-gray-400">No item data yet.</p>
            </div>
          `;
        return;
      }
      list.innerHTML = TOP_ITEMS.map(
        (it, i) => `
            <div class="flex items-center gap-2.5 px-3 py-2.5 sm:px-4 sm:py-3">
              <div class="relative shrink-0">
                <div class="w-9 h-9 sm:w-10 sm:h-10 overflow-hidden bg-gray-100 rounded-full">
                  ${it.img ? `<img src="${escapeHtml(it.img)}" alt="${escapeHtml(it.name)}" class="w-full h-full object-cover" />` : `<div class="w-full h-full bg-gradient-to-br from-emerald-500 to-emerald-700 flex items-center justify-center text-white text-xs font-bold">${getInitials(it.name)}</div>`}
                </div>
                <span class="absolute -bottom-1 -right-1 w-[18px] h-[18px] sm:w-5 sm:h-5 flex items-center justify-center text-[9px] sm:text-[10px] font-bold rounded-full ${RANK_BADGE_CLS[i] || "bg-gray-200 text-gray-600"}">${i + 1}</span>
              </div>
              <div class="flex-1 min-w-0">
                <p class="text-xs font-semibold text-gray-800 truncate">${escapeHtml(it.name)}</p>
                <p class="text-[10px] text-gray-400 truncate mt-0.5">${escapeHtml(it.category)}</p>
              </div>
              <span class="text-xs font-semibold text-gray-900 shrink-0 whitespace-nowrap">${it.orders} orders</span>
            </div>
          `,
      ).join('<div class="border-t border-gray-100"></div>');
    }

    function renderTopCustomers() {
      const list = document.getElementById("topCustomersList");
      if (!TOP_CUSTOMERS.length) {
        list.innerHTML = `
            <div class="px-4 py-8 text-center">
              <p class="text-xs text-gray-400">No customer data yet.</p>
            </div>
          `;
        return;
      }
      list.innerHTML = TOP_CUSTOMERS.map(
        (c, i) => `
            <div class="flex items-center gap-2.5 px-3 py-2.5 sm:px-4 sm:py-3">
              <div class="relative shrink-0">
                ${personAvatarHtml(c.customerImage, c.name, "w-8 h-8 sm:w-9 sm:h-9")}
                <span class="absolute -bottom-1 -right-1 w-[18px] h-[18px] sm:w-5 sm:h-5 flex items-center justify-center text-[9px] sm:text-[10px] font-bold rounded-full ${RANK_BADGE_CLS[i] || "bg-gray-200 text-gray-600"}">${i + 1}</span>
              </div>
              <div class="flex-1 min-w-0">
                <p class="text-xs font-semibold text-gray-800 truncate flex items-center gap-1.5">
                  <span class="truncate">${escapeHtml(c.name)}</span>
                  ${customerTypeBadge(c.customerType)}
                </p>
                <p class="text-[10px] text-gray-400 truncate mt-0.5">${c.orders} orders</p>
              </div>
              <span class="text-xs font-semibold text-gray-900 shrink-0 whitespace-nowrap">&#8369;${c.totalSpend.toFixed(2)}</span>
            </div>
          `,
      ).join('<div class="border-t border-gray-100"></div>');
    }

    function moveStatusTypeIndicator(activeBtn) {
      const indicator = document.getElementById("statusTypeIndicator");
      const container = document.getElementById("statusTypeTabs");
      if (!indicator || !container || !activeBtn) return;
      const containerRect = container.getBoundingClientRect();
      const btnRect = activeBtn.getBoundingClientRect();
      indicator.style.left = (btnRect.left - containerRect.left) + "px";
      indicator.style.width = btnRect.width + "px";
    }

    function renderStatusTypeTabStyles() {
      document.querySelectorAll(".status-type-tab").forEach((btn) => {
        const isActive = btn.dataset.type === currentStatusType;
        btn.classList.toggle("text-emerald-700", isActive);
        btn.classList.toggle("text-gray-500", !isActive);
      });
    }

    function renderStatusDonut(type) {
      const svg = document.getElementById("statusDonutSvg");
      const totalEl = document.getElementById("statusDonutTotal");
      const legend = document.getElementById("statusLegendList");
      const subtitle = document.getElementById("statusOverviewSubtitle");
      const data = ORDER_STATUS_BREAKDOWN[type] || {};

      const totalCount = Object.values(data).reduce((sum, v) => sum + v, 0);
      subtitle.textContent = totalCount + " " + type + " order" + (totalCount === 1 ? "" : "s") + " today";
      totalEl.textContent = totalCount;

      const entries = Object.keys(data)
        .map((key) => ({
          key,
          count: data[key],
          meta: STATUS_CHART_META[key] || { label: key, hex: "#d1d5db", dot: "bg-gray-300" },
        }))
        .filter((e) => e.count > 0);

      if (totalCount === 0 || entries.length === 0) {
        svg.innerHTML = `<circle cx="60" cy="60" r="45" fill="none" stroke="#f3f4f6" stroke-width="14" />`;
        legend.innerHTML = `<p class="text-xs text-gray-400 text-center py-6">No ${type} orders today.</p>`;
        return;
      }

      const radius = 45;
      const circumference = 2 * Math.PI * radius;
      let cumulative = 0;

      const segmentsHtml = entries.map((e) => {
        const fraction = e.count / totalCount;
        const arcLength = fraction * circumference;
        const dashOffset = -cumulative;
        cumulative += arcLength;
        return `<circle
            cx="60" cy="60" r="${radius}" fill="none"
            stroke="${e.meta.hex}" stroke-width="14" stroke-linecap="butt"
            stroke-dashoffset="${dashOffset}"
            class="donut-segment"
            style="stroke-dasharray: 0 ${circumference};"
            data-target-arc="${arcLength}"
            data-gap="${circumference - arcLength}"></circle>`;
      });

      svg.innerHTML = `<circle cx="60" cy="60" r="${radius}" fill="none" stroke="#f3f4f6" stroke-width="14" />` + segmentsHtml.join("");

      legend.innerHTML = entries.map((e) => {
        const pct = Math.round((e.count / totalCount) * 100);
        return `
            <div class="status-legend-row flex items-center justify-between text-[11px]" style="opacity:0">
              <div class="flex items-center gap-1.5 min-w-0">
                <span class="w-2 h-2 rounded-full ${e.meta.dot} shrink-0"></span>
                <span class="text-gray-600 truncate">${escapeHtml(e.meta.label)}</span>
              </div>
              <span class="font-semibold text-gray-800 shrink-0 whitespace-nowrap">${e.count} <span class="text-gray-400 font-normal">(${pct}%)</span></span>
            </div>
          `;
      }).join("");

      requestAnimationFrame(() => {
        svg.querySelectorAll(".donut-segment").forEach((seg) => {
          seg.style.strokeDasharray = `${seg.dataset.targetArc} ${seg.dataset.gap}`;
        });
        legend.querySelectorAll(".status-legend-row").forEach((row) => {
          row.style.opacity = "1";
        });
      });
    }

    function switchStatusType(type, btnEl) {
      if (type === currentStatusType) return;
      currentStatusType = type;
      renderStatusTypeTabStyles();
      moveStatusTypeIndicator(btnEl);
      renderStatusDonut(type);
    }

    function setupStatusOverview() {
      document.querySelectorAll(".status-type-tab").forEach((btn) => {
        btn.addEventListener("click", () => switchStatusType(btn.dataset.type, btn));
      });

      const deliveryBtn = document.querySelector('.status-type-tab[data-type="delivery"]');
      renderStatusTypeTabStyles();
      moveStatusTypeIndicator(deliveryBtn);
      renderStatusDonut("delivery");

      window.addEventListener("resize", () => {
        const activeTypeBtn = document.querySelector(`.status-type-tab[data-type="${currentStatusType}"]`);
        if (activeTypeBtn) moveStatusTypeIndicator(activeTypeBtn);
      });
    }

    function updateNotifBadge(count) {
      const badge = document.getElementById("notifBadge");
      notifUnreadCount = count;
      if (count > 0) {
        badge.textContent = count > 99 ? "99+" : String(count);
        badge.classList.remove("hidden");
      } else {
        badge.classList.add("hidden");
      }
    }

    function renderNotifications() {
      const list = document.getElementById("notifList");
      if (!ALL_NOTIFICATIONS.length) {
        list.innerHTML = `
            <div class="px-4 py-8 text-center">
              <p class="text-xs text-gray-400">No notifications yet.</p>
            </div>
          `;
        return;
      }
      list.innerHTML = ALL_NOTIFICATIONS.map(
        (n) => `
            <a href="${n.link ? escapeHtml(n.link) : '#'}" class="notif-item block px-3 py-2.5 hover:bg-gray-50 ${n.isRead ? '' : 'bg-emerald-50/40'}" data-id="${n.id}">
              <div class="flex items-start gap-2">
                <span class="w-1.5 h-1.5 rounded-full mt-1.5 shrink-0 ${n.isRead ? '' : 'bg-emerald-500'}"></span>
                <div class="flex-1 min-w-0">
                  <p class="text-xs font-semibold text-gray-800">${escapeHtml(n.title)}</p>
                  <p class="text-[11px] text-gray-500 mt-0.5">${escapeHtml(n.message)}</p>
                  <p class="text-[10px] text-gray-400 mt-1">${escapeHtml(n.time)}</p>
                </div>
              </div>
            </a>
          `,
      ).join("");

      document.querySelectorAll(".notif-item").forEach((item) => {
        item.addEventListener("click", async () => {
          const notifId = parseInt(item.getAttribute("data-id"));
          const res = await postAction("mark_notification_read", { notification_id: notifId });
          if (res.success) {
            ALL_NOTIFICATIONS = res.notifications;
            renderNotifications();
            updateNotifBadge(res.unreadCount);
          }
        });
      });
    }

    function setupNotifications() {
      renderNotifications();
      updateNotifBadge(notifUnreadCount);

      document.getElementById("notifBtn").addEventListener("click", (e) => {
        e.stopPropagation();
        document.getElementById("notifDropdown").classList.toggle("hidden");
      });

      document.getElementById("markAllReadBtn").addEventListener("click", async (e) => {
        e.stopPropagation();
        const res = await postAction("mark_all_notifications_read");
        if (res.success) {
          ALL_NOTIFICATIONS = res.notifications;
          renderNotifications();
          updateNotifBadge(res.unreadCount);
        }
      });

      document.addEventListener("click", (e) => {
        const dropdown = document.getElementById("notifDropdown");
        if (!e.target.closest("#notifBtn") && !e.target.closest("#notifDropdown")) {
          dropdown.classList.add("hidden");
        }
      });
    }

    function setupMessageButton() {
      document.getElementById("messageBtn").addEventListener("click", () => {});
    }

    function urlBase64ToUint8Array(base64String) {
      const padding = "=".repeat((4 - (base64String.length % 4)) % 4);
      const base64 = (base64String + padding).replace(/-/g, "+").replace(/_/g, "/");
      const rawData = window.atob(base64);
      const outputArray = new Uint8Array(rawData.length);
      for (let i = 0; i < rawData.length; i++) {
        outputArray[i] = rawData.charCodeAt(i);
      }
      return outputArray;
    }

    async function subscribeToPush() {
      try {
        const registration = await navigator.serviceWorker.register("../service-worker.js");
        await navigator.serviceWorker.ready;

        const existingSubscription = await registration.pushManager.getSubscription();
        const subscription = existingSubscription || (await registration.pushManager.subscribe({
          userVisibleOnly: true,
          applicationServerKey: urlBase64ToUint8Array(VAPID_PUBLIC_KEY),
        }));

        await fetch("../save-push-subscription.php", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify(subscription),
        });

        return true;
      } catch (err) {
        return false;
      }
    }

    async function setupPushPromptBanner() {
      if ("serviceWorker" in navigator) {
        navigator.serviceWorker.register("../service-worker.js");
      }

      if (!("serviceWorker" in navigator) || !("PushManager" in window)) {
        return;
      }

      if (Notification.permission === "granted") {
        const registration = await navigator.serviceWorker.getRegistration();
        const existingSubscription = registration && (await registration.pushManager.getSubscription());

        if (existingSubscription) {
          await fetch("../save-push-subscription.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify(existingSubscription),
          });
          return;
        }

        if (localStorage.getItem("notificationsOptedOut") === "1") {
          return;
        }

        subscribeToPush();
        return;
      }

      if (Notification.permission === "denied") {
        return;
      }

      if (localStorage.getItem("pushPromptDismissed") === "1") {
        return;
      }

      const banner = document.getElementById("pushPromptBanner");
      banner.classList.remove("hidden");

      document.getElementById("pushEnableBtn").addEventListener("click", async () => {
        const granted = await Notification.requestPermission();
        banner.classList.add("hidden");
        if (granted === "granted") {
          localStorage.removeItem("notificationsOptedOut");
          await subscribeToPush();
        }
      });

      document.getElementById("pushDismissBtn").addEventListener("click", () => {
        banner.classList.add("hidden");
        localStorage.setItem("pushPromptDismissed", "1");
      });
    }

    function init() {
      renderStats();
      renderRecentOrders();
      renderTopItems();
      renderTopCustomers();
      setupStatusOverview();
      setupNotifications();
      setupMessageButton();
      setupPushPromptBanner();
      setupStallStatusToggle();
    }

    window.addEventListener("load", init);
  </script>
</body>

</html>