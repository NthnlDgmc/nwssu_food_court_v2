<?php
session_start();
require_once '../config/database.php';
require_once '../config/vapid.php';

if (!isset($_SESSION['customer_id'])) {
  header('Location: ../auth/login.php');
  exit;
}

$customerId = $_SESSION['customer_id'];

$statusCheckStmt = $conn->prepare("SELECT status, email, contact_number FROM customers WHERE customer_id = ? LIMIT 1");
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'mark_notification_read') {
  header('Content-Type: application/json');
  $notifId = (int) ($_POST['notification_id'] ?? 0);

  $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE notification_id = ? AND user_type = 'customer' AND user_id = ?");
  $stmt->bind_param("ii", $notifId, $customerId);
  $stmt->execute();
  $stmt->close();

  echo json_encode([
    'success' => true,
    'notifications' => fetchNotifications($conn, 'customer', $customerId, 10),
    'unreadCount' => countUnreadNotifications($conn, 'customer', $customerId),
  ]);
  $conn->close();
  exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'mark_all_notifications_read') {
  header('Content-Type: application/json');

  $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE user_type = 'customer' AND user_id = ?");
  $stmt->bind_param("i", $customerId);
  $stmt->execute();
  $stmt->close();

  echo json_encode([
    'success' => true,
    'notifications' => fetchNotifications($conn, 'customer', $customerId, 10),
    'unreadCount' => 0,
  ]);
  $conn->close();
  exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_to_cart') {
  header('Content-Type: application/json');

  $menuItemId = (int) ($_POST['menu_item_id'] ?? 0);

  if ($menuItemId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid item.']);
    $conn->close();
    exit;
  }

  $stmt = $conn->prepare("
        SELECT mi.stall_id
        FROM menu_items mi
        JOIN stalls s ON mi.stall_id = s.stall_id
        WHERE mi.menu_item_id = ?
          AND mi.status = 'available'
          AND mi.owner_id = s.owner_id
        LIMIT 1
    ");
  $stmt->bind_param("i", $menuItemId);
  $stmt->execute();
  $menuItemRow = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if (!$menuItemRow) {
    echo json_encode(['success' => false, 'message' => 'Item not found.']);
    $conn->close();
    exit;
  }

  $stallId = (int) $menuItemRow['stall_id'];

  $stmt = $conn->prepare("
        INSERT INTO carts (customer_id, menu_item_id, stall_id, quantity)
        VALUES (?, ?, ?, 1)
        ON DUPLICATE KEY UPDATE quantity = quantity + 1
    ");
  $stmt->bind_param("iii", $customerId, $menuItemId, $stallId);
  $ok = $stmt->execute();
  $stmt->close();

  if (!$ok) {
    echo json_encode(['success' => false, 'message' => 'Failed to add item to cart.']);
    $conn->close();
    exit;
  }

  $stmt = $conn->prepare("SELECT quantity FROM carts WHERE customer_id = ? AND menu_item_id = ? LIMIT 1");
  $stmt->bind_param("ii", $customerId, $menuItemId);
  $stmt->execute();
  $cartRow = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  $newQuantity = $cartRow ? (int) $cartRow['quantity'] : 1;

  echo json_encode(['success' => true, 'quantity' => $newQuantity]);
  $conn->close();
  exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_favorite') {
  header('Content-Type: application/json');

  $menuItemId = (int) ($_POST['menu_item_id'] ?? 0);

  if ($menuItemId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid item.']);
    $conn->close();
    exit;
  }

  $stmt = $conn->prepare("SELECT favorite_id FROM favorites WHERE customer_id = ? AND menu_item_id = ? LIMIT 1");
  $stmt->bind_param("ii", $customerId, $menuItemId);
  $stmt->execute();
  $existing = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if ($existing) {
    $stmt = $conn->prepare("DELETE FROM favorites WHERE favorite_id = ?");
    $stmt->bind_param("i", $existing['favorite_id']);
    $ok = $stmt->execute();
    $stmt->close();

    if (!$ok) {
      echo json_encode(['success' => false, 'message' => 'Failed to remove favorite.']);
      $conn->close();
      exit;
    }

    echo json_encode(['success' => true, 'favorited' => false]);
    $conn->close();
    exit;
  }

  $stmt = $conn->prepare("SELECT menu_item_id FROM menu_items WHERE menu_item_id = ? LIMIT 1");
  $stmt->bind_param("i", $menuItemId);
  $stmt->execute();
  $menuItemExists = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if (!$menuItemExists) {
    echo json_encode(['success' => false, 'message' => 'Item not found.']);
    $conn->close();
    exit;
  }

  $stmt = $conn->prepare("INSERT INTO favorites (customer_id, menu_item_id) VALUES (?, ?)");
  $stmt->bind_param("ii", $customerId, $menuItemId);
  $ok = $stmt->execute();
  $stmt->close();

  if (!$ok) {
    echo json_encode(['success' => false, 'message' => 'Failed to add favorite.']);
    $conn->close();
    exit;
  }

  echo json_encode(['success' => true, 'favorited' => true]);
  $conn->close();
  exit;
}

$stmt = $conn->prepare("SELECT first_name, profile_image FROM customers WHERE customer_id = ? LIMIT 1");
$stmt->bind_param("i", $customerId);
$stmt->execute();
$customer = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$customer) {
  $conn->close();
  header('Location: ../auth/login.php');
  exit;
}

$categoriesResult = $conn->query("SELECT category_id, category_name FROM categories WHERE status = 'active' ORDER BY category_name ASC");
$categories = [];
while ($row = $categoriesResult->fetch_assoc()) {
  $categories[] = [
    'category_id' => (int) $row['category_id'],
    'category_name' => $row['category_name'],
  ];
}

$menuItemsResult = $conn->query("
    SELECT mi.menu_item_id, mi.item_name, mi.price, mi.image, mi.stall_id, s.stall_name, mi.category_id, c.category_name, s.opens_at, s.closes_at
    FROM menu_items mi
    JOIN stalls s ON mi.stall_id = s.stall_id
    JOIN categories c ON mi.category_id = c.category_id
    WHERE mi.status = 'available' AND s.status = 'open' AND c.status = 'active' AND mi.owner_id = s.owner_id
    ORDER BY mi.created_at DESC
");

$currentTime = date('H:i:s');

function isStallOpenNow($opensAt, $closesAt, $currentTime)
{
  if (!$opensAt || !$closesAt) {
    return true;
  }
  return $currentTime >= $opensAt && $currentTime <= $closesAt;
}

$menuItems = [];
while ($row = $menuItemsResult->fetch_assoc()) {
  if (!isStallOpenNow($row['opens_at'], $row['closes_at'], $currentTime)) {
    continue;
  }
  $menuItems[] = [
    'menu_item_id' => (int) $row['menu_item_id'],
    'item_name' => $row['item_name'],
    'price' => (float) $row['price'],
    'image' => $row['image'] ? '../' . $row['image'] : null,
    'stall_id' => (int) $row['stall_id'],
    'stall_name' => $row['stall_name'],
    'category_id' => (int) $row['category_id'],
    'category_name' => $row['category_name'],
  ];
}

$favoritesStmt = $conn->prepare("SELECT menu_item_id FROM favorites WHERE customer_id = ?");
$favoritesStmt->bind_param("i", $customerId);
$favoritesStmt->execute();
$favoritesRows = $favoritesStmt->get_result();
$favoriteMenuItemIds = [];
while ($row = $favoritesRows->fetch_assoc()) {
  $favoriteMenuItemIds[] = (int) $row['menu_item_id'];
}
$favoritesStmt->close();

$notifications = fetchNotifications($conn, 'customer', $customerId, 10);
$unreadNotifCount = countUnreadNotifications($conn, 'customer', $customerId);

$conn->close();

$firstName = $customer['first_name'];
$profileImage = $customer['profile_image'] ? '../' . $customer['profile_image'] : null;
$avatarInitial = mb_strtoupper(mb_substr($firstName, 0, 1));
?>
<!doctype html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>NWSSU Food Court - Home</title>
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
    }

    #mainContent::-webkit-scrollbar-thumb {
      background: #059669;
    }

    .no-scrollbar::-webkit-scrollbar {
      display: none;
    }

    .no-scrollbar {
      -ms-overflow-style: none;
      scrollbar-width: none;
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

    .category-active-custom {
      background: linear-gradient(135deg, #059669 0%, #047857 100%);
      border-color: #059669;
      color: white;
    }

    input:focus {
      outline: none;
      border-color: #059669;
    }

    .added-state {
      background-color: #047857 !important;
    }

    .item-image-fade {
      opacity: 0;
      transition: opacity 0.35s ease;
    }

    .item-image-fade.loaded {
      opacity: 1;
    }

    .item-card-enter {
      opacity: 0;
      transform: translateY(6px);
      animation: item-card-fade-in 0.3s ease forwards;
    }

    @keyframes item-card-fade-in {
      to {
        opacity: 1;
        transform: translateY(0);
      }
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
            id="profileAvatar"
            class="w-9 h-9 bg-gradient-to-br from-emerald-500 to-emerald-700 flex items-center justify-center text-white font-bold text-xs shrink-0 overflow-hidden rounded-full">
            <?php if ($profileImage): ?>
              <img src="<?php echo htmlspecialchars($profileImage); ?>" alt="<?php echo htmlspecialchars($firstName); ?>" class="w-full h-full object-cover" />
            <?php else: ?>
              <?php echo htmlspecialchars($avatarInitial); ?>
            <?php endif; ?>
          </a>
          <div class="min-w-0">
            <p class="text-sm font-bold text-gray-800 leading-tight truncate">
              Hello, <span class="text-emerald-600"><?php echo htmlspecialchars($firstName); ?></span>! 👋
            </p>
            <p class="text-[10px] text-gray-400 leading-none mt-0.5 truncate">
              What would you like to eat?
            </p>
          </div>
        </div>
        <div class="flex items-center gap-2 shrink-0">
          <div class="relative">
            <button
              id="notifBtn"
              class="relative bg-white border border-gray-200 hover:border-emerald-500 hover:bg-slate-50 transition-all flex items-center justify-center shrink-0"
              style="width: 34px; height: 34px; border-radius: 6px"
              title="Notifications">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 text-gray-600">
                <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0" />
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
          <a
            href="./favorites.php"
            id="headerFavoritesBtn"
            class="relative bg-white border border-gray-200 hover:border-emerald-500 hover:bg-slate-50 transition-all flex items-center justify-center shrink-0"
            style="width: 34px; height: 34px; border-radius: 6px"
            title="Favorites">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 text-gray-600">
              <path stroke-linecap="round" stroke-linejoin="round" d="M21 8.25c0-2.485-2.099-4.5-4.688-4.5-1.935 0-3.597 1.126-4.312 2.733-.715-1.607-2.377-2.733-4.313-2.733C5.1 3.75 3 5.765 3 8.25c0 7.22 9 12 9 12s9-4.78 9-12Z" />
            </svg>
            <span id="favoritesDot" class="hidden absolute top-0.5 right-0.5 w-2 h-2 bg-emerald-500 rounded-full"></span>
          </a>
        </div>
      </div>
    </div>

    <div class="flex-1 overflow-y-auto mt-12 mb-16" id="mainContent">
      <div class="max-w-5xl mx-auto px-4 pt-3 pb-4 flex flex-col gap-3">

        <div id="pushPromptBanner" class="hidden bg-emerald-50 border border-emerald-200 p-3 rounded-md">
          <div class="flex items-center gap-2.5">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 text-emerald-600 shrink-0">
              <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0" />
            </svg>
            <div class="flex-1 min-w-0">
              <p class="text-xs font-semibold text-gray-800">Stay updated on your orders</p>
              <p class="text-[10px] text-gray-500 mt-0.5">Turn on notifications so you never miss an update.</p>
            </div>
            <button id="pushEnableBtn" class="shrink-0 px-3 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white text-[11px] font-semibold rounded-[3px]">Enable</button>
            <button id="pushDismissBtn" class="shrink-0 p-1 hover:bg-emerald-100 rounded-[3px]">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 text-gray-400">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
              </svg>
            </button>
          </div>
        </div>

        <div class="bg-white border border-gray-200 p-3 rounded-md">
          <div class="flex gap-2">
            <div class="relative flex-1">
              <input
                type="text"
                id="searchInput"
                placeholder="Search foods or stalls..."
                class="w-full px-4 py-2 pl-10 pr-10 bg-white border border-gray-200 text-xs text-gray-900 placeholder-gray-400 focus:outline-none focus:border-emerald-600 rounded-[3px]" />
              <div class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4">
                  <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
                </svg>
              </div>
              <button type="button" id="clearSearch" class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-700 transition-colors hidden items-center justify-center rounded-[3px]">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                </svg>
              </button>
            </div>
            <button type="button" id="openFilterBtn" class="relative h-full px-3 py-2 bg-white border border-gray-200 text-gray-700 flex items-center justify-center focus:outline-none focus:border-emerald-600 rounded-[3px]">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4">
                <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 6h9.75M10.5 6a1.5 1.5 0 1 1-3 0m3 0a1.5 1.5 0 1 0-3 0M3.75 6H7.5m3 12h9.75m-9.75 0a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m-3.75 0H7.5m9-6h3.75m-3.75 0a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m-9.75 0h9.75" />
              </svg>
            </button>
          </div>
        </div>

        <div class="bg-white border border-gray-200 p-3 rounded-md">
          <div class="flex gap-2 overflow-x-auto no-scrollbar">
            <button class="category-btn category-active-custom px-4 py-2 border border-gray-200 bg-white flex-shrink-0 text-xs font-semibold whitespace-nowrap rounded-[3px]" data-cat-id="all">All</button>
            <?php foreach ($categories as $cat): ?>
              <button class="category-btn px-4 py-2 border border-gray-200 bg-white text-gray-500 hover:border-emerald-500 hover:text-emerald-600 hover:bg-emerald-50 flex-shrink-0 text-xs font-semibold whitespace-nowrap rounded-[3px]" data-cat-id="<?php echo $cat['category_id']; ?>"><?php echo htmlspecialchars($cat['category_name']); ?></button>
            <?php endforeach; ?>
          </div>
        </div>

        <div id="featured-skeleton">
          <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-2.5">
            <div class="bg-white border border-gray-200 overflow-hidden shadow-sm rounded-md">
              <div class="w-full h-32 skeleton-bg"></div>
              <div class="p-3 space-y-2">
                <div class="flex items-start justify-between gap-2">
                  <div class="flex-1 space-y-1.5">
                    <div class="h-3 skeleton-bg rounded-[3px]"></div>
                    <div class="h-2 skeleton-bg w-1/2 rounded-[3px]"></div>
                  </div>
                  <div class="h-4 skeleton-bg w-10 shrink-0 rounded-[3px]"></div>
                </div>
                <div class="w-full h-8 skeleton-bg rounded-[3px]"></div>
              </div>
            </div>
            <div class="bg-white border border-gray-200 overflow-hidden shadow-sm rounded-md">
              <div class="w-full h-32 skeleton-bg"></div>
              <div class="p-3 space-y-2">
                <div class="flex items-start justify-between gap-2">
                  <div class="flex-1 space-y-1.5">
                    <div class="h-3 skeleton-bg rounded-[3px]"></div>
                    <div class="h-2 skeleton-bg w-1/2 rounded-[3px]"></div>
                  </div>
                  <div class="h-4 skeleton-bg w-10 shrink-0 rounded-[3px]"></div>
                </div>
                <div class="w-full h-8 skeleton-bg rounded-[3px]"></div>
              </div>
            </div>
            <div class="bg-white border border-gray-200 overflow-hidden shadow-sm rounded-md">
              <div class="w-full h-32 skeleton-bg"></div>
              <div class="p-3 space-y-2">
                <div class="flex items-start justify-between gap-2">
                  <div class="flex-1 space-y-1.5">
                    <div class="h-3 skeleton-bg rounded-[3px]"></div>
                    <div class="h-2 skeleton-bg w-1/2 rounded-[3px]"></div>
                  </div>
                  <div class="h-4 skeleton-bg w-10 shrink-0 rounded-[3px]"></div>
                </div>
                <div class="w-full h-8 skeleton-bg rounded-[3px]"></div>
              </div>
            </div>
            <div class="bg-white border border-gray-200 overflow-hidden shadow-sm rounded-md">
              <div class="w-full h-32 skeleton-bg"></div>
              <div class="p-3 space-y-2">
                <div class="flex items-start justify-between gap-2">
                  <div class="flex-1 space-y-1.5">
                    <div class="h-3 skeleton-bg rounded-[3px]"></div>
                    <div class="h-2 skeleton-bg w-1/2 rounded-[3px]"></div>
                  </div>
                  <div class="h-4 skeleton-bg w-10 shrink-0 rounded-[3px]"></div>
                </div>
                <div class="w-full h-8 skeleton-bg rounded-[3px]"></div>
              </div>
            </div>
          </div>
        </div>

        <div class="hidden" id="featured-content">
          <div id="menuItemsGrid" class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-2.5"></div>
          <div class="flex justify-center mt-4" id="loadMoreWrapper">
            <button type="button" id="loadMoreBtn" class="px-6 py-2.5 bg-white border border-gray-200 text-gray-700 text-xs font-semibold hover:border-emerald-500 hover:text-emerald-600 transition-all rounded-[3px]">
              Load More
            </button>
          </div>
        </div>

        <div class="hidden" id="no-results">
          <div class="flex flex-col items-center justify-center py-16 text-center">
            <div class="w-24 h-24 bg-gray-100 flex items-center justify-center mb-4 rounded-[3px]">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-10 h-10 text-gray-400">
                <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
              </svg>
            </div>
            <p class="text-base font-semibold text-gray-800">No foods found</p>
            <p class="text-gray-500 text-sm mt-1">Try searching with different keywords</p>
          </div>
        </div>

      </div>
    </div>

    <button id="scrollToTopBtn" class="fixed bottom-24 w-10 h-10 bg-emerald-600 text-white shadow-lg hover:bg-emerald-700 transition-all opacity-0 pointer-events-none flex items-center justify-center z-30 rounded-[3px]" style="right: max(1rem, calc((100vw - 72rem) / 2 + 1rem)); transition:opacity 0.3s ease;">
      <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5">
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 19.5v-15m0 0-6.75 6.75M12 4.5l6.75 6.75" />
      </svg>
    </button>

    <div class="bg-white border-t border-gray-200 flex-shrink-0 fixed bottom-0 left-0 right-0 z-20">
      <div class="max-w-5xl mx-auto px-4 flex justify-around py-2">
        <a href="./home.php" class="flex flex-col items-center justify-center py-2 px-3 transition-all duration-200 group text-emerald-600 bg-emerald-50 rounded-[3px]">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 transition-transform group-hover:scale-110">
            <path stroke-linecap="round" stroke-linejoin="round" d="m2.25 12 8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" />
          </svg>
          <span class="text-xs font-medium mt-1">Home</span>
        </a>
        <a href="./cart.php" class="flex flex-col items-center justify-center py-2 px-3 transition-all duration-200 group text-gray-500 hover:text-gray-900 hover:bg-gray-50 relative rounded-[3px]">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 transition-transform group-hover:scale-110">
            <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 3h1.386c.51 0 .955.343 1.087.836l.383 1.437M7.5 14.25a3 3 0 0 0-3 3h15.75m-12.75-3h11.218c1.121-2.3 1.994-4.694 2.602-7.152.126-.51-.26-1.006-.786-1.006H5.106M7.5 14.25 5.106 5.272M6 20.25a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Zm12.75 0a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Z" />
          </svg>
          <span class="text-xs font-medium mt-1">Cart</span>
        </a>
        <a href="./order.php" class="flex flex-col items-center justify-center py-2 px-3 transition-all duration-200 group text-gray-500 hover:text-gray-900 hover:bg-gray-50 rounded-[3px]">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 transition-transform group-hover:scale-110">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 0 0 2.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 0 0-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 0 0 .75-.75 2.25 2.25 0 0 0-.1-.664m-5.8 0A2.251 2.251 0 0 1 13.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25Z" />
          </svg>
          <span class="text-xs font-medium mt-1">Orders</span>
        </a>
        <a href="./chat.php" class="flex flex-col items-center justify-center py-2 px-3 transition-all duration-200 group text-gray-500 hover:text-gray-900 hover:bg-gray-50 relative rounded-[3px]">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 transition-transform group-hover:scale-110">
            <path stroke-linecap="round" stroke-linejoin="round" d="M20.25 8.511c.884.284 1.5 1.128 1.5 2.097v4.286c0 1.136-.847 2.1-1.98 2.193-.34.027-.68.052-1.02.072v3.091l-3-3c-1.354 0-2.694-.055-4.02-.163a2.115 2.115 0 0 1-.825-.242m9.345-8.334a2.126 2.126 0 0 0-.476-.095 48.64 48.64 0 0 0-8.048 0c-1.131.094-1.976 1.057-1.976 2.192v4.286c0 .837.46 1.58 1.155 1.951m9.345-8.334V6.637c0-1.621-1.152-3.026-2.76-3.235A48.455 48.455 0 0 0 11.25 3c-2.115 0-4.198.137-6.24.402-1.608.209-2.76 1.614-2.76 3.235v6.226c0 1.621 1.152 3.026 2.76 3.235.577.075 1.157.14 1.74.194V21l4.155-4.155" />
          </svg>
          <span class="text-xs font-medium mt-1">Chats</span>
        </a>
        <a href="./account.php" class="flex flex-col items-center justify-center py-2 px-3 transition-all duration-200 group text-gray-500 hover:text-gray-900 hover:bg-gray-50 rounded-[3px]">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 transition-transform group-hover:scale-110">
            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" />
          </svg>
          <span class="text-xs font-medium mt-1">Account</span>
        </a>
      </div>
    </div>

  </div>

  <div
    id="filterModal"
    class="fixed inset-0 z-50 hidden items-end sm:items-center justify-center">
    <div class="modal-overlay absolute inset-0" id="closeFilterOverlay"></div>
    <div class="bg-white w-full sm:max-w-md relative z-10 shadow-2xl max-h-[85vh] flex flex-col rounded-t-2xl sm:rounded-md">
      <div class="p-4 border-b border-gray-100 flex items-center justify-between shrink-0">
        <h2 class="font-bold text-gray-800 text-sm">Sort By</h2>
        <button id="closeFilterBtn" class="p-1 hover:bg-gray-100 rounded-[3px]">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 text-gray-500">
            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
          </svg>
        </button>
      </div>

      <div class="p-4 flex flex-col gap-2 overflow-y-auto">
        <button type="button" class="sort-option-btn category-active-custom px-3 py-2.5 border border-gray-200 bg-white text-xs font-semibold text-gray-700 text-left rounded-[3px]" data-sort="newest">Newest</button>
        <button type="button" class="sort-option-btn px-3 py-2.5 border border-gray-200 bg-white text-xs font-semibold text-gray-700 text-left hover:border-emerald-500 hover:text-emerald-600 hover:bg-emerald-50 rounded-[3px]" data-sort="price_low">Price: Low to High</button>
        <button type="button" class="sort-option-btn px-3 py-2.5 border border-gray-200 bg-white text-xs font-semibold text-gray-700 text-left hover:border-emerald-500 hover:text-emerald-600 hover:bg-emerald-50 rounded-[3px]" data-sort="price_high">Price: High to Low</button>
        <button type="button" class="sort-option-btn px-3 py-2.5 border border-gray-200 bg-white text-xs font-semibold text-gray-700 text-left hover:border-emerald-500 hover:text-emerald-600 hover:bg-emerald-50 rounded-[3px]" data-sort="name_az">Name: A-Z</button>
        <button type="button" class="sort-option-btn px-3 py-2.5 border border-gray-200 bg-white text-xs font-semibold text-gray-700 text-left hover:border-emerald-500 hover:text-emerald-600 hover:bg-emerald-50 rounded-[3px]" data-sort="name_za">Name: Z-A</button>
      </div>

      <div class="p-4 border-t border-gray-100 flex gap-2 shrink-0">
        <button type="button" id="resetFilterBtn" class="flex-1 py-2.5 border border-gray-200 text-gray-700 text-xs font-semibold hover:bg-gray-50 transition-colors rounded-[3px]">
          Reset
        </button>
        <button type="button" id="applyFilterBtn" class="flex-1 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold transition-colors rounded-[3px]">
          Apply
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
    const ALL_MENU_ITEMS = <?php echo json_encode($menuItems); ?>;
    const INITIAL_FAVORITE_IDS = <?php echo json_encode($favoriteMenuItemIds); ?>;
    const VAPID_PUBLIC_KEY = "<?php echo VAPID_PUBLIC_KEY; ?>";

    const ADD_BTN_DEFAULT_HTML = `
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-3.5 h-3.5">
          <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 3h1.386c.51 0 .955.343 1.087.836l.383 1.437M7.5 14.25a3 3 0 0 0-3 3h15.75m-12.75-3h11.218c1.121-2.3 1.994-4.694 2.602-7.152.126-.51-.26-1.006-.786-1.006H5.106M7.5 14.25 5.106 5.272M6 20.25a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Zm12.75 0a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Z" />
        </svg>
        Add to Cart
      `;

    const ADD_BTN_ADDED_HTML = `
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-3.5 h-3.5">
          <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
        </svg>
        Added
      `;

    const HEART_OUTLINE_PATH =
      "M21 8.25c0-2.485-2.099-4.5-4.688-4.5-1.935 0-3.597 1.126-4.312 2.733-.715-1.607-2.377-2.733-4.313-2.733C5.1 3.75 3 5.765 3 8.25c0 7.22 9 12 9 12s9-4.78 9-12Z";

    const PAGE_SIZE = 12;

    let ALL_NOTIFICATIONS = <?php echo json_encode($notifications); ?>;
    let notifUnreadCount = <?php echo (int) $unreadNotifCount; ?>;
    const FAVORITE_ITEM_IDS = new Set(INITIAL_FAVORITE_IDS);
    let currentFilteredItems = [];
    let displayedCount = 0;

    const scrollToTopBtn = document.getElementById("scrollToTopBtn");
    const mainContent = document.getElementById("mainContent");

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

    function setupScrollToTop() {
      mainContent.addEventListener("scroll", function() {
        const isScrolled = mainContent.scrollTop > 300;
        scrollToTopBtn.classList.toggle("opacity-100", isScrolled);
        scrollToTopBtn.classList.toggle("pointer-events-auto", isScrolled);
        scrollToTopBtn.classList.toggle("opacity-0", !isScrolled);
        scrollToTopBtn.classList.toggle("pointer-events-none", !isScrolled);
      });

      scrollToTopBtn.addEventListener("click", function() {
        mainContent.scrollTo({ top: 0, behavior: "smooth" });
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
        console.log("[push] Registering service worker...");
        const registration = await navigator.serviceWorker.register("../service-worker.js");
        await navigator.serviceWorker.ready;
        console.log("[push] Service worker ready.");

        const existingSubscription = await registration.pushManager.getSubscription();
        const subscription = existingSubscription || (await registration.pushManager.subscribe({
          userVisibleOnly: true,
          applicationServerKey: urlBase64ToUint8Array(VAPID_PUBLIC_KEY),
        }));
        console.log("[push] Subscription object:", subscription);

        const res = await fetch("../save-push-subscription.php", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify(subscription),
        });
        const resJson = await res.json();
        console.log("[push] Save response:", resJson);

        return true;
      } catch (err) {
        console.error("[push] Subscription failed:", err);
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

    function updateFavoritesDot() {
      const dot = document.getElementById("favoritesDot");
      if (!dot) return;
      dot.classList.toggle("hidden", FAVORITE_ITEM_IDS.size === 0);
    }

    function buildItemCardHTML(item) {
      const isFavorited = FAVORITE_ITEM_IDS.has(item.menu_item_id);
      return `
          <div class="bg-white border border-gray-200 overflow-hidden hover:shadow-md transition-all shadow-sm rounded-md item-card-enter">
            <div class="w-full h-32 bg-gray-100 overflow-hidden relative">
              ${item.image ? `<img src="${escapeHtml(item.image)}" alt="${escapeHtml(item.item_name)}" class="w-full h-full object-cover item-image-fade" loading="lazy" onload="this.classList.add('loaded')" />` : ""}
              <button type="button" data-item-id="${item.menu_item_id}" class="favorite-btn absolute top-1.5 right-1.5 w-7 h-7 bg-white/90 hover:bg-white flex items-center justify-center shadow-sm transition-all rounded-full">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" fill="${isFavorited ? "currentColor" : "none"}" class="w-4 h-4 ${isFavorited ? "text-red-500" : "text-gray-400"} transition-colors">
                  <path stroke-linecap="round" stroke-linejoin="round" d="${HEART_OUTLINE_PATH}" />
                </svg>
              </button>
            </div>
            <div class="p-3">
              <div class="flex items-start justify-between gap-2 mb-1">
                <div class="flex-1 min-w-0">
                  <h3 class="text-sm font-semibold text-gray-900 truncate">${escapeHtml(item.item_name)}</h3>
                  <p class="text-xs text-gray-500 mt-0.5 truncate">${escapeHtml(item.stall_name)}</p>
                </div>
                <span class="text-base font-bold text-emerald-600 shrink-0">&#8369;${item.price.toFixed(0)}</span>
              </div>
              <button type="button" data-item-id="${item.menu_item_id}" class="w-full mt-2 py-2 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-medium flex items-center justify-center gap-1 transition-all add-btn rounded-[3px]">
                ${ADD_BTN_DEFAULT_HTML}
              </button>
            </div>
          </div>
        `;
    }

    function attachCardListeners(cardElements) {
      cardElements.forEach((card) => {
        const favBtn = card.querySelector(".favorite-btn");
        if (favBtn) {
          favBtn.addEventListener("click", async function() {
            if (this.disabled) return;
            this.disabled = true;

            const menuItemId = parseInt(this.getAttribute("data-item-id"));
            const svg = this.querySelector("svg");
            const wasFavorited = FAVORITE_ITEM_IDS.has(menuItemId);

            if (wasFavorited) {
              FAVORITE_ITEM_IDS.delete(menuItemId);
              svg.setAttribute("fill", "none");
              svg.classList.remove("text-red-500");
              svg.classList.add("text-gray-400");
            } else {
              FAVORITE_ITEM_IDS.add(menuItemId);
              svg.setAttribute("fill", "currentColor");
              svg.classList.remove("text-gray-400");
              svg.classList.add("text-red-500");
            }
            updateFavoritesDot();

            const formData = new FormData();
            formData.append("action", "toggle_favorite");
            formData.append("menu_item_id", menuItemId);

            try {
              const response = await fetch(window.location.href, {
                method: "POST",
                body: formData,
              });
              const res = await response.json();

              if (res.success) {
                const item = ALL_MENU_ITEMS.find((it) => it.menu_item_id === menuItemId);
                const itemLabel = item ? item.item_name : "Item";
                showToast(itemLabel + (wasFavorited ? " removed from favorites" : " added to favorites"));
              } else {
                if (wasFavorited) {
                  FAVORITE_ITEM_IDS.add(menuItemId);
                  svg.setAttribute("fill", "currentColor");
                  svg.classList.remove("text-gray-400");
                  svg.classList.add("text-red-500");
                } else {
                  FAVORITE_ITEM_IDS.delete(menuItemId);
                  svg.setAttribute("fill", "none");
                  svg.classList.remove("text-red-500");
                  svg.classList.add("text-gray-400");
                }
                updateFavoritesDot();
              }
            } catch (err) {
              if (wasFavorited) {
                FAVORITE_ITEM_IDS.add(menuItemId);
                svg.setAttribute("fill", "currentColor");
                svg.classList.remove("text-gray-400");
                svg.classList.add("text-red-500");
              } else {
                FAVORITE_ITEM_IDS.delete(menuItemId);
                svg.setAttribute("fill", "none");
                svg.classList.remove("text-red-500");
                svg.classList.add("text-gray-400");
              }
              updateFavoritesDot();
              showToast(res.message || "Something went wrong. Please try again.", "warning");
            }

            this.disabled = false;
          });
        }

        const addBtn = card.querySelector(".add-btn");
        if (addBtn) {
          addBtn.addEventListener("click", async function() {
            if (this.disabled) return;
            this.disabled = true;

            const menuItemId = this.getAttribute("data-item-id");
            const formData = new FormData();
            formData.append("action", "add_to_cart");
            formData.append("menu_item_id", menuItemId);

            try {
              const response = await fetch(window.location.href, {
                method: "POST",
                body: formData,
              });
              const res = await response.json();

              if (res.success) {
                this.classList.add("added-state");
                this.innerHTML = ADD_BTN_ADDED_HTML;

                const addedItem = ALL_MENU_ITEMS.find((it) => it.menu_item_id === parseInt(menuItemId));
                const itemLabel = addedItem ? addedItem.item_name : "Item";
                const qtyText = res.quantity && res.quantity > 1 ? ` (${res.quantity}x)` : "";
                showToast(itemLabel + " added to cart" + qtyText);

                setTimeout(() => {
                  this.innerHTML = ADD_BTN_DEFAULT_HTML;
                  this.classList.remove("added-state");
                  this.disabled = false;
                }, 1500);
              } else {
                this.disabled = false;
              }
            } catch (err) {
              this.disabled = false;
            }
          });
        }
      });
    }

    function updateLoadMoreVisibility() {
      const wrapper = document.getElementById("loadMoreWrapper");
      const hasMore = displayedCount < currentFilteredItems.length;
      wrapper.classList.toggle("hidden", !hasMore);
    }

    function renderMenuItems(items) {
      const grid = document.getElementById("menuItemsGrid");
      currentFilteredItems = items;
      displayedCount = Math.min(PAGE_SIZE, items.length);

      grid.innerHTML = items.slice(0, displayedCount).map(buildItemCardHTML).join("");
      attachCardListeners(Array.from(grid.children));
      updateLoadMoreVisibility();
    }

    function appendMoreItems() {
      const grid = document.getElementById("menuItemsGrid");
      const previousChildCount = grid.children.length;
      const nextSlice = currentFilteredItems.slice(displayedCount, displayedCount + PAGE_SIZE);

      grid.insertAdjacentHTML("beforeend", nextSlice.map(buildItemCardHTML).join(""));
      displayedCount += nextSlice.length;

      const newCards = Array.from(grid.children).slice(previousChildCount);
      attachCardListeners(newCards);
      updateLoadMoreVisibility();
    }

    function sortItems(items, sortBy) {
      const sorted = [...items];
      if (sortBy === "price_low") {
        sorted.sort((a, b) => a.price - b.price);
      } else if (sortBy === "price_high") {
        sorted.sort((a, b) => b.price - a.price);
      } else if (sortBy === "name_az") {
        sorted.sort((a, b) => a.item_name.localeCompare(b.item_name));
      } else if (sortBy === "name_za") {
        sorted.sort((a, b) => b.item_name.localeCompare(a.item_name));
      }
      return sorted;
    }

    function setupFilterModal() {
      const filterModal = document.getElementById("filterModal");
      const openFilterBtn = document.getElementById("openFilterBtn");
      const closeFilterBtn = document.getElementById("closeFilterBtn");
      const closeFilterOverlay = document.getElementById("closeFilterOverlay");
      const applyFilterBtn = document.getElementById("applyFilterBtn");
      const resetFilterBtn = document.getElementById("resetFilterBtn");
      const sortOptionBtns = document.querySelectorAll(".sort-option-btn");

      function openModal() {
        filterModal.classList.remove("hidden");
        filterModal.classList.add("flex");
        document.body.style.overflow = "hidden";
      }

      function closeModal() {
        filterModal.classList.add("hidden");
        filterModal.classList.remove("flex");
        document.body.style.overflow = "";
      }

      function setActiveSortBtn(activeBtn) {
        sortOptionBtns.forEach((btn) => {
          btn.classList.remove("category-active-custom");
          btn.classList.add("bg-white", "border-gray-200", "text-gray-700", "hover:border-emerald-500", "hover:text-emerald-600", "hover:bg-emerald-50");
        });
        activeBtn.classList.add("category-active-custom");
        activeBtn.classList.remove("bg-white", "border-gray-200", "text-gray-700", "hover:border-emerald-500", "hover:text-emerald-600", "hover:bg-emerald-50");
      }

      openFilterBtn.addEventListener("click", openModal);
      closeFilterBtn.addEventListener("click", closeModal);
      closeFilterOverlay.addEventListener("click", closeModal);

      sortOptionBtns.forEach((btn) => {
        btn.addEventListener("click", function() {
          setActiveSortBtn(this);
        });
      });

      applyFilterBtn.addEventListener("click", function() {
        applyFilters();
        closeModal();
      });

      resetFilterBtn.addEventListener("click", function() {
        setActiveSortBtn(document.querySelector('.sort-option-btn[data-sort="newest"]'));
        applyFilters();
        closeModal();
      });
    }

    function setupCategoryFilter() {
      const categoryBtns = document.querySelectorAll(".category-btn");

      function setActiveCategory(activeBtn) {
        categoryBtns.forEach((btn) => {
          btn.classList.remove("category-active-custom");
          btn.classList.add("bg-white", "border-gray-200", "text-gray-500", "hover:border-emerald-500", "hover:text-emerald-600", "hover:bg-emerald-50");
        });
        activeBtn.classList.add("category-active-custom");
        activeBtn.classList.remove("bg-white", "border-gray-200", "text-gray-500", "hover:border-emerald-500", "hover:text-emerald-600", "hover:bg-emerald-50");
      }

      categoryBtns.forEach((btn) => {
        btn.addEventListener("click", function() {
          setActiveCategory(this);
          applyFilters();
        });
      });
    }

    function setupSearch() {
      const searchInput = document.getElementById("searchInput");
      const clearSearchBtn = document.getElementById("clearSearch");
      let debounceTimer;

      searchInput.addEventListener("input", function() {
        const hasVal = this.value.length > 0;
        clearSearchBtn.classList.toggle("hidden", !hasVal);
        clearSearchBtn.classList.toggle("flex", hasVal);
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(() => applyFilters(), 300);
      });

      clearSearchBtn.addEventListener("click", function(e) {
        e.preventDefault();
        searchInput.value = "";
        clearSearchBtn.classList.add("hidden");
        clearSearchBtn.classList.remove("flex");
        searchInput.focus();
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(() => applyFilters(), 300);
      });
    }

    function applyFilters() {
      const searchInput = document.getElementById("searchInput");
      const activeSortBtn = document.querySelector(".sort-option-btn.category-active-custom");
      const sortValue = activeSortBtn ? activeSortBtn.getAttribute("data-sort") : "newest";

      const query = searchInput.value.toLowerCase().trim();
      const activeCategoryBtn = document.querySelector(".category-btn.category-active-custom");
      const activeCategoryId = activeCategoryBtn ? activeCategoryBtn.getAttribute("data-cat-id") : "all";

      const filtered = ALL_MENU_ITEMS.filter((item) => {
        const nameMatch = query === "" ||
          item.item_name.toLowerCase().includes(query) ||
          item.stall_name.toLowerCase().includes(query);
        const categoryMatch = activeCategoryId === "all" || item.category_id === parseInt(activeCategoryId);
        return nameMatch && categoryMatch;
      });

      const sorted = sortItems(filtered, sortValue);
      renderMenuItems(sorted);

      const showNoResults = sorted.length === 0;
      document.getElementById("no-results").classList.toggle("hidden", !showNoResults);
      document.getElementById("featured-content").classList.toggle("hidden", showNoResults);
    }

    function setupLoadMore() {
      document.getElementById("loadMoreBtn").addEventListener("click", function() {
        appendMoreItems();
      });
    }

    function setupRotatingPlaceholder() {
      const searchInput = document.getElementById("searchInput");
      const defaultPlaceholder = "Search foods or stalls...";

      const uniqueNames = [...new Set(ALL_MENU_ITEMS.map((item) => item.item_name))];
      const shuffled = uniqueNames.sort(() => Math.random() - 0.5);
      const samples = shuffled.slice(0, 5);

      const placeholders = [defaultPlaceholder, ...samples.map((name) => `Search for "${name}"...`)];

      let currentIndex = 0;
      let rotateInterval = null;

      function rotate() {
        currentIndex++;
        if (currentIndex >= placeholders.length) {
          searchInput.placeholder = defaultPlaceholder;
          stopRotating();
          return;
        }
        searchInput.placeholder = placeholders[currentIndex];
      }

      function startRotating() {
        if (rotateInterval || placeholders.length <= 1) return;
        rotateInterval = setInterval(rotate, 3000);
      }

      function stopRotating() {
        clearInterval(rotateInterval);
        rotateInterval = null;
      }

      searchInput.addEventListener("focus", stopRotating);
      searchInput.addEventListener("input", stopRotating);

      startRotating();
    }

    function initializeEventListeners() {
      setupFilterModal();
      setupCategoryFilter();
      setupSearch();
      setupRotatingPlaceholder();
      setupLoadMore();
      applyFilters();
    }

    function loadContent() {
      document.getElementById("featured-skeleton").classList.add("hidden");
      document.getElementById("featured-content").classList.remove("hidden");
      initializeEventListeners();
    }

    function init() {
      setupScrollToTop();
      updateFavoritesDot();
      updateNotifBadge(notifUnreadCount);
      setupNotifications();
      setupPushPromptBanner();
      setTimeout(() => loadContent(), 200);
    }

    window.addEventListener("load", init);
  </script>
</body>

</html>