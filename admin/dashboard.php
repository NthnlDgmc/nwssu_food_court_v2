<?php
date_default_timezone_set('Asia/Manila');
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['admin_id'])) {
  header('Location: ../auth/login.php');
  exit;
}

$adminId = $_SESSION['admin_id'];

$stmt = $conn->prepare("SELECT first_name, last_name, profile_image FROM admin WHERE admin_id = ? LIMIT 1");
$stmt->bind_param("s", $adminId);
$stmt->execute();
$result = $stmt->get_result();
$admin = $result->fetch_assoc();
$stmt->close();

if (!$admin) {
  header('Location: ../auth/login.php');
  exit;
}

$adminFirstName = $admin['first_name'];
$adminLastName = $admin['last_name'];
$adminFullName = $adminFirstName . ' ' . $adminLastName;
$adminProfileImage = $admin['profile_image'] ? '../' . $admin['profile_image'] : null;

function getAdminInitials($first, $last)
{
  $f = mb_substr(trim($first), 0, 1);
  $l = mb_substr(trim($last), 0, 1);
  return mb_strtoupper($f . $l);
}

$adminInitials = getAdminInitials($adminFirstName, $adminLastName);

function formatRelativeTime($datetime)
{
  $date = new DateTime($datetime);
  $now = new DateTime();
  $dateOnly = $date->format('Y-m-d');
  $nowOnly = $now->format('Y-m-d');

  if ($dateOnly === $nowOnly) {
    return 'Today';
  }

  $yesterday = (clone $now)->modify('-1 day')->format('Y-m-d');
  if ($dateOnly === $yesterday) {
    return 'Yesterday';
  }

  $daysDiff = $now->diff($date)->days;
  if ($daysDiff < 7) {
    return $daysDiff . ' days ago';
  }

  return $date->format('M d, Y');
}

$totalCustomersResult = $conn->query("SELECT COUNT(*) AS total FROM customers");
$totalCustomersRow = $totalCustomersResult->fetch_assoc();
$totalCustomers = (int) $totalCustomersRow['total'];

$recentCustomersResult = $conn->query("SELECT first_name, last_name, customer_type, status, created_at FROM customers ORDER BY customer_id DESC LIMIT 4");
$recentCustomers = [];
while ($row = $recentCustomersResult->fetch_assoc()) {
  $recentCustomers[] = [
    'name' => $row['first_name'] . ' ' . $row['last_name'],
    'type' => $row['customer_type'],
    'status' => $row['status'],
    'joined' => formatRelativeTime($row['created_at']),
  ];
}

$totalStallOwnersResult = $conn->query("SELECT COUNT(*) AS total FROM stall_owners");
$totalStallOwnersRow = $totalStallOwnersResult->fetch_assoc();
$totalStallOwners = (int) $totalStallOwnersRow['total'];

$recentOwnersResult = $conn->query("SELECT first_name, last_name, status, created_at FROM stall_owners ORDER BY owner_id DESC LIMIT 3");
$recentOwners = [];
while ($row = $recentOwnersResult->fetch_assoc()) {
  $recentOwners[] = [
    'name' => $row['first_name'] . ' ' . $row['last_name'],
    'status' => $row['status'],
    'joined' => formatRelativeTime($row['created_at']),
  ];
}

$totalDeliveryStaffResult = $conn->query("SELECT COUNT(*) AS total FROM delivery_staff");
$totalDeliveryStaffRow = $totalDeliveryStaffResult->fetch_assoc();
$totalDeliveryStaff = (int) $totalDeliveryStaffRow['total'];

$recentDeliveryResult = $conn->query("SELECT first_name, last_name, status, created_at FROM delivery_staff ORDER BY staff_id DESC LIMIT 3");
$recentDelivery = [];
while ($row = $recentDeliveryResult->fetch_assoc()) {
  $recentDelivery[] = [
    'name' => $row['first_name'] . ' ' . $row['last_name'],
    'status' => $row['status'],
    'joined' => formatRelativeTime($row['created_at']),
  ];
}

$totalCategoriesResult = $conn->query("SELECT COUNT(*) AS total FROM categories");
$totalCategoriesRow = $totalCategoriesResult->fetch_assoc();
$totalCategories = (int) $totalCategoriesRow['total'];

$totalStallsResult = $conn->query("SELECT COUNT(*) AS total FROM stalls");
$totalStallsRow = $totalStallsResult->fetch_assoc();
$totalStalls = (int) $totalStallsRow['total'];

$conn->close();
?>
<!doctype html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta
    name="viewport"
    content="width=device-width, initial-scale=1.0, user-scalable=yes" />
  <title>Admin - Dashboard</title>
  <link rel="icon" href="../assets/images/nwssu-logo.png" type="image/png" />
  <link rel="manifest" href="/manifest.json" />
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

    #sidebar {
      position: fixed;
      top: 0;
      left: 0;
      bottom: 0;
      width: 272px;
      background: #ffffff;
      z-index: 60;
      transform: translateX(-100%);
      transition: transform 0.3s ease;
      display: flex;
      flex-direction: column;
      box-shadow: 2px 0 16px rgba(0, 0, 0, 0.08);
      border-radius: 0;
    }

    #sidebar.open {
      transform: translateX(0);
    }

    #sidebarOverlay {
      position: fixed;
      inset: 0;
      background: rgba(0, 0, 0, 0.3);
      z-index: 59;
      opacity: 0;
      pointer-events: none;
      transition: opacity 0.3s ease;
    }

    #sidebarOverlay.open {
      opacity: 1;
      pointer-events: all;
    }

    #menuToggle .icon-menu,
    #menuToggle .icon-close {
      transition: opacity 0.2s ease;
      position: absolute;
    }

    #menuToggle .icon-close {
      opacity: 0;
    }

    #menuToggle.is-open .icon-menu {
      opacity: 0;
    }

    #menuToggle.is-open .icon-close {
      opacity: 1;
    }
  </style>
</head>

<body class="bg-white">
  <div class="flex flex-col h-screen">
    <div class="bg-white flex-shrink-0 fixed top-0 left-0 right-0 z-20">
      <div class="max-w-5xl mx-auto px-4 py-2 flex items-center justify-between gap-3">
        <div class="flex items-center gap-2.5 min-w-0">
          <button
            id="menuToggle"
            class="p-1.5 bg-white border border-gray-200 hover:border-emerald-500 hover:bg-slate-50 transition-all relative flex items-center justify-center shrink-0"
            style="width: 34px; height: 34px; border-radius: 6px"
            title="Menu"
            aria-label="Open sidebar menu"
            aria-expanded="false"
            aria-controls="sidebar">
            <svg class="icon-menu w-5 h-5 text-gray-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
            </svg>
            <svg class="icon-close w-5 h-5 text-gray-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
            </svg>
          </button>
          <nav class="flex items-center gap-1.5 text-xs text-gray-500 min-w-0" aria-label="Breadcrumb">
            <span class="text-gray-700 font-medium truncate">Dashboard</span>
          </nav>
        </div>
        <div class="flex items-center gap-2 shrink-0">
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
            <span class="absolute top-0.5 right-0.5 w-2 h-2 bg-red-500 rounded-full"></span>
          </button>
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
          <a
            href="./account.php"
            id="accountBtn"
            class="w-9 h-9 bg-gradient-to-br from-emerald-500 to-emerald-700 flex items-center justify-center text-white font-bold text-xs shrink-0 overflow-hidden rounded-full"
            title="Account">
            <?php if ($adminProfileImage): ?>
              <img
                src="<?php echo htmlspecialchars($adminProfileImage); ?>"
                alt="<?php echo htmlspecialchars($adminFullName); ?>"
                class="w-full h-full object-cover" />
            <?php else: ?>
              <?php echo htmlspecialchars($adminInitials); ?>
            <?php endif; ?>
          </a>
        </div>
      </div>
    </div>

    <div id="sidebarOverlay"></div>

    <aside id="sidebar">
      <div class="flex items-center justify-between px-4 py-4 border-b border-gray-100 shrink-0">
        <a href="./account.php" class="flex items-center gap-2.5 min-w-0">
          <div class="w-9 h-9 bg-gradient-to-br from-emerald-500 to-emerald-700 flex items-center justify-center text-white font-bold text-xs shrink-0 overflow-hidden rounded-full">
            <?php if ($adminProfileImage): ?>
              <img
                src="<?php echo htmlspecialchars($adminProfileImage); ?>"
                alt="<?php echo htmlspecialchars($adminFullName); ?>"
                class="w-full h-full object-cover" />
            <?php else: ?>
              <?php echo htmlspecialchars($adminInitials); ?>
            <?php endif; ?>
          </div>
          <div class="min-w-0">
            <p class="text-sm font-bold text-gray-800 truncate"><?php echo htmlspecialchars($adminFullName); ?></p>
            <p class="text-[10px] text-gray-400 truncate">System Administrator</p>
          </div>
        </a>
        <button id="closeSidebar" class="p-1.5 hover:bg-gray-100 transition-colors text-gray-500" style="border-radius:3px" aria-label="Close menu">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5">
            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
          </svg>
        </button>
      </div>

      <nav class="flex-1 overflow-y-auto py-3 px-3 space-y-1">

        <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest px-2 pt-1 pb-1.5">Main</p>

        <a href="./dashboard.php" class="sidebar-link active flex items-center gap-3 px-3 py-2.5 text-emerald-700 bg-emerald-50 border border-emerald-100 font-semibold transition-colors" style="border-radius:6px">
          <span class="w-8 h-8 flex items-center justify-center bg-emerald-600 shrink-0" style="border-radius:3px">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 text-white">
              <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 0 1 6 3.75h2.25A2.25 2.25 0 0 1 10.5 6v2.25a2.25 2.25 0 0 1-2.25 2.25H6a2.25 2.25 0 0 1-2.25-2.25V6ZM3.75 15.75A2.25 2.25 0 0 1 6 13.5h2.25a2.25 2.25 0 0 1 2.25 2.25V18a2.25 2.25 0 0 1-2.25 2.25H6A2.25 2.25 0 0 1 3.75 18v-2.25ZM13.5 6a2.25 2.25 0 0 1 2.25-2.25H18A2.25 2.25 0 0 1 20.25 6v2.25A2.25 2.25 0 0 1 18 10.5h-2.25a2.25 2.25 0 0 1-2.25-2.25V6ZM13.5 15.75a2.25 2.25 0 0 1 2.25-2.25H18a2.25 2.25 0 0 1 2.25 2.25V18A2.25 2.25 0 0 1 18 20.25h-2.25A2.25 2.25 0 0 1 13.5 18v-2.25Z" />
            </svg>
          </span>
          <span class="text-sm">Dashboard</span>
        </a>

        <a href="./chat.php" class="sidebar-link flex items-center gap-3 px-3 py-2.5 text-gray-600 hover:bg-gray-50 hover:text-gray-900 border border-transparent font-medium transition-colors" style="border-radius:6px">
          <span class="w-8 h-8 flex items-center justify-center bg-gray-100 shrink-0 relative" style="border-radius:3px">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 text-gray-500">
              <path stroke-linecap="round" stroke-linejoin="round" d="M12 20.25c4.97 0 9-3.694 9-8.25s-4.03-8.25-9-8.25S3 7.444 3 12c0 2.104.859 4.023 2.273 5.48.432.447.74 1.04.586 1.641a4.483 4.483 0 0 1-.923 1.785A5.969 5.969 0 0 0 6 21c1.282 0 2.47-.402 3.445-1.087.81.22 1.668.337 2.555.337Z" />
            </svg>
          </span>
          <span class="text-sm flex-1">Chats</span>
        </a>

        <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest px-2 pt-3 pb-1.5">Manage</p>

        <a href="./stalls.php" class="sidebar-link flex items-center gap-3 px-3 py-2.5 text-gray-600 hover:bg-gray-50 hover:text-gray-900 border border-transparent font-medium transition-colors" style="border-radius:6px">
          <span class="w-8 h-8 flex items-center justify-center bg-gray-100 shrink-0" style="border-radius:3px">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 text-gray-500">
              <path stroke-linecap="round" stroke-linejoin="round" d="M3 7.5 4.5 3h15L21 7.5m-18 0v12a1.5 1.5 0 0 0 1.5 1.5h15a1.5 1.5 0 0 0 1.5-1.5v-12m-18 0h18M9 12h6" />
            </svg>
          </span>
          <span class="text-sm">Stalls</span>
        </a>

        <a href="./stall-owners.php" class="sidebar-link flex items-center gap-3 px-3 py-2.5 text-gray-600 hover:bg-gray-50 hover:text-gray-900 border border-transparent font-medium transition-colors" style="border-radius:6px">
          <span class="w-8 h-8 flex items-center justify-center bg-gray-100 shrink-0" style="border-radius:3px">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 text-gray-500">
              <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" />
            </svg>
          </span>
          <span class="text-sm">Stall Owners</span>
        </a>

        <a href="./delivery-staff.php" class="sidebar-link flex items-center gap-3 px-3 py-2.5 text-gray-600 hover:bg-gray-50 hover:text-gray-900 border border-transparent font-medium transition-colors" style="border-radius:6px">
          <span class="w-8 h-8 flex items-center justify-center bg-gray-100 shrink-0" style="border-radius:3px">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 text-gray-500">
              <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" />
            </svg>
          </span>
          <span class="text-sm">Delivery Staff</span>
        </a>

        <a href="./customers.php" class="sidebar-link flex items-center gap-3 px-3 py-2.5 text-gray-600 hover:bg-gray-50 hover:text-gray-900 border border-transparent font-medium transition-colors" style="border-radius:6px">
          <span class="w-8 h-8 flex items-center justify-center bg-gray-100 shrink-0" style="border-radius:3px">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 text-gray-500">
              <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z" />
            </svg>
          </span>
          <span class="text-sm">Customers</span>
        </a>

        <a href="./categories.php" class="sidebar-link flex items-center gap-3 px-3 py-2.5 text-gray-600 hover:bg-gray-50 hover:text-gray-900 border border-transparent font-medium transition-colors" style="border-radius:6px">
          <span class="w-8 h-8 flex items-center justify-center bg-gray-100 shrink-0" style="border-radius:3px">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 text-gray-500">
              <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 12h16.5m-16.5 3.75h16.5M3.75 19.5h16.5M5.625 4.5h12.75a1.875 1.875 0 0 1 0 3.75H5.625a1.875 1.875 0 0 1 0-3.75Z" />
            </svg>
          </span>
          <span class="text-sm">Categories</span>
        </a>
        <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest px-2 pt-3 pb-1.5">Account</p>

        <a href="./account.php" class="sidebar-link flex items-center gap-3 px-3 py-2.5 text-gray-600 hover:bg-gray-50 hover:text-gray-900 border border-transparent font-medium transition-colors" style="border-radius:6px">
          <span class="w-8 h-8 flex items-center justify-center bg-gray-100 shrink-0" style="border-radius:3px">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 text-gray-500">
              <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" />
            </svg>
          </span>
          <span class="text-sm">My Account</span>
        </a>

      </nav>
    </aside>

    <div class="flex-1 overflow-y-auto mt-12" id="mainContent">
      <div class="max-w-5xl mx-auto px-4 pt-3 pb-4 space-y-4" id="dashboardContent">
        <div class="grid grid-cols-2 sm:grid-cols-3 gap-3" id="statsGrid"></div>

        <div class="bg-white border border-gray-200 shadow-sm overflow-hidden" style="border-radius:6px">
          <div class="p-4 border-b border-gray-100">
            <p class="text-[11px] font-bold text-gray-500 uppercase tracking-wide">Quick Actions</p>
            <p class="text-[10px] text-gray-400 mt-0.5">Jump straight into any record list</p>
          </div>
          <div class="p-3 grid grid-cols-3 gap-1.5">
            <a
              href="./stalls.php"
              class="flex flex-col items-center justify-center gap-1 py-2 border border-gray-200 hover:border-emerald-500 hover:bg-emerald-50/40 transition-all rounded-[6px]">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 text-emerald-600">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
              </svg>
              <span class="text-[10px] font-semibold text-gray-600 text-center">Add Stall</span>
            </a>
            <a
              href="./customers-bulk.php"
              class="flex flex-col items-center justify-center gap-1 py-2 border border-gray-200 hover:border-emerald-500 hover:bg-emerald-50/40 transition-all rounded-[6px]">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 text-emerald-600">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" />
              </svg>
              <span class="text-[10px] font-semibold text-gray-600 text-center">Bulk Register</span>
            </a>
            <a
              href="./stall-owners.php"
              class="flex flex-col items-center justify-center gap-1 py-2 border border-gray-200 hover:border-emerald-500 hover:bg-emerald-50/40 transition-all rounded-[6px]">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 text-emerald-600">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" />
              </svg>
              <span class="text-[10px] font-semibold text-gray-600 text-center">Manage Owners</span>
            </a>
            <a
              href="./delivery-staff.php"
              class="flex flex-col items-center justify-center gap-1 py-2 border border-gray-200 hover:border-emerald-500 hover:bg-emerald-50/40 transition-all rounded-[6px]">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 text-emerald-600">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" />
              </svg>
              <span class="text-[10px] font-semibold text-gray-600 text-center">Delivery Staff</span>
            </a>
            <a
              href="./categories.php"
              class="flex flex-col items-center justify-center gap-1 py-2 border border-gray-200 hover:border-emerald-500 hover:bg-emerald-50/40 transition-all rounded-[6px]">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 text-emerald-600">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 12h16.5m-16.5 3.75h16.5M3.75 19.5h16.5M5.625 4.5h12.75a1.875 1.875 0 0 1 0 3.75H5.625a1.875 1.875 0 0 1 0-3.75Z" />
              </svg>
              <span class="text-[10px] font-semibold text-gray-600 text-center">Categories</span>
            </a>
            <a
              href="./report.php"
              class="flex flex-col items-center justify-center gap-1 py-2 border border-gray-200 hover:border-emerald-500 hover:bg-emerald-50/40 transition-all rounded-[6px]">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 text-emerald-600">
                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" />
              </svg>
              <span class="text-[10px] font-semibold text-gray-600 text-center">View Reports</span>
            </a>
          </div>
        </div>

        <div class="bg-white border border-gray-200 shadow-sm overflow-hidden" style="border-radius:6px">
          <div class="p-4 border-b border-gray-100 flex items-center justify-between">
            <div>
              <p class="text-[11px] font-bold text-gray-500 uppercase tracking-wide">Recent Customers</p>
              <p class="text-[10px] text-gray-400 mt-0.5">Newest registered customers</p>
            </div>
            <a href="./customers.php" class="text-[11px] font-semibold text-emerald-600 shrink-0">View All</a>
          </div>
          <div class="divide-y divide-gray-100" id="recentCustomersList"></div>
        </div>

        <div class="bg-white border border-gray-200 shadow-sm overflow-hidden" style="border-radius:6px">
          <div class="p-4 border-b border-gray-100 flex items-center justify-between">
            <div>
              <p class="text-[11px] font-bold text-gray-500 uppercase tracking-wide">Recent Stall Owners</p>
              <p class="text-[10px] text-gray-400 mt-0.5">Newest registered stall owners</p>
            </div>
            <a href="./stall-owners.php" class="text-[11px] font-semibold text-emerald-600 shrink-0">View All</a>
          </div>
          <div class="divide-y divide-gray-100" id="recentOwnersList"></div>
        </div>

        <div class="bg-white border border-gray-200 shadow-sm overflow-hidden" style="border-radius:6px">
          <div class="p-4 border-b border-gray-100 flex items-center justify-between">
            <div>
              <p class="text-[11px] font-bold text-gray-500 uppercase tracking-wide">Recent Delivery Staff</p>
              <p class="text-[10px] text-gray-400 mt-0.5">Newest registered delivery staff</p>
            </div>
            <a href="./delivery-staff.php" class="text-[11px] font-semibold text-emerald-600 shrink-0">View All</a>
          </div>
          <div class="divide-y divide-gray-100" id="recentDeliveryList"></div>
        </div>
      </div>
    </div>
  </div>
  <script>
    const DASHBOARD_STATS = [{
        label: "Total Stalls",
        value: "<?php echo number_format($totalStalls); ?>",
        accent: "emerald",
        href: "./stalls.php",
        icon: '<path stroke-linecap="round" stroke-linejoin="round" d="M3 7.5 4.5 3h15L21 7.5m-18 0v12a1.5 1.5 0 0 0 1.5 1.5h15a1.5 1.5 0 0 0 1.5-1.5v-12m-18 0h18M9 12h6" />',
      },
      {
        label: "Stall Owners",
        value: "<?php echo number_format($totalStallOwners); ?>",
        accent: "purple",
        href: "./stall-owners.php",
        icon: '<path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" />',
      },
      {
        label: "Delivery Staff",
        value: "<?php echo number_format($totalDeliveryStaff); ?>",
        accent: "amber",
        href: "./delivery-staff.php",
        icon: '<path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" />',
      },
      {
        label: "Total Customers",
        value: "<?php echo number_format($totalCustomers); ?>",
        accent: "blue",
        href: "./customers.php",
        icon: '<path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z" />',
      },
      {
        label: "Categories",
        value: "<?php echo number_format($totalCategories); ?>",
        accent: "rose",
        href: "./categories.php",
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
      rose: {
        card: "bg-white",
        border: "border-gray-200",
        chip: "bg-rose-50",
        icon: "text-rose-600",
        value: "text-gray-800",
        label: "text-gray-400"
      },
    };

    const RECENT_CUSTOMERS = <?php echo json_encode($recentCustomers); ?>;

    const RECENT_OWNERS = <?php echo json_encode($recentOwners); ?>;

    const RECENT_DELIVERY = <?php echo json_encode($recentDelivery); ?>;

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
      outsider: {
        label: "Outsider",
        cls: "bg-zinc-100 text-zinc-600 border-zinc-200"
      },
    };

    const CUSTOMER_STATUS_MAP = {
      active: {
        label: "Active",
        cls: "bg-emerald-50 text-emerald-700 border-emerald-200"
      },
      inactive: {
        label: "Inactive",
        cls: "bg-red-50 text-red-500 border-red-200"
      },
    };

    const OWNER_STATUS_MAP = {
      active: {
        label: "Active",
        cls: "bg-emerald-50 text-emerald-700 border-emerald-200"
      },
      inactive: {
        label: "Inactive",
        cls: "bg-red-50 text-red-500 border-red-200"
      },
    };

    const DELIVERY_STATUS_MAP = {
      active: {
        label: "Active",
        cls: "bg-emerald-50 text-emerald-700 border-emerald-200"
      },
      inactive: {
        label: "Inactive",
        cls: "bg-red-50 text-red-500 border-red-200"
      },
    };

    function customerTypeBadge(type) {
      const t = CUSTOMER_TYPE_MAP[type];
      if (!t) return "";
      return `<span class="text-[9px] font-semibold px-1.5 py-0.5 border ${t.cls} shrink-0" style="border-radius:3px">${t.label}</span>`;
    }

    function customerStatusBadge(status) {
      const s = CUSTOMER_STATUS_MAP[status];
      if (!s) return "";
      return `<span class="text-[9px] font-semibold px-1.5 py-0.5 border ${s.cls} shrink-0" style="border-radius:3px">${s.label}</span>`;
    }

    function ownerStatusBadge(status) {
      const s = OWNER_STATUS_MAP[status];
      if (!s) return "";
      return `<span class="text-[9px] font-semibold px-1.5 py-0.5 border ${s.cls} shrink-0" style="border-radius:3px">${s.label}</span>`;
    }

    function deliveryStatusBadge(status) {
      const s = DELIVERY_STATUS_MAP[status];
      if (!s) return "";
      return `<span class="text-[9px] font-semibold px-1.5 py-0.5 border ${s.cls} shrink-0" style="border-radius:3px">${s.label}</span>`;
    }

    function escapeHtml(str) {
      if (!str) return "";
      return String(str)
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;");
    }

    function getInitials(name) {
      return name
        .split(" ")
        .filter(Boolean)
        .map((w) => w[0])
        .join("")
        .slice(0, 2)
        .toUpperCase();
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

    function renderRecentCustomers() {
      const list = document.getElementById("recentCustomersList");
      if (!RECENT_CUSTOMERS.length) {
        list.innerHTML = `
            <div class="px-4 py-8 text-center">
              <p class="text-xs text-gray-400">No customers yet.</p>
            </div>
          `;
        return;
      }
      list.innerHTML = RECENT_CUSTOMERS.map(
        (c) => `
            <a href="./customers.php" class="flex items-center gap-2.5 px-3 py-2.5 sm:px-4 sm:py-3 hover:bg-gray-50">
              <div class="w-8 h-8 sm:w-9 sm:h-9 bg-gradient-to-br from-emerald-500 to-emerald-700 flex items-center justify-center text-white text-[11px] sm:text-xs font-bold shrink-0 rounded-full">${getInitials(c.name)}</div>
              <div class="flex-1 min-w-0">
                <p class="text-xs font-semibold text-gray-800 truncate flex items-center gap-1.5">
                  <span class="truncate">${escapeHtml(c.name)}</span>
                  ${customerTypeBadge(c.type)}
                  ${customerStatusBadge(c.status)}
                </p>
                <p class="text-[10px] text-gray-400 truncate mt-0.5">Joined ${escapeHtml(c.joined)}</p>
              </div>
            </a>
          `,
      ).join('<div class="border-t border-gray-100"></div>');
    }

    function renderRecentOwners() {
      const list = document.getElementById("recentOwnersList");
      if (!RECENT_OWNERS.length) {
        list.innerHTML = `
            <div class="px-4 py-8 text-center">
              <p class="text-xs text-gray-400">No stall owners yet.</p>
            </div>
          `;
        return;
      }
      list.innerHTML = RECENT_OWNERS.map(
        (o) => `
            <a href="./stall-owners.php" class="flex items-center gap-2.5 px-3 py-2.5 sm:px-4 sm:py-3 hover:bg-gray-50">
              <div class="w-8 h-8 sm:w-9 sm:h-9 bg-gradient-to-br from-emerald-500 to-emerald-700 flex items-center justify-center text-white text-[11px] sm:text-xs font-bold shrink-0 rounded-full">${getInitials(o.name)}</div>
              <div class="flex-1 min-w-0">
                <p class="text-xs font-semibold text-gray-800 truncate flex items-center gap-1.5">
                  <span class="truncate">${escapeHtml(o.name)}</span>
                  ${ownerStatusBadge(o.status)}
                </p>
                <p class="text-[10px] text-gray-400 truncate mt-0.5">Joined ${escapeHtml(o.joined)}</p>
              </div>
            </a>
          `,
      ).join('<div class="border-t border-gray-100"></div>');
    }

    function renderRecentDelivery() {
      const list = document.getElementById("recentDeliveryList");
      if (!RECENT_DELIVERY.length) {
        list.innerHTML = `
            <div class="px-4 py-8 text-center">
              <p class="text-xs text-gray-400">No delivery staff yet.</p>
            </div>
          `;
        return;
      }
      list.innerHTML = RECENT_DELIVERY.map(
        (d) => `
            <a href="./delivery-staff.php" class="flex items-center gap-2.5 px-3 py-2.5 sm:px-4 sm:py-3 hover:bg-gray-50">
              <div class="w-8 h-8 sm:w-9 sm:h-9 bg-gradient-to-br from-emerald-500 to-emerald-700 flex items-center justify-center text-white text-[11px] sm:text-xs font-bold shrink-0 rounded-full">${getInitials(d.name)}</div>
              <div class="flex-1 min-w-0">
                <p class="text-xs font-semibold text-gray-800 truncate flex items-center gap-1.5">
                  <span class="truncate">${escapeHtml(d.name)}</span>
                  ${deliveryStatusBadge(d.status)}
                </p>
                <p class="text-[10px] text-gray-400 truncate mt-0.5">Joined ${escapeHtml(d.joined)}</p>
              </div>
            </a>
          `,
      ).join('<div class="border-t border-gray-100"></div>');
    }

    window.addEventListener("load", function() {
      renderStats();
      renderRecentCustomers();
      renderRecentOwners();
      renderRecentDelivery();

      document.getElementById("notifBtn").addEventListener("click", () => {});
      document
        .getElementById("messageBtn")
        .addEventListener("click", () => {});

      setupSidebar();
    });

    function setupSidebar() {
      const menuToggle = document.getElementById("menuToggle");
      const sidebar = document.getElementById("sidebar");
      const sidebarOverlay = document.getElementById("sidebarOverlay");
      const closeSidebarBtn = document.getElementById("closeSidebar");

      if (!menuToggle || !sidebar || !sidebarOverlay || !closeSidebarBtn) return;

      function openSidebar() {
        sidebar.classList.add("open");
        sidebarOverlay.classList.add("open");
        document.body.style.overflow = "hidden";
        menuToggle.classList.add("is-open");
        menuToggle.setAttribute("aria-expanded", "true");
      }

      function closeSidebarFn() {
        sidebar.classList.remove("open");
        sidebarOverlay.classList.remove("open");
        document.body.style.overflow = "";
        menuToggle.classList.remove("is-open");
        menuToggle.setAttribute("aria-expanded", "false");
      }

      menuToggle.addEventListener("click", () => {
        sidebar.classList.contains("open") ? closeSidebarFn() : openSidebar();
      });
      closeSidebarBtn.addEventListener("click", closeSidebarFn);
      sidebarOverlay.addEventListener("click", closeSidebarFn);

      document.addEventListener("keydown", (e) => {
        if (e.key === "Escape" && sidebar.classList.contains("open")) closeSidebarFn();
    });
    }
  </script>

</body>

</html>