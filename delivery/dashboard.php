<?php
session_start();
require_once '../config/database.php';
require_once '../config/vapid.php';

if (!isset($_SESSION['staff_id'])) {
  header('Location: ../auth/login.php');
  exit;
}

$staffId = $_SESSION['staff_id'];

$stmt = $conn->prepare("SELECT first_name, last_name, profile_image FROM delivery_staff WHERE staff_id = ? LIMIT 1");
$stmt->bind_param("s", $staffId);
$stmt->execute();
$result = $stmt->get_result();
$staff = $result->fetch_assoc();
$stmt->close();

if (!$staff) {
  $conn->close();
  header('Location: ../auth/login.php');
  exit;
}

$staffFirstName = $staff['first_name'];
$staffLastName = $staff['last_name'];
$staffFullName = $staffFirstName . ' ' . $staffLastName;
$staffProfileImage = $staff['profile_image'] ? '../' . $staff['profile_image'] : null;

function getStaffInitials($first, $last)
{
  $f = mb_substr(trim($first), 0, 1);
  $l = mb_substr(trim($last), 0, 1);
  return mb_strtoupper($f . $l);
}

$staffInitials = getStaffInitials($staffFirstName, $staffLastName);

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

function fetchDashboardStats($conn, $staffId)
{
  $stats = [
    'todaysDeliveries' => 0,
    'pendingPickups' => 0,
    'completedToday' => 0,
  ];

  $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM orders WHERE staff_id = ? AND order_type = 'delivery' AND status NOT IN ('pending', 'preparing') AND DATE(created_at) = CURDATE()");
  $stmt->bind_param("s", $staffId);
  $stmt->execute();
  $stats['todaysDeliveries'] = (int) $stmt->get_result()->fetch_assoc()['cnt'];
  $stmt->close();

  $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM orders WHERE staff_id = ? AND status = 'ready_for_dispatch'");
  $stmt->bind_param("s", $staffId);
  $stmt->execute();
  $stats['pendingPickups'] = (int) $stmt->get_result()->fetch_assoc()['cnt'];
  $stmt->close();

  $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM orders WHERE staff_id = ? AND status = 'delivered' AND DATE(proof_captured_at) = CURDATE()");
  $stmt->bind_param("s", $staffId);
  $stmt->execute();
  $stats['completedToday'] = (int) $stmt->get_result()->fetch_assoc()['cnt'];
  $stmt->close();

  return $stats;
}

function fetchOrderStatusBreakdown($conn, $staffId)
{
  $breakdown = [
    'ready_for_dispatch' => 0,
    'collected' => 0,
    'out_for_delivery' => 0,
    'delivered' => 0,
    'cancelled' => 0,
  ];

  $stmt = $conn->prepare("SELECT status, COUNT(*) AS cnt FROM orders WHERE staff_id = ? AND order_type = 'delivery' AND DATE(created_at) = CURDATE() GROUP BY status");
  $stmt->bind_param("s", $staffId);
  $stmt->execute();
  $result = $stmt->get_result();

  while ($row = $result->fetch_assoc()) {
    if (isset($breakdown[$row['status']])) {
      $breakdown[$row['status']] = (int) $row['cnt'];
    }
  }
  $stmt->close();

  return $breakdown;
}

function fetchActiveDeliveries($conn, $staffId)
{
  $stmt = $conn->prepare("
        SELECT o.status, o.drop_off_location, o.created_at,
               c.first_name, c.last_name, c.customer_type
        FROM orders o
        JOIN customers c ON o.customer_id = c.customer_id
        WHERE o.staff_id = ?
          AND o.order_type = 'delivery'
          AND o.status IN ('ready_for_dispatch', 'collected', 'out_for_delivery')
        ORDER BY o.created_at ASC
    ");
  $stmt->bind_param("s", $staffId);
  $stmt->execute();
  $result = $stmt->get_result();

  $deliveries = [];
  while ($row = $result->fetch_assoc()) {
    $deliveries[] = [
      'customer' => trim($row['first_name'] . ' ' . $row['last_name']),
      'customerType' => $row['customer_type'],
      'location' => $row['drop_off_location'],
      'status' => $row['status'],
      'time' => 'Assigned ' . formatRelativeTime($row['created_at']),
    ];
  }
  $stmt->close();

  return $deliveries;
}

function fetchRecentDeliveries($conn, $staffId, $limit = 5)
{
  $stmt = $conn->prepare("
        SELECT o.status, o.drop_off_location, o.total_delivery_fee,
               o.created_at, o.proof_captured_at, o.cancelled_at,
               c.first_name, c.last_name, c.customer_type
        FROM orders o
        JOIN customers c ON o.customer_id = c.customer_id
        WHERE o.staff_id = ?
          AND o.order_type = 'delivery'
          AND o.status IN ('delivered', 'cancelled')
        ORDER BY COALESCE(o.proof_captured_at, o.cancelled_at, o.created_at) DESC
        LIMIT ?
    ");
  $stmt->bind_param("si", $staffId, $limit);
  $stmt->execute();
  $result = $stmt->get_result();

  $deliveries = [];
  while ($row = $result->fetch_assoc()) {
    $completedAt = $row['proof_captured_at'] ?? $row['cancelled_at'] ?? $row['created_at'];
    $deliveries[] = [
      'customer' => trim($row['first_name'] . ' ' . $row['last_name']),
      'customerType' => $row['customer_type'],
      'location' => $row['drop_off_location'],
      'status' => $row['status'],
      'earning' => (float) $row['total_delivery_fee'],
      'time' => formatRelativeTime($completedAt),
    ];
  }
  $stmt->close();

  return $deliveries;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
  header('Content-Type: application/json');
  $action = $_POST['action'];

  if ($action === 'mark_notification_read') {
    $notifId = (int) ($_POST['notification_id'] ?? 0);

    $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE notification_id = ? AND user_type = 'delivery_staff' AND user_id = ?");
    $stmt->bind_param("ii", $notifId, $staffId);
    $stmt->execute();
    $stmt->close();

    echo json_encode([
      'success' => true,
      'notifications' => fetchNotifications($conn, 'delivery_staff', $staffId, 10),
      'unreadCount' => countUnreadNotifications($conn, 'delivery_staff', $staffId),
    ]);
    $conn->close();
    exit;
  }

  if ($action === 'mark_all_notifications_read') {
    $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE user_type = 'delivery_staff' AND user_id = ?");
    $stmt->bind_param("i", $staffId);
    $stmt->execute();
    $stmt->close();

    echo json_encode([
      'success' => true,
      'notifications' => fetchNotifications($conn, 'delivery_staff', $staffId, 10),
      'unreadCount' => 0,
    ]);
    $conn->close();
    exit;
  }

  echo json_encode(['success' => false, 'message' => 'Unknown action.']);
  $conn->close();
  exit;
}

$notifications = fetchNotifications($conn, 'delivery_staff', $staffId, 10);
$unreadNotifCount = countUnreadNotifications($conn, 'delivery_staff', $staffId);
$dashboardStats = fetchDashboardStats($conn, $staffId);
$orderStatusBreakdown = fetchOrderStatusBreakdown($conn, $staffId);
$activeDeliveries = fetchActiveDeliveries($conn, $staffId);
$recentDeliveries = fetchRecentDeliveries($conn, $staffId, 5);
$conn->close();
?>
<!doctype html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta
    name="viewport"
    content="width=device-width, initial-scale=1.0, user-scalable=yes" />
  <title>Delivery Staff - Dashboard</title>
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
  </style>
</head>

<body class="bg-white">
  <div class="flex flex-col h-screen">
    <div class="bg-white flex-shrink-0 fixed top-0 left-0 right-0 z-20">
      <div class="max-w-5xl mx-auto px-4 py-2 flex items-center justify-between gap-3">
        <div class="flex items-center gap-2.5 min-w-0">
          <a
            href="./account.php"
            id="accountBtn"
            class="rounded-full shrink-0 block w-9 h-9 overflow-hidden border border-gray-200 hover:border-emerald-500 transition-all"
            title="Account">
            <?php if ($staffProfileImage): ?>
              <img
                src="<?php echo htmlspecialchars($staffProfileImage); ?>"
                alt="<?php echo htmlspecialchars($staffFullName); ?>"
                class="w-full h-full object-cover" />
            <?php else: ?>
              <div class="w-full h-full bg-gradient-to-br from-emerald-500 to-emerald-700 flex items-center justify-center text-white font-bold text-xs">
                <?php echo htmlspecialchars($staffInitials); ?>
              </div>
            <?php endif; ?>
          </a>
          <div class="min-w-0">
            <p class="text-sm font-bold text-gray-800 leading-tight truncate">
              Hello, <span class="text-emerald-600"><?php echo htmlspecialchars($staffFirstName); ?></span>! 👋
            </p>
            <p class="text-[10px] text-gray-400 leading-none mt-0.5">Ready for today's deliveries?</p>
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
              <span id="notifBadge" class="<?php echo $unreadNotifCount > 0 ? '' : 'hidden'; ?> absolute -top-1 -right-1 min-w-[16px] h-4 px-1 bg-red-500 text-white text-[9px] font-bold flex items-center justify-center rounded-full">
                <?php echo $unreadNotifCount > 99 ? '99+' : $unreadNotifCount; ?>
              </span>
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
        <div id="pushPromptBanner" class="hidden bg-emerald-50 border border-emerald-200 p-3 rounded-md">
          <div class="flex items-center gap-2.5">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 text-emerald-600 shrink-0">
              <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0" />
            </svg>
            <div class="flex-1 min-w-0">
              <p class="text-xs font-semibold text-gray-800">Stay updated on new deliveries</p>
              <p class="text-[10px] text-gray-500 mt-0.5">Turn on notifications so you never miss an assignment.</p>
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

        <div class="bg-white border border-gray-200 shadow-sm overflow-hidden" style="border-radius: 6px">
          <div class="p-4 border-b border-gray-100">
            <p class="text-[11px] font-bold text-gray-500 uppercase tracking-wide">Quick Actions</p>
            <p class="text-[10px] text-gray-400 mt-0.5">Manage your deliveries in one tap</p>
          </div>
          <div class="p-3 grid grid-cols-4 gap-1.5">
            <a
              href="./deliveries.php"
              class="flex flex-col items-center justify-center gap-1 py-2 border border-gray-200 hover:border-emerald-500 hover:bg-emerald-50/40 transition-all rounded-[6px]">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 text-emerald-600">
                <path stroke-linecap="round" stroke-linejoin="round" d="m21 7.5-9-5.25L3 7.5m18 0-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9" />
              </svg>
              <span class="text-[10px] font-semibold text-gray-600 text-center">Deliveries</span>
            </a>
            <a
              href="./earnings.php"
              class="flex flex-col items-center justify-center gap-1 py-2 border border-gray-200 hover:border-emerald-500 hover:bg-emerald-50/40 transition-all rounded-[6px]">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 text-emerald-600">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z" />
              </svg>
              <span class="text-[10px] font-semibold text-gray-600 text-center">Earnings</span>
            </a>
            <a
              href="./history.php"
              class="flex flex-col items-center justify-center gap-1 py-2 border border-gray-200 hover:border-emerald-500 hover:bg-emerald-50/40 transition-all rounded-[6px]">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 text-emerald-600">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
              </svg>
              <span class="text-[10px] font-semibold text-gray-600 text-center">History</span>
            </a>
            <a
              href="./report.php"
              class="flex flex-col items-center justify-center gap-1 py-2 border border-gray-200 hover:border-emerald-500 hover:bg-emerald-50/40 transition-all rounded-[6px]">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 text-emerald-600">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" />
              </svg>
              <span class="text-[10px] font-semibold text-gray-600 text-center">Report</span>
            </a>
          </div>
        </div>

        <div class="bg-white border border-gray-200 shadow-sm overflow-hidden" style="border-radius: 6px">
          <div class="p-4 border-b border-gray-100">
            <p class="text-[11px] font-bold text-gray-500 uppercase tracking-wide">Delivery Status Breakdown</p>
            <p class="text-[10px] text-gray-400 mt-0.5" id="statusOverviewSubtitle">Your deliveries today</p>
          </div>
          <div class="p-4">
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

        <div class="bg-white border border-gray-200 shadow-sm overflow-hidden" style="border-radius: 6px">
          <div class="p-4 border-b border-gray-100 flex items-center justify-between">
            <div>
              <p class="text-[11px] font-bold text-gray-500 uppercase tracking-wide">Active Deliveries</p>
              <p class="text-[10px] text-gray-400 mt-0.5">Deliveries you're currently handling</p>
            </div>
            <a href="./deliveries.php" class="text-[11px] font-semibold text-emerald-600 shrink-0">View All</a>
          </div>
          <div class="divide-y divide-gray-100" id="activeDeliveriesList"></div>
        </div>

        <div class="bg-white border border-gray-200 shadow-sm overflow-hidden" style="border-radius: 6px">
          <div class="p-4 border-b border-gray-100 flex items-center justify-between">
            <div>
              <p class="text-[11px] font-bold text-gray-500 uppercase tracking-wide">Recent Deliveries</p>
              <p class="text-[10px] text-gray-400 mt-0.5">Your completed deliveries</p>
            </div>
            <a href="./history.php" class="text-[11px] font-semibold text-emerald-600 shrink-0">View All</a>
          </div>
          <div class="divide-y divide-gray-100" id="recentDeliveriesList"></div>
        </div>
      </div>
    </div>

    <div class="bg-white border-t border-gray-200 flex-shrink-0 fixed bottom-0 left-0 right-0 z-20">
      <div class="max-w-5xl mx-auto px-4 flex justify-around py-2">
        <a
          href="./dashboard.php"
          class="flex flex-col items-center justify-center py-2 px-3 transition-all duration-200 group text-emerald-600 bg-emerald-50"
          style="border-radius: 3px">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5">
            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 0 1 6 3.75h2.25A2.25 2.25 0 0 1 10.5 6v2.25a2.25 2.25 0 0 1-2.25 2.25H6a2.25 2.25 0 0 1-2.25-2.25V6ZM3.75 15.75A2.25 2.25 0 0 1 6 13.5h2.25a2.25 2.25 0 0 1 2.25 2.25V18a2.25 2.25 0 0 1-2.25 2.25H6A2.25 2.25 0 0 1 3.75 18v-2.25ZM13.5 6a2.25 2.25 0 0 1 2.25-2.25H18A2.25 2.25 0 0 1 20.25 6v2.25A2.25 2.25 0 0 1 18 10.5h-2.25a2.25 2.25 0 0 1-2.25-2.25V6ZM13.5 15.75a2.25 2.25 0 0 1 2.25-2.25H18a2.25 2.25 0 0 1 2.25 2.25V18A2.25 2.25 0 0 1 18 20.25h-2.25A2.25 2.25 0 0 1 13.5 18v-2.25Z" />
          </svg>
          <span class="text-xs font-medium mt-1">Dashboard</span>
        </a>
        <a
          href="./deliveries.php"
          class="flex flex-col items-center justify-center py-2 px-3 transition-all duration-200 group text-gray-500 hover:text-gray-900 hover:bg-gray-50"
          style="border-radius: 3px">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 transition-transform group-hover:scale-110">
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

  <script>
    let ALL_NOTIFICATIONS = <?php echo json_encode($notifications); ?>;
    let notifUnreadCount = <?php echo (int) $unreadNotifCount; ?>;
    const VAPID_PUBLIC_KEY = "<?php echo VAPID_PUBLIC_KEY; ?>";
    const ORDER_STATUS_BREAKDOWN = <?php echo json_encode($orderStatusBreakdown); ?>;
    const dashboardStats = <?php echo json_encode($dashboardStats); ?>;
    const ACTIVE_DELIVERIES = <?php echo json_encode($activeDeliveries); ?>;
    const RECENT_DELIVERIES = <?php echo json_encode($recentDeliveries); ?>;

    const DASHBOARD_STATS = [{
        label: "Today's Deliveries",
        value: String(dashboardStats.todaysDeliveries),
        accent: "blue",
        href: "./deliveries.php",
        icon: '<path stroke-linecap="round" stroke-linejoin="round" d="m21 7.5-9-5.25L3 7.5m18 0-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9" />',
      },
      {
        label: "Pending Pickups",
        value: String(dashboardStats.pendingPickups),
        accent: "amber",
        href: "./deliveries.php",
        icon: '<path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />',
      },
      {
        label: "Today's Earnings",
        value: "\u20b1480.00",
        accent: "emerald",
        href: "./earnings.php",
        icon: '<path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z" />',
      },
      {
        label: "Completed Today",
        value: String(dashboardStats.completedToday),
        accent: "purple",
        href: "./history.php",
        icon: '<path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />',
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

    const DELIVERY_STATUS_MAP = {
      ready_for_dispatch: {
        label: "Ready for Pickup",
        cls: "bg-indigo-50 text-indigo-700 border-indigo-200"
      },
      collected: {
        label: "Picked Up",
        cls: "bg-sky-50 text-sky-700 border-sky-200"
      },
      out_for_delivery: {
        label: "Out for Delivery",
        cls: "bg-blue-50 text-blue-700 border-blue-200"
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

    const STATUS_CHART_META = {
      ready_for_dispatch: { label: "Ready for Pickup", hex: "#6366f1", dot: "bg-indigo-500" },
      collected: { label: "Picked Up", hex: "#0284c7", dot: "bg-sky-600" },
      out_for_delivery: { label: "Out for Delivery", hex: "#0ea5e9", dot: "bg-sky-500" },
      delivered: { label: "Delivered", hex: "#10b981", dot: "bg-emerald-500" },
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

    function getInitials(name) {
      return String(name || "")
        .split(" ")
        .filter(Boolean)
        .map((w) => w[0])
        .join("")
        .slice(0, 2)
        .toUpperCase();
    }

    function customerTypeBadge(type) {
      const t = CUSTOMER_TYPE_MAP[type];
      if (!t) return "";
      return `<span class="text-[10px] font-semibold px-2 py-0.5 border rounded-[3px] ${t.cls}">${t.label}</span>`;
    }

    function deliveryStatusBadge(status) {
      const s = DELIVERY_STATUS_MAP[status] || DELIVERY_STATUS_MAP.delivered;
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

    function renderActiveDeliveries() {
      const list = document.getElementById("activeDeliveriesList");
      if (!ACTIVE_DELIVERIES.length) {
        list.innerHTML = `
            <div class="px-4 py-8 text-center">
              <p class="text-xs text-gray-400">No active deliveries right now.</p>
            </div>
          `;
        return;
      }
      list.innerHTML = ACTIVE_DELIVERIES.map(
        (d) => `
            <a href="./deliveries.php" class="flex items-center gap-2.5 px-3 py-2.5 sm:px-4 sm:py-3 hover:bg-gray-50">
              <div class="w-8 h-8 sm:w-9 sm:h-9 bg-gradient-to-br from-emerald-500 to-emerald-700 flex items-center justify-center text-white text-[11px] sm:text-xs font-bold shrink-0 rounded-full">${getInitials(d.customer)}</div>
              <div class="flex-1 min-w-0">
                <p class="text-xs font-semibold text-gray-800 truncate flex items-center gap-1.5">
                  <span class="truncate">${escapeHtml(d.customer)}</span>
                  ${customerTypeBadge(d.customerType)}
                </p>
                <p class="text-[10px] text-gray-400 truncate mt-0.5">${escapeHtml(d.location)}</p>
              </div>
              <div class="flex flex-col items-end gap-1 shrink-0">
                ${deliveryStatusBadge(d.status)}
                <span class="text-[10px] text-gray-400 whitespace-nowrap">${escapeHtml(d.time)}</span>
              </div>
            </a>
          `,
      ).join('<div class="border-t border-gray-100"></div>');
    }

    function renderRecentDeliveries() {
      const list = document.getElementById("recentDeliveriesList");
      if (!RECENT_DELIVERIES.length) {
        list.innerHTML = `
            <div class="px-4 py-8 text-center">
              <p class="text-xs text-gray-400">No deliveries yet.</p>
            </div>
          `;
        return;
      }
      list.innerHTML = RECENT_DELIVERIES.map(
        (d) => `
            <a href="./history.php" class="flex items-center gap-2.5 px-3 py-2.5 sm:px-4 sm:py-3 hover:bg-gray-50">
              <div class="w-8 h-8 sm:w-9 sm:h-9 bg-gradient-to-br from-emerald-500 to-emerald-700 flex items-center justify-center text-white text-[11px] sm:text-xs font-bold shrink-0 rounded-full">${getInitials(d.customer)}</div>
              <div class="flex-1 min-w-0">
                <p class="text-xs font-semibold text-gray-800 truncate flex items-center gap-1.5">
                  <span class="truncate">${escapeHtml(d.customer)}</span>
                  ${customerTypeBadge(d.customerType)}
                </p>
                <p class="text-[10px] text-gray-400 truncate mt-0.5">${escapeHtml(d.location)}</p>
              </div>
              <div class="flex flex-col items-end gap-1 shrink-0">
                ${deliveryStatusBadge(d.status)}
                <span class="text-[10px] text-gray-400 whitespace-nowrap">+\u20b1${d.earning.toFixed(2)} &middot; ${escapeHtml(d.time)}</span>
              </div>
            </a>
          `,
      ).join('<div class="border-t border-gray-100"></div>');
    }

    function renderStatusDonut() {
      const svg = document.getElementById("statusDonutSvg");
      const totalEl = document.getElementById("statusDonutTotal");
      const legend = document.getElementById("statusLegendList");
      const subtitle = document.getElementById("statusOverviewSubtitle");
      const data = ORDER_STATUS_BREAKDOWN;

      const totalCount = Object.values(data).reduce((sum, v) => sum + v, 0);
      subtitle.textContent = totalCount + " " + (totalCount === 1 ? "delivery" : "deliveries") + " today";
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
        legend.innerHTML = `<p class="text-xs text-gray-400 text-center py-6">No deliveries today.</p>`;
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

    function setupPushPromptBanner() {
      if ("serviceWorker" in navigator) {
        navigator.serviceWorker.register("../service-worker.js");
      }

      if (!("serviceWorker" in navigator) || !("PushManager" in window)) {
        return;
      }

      if (Notification.permission === "granted") {
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
      renderActiveDeliveries();
      renderRecentDeliveries();
      renderStatusDonut();
      setupNotifications();
      setupMessageButton();
      setupPushPromptBanner();
    }

    window.addEventListener("load", init);
  </script>
</body>

</html>