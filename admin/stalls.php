<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['admin_id'])) {
  header('Location: ../auth/login.php');
  exit;
}

$adminId = $_SESSION['admin_id'];

$adminStmt = $conn->prepare("SELECT first_name, last_name, profile_image FROM admins WHERE admin_id = ? LIMIT 1");
$adminStmt->bind_param("s", $adminId);
$adminStmt->execute();
$adminResult = $adminStmt->get_result();
$admin = $adminResult->fetch_assoc();
$adminStmt->close();

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

function fetchStallsData($conn)
{
  $result = $conn->query("SELECT stall_id, stall_name, status, owner_id, staff_id FROM stalls ORDER BY stall_id ASC");
  $stalls = [];
  while ($row = $result->fetch_assoc()) {
    $stalls[] = [
      'stall_id' => (int) $row['stall_id'],
      'name' => $row['stall_name'],
      'status' => $row['status'],
      'owner_id' => $row['owner_id'] !== null ? (int) $row['owner_id'] : null,
      'staff_id' => $row['staff_id'] !== null ? (int) $row['staff_id'] : null,
    ];
  }
  return $stalls;
}

function fetchOwnersData($conn)
{
  $result = $conn->query("SELECT owner_id, profile_image, first_name, last_name, contact_number, status FROM stall_owners ORDER BY first_name ASC");
  $owners = [];
  while ($row = $result->fetch_assoc()) {
    $owners[] = [
      'owner_id' => (int) $row['owner_id'],
      'profile_image' => $row['profile_image'] ? '../' . $row['profile_image'] : null,
      'first_name' => $row['first_name'],
      'last_name' => $row['last_name'],
      'phone' => $row['contact_number'],
      'status' => $row['status'],
    ];
  }
  return $owners;
}

function formatStaffStatus($status)
{
  $map = [
    'active' => 'Active',
    'inactive' => 'Inactive',
    'on_leave' => 'On Leave',
  ];
  return $map[$status] ?? $status;
}

function fetchStaffData($conn)
{
  $result = $conn->query("SELECT staff_id, profile_image, first_name, last_name, contact_number, status FROM delivery_staff ORDER BY first_name ASC");
  $staff = [];
  while ($row = $result->fetch_assoc()) {
    $staff[] = [
      'staff_id' => (int) $row['staff_id'],
      'profile_image' => $row['profile_image'] ? '../' . $row['profile_image'] : null,
      'first_name' => $row['first_name'],
      'last_name' => $row['last_name'],
      'phone' => $row['contact_number'],
      'status' => formatStaffStatus($row['status']),
    ];
  }
  return $staff;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
  header('Content-Type: application/json');
  $action = $_POST['action'];

  if ($action === 'add_stall') {
    $name = trim($_POST['name'] ?? '');

    if ($name === '') {
      echo json_encode(['success' => false, 'message' => 'Stall name is required.']);
      exit;
    }

    $stmt = $conn->prepare("INSERT INTO stalls (stall_name) VALUES (?)");
    $stmt->bind_param("s", $name);
    $ok = $stmt->execute();
    $stmt->close();

    echo json_encode($ok
      ? ['success' => true]
      : ['success' => false, 'message' => 'Failed to add stall.']);
    $conn->close();
    exit;
  }

  if ($action === 'edit_stall') {
    $stallId = (int) ($_POST['stall_id'] ?? 0);
    $name = trim($_POST['name'] ?? '');

    if ($stallId <= 0 || $name === '') {
      echo json_encode(['success' => false, 'message' => 'Stall name is required.']);
      exit;
    }

    $stmt = $conn->prepare("UPDATE stalls SET stall_name = ? WHERE stall_id = ?");
    $stmt->bind_param("si", $name, $stallId);
    $ok = $stmt->execute();
    $stmt->close();

    echo json_encode($ok
      ? ['success' => true]
      : ['success' => false, 'message' => 'Failed to update stall.']);
    $conn->close();
    exit;
  }

  if ($action === 'delete_stall') {
    $stallId = (int) ($_POST['stall_id'] ?? 0);

    if ($stallId <= 0) {
      echo json_encode(['success' => false, 'message' => 'Invalid stall.']);
      exit;
    }

    try {
      $stmt = $conn->prepare("DELETE FROM stalls WHERE stall_id = ?");
      $stmt->bind_param("i", $stallId);
      $ok = $stmt->execute();
      $stmt->close();

      echo json_encode($ok
        ? ['success' => true]
        : ['success' => false, 'message' => 'Failed to delete stall.']);
    } catch (\mysqli_sql_exception $e) {
      if ($e->getCode() === 1451) {
        echo json_encode(['success' => false, 'message' => 'Still in use — cannot delete']);
      } else {
        error_log('Delete stall failed: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to delete stall.']);
      }
    }
    $conn->close();
    exit;
  }

  if ($action === 'assign_owner') {
    $stallId = (int) ($_POST['stall_id'] ?? 0);
    $ownerIdRaw = $_POST['owner_id'] ?? '';
    $ownerId = $ownerIdRaw === '' ? null : (int) $ownerIdRaw;

    if ($stallId <= 0) {
      echo json_encode(['success' => false, 'message' => 'Invalid stall.']);
      exit;
    }

    if ($ownerId !== null) {
      $checkStmt = $conn->prepare("SELECT stall_id FROM stalls WHERE owner_id = ? AND stall_id != ? LIMIT 1");
      $checkStmt->bind_param("ii", $ownerId, $stallId);
      $checkStmt->execute();
      $taken = $checkStmt->get_result()->fetch_assoc();
      $checkStmt->close();

      if ($taken) {
        echo json_encode(['success' => false, 'message' => 'This owner is already assigned to another stall.']);
        exit;
      }

      $stmt = $conn->prepare("UPDATE stalls SET owner_id = ? WHERE stall_id = ?");
      $stmt->bind_param("ii", $ownerId, $stallId);
    } else {
      $stmt = $conn->prepare("UPDATE stalls SET owner_id = NULL WHERE stall_id = ?");
      $stmt->bind_param("i", $stallId);
    }

    $ok = $stmt->execute();
    $stmt->close();

    if ($ok && $ownerId !== null) {
      $syncStmt = $conn->prepare("UPDATE menu_items SET stall_id = ? WHERE owner_id = ?");
      $syncStmt->bind_param("ii", $stallId, $ownerId);
      $syncStmt->execute();
      $syncStmt->close();
    }

    echo json_encode($ok
      ? ['success' => true]
      : ['success' => false, 'message' => 'Failed to assign owner.']);
    $conn->close();
    exit;
  }

  if ($action === 'assign_staff') {
    $stallId = (int) ($_POST['stall_id'] ?? 0);
    $staffIdRaw = $_POST['staff_id'] ?? '';
    $staffId = $staffIdRaw === '' ? null : (int) $staffIdRaw;

    if ($stallId <= 0) {
      echo json_encode(['success' => false, 'message' => 'Invalid stall.']);
      exit;
    }

    if ($staffId !== null) {
      $stmt = $conn->prepare("UPDATE stalls SET staff_id = ? WHERE stall_id = ?");
      $stmt->bind_param("ii", $staffId, $stallId);
    } else {
      $stmt = $conn->prepare("UPDATE stalls SET staff_id = NULL WHERE stall_id = ?");
      $stmt->bind_param("i", $stallId);
    }

    $ok = $stmt->execute();
    $stmt->close();

    echo json_encode($ok
      ? ['success' => true]
      : ['success' => false, 'message' => 'Failed to assign delivery staff.']);
    $conn->close();
    exit;
  }

  if ($action === 'get_stalls') {
    echo json_encode([
      'success' => true,
      'stalls' => fetchStallsData($conn),
    ]);
    $conn->close();
    exit;
  }

  echo json_encode(['success' => false, 'message' => 'Unknown action.']);
  $conn->close();
  exit;
}

$initialStalls = fetchStallsData($conn);
$initialOwners = fetchOwnersData($conn);
$initialStaff = fetchStaffData($conn);
$conn->close();
?>
<!doctype html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta
    name="viewport"
    content="width=device-width, initial-scale=1.0, user-scalable=yes" />
  <title>Admin - Stalls</title>
  <link rel="icon" href="../assets/images/nwssu-logo.png" type="image/png" />
  <link rel="manifest" href="/manifest.json" />
  <meta name="apple-mobile-web-app-capable" content="yes" />
  <meta name="apple-mobile-web-app-status-bar-style" content="default" />
  <meta name="apple-mobile-web-app-title" content="Norwesso Eats" />
  <link rel="apple-touch-icon" href="/assets/images/icon-192.png" />
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

    .sheet-overlay {
      background-color: rgba(0, 0, 0, 0.45);
    }

    .bottom-sheet {
      border-radius: 14px 14px 0 0;
      max-height: 80vh;
      overflow-y: auto;
    }

    .bottom-sheet::-webkit-scrollbar {
      width: 4px;
    }

    .bottom-sheet::-webkit-scrollbar-thumb {
      background: #d1d5db;
      border-radius: 3px;
    }

    .no-scrollbar::-webkit-scrollbar {
      display: none;
    }

    .no-scrollbar {
      -ms-overflow-style: none;
      scrollbar-width: none;
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
        <div class="flex items-center gap-2 min-w-0">
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
            <a href="./dashboard.php" class="hover:text-gray-900 shrink-0">Dashboard</a>
            <span class="text-gray-300 shrink-0">/</span>
            <span class="text-emerald-600 font-medium truncate">Stalls</span>
          </nav>
        </div>
        <button
          id="addStallBtn"
          class="p-1.5 bg-white border border-gray-200 hover:border-emerald-500 hover:bg-slate-50 transition-all flex items-center justify-center shrink-0"
          style="width: 34px; height: 34px; border-radius: 6px"
          title="Add stall">
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
              d="M12 4.5v15m7.5-7.5h-15" />
          </svg>
        </button>
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

        <a href="./dashboard.php" class="sidebar-link flex items-center gap-3 px-3 py-2.5 text-gray-600 hover:bg-gray-50 hover:text-gray-900 border border-transparent font-medium transition-colors" style="border-radius:6px">
          <span class="w-8 h-8 flex items-center justify-center bg-gray-100 shrink-0" style="border-radius:3px">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 text-gray-500">
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

        <a href="./stalls.php" class="sidebar-link active flex items-center gap-3 px-3 py-2.5 text-emerald-700 bg-emerald-50 border border-emerald-100 font-semibold transition-colors" style="border-radius:6px">
          <span class="w-8 h-8 flex items-center justify-center bg-emerald-600 shrink-0" style="border-radius:3px">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 text-white">
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
      <div class="max-w-5xl mx-auto px-4 pt-3 pb-4 space-y-3">
        <div class="rounded-md bg-white border border-gray-200 p-3 shadow-sm space-y-3">
          <div class="relative">
            <input
              type="text"
              id="searchInput"
              placeholder="Search stalls or owners..."
              class="w-full pl-9 pr-9 py-2 bg-white border border-gray-200 text-xs text-gray-900 placeholder-gray-400 focus:outline-none focus:border-emerald-600 rounded-[3px]" />
            <div class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400">
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
                  d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
              </svg>
            </div>
            <button
              type="button"
              id="clearSearchBtn"
              class="hidden absolute right-2.5 top-1/2 -translate-y-1/2 p-0.5 text-gray-400 hover:text-gray-600 transition-colors rounded-[3px]"
              title="Clear search">
              <svg
                xmlns="http://www.w3.org/2000/svg"
                fill="none"
                viewBox="0 0 24 24"
                stroke-width="2"
                stroke="currentColor"
                class="w-4 h-4">
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  d="M6 18 18 6M6 6l12 12" />
              </svg>
            </button>
          </div>
          <div class="flex flex-wrap justify-end gap-2">
            <div class="relative inline-block">
              <select
                id="statusFilterSelect"
                class="pl-2.5 pr-6 py-2 bg-white border border-gray-200 text-xs font-normal text-gray-700 focus:outline-none focus:border-emerald-600 appearance-none cursor-pointer rounded-[3px]">
                <option value="all">All Stalls</option>
                <option value="open">Open</option>
                <option value="closed">Closed</option>
              </select>
              <div
                class="absolute right-2 top-1/2 -translate-y-1/2 text-gray-400 pointer-events-none">
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
                    d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                </svg>
              </div>
            </div>
          </div>
        </div>

        <div class="rounded-md bg-white border border-gray-200 shadow-sm overflow-hidden">
          <div class="p-4 border-b border-gray-100 flex items-center justify-between">
            <p class="text-xs font-bold text-gray-700">
              All Stalls
              <span class="text-gray-400 font-normal" id="stallCount"></span>
            </p>
          </div>
          <div id="stallList" class="divide-y divide-gray-100"></div>
          <div id="emptyState" class="hidden py-12 text-center">
            <svg
              xmlns="http://www.w3.org/2000/svg"
              fill="none"
              viewBox="0 0 24 24"
              stroke-width="1.5"
              stroke="currentColor"
              class="w-10 h-10 text-gray-300 mx-auto mb-3">
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                d="M13.5 21v-7.5a.75.75 0 0 1 .75-.75h3a.75.75 0 0 1 .75.75V21m-4.5 0H2.36m11.14 0H18m0 0h3.64m-1.39 0V9.349M3.75 21V9.349m0 0a3.001 3.001 0 0 0 3.75-.615A2.993 2.993 0 0 0 9.75 9.75c.896 0 1.7-.393 2.25-1.016a2.993 2.993 0 0 0 2.25 1.016c.896 0 1.7-.393 2.25-1.015a3.001 3.001 0 0 0 3.75.614m-16.5 0a3.004 3.004 0 0 1-.621-4.72l1.189-1.19A1.5 1.5 0 0 1 5.378 3h13.243a1.5 1.5 0 0 1 1.06.44l1.19 1.189a3 3 0 0 1-.621 4.72M6.75 18h3.75a.75.75 0 0 0 .75-.75V13.5a.75.75 0 0 0-.75-.75H6.75a.75.75 0 0 0-.75.75v3.75c0 .414.336.75.75.75Z" />
            </svg>
            <p class="text-sm font-semibold text-gray-500">No stalls found</p>
            <p class="text-xs text-gray-400 mt-0.5">
              Try adjusting your search or filter
            </p>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div
    id="stallModal"
    class="fixed inset-0 z-50 hidden flex items-center justify-center px-4">
    <div class="modal-overlay absolute inset-0" id="closeStallOverlay"></div>
    <div
      class="bg-white w-full max-w-md max-h-[90vh] overflow-y-auto relative z-10 shadow-2xl"
      style="border-radius: 6px">
      <div
        class="p-4 border-b border-gray-100 flex items-center justify-between sticky top-0 bg-white z-10">
        <h2 class="font-bold text-gray-800 text-sm" id="stallModalTitle">
          Add Stall
        </h2>
        <button
          id="closeStallModalBtn"
          class="p-1 hover:bg-gray-100"
          style="border-radius: 3px">
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
      <div class="p-4 space-y-3">
        <div>
          <label
            class="block text-[11px] font-semibold text-gray-500 uppercase tracking-wide mb-1.5">Stall Name</label>
          <input
            type="text"
            id="fieldStallName"
            placeholder="e.g. Stall 1 or Mimi's Milktea"
            class="w-full px-3 py-2.5 bg-white border border-gray-200 text-xs text-gray-900 placeholder-gray-400 focus:outline-none focus:border-emerald-600"
            style="border-radius: 3px" />
        </div>

        <div
          id="stallFormError"
          class="hidden items-center gap-2 p-3 bg-red-50 border border-red-200"
          style="border-radius: 3px">
          <svg
            xmlns="http://www.w3.org/2000/svg"
            fill="none"
            viewBox="0 0 24 24"
            stroke-width="1.5"
            stroke="currentColor"
            class="w-4 h-4 text-red-500 shrink-0">
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
          </svg>
          <p
            class="text-[10px] text-red-600 font-medium leading-none"
            id="stallFormErrorMsg"></p>
        </div>
      </div>
      <div class="px-4 pb-4 flex gap-2">
        <button
          id="cancelStallBtn"
          class="flex-1 py-2.5 border border-gray-200 text-gray-700 text-xs font-semibold hover:bg-gray-50 transition-colors"
          style="border-radius: 3px">
          Cancel
        </button>
        <button
          id="saveStallBtn"
          class="flex-1 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold transition-colors"
          style="border-radius: 3px">
          Save Stall
        </button>
      </div>
    </div>
  </div>

  <div
    id="assignSheet"
    class="fixed inset-0 z-50 hidden flex flex-col justify-end">
    <div class="sheet-overlay absolute inset-0" id="closeAssignOverlay"></div>
    <div class="bottom-sheet bg-white w-full relative z-10 shadow-2xl">
      <div class="flex justify-center pt-2.5 pb-1">
        <div class="w-9 h-1 bg-gray-300" style="border-radius: 999px"></div>
      </div>
      <div
        class="px-4 py-3 border-b border-gray-100 flex items-center justify-between">
        <div>
          <h3 class="text-sm font-bold text-gray-800" id="sheetTitle">
            Assign Owner
          </h3>
          <p class="text-[10px] text-gray-400 mt-0.5" id="sheetSub"></p>
        </div>
        <button
          id="closeSheetBtn"
          class="p-1 hover:bg-gray-100"
          style="border-radius: 3px">
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
      <div
        id="sheetBody"
        class="divide-y divide-gray-100 max-h-64 overflow-y-auto"></div>
      <div class="px-4 py-3 border-t border-gray-100 flex gap-2">
        <button
          id="cancelAssignBtn"
          class="flex-1 py-2.5 border border-gray-200 text-gray-700 text-xs font-semibold hover:bg-gray-50 transition-colors"
          style="border-radius: 3px">
          Cancel
        </button>
        <button
          id="confirmAssignBtn"
          class="flex-1 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold transition-colors"
          style="border-radius: 3px">
          Confirm
        </button>
      </div>
    </div>
  </div>

  <div
    id="deleteStallModal"
    class="fixed inset-0 z-[60] hidden flex items-center justify-center px-4">
    <div class="modal-overlay absolute inset-0" id="closeDeleteStallOverlay"></div>
    <div
      class="bg-white w-full max-w-sm relative z-10 shadow-2xl p-5 space-y-3"
      style="border-radius: 6px">
      <div class="flex items-center gap-2.5">
        <div
          class="w-8 h-8 bg-red-50 flex items-center justify-center shrink-0"
          style="border-radius: 3px">
          <svg
            xmlns="http://www.w3.org/2000/svg"
            fill="none"
            viewBox="0 0 24 24"
            stroke-width="1.5"
            stroke="currentColor"
            class="w-4 h-4 text-red-500">
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
          </svg>
        </div>
        <div>
          <p class="text-sm font-bold text-gray-800">Delete Stall</p>
          <p class="text-[10px] text-gray-400 mt-0.5" id="deleteStallName"></p>
        </div>
      </div>
      <p class="text-xs text-gray-500">
        This stall and its owner/delivery assignments will be permanently
        removed. This cannot be undone.
      </p>
      <div class="flex gap-2 pt-1">
        <button
          id="cancelDeleteStallBtn"
          class="flex-1 py-2 border border-gray-200 text-gray-700 text-xs font-semibold hover:bg-gray-50"
          style="border-radius: 3px">
          Cancel
        </button>
        <button
          id="confirmDeleteStallBtn"
          class="flex-1 py-2 bg-red-500 hover:bg-red-600 text-white text-xs font-semibold transition-colors"
          style="border-radius: 3px">
          Delete
        </button>
      </div>
    </div>
  </div>

  <div
    id="toast"
    class="hidden items-center gap-2 fixed left-1/2 bottom-6 z-40 -translate-x-1/2 max-w-[calc(100%-2rem)] bg-gray-900 text-white text-xs font-medium px-4 py-2.5 shadow-lg rounded-[6px]">
    <svg id="toastIconSvg" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-4 h-4 text-emerald-400 shrink-0">
      <path id="toastIconPath" stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
    </svg>
    <span id="toastMessage" class="truncate"></span>
  </div>

  <script>
    let stalls = <?php echo json_encode($initialStalls); ?>;
    const owners = <?php echo json_encode($initialOwners); ?>;
    const staffPool = <?php echo json_encode($initialStaff); ?>;

    let searchQuery = "";
    let currentStatus = "all";
    let editingStallId = null;
    let deletingStallId = null;
    let sheetStallId = null;
    let sheetType = null;
    let selectedPersonId = null;
    let removeMode = false;
    let toastHideTimeout;

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

    async function refreshStalls() {
      const res = await postAction("get_stalls");
      if (res.success) {
        stalls = res.stalls;
        renderList();
      }
    }

    function getInitials(first, last) {
      return ((first[0] || "") + (last[0] || "")).toUpperCase();
    }

    function escapeHtml(str) {
      if (!str) return "";
      return String(str)
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;");
    }

    function personAvatarHtml(person, sizeCls, textSizeCls) {
      if (person.profile_image) {
        return `<img src="${escapeHtml(person.profile_image)}" class="${sizeCls} object-cover shrink-0 rounded-full" />`;
      }
      return `<div class="${sizeCls} bg-gradient-to-br from-emerald-500 to-emerald-700 text-white ${textSizeCls} flex items-center justify-center font-bold shrink-0 rounded-full">${getInitials(person.first_name, person.last_name)}</div>`;
    }

    function statusBadge(status) {
      if (status === "open")
        return `<span class="text-[10px] font-semibold px-2 py-0.5 border bg-emerald-50 text-emerald-700 border-emerald-200" style="border-radius:3px">Open</span>`;
      return `<span class="text-[10px] font-semibold px-2 py-0.5 border bg-red-50 text-red-500 border-red-200" style="border-radius:3px">Closed</span>`;
    }

    function renderList() {
      const container = document.getElementById("stallList");
      const empty = document.getElementById("emptyState");
      const q = searchQuery.toLowerCase();

      let filtered = stalls.filter((s) => {
        const owner = owners.find((o) => o.owner_id === s.owner_id);
        const ownerName = owner ?
          `${owner.first_name} ${owner.last_name}`.toLowerCase() :
          "";
        const matchSearch = !q || s.name.toLowerCase().includes(q) || ownerName.includes(q);
        const matchStatus =
          currentStatus === "all" || s.status === currentStatus;
        return matchSearch && matchStatus;
      });

      document.getElementById("stallCount").textContent =
        `(${filtered.length})`;

      if (filtered.length === 0) {
        container.innerHTML = "";
        empty.classList.remove("hidden");
        return;
      }
      empty.classList.add("hidden");

      container.innerHTML = filtered
        .map((s) => {
          const owner = owners.find((o) => o.owner_id === s.owner_id);
          const staff = staffPool.find((d) => d.staff_id === s.staff_id);
          const nameParts = s.name.split(" ");

          const ownerRow = owner ?
            `${personAvatarHtml(owner, "w-6 h-6", "text-[9px]")}
               <span class="text-[11px] text-gray-700 font-medium truncate">${escapeHtml(owner.first_name)} ${escapeHtml(owner.last_name)}</span>` :
            `<div class="w-6 h-6 bg-gray-100 flex items-center justify-center text-gray-400 shrink-0 rounded-full">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-3.5 h-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" /></svg>
               </div>
               <span class="text-[11px] text-gray-400 italic">No owner assigned</span>`;

          const staffRow = staff ?
            `${personAvatarHtml(staff, "w-6 h-6", "text-[9px]")}
               <span class="text-[11px] text-gray-700 font-medium truncate">${escapeHtml(staff.first_name)} ${escapeHtml(staff.last_name)}</span>` :
            `<div class="w-6 h-6 bg-gray-100 flex items-center justify-center text-gray-400 shrink-0 rounded-full">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-3.5 h-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" /></svg>
               </div>
               <span class="text-[11px] text-gray-400 italic">No staff assigned</span>`;

          const ownerBtnCls = owner ?
            "text-[10px] font-semibold px-2 py-1 border border-gray-200 text-gray-500 hover:bg-gray-50 transition-colors shrink-0" :
            "text-[10px] font-semibold px-2 py-1 border border-emerald-500 text-emerald-600 hover:bg-emerald-50 transition-colors shrink-0";

          const staffBtnCls =
            "text-[10px] font-semibold px-2 py-1 border border-gray-200 text-gray-500 hover:bg-gray-50 transition-colors shrink-0";

          return `
            <div class="px-4 py-3">
              <div class="flex items-center gap-3 mb-2.5">
                <div class="w-9 h-9 bg-gradient-to-br from-emerald-500 to-emerald-700 flex items-center justify-center text-white text-[10px] font-bold shrink-0 rounded-full">
                  ${getInitials(nameParts[0] || "", nameParts[1] || "")}
                </div>
                <div class="flex-1 min-w-0">
                  <p class="text-xs font-bold text-gray-800 truncate">${escapeHtml(s.name)}</p>
                  <div class="flex items-center gap-1.5 mt-0.5 flex-wrap">
                    ${statusBadge(s.status)}
                  </div>
                </div>
                <button class="p-1 hover:bg-gray-100 transition-colors edit-stall-btn shrink-0" data-id="${s.stall_id}" title="Edit stall" style="border-radius:3px">
                  <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-3.5 h-3.5 text-gray-400 pointer-events-none">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0 1 15.75 21H5.25A2.25 2.25 0 0 1 3 18.75V8.25A2.25 2.25 0 0 1 5.25 6H10" />
                  </svg>
                </button>
                <button class="p-1 hover:bg-red-50 transition-colors delete-stall-btn shrink-0" data-id="${s.stall_id}" title="Delete stall" style="border-radius:3px">
                  <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-3.5 h-3.5 text-red-400 pointer-events-none">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                  </svg>
                </button>
              </div>
              <div class="bg-gray-50 border border-gray-100 p-2.5 space-y-2" style="border-radius:6px">
                <div class="flex items-center gap-2">
                  <span class="text-[10px] text-gray-400 w-12 shrink-0">Owner</span>
                  <div class="flex items-center gap-1.5 flex-1 min-w-0">${ownerRow}</div>
                  <button class="${ownerBtnCls} assign-owner-btn" data-id="${s.stall_id}" style="border-radius:3px">${owner ? "Change" : "Assign"}</button>
                </div>
                <div class="border-t border-gray-100"></div>
                <div class="flex items-center gap-2">
                  <span class="text-[10px] text-gray-400 w-12 shrink-0">Delivery</span>
                  <div class="flex items-center gap-1.5 flex-1 min-w-0">${staffRow}</div>
                  <button class="${staffBtnCls} assign-staff-btn" data-id="${s.stall_id}" style="border-radius:3px">${staff ? "Change" : "Assign"}</button>
                </div>
              </div>
            </div>
          `;
        })
        .join('<div class="border-t border-gray-100"></div>');

      container.querySelectorAll(".assign-owner-btn").forEach((btn) => {
        btn.addEventListener("click", () =>
          openAssignSheet(parseInt(btn.dataset.id), "owner"),
        );
      });
      container.querySelectorAll(".assign-staff-btn").forEach((btn) => {
        btn.addEventListener("click", () =>
          openAssignSheet(parseInt(btn.dataset.id), "staff"),
        );
      });
      container.querySelectorAll(".edit-stall-btn").forEach((btn) => {
        btn.addEventListener("click", () =>
          openEditStallModal(parseInt(btn.dataset.id)),
        );
      });
      container.querySelectorAll(".delete-stall-btn").forEach((btn) => {
        btn.addEventListener("click", () =>
          openDeleteStallModal(parseInt(btn.dataset.id)),
        );
      });
    }

    function openAssignSheet(stallId, type) {
      sheetStallId = stallId;
      sheetType = type;
      removeMode = false;
      const stall = stalls.find((s) => s.stall_id === stallId);
      const pool = type === "owner" ? owners : staffPool;
      const currentId = type === "owner" ? stall.owner_id : stall.staff_id;
      selectedPersonId = currentId;
      const idKey = type === "owner" ? "owner_id" : "staff_id";

      document.getElementById("sheetTitle").textContent =
        type === "owner" ? "Assign owner" : "Assign delivery staff";
      document.getElementById("sheetSub").textContent = stall.name;

      let bodyHtml = "";

      if (type === "staff") {
        bodyHtml += `
            <div class="px-4 py-3 bg-blue-50 border-b border-blue-100">
              <p class="text-[10px] text-blue-600 font-medium">Delivery staff is optional. You can assign one anytime.</p>
            </div>
          `;
      } else {
        bodyHtml += `
            <div class="px-4 py-3 bg-emerald-50 border-b border-emerald-100">
              <p class="text-[10px] text-emerald-700 font-medium">Note: assigning an owner will bring their existing menu items (and prices) along with them to this stall.</p>
            </div>
          `;
      }

      if (currentId) {
        bodyHtml += `
            <div class="flex items-center gap-3 px-4 py-3 cursor-pointer hover:bg-red-50 transition-colors remove-row border-b border-gray-100">
              <div class="w-8 h-8 bg-red-100 flex items-center justify-center shrink-0 rounded-full">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 text-red-500 pointer-events-none">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M22 10.5h-6m-2.25-4.125a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0ZM4 19.235v-.11a6.375 6.375 0 0 1 12.75 0v.109A12.318 12.318 0 0 1 10.374 21c-2.331 0-4.512-.645-6.374-1.766Z" />
                </svg>
              </div>
              <div class="flex-1">
                <p class="text-xs font-semibold text-red-600">Remove current assignment</p>
                <p class="text-[10px] text-red-400">${type === "staff" ? "Leave stall without delivery staff" : "Leave stall without an owner"}</p>
              </div>
            </div>
          `;
      }

      bodyHtml += pool
        .map((p) => {
          const pid = p[idKey];
          const name = `${p.first_name} ${p.last_name}`;
          const sub =
            type === "owner" ?
            escapeHtml(p.phone) :
            `${escapeHtml(p.status)}`;
          const isSelected = pid === currentId;

          const ownerTaken =
            type === "owner" &&
            stalls.some((s) => s.owner_id === pid && s.stall_id !== stallId);
          const ownerTakenStall = ownerTaken ?
            stalls.find((s) => s.owner_id === pid && s.stall_id !== stallId) :
            null;

          const staffAssignedStalls =
            type === "staff" ?
            stalls
            .filter((s) => s.staff_id === pid && s.stall_id !== stallId)
            .map((s) => s.name) :
            [];

          if (ownerTaken) {
            return `
              <div class="flex items-center gap-3 px-4 py-3 opacity-40 cursor-not-allowed select-none" data-pid="${pid}">
                ${personAvatarHtml(p, "w-8 h-8", "text-xs")}
                <div class="flex-1 min-w-0">
                  <p class="text-xs font-semibold text-gray-800 truncate">${escapeHtml(name)}</p>
                  <p class="text-[10px] text-gray-400">Already owner of <span class="font-medium">${escapeHtml(ownerTakenStall.name)}</span></p>
                </div>
                <span class="text-[10px] font-semibold px-2 py-0.5 border bg-gray-100 text-gray-400 border-gray-200 shrink-0" style="border-radius:3px">Taken</span>
              </div>
            `;
          }

          const staffSubText =
            staffAssignedStalls.length > 0 ?
            `${sub} · <span class="text-blue-500 font-medium">Assigned to ${staffAssignedStalls.join(", ")}</span>` :
            sub;

          return `
            <div class="flex items-center gap-3 px-4 py-3 cursor-pointer hover:bg-gray-50 transition-colors person-row ${isSelected ? "bg-emerald-50" : ""}" data-pid="${pid}">
              ${personAvatarHtml(p, "w-8 h-8", "text-xs")}
              <div class="flex-1 min-w-0">
                <p class="text-xs font-semibold text-gray-800 truncate">${escapeHtml(name)}</p>
                <p class="text-[10px] text-gray-400">${staffSubText}</p>
              </div>
              <div class="shrink-0 ${isSelected ? "" : "opacity-0"} selected-check">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-4 h-4 text-emerald-600">
                  <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                </svg>
              </div>
            </div>
          `;
        })
        .join('<div class="border-t border-gray-100 mx-4"></div>');

      document.getElementById("sheetBody").innerHTML = bodyHtml;

      document.querySelectorAll(".person-row").forEach((row) => {
        row.addEventListener("click", () => {
          removeMode = false;
          selectedPersonId = parseInt(row.dataset.pid);
          document.querySelectorAll(".person-row").forEach((r) => {
            r.classList.remove("bg-emerald-50");
            r.querySelector(".selected-check").classList.add("opacity-0");
          });
          row.classList.add("bg-emerald-50");
          row.querySelector(".selected-check").classList.remove("opacity-0");
          const rr = document.querySelector(".remove-row");
          if (rr) rr.classList.remove("bg-red-100");
        });
      });

      const removeRow = document.querySelector(".remove-row");
      if (removeRow) {
        removeRow.addEventListener("click", () => {
          removeMode = true;
          selectedPersonId = null;
          document.querySelectorAll(".person-row").forEach((r) => {
            r.classList.remove("bg-emerald-50");
            r.querySelector(".selected-check").classList.add("opacity-0");
          });
          removeRow.classList.add("bg-red-100");
        });
      }

      document.getElementById("assignSheet").classList.remove("hidden");
      document.body.style.overflow = "hidden";
    }

    function closeAssignSheet() {
      document.getElementById("assignSheet").classList.add("hidden");
      document.body.style.overflow = "";
      sheetStallId = null;
      sheetType = null;
      selectedPersonId = null;
      removeMode = false;
    }

    async function confirmAssign() {
      if (!sheetStallId || !sheetType) return;
      const action = sheetType === "owner" ? "assign_owner" : "assign_staff";
      const idKey = sheetType === "owner" ? "owner_id" : "staff_id";
      const value = removeMode ? "" : selectedPersonId;

      const confirmBtn = document.getElementById("confirmAssignBtn");
      confirmBtn.disabled = true;

      const res = await postAction(action, {
        stall_id: sheetStallId,
        [idKey]: value,
      });

      confirmBtn.disabled = false;

      if (!res.success) {
        showToast(res.message || "Something went wrong. Please try again.", "warning");
        return;
      }

      const wasRemoveMode = removeMode;
      closeAssignSheet();
      await refreshStalls();
      showToast(wasRemoveMode ? "Removed successfully" : "Assigned successfully");
    }

    function openAddStallModal() {
      editingStallId = null;
      document.getElementById("stallModalTitle").textContent = "Add Stall";
      document.getElementById("fieldStallName").value = "";
      document.getElementById("stallFormError").classList.add("hidden");
      document.getElementById("stallFormError").classList.remove("flex");
      document.getElementById("stallModal").classList.remove("hidden");
      document.body.style.overflow = "hidden";
    }

    function openEditStallModal(id) {
      const s = stalls.find((x) => x.stall_id === id);
      if (!s) return;
      editingStallId = id;
      document.getElementById("stallModalTitle").textContent = "Edit Stall";
      document.getElementById("fieldStallName").value = s.name;
      document.getElementById("stallFormError").classList.add("hidden");
      document.getElementById("stallFormError").classList.remove("flex");
      document.getElementById("stallModal").classList.remove("hidden");
      document.body.style.overflow = "hidden";
    }

    function closeStallModal() {
      document.getElementById("stallModal").classList.add("hidden");
      document.body.style.overflow = "";
      editingStallId = null;
    }

    function openDeleteStallModal(id) {
      const s = stalls.find((x) => x.stall_id === id);
      if (!s) return;
      deletingStallId = id;
      document.getElementById("deleteStallName").textContent = s.name;
      document.getElementById("deleteStallModal").classList.remove("hidden");
      document.body.style.overflow = "hidden";
    }

    function closeDeleteStallModal() {
      document.getElementById("deleteStallModal").classList.add("hidden");
      document.body.style.overflow = "";
      deletingStallId = null;
    }

    async function saveStall() {
      const name = document.getElementById("fieldStallName").value.trim();
      const errEl = document.getElementById("stallFormError");
      const errMsg = document.getElementById("stallFormErrorMsg");

      if (!name) {
        errMsg.textContent = "Stall name is required.";
        errEl.classList.remove("hidden");
        errEl.classList.add("flex");
        return;
      }
      errEl.classList.add("hidden");
      errEl.classList.remove("flex");

      const saveBtn = document.getElementById("saveStallBtn");
      saveBtn.disabled = true;

      const isEditing = !!editingStallId;
      const res = isEditing ?
        await postAction("edit_stall", {
          stall_id: editingStallId,
          name
        }) :
        await postAction("add_stall", {
          name
        });

      saveBtn.disabled = false;

      if (!res.success) {
        errMsg.textContent = res.message || "Something went wrong. Please try again.";
        errEl.classList.remove("hidden");
        errEl.classList.add("flex");
        return;
      }

      closeStallModal();
      await refreshStalls();
      showToast(isEditing ? "Stall updated successfully" : "Stall added successfully");
    }

    function updateStatusFilterWidth() {
      const selectEl = document.getElementById("statusFilterSelect");
      const measureEl = document.getElementById("statusFilterMeasure");
      if (!selectEl || !measureEl) return;
      const selectedText = selectEl.options[selectEl.selectedIndex].text;
      measureEl.textContent = selectedText;
      const textWidth = measureEl.offsetWidth;
      selectEl.style.width = (textWidth + 38) + "px";
    }

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

    window.addEventListener("load", function() {
      renderList();
      setupSidebar();

      document
        .getElementById("statusFilterSelect")
        .addEventListener("change", (e) => {
          currentStatus = e.target.value;
          renderList();
        });

      document
        .getElementById("addStallBtn")
        .addEventListener("click", openAddStallModal);
      document
        .getElementById("closeStallModalBtn")
        .addEventListener("click", closeStallModal);
      document
        .getElementById("closeStallOverlay")
        .addEventListener("click", closeStallModal);
      document
        .getElementById("cancelStallBtn")
        .addEventListener("click", closeStallModal);
      document
        .getElementById("saveStallBtn")
        .addEventListener("click", saveStall);

      document
        .getElementById("closeSheetBtn")
        .addEventListener("click", closeAssignSheet);
      document
        .getElementById("closeAssignOverlay")
        .addEventListener("click", closeAssignSheet);
      document
        .getElementById("cancelAssignBtn")
        .addEventListener("click", closeAssignSheet);
      document
        .getElementById("confirmAssignBtn")
        .addEventListener("click", confirmAssign);

      document
        .getElementById("closeDeleteStallOverlay")
        .addEventListener("click", closeDeleteStallModal);
      document
        .getElementById("cancelDeleteStallBtn")
        .addEventListener("click", closeDeleteStallModal);
      document
        .getElementById("confirmDeleteStallBtn")
        .addEventListener("click", async () => {
          const res = await postAction("delete_stall", {
            stall_id: deletingStallId
          });
          closeDeleteStallModal();
          if (res.success) {
            await refreshStalls();
            showToast("Stall deleted successfully");
          } else {
            showToast(res.message || "Failed to delete stall", "warning");
          }
        });

      document
        .getElementById("searchInput")
        .addEventListener("input", (e) => {
          searchQuery = e.target.value;
          document
            .getElementById("clearSearchBtn")
            .classList.toggle("hidden", searchQuery.length === 0);
          renderList();
        });

      document
        .getElementById("clearSearchBtn")
        .addEventListener("click", () => {
          const input = document.getElementById("searchInput");
          input.value = "";
          searchQuery = "";
          document.getElementById("clearSearchBtn").classList.add("hidden");
          input.focus();
          renderList();
        });
    });
  </script>

</body>

</html>