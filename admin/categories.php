<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['admin_id'])) {
  header('Location: ../auth/login.php');
  exit;
}

$adminId = $_SESSION['admin_id'];

$adminStmt = $conn->prepare("SELECT first_name, last_name, profile_image FROM admin WHERE admin_id = ? LIMIT 1");
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

function fetchCategoriesData($conn)
{
  $result = $conn->query("SELECT category_id, category_name, status FROM categories ORDER BY category_id DESC");
  $categories = [];
  while ($row = $result->fetch_assoc()) {
    $categories[] = [
      'category_id'   => (int) $row['category_id'],
      'category_name' => $row['category_name'],
      'status'        => $row['status'],
    ];
  }
  return $categories;
}

function categoryNameTakenByOther($conn, $name, $excludeCategoryId = 0)
{
  $stmt = $conn->prepare("SELECT category_id FROM categories WHERE category_name = ? AND category_id != ? LIMIT 1");
  $stmt->bind_param("si", $name, $excludeCategoryId);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  return (bool) $row;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
  header('Content-Type: application/json');
  $action = $_POST['action'];

  if ($action === 'add_category') {
    $categoryName = trim($_POST['category_name'] ?? '');
    $status       = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';

    if ($categoryName === '') {
      echo json_encode(['success' => false, 'message' => 'Please enter a category name.']);
      $conn->close();
      exit;
    }

    if (categoryNameTakenByOther($conn, $categoryName)) {
      echo json_encode(['success' => false, 'message' => 'This category name already exists.']);
      $conn->close();
      exit;
    }

    $stmt = $conn->prepare("INSERT INTO categories (category_name, status) VALUES (?, ?)");
    $stmt->bind_param("ss", $categoryName, $status);
    $ok = $stmt->execute();
    $stmt->close();

    echo json_encode($ok
      ? ['success' => true]
      : ['success' => false, 'message' => 'Failed to add category.']);
    $conn->close();
    exit;
  }

  if ($action === 'edit_category') {
    $categoryId   = (int) ($_POST['category_id'] ?? 0);
    $categoryName = trim($_POST['category_name'] ?? '');
    $status       = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';

    if ($categoryId <= 0 || $categoryName === '') {
      echo json_encode(['success' => false, 'message' => 'Please enter a category name.']);
      $conn->close();
      exit;
    }

    if (categoryNameTakenByOther($conn, $categoryName, $categoryId)) {
      echo json_encode(['success' => false, 'message' => 'This category name already exists.']);
      $conn->close();
      exit;
    }

    $stmt = $conn->prepare("SELECT category_id FROM categories WHERE category_id = ? LIMIT 1");
    $stmt->bind_param("i", $categoryId);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$existing) {
      echo json_encode(['success' => false, 'message' => 'Category not found.']);
      $conn->close();
      exit;
    }

    $stmt = $conn->prepare("UPDATE categories SET category_name = ?, status = ? WHERE category_id = ?");
    $stmt->bind_param("ssi", $categoryName, $status, $categoryId);
    $ok = $stmt->execute();
    $stmt->close();

    echo json_encode($ok
      ? ['success' => true]
      : ['success' => false, 'message' => 'Failed to update category.']);
    $conn->close();
    exit;
  }

  if ($action === 'delete_category') {
    $categoryId = (int) ($_POST['category_id'] ?? 0);

    if ($categoryId <= 0) {
      echo json_encode(['success' => false, 'message' => 'Invalid category.']);
      $conn->close();
      exit;
    }

    $stmt = $conn->prepare("DELETE FROM categories WHERE category_id = ?");
    $stmt->bind_param("i", $categoryId);
    $ok = $stmt->execute();
    $stmt->close();

    echo json_encode($ok
      ? ['success' => true]
      : ['success' => false, 'message' => 'Failed to delete category.']);
    $conn->close();
    exit;
  }

  if ($action === 'get_categories') {
    echo json_encode([
      'success'    => true,
      'categories' => fetchCategoriesData($conn),
    ]);
    $conn->close();
    exit;
  }

  echo json_encode(['success' => false, 'message' => 'Unknown action.']);
  $conn->close();
  exit;
}

$initialCategories = fetchCategoriesData($conn);
$conn->close();
?>
<!doctype html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta
    name="viewport"
    content="width=device-width, initial-scale=1.0, user-scalable=yes" />
  <title>Admin - Categories</title>
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

    .modal-overlay {
      background-color: rgba(0, 0, 0, 0.5);
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
            <a href="./dashboard.php" class="hover:text-emerald-600 shrink-0">Dashboard</a>
            <span class="text-gray-300 shrink-0">/</span>
            <span class="text-gray-700 font-medium truncate">Categories</span>
          </nav>
        </div>
        <button
          id="addCategoryBtn"
          class="p-1.5 bg-white border border-gray-200 hover:border-emerald-500 hover:bg-slate-50 transition-all flex items-center justify-center shrink-0"
          style="width: 34px; height: 34px; border-radius: 6px"
          title="Add category">
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

        <a href="./categories.php" class="sidebar-link active flex items-center gap-3 px-3 py-2.5 text-emerald-700 bg-emerald-50 border border-emerald-100 font-semibold transition-colors" style="border-radius:6px">
          <span class="w-8 h-8 flex items-center justify-center bg-emerald-600 shrink-0" style="border-radius:3px">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 text-white">
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
              placeholder="Search categories..."
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
                <option value="all">All Categories</option>
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
              </select>
              <span
                id="statusFilterMeasure"
                class="text-xs font-normal"
                style="position: absolute; visibility: hidden; white-space: pre; left: -9999px; top: -9999px;"></span>
              <div class="absolute right-2 top-1/2 -translate-y-1/2 text-gray-400 pointer-events-none">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4">
                  <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                </svg>
              </div>
            </div>
          </div>
        </div>

        <div class="rounded-md bg-white border border-gray-200 shadow-sm overflow-hidden">
          <div class="p-4 border-b border-gray-100 flex items-center justify-between">
            <p class="text-xs font-bold text-gray-700">
              All Categories
              <span class="text-gray-400 font-normal" id="categoryCount"></span>
            </p>
          </div>
          <div id="categoryList" class="divide-y divide-gray-100"></div>
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
                d="M3.75 12h16.5m-16.5 3.75h16.5M3.75 19.5h16.5M5.625 4.5h12.75a1.875 1.875 0 0 1 0 3.75H5.625a1.875 1.875 0 0 1 0-3.75Z" />
            </svg>
            <p class="text-sm font-semibold text-gray-500">No categories found</p>
            <p class="text-xs text-gray-400 mt-0.5">
              Try adjusting your search or filter
            </p>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div
    id="categoryModal"
    class="fixed inset-0 z-50 hidden flex items-center justify-center px-4">
    <div class="modal-overlay absolute inset-0" id="closeCategoryOverlay"></div>
    <div
      class="bg-white w-full max-w-md max-h-[90vh] overflow-y-auto relative z-10 shadow-2xl"
      style="border-radius: 6px">
      <div
        class="p-4 border-b border-gray-100 flex items-center justify-between sticky top-0 bg-white z-10">
        <h2 class="font-bold text-gray-800 text-sm" id="categoryModalTitle">
          Add Category
        </h2>
        <button
          id="closeCategoryModalBtn"
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
            class="block text-[11px] font-semibold text-gray-500 uppercase tracking-wide mb-1.5">Category Name</label>
          <input
            type="text"
            id="fieldCategoryName"
            placeholder="e.g. Meals, Drinks, Snacks"
            maxlength="50"
            class="w-full px-3 py-2.5 bg-white border border-gray-200 text-xs text-gray-900 placeholder-gray-400 focus:outline-none focus:border-emerald-600"
            style="border-radius: 3px" />
        </div>

        <div>
          <label
            class="block text-[11px] font-semibold text-gray-500 uppercase tracking-wide mb-1.5">Status</label>
          <div class="flex gap-2">
            <label
              class="flex items-center gap-2 p-2.5 flex-1 border border-gray-200 cursor-pointer hover:border-emerald-500 transition-all has-[:checked]:border-emerald-500 has-[:checked]:bg-emerald-50/40"
              style="border-radius: 3px">
              <input
                type="radio"
                name="categoryStatus"
                value="active"
                checked
                class="accent-emerald-600 shrink-0" />
              <span class="text-xs font-medium text-gray-700">Active</span>
            </label>
            <label
              class="flex items-center gap-2 p-2.5 flex-1 border border-gray-200 cursor-pointer hover:border-emerald-500 transition-all has-[:checked]:border-emerald-500 has-[:checked]:bg-emerald-50/40"
              style="border-radius: 3px">
              <input
                type="radio"
                name="categoryStatus"
                value="inactive"
                class="accent-emerald-600 shrink-0" />
              <span class="text-xs font-medium text-gray-700">Inactive</span>
            </label>
          </div>
        </div>

        <div
          id="categoryFormError"
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
            id="categoryFormErrorMsg"></p>
        </div>
      </div>
      <div class="px-4 pb-4 flex gap-2">
        <button
          id="cancelCategoryBtn"
          class="flex-1 py-2.5 border border-gray-200 text-gray-700 text-xs font-semibold hover:bg-gray-50 transition-colors"
          style="border-radius: 3px">
          Cancel
        </button>
        <button
          id="saveCategoryBtn"
          class="flex-1 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold transition-colors"
          style="border-radius: 3px">
          Save Category
        </button>
      </div>
    </div>
  </div>

  <div
    id="deleteCategoryModal"
    class="fixed inset-0 z-[60] hidden flex items-center justify-center px-4">
    <div class="modal-overlay absolute inset-0" id="closeDeleteCategoryOverlay"></div>
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
          <p class="text-sm font-bold text-gray-800">Delete Category</p>
          <p class="text-[10px] text-gray-400 mt-0.5" id="deleteCategoryName"></p>
        </div>
      </div>
      <p class="text-xs text-gray-500">
        This category will be permanently removed. This cannot be
        undone.
      </p>
      <div class="flex gap-2 pt-1">
        <button
          id="cancelDeleteCategoryBtn"
          class="flex-1 py-2 border border-gray-200 text-gray-700 text-xs font-semibold hover:bg-gray-50"
          style="border-radius: 3px">
          Cancel
        </button>
        <button
          id="confirmDeleteCategoryBtn"
          class="flex-1 py-2 bg-red-500 hover:bg-red-600 text-white text-xs font-semibold transition-colors"
          style="border-radius: 3px">
          Delete
        </button>
      </div>
    </div>
  </div>
  <script>
    let categories = <?php echo json_encode($initialCategories); ?>;

    let searchQuery = "";
    let currentStatus = "all";
    let editingCategoryId = null;
    let deletingCategoryId = null;

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

    async function refreshCategories() {
      const res = await postAction("get_categories");
      if (res.success) {
        categories = res.categories;
        renderList();
      }
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
      return String(name)
        .split(" ")
        .filter(Boolean)
        .map((w) => w[0])
        .join("")
        .slice(0, 2)
        .toUpperCase();
    }

    function statusBadge(status) {
      if (status === "active")
        return `<span class="text-[10px] font-semibold px-2 py-0.5 border bg-emerald-50 text-emerald-700 border-emerald-200" style="border-radius:3px">Active</span>`;
      return `<span class="text-[10px] font-semibold px-2 py-0.5 border bg-red-50 text-red-500 border-red-200" style="border-radius:3px">Inactive</span>`;
    }

    function avatarHtml(category) {
      return `<div class="w-9 h-9 bg-gradient-to-br from-emerald-500 to-emerald-700 flex items-center justify-center text-white text-[10px] font-bold shrink-0 rounded-full">${getInitials(category.category_name)}</div>`;
    }

    function renderList() {
      const container = document.getElementById("categoryList");
      const empty = document.getElementById("emptyState");
      const q = searchQuery.toLowerCase();

      let filtered = categories.filter((c) => {
        const matchSearch = !q || c.category_name.toLowerCase().includes(q);
        const matchStatus = currentStatus === "all" || c.status === currentStatus;
        return matchSearch && matchStatus;
      });

      document.getElementById("categoryCount").textContent = `(${filtered.length})`;

      if (filtered.length === 0) {
        container.innerHTML = "";
        empty.classList.remove("hidden");
        return;
      }
      empty.classList.add("hidden");

      container.innerHTML = filtered
        .map((c) => {
          return `
            <div class="px-4 py-3 flex items-center gap-3">
              ${avatarHtml(c)}
              <div class="flex-1 min-w-0">
                <p class="text-xs font-semibold text-gray-800 truncate">${escapeHtml(c.category_name)}</p>
                <div class="flex items-center gap-1.5 mt-1 flex-wrap">
                  ${statusBadge(c.status)}
                </div>
              </div>
              <button class="p-1 hover:bg-gray-100 transition-colors edit-category-btn shrink-0" data-id="${c.category_id}" title="Edit category" style="border-radius:3px">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-3.5 h-3.5 text-gray-400 pointer-events-none">
                  <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0 1 15.75 21H5.25A2.25 2.25 0 0 1 3 18.75V8.25A2.25 2.25 0 0 1 5.25 6H10" />
                </svg>
              </button>
              <button class="p-1 hover:bg-red-50 transition-colors delete-category-btn shrink-0" data-id="${c.category_id}" title="Delete category" style="border-radius:3px">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-3.5 h-3.5 text-red-400 pointer-events-none">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                </svg>
              </button>
            </div>
          `;
        })
        .join("");

      container.querySelectorAll(".edit-category-btn").forEach((btn) => {
        btn.addEventListener("click", () => openEditCategoryModal(parseInt(btn.dataset.id)));
      });
      container.querySelectorAll(".delete-category-btn").forEach((btn) => {
        btn.addEventListener("click", () => openDeleteCategoryModal(parseInt(btn.dataset.id)));
      });
    }

    function openAddCategoryModal() {
      editingCategoryId = null;
      document.getElementById("categoryModalTitle").textContent = "Add Category";
      document.getElementById("fieldCategoryName").value = "";
      document.querySelector("input[name='categoryStatus'][value='active']").checked = true;
      document.getElementById("categoryFormError").classList.add("hidden");
      document.getElementById("categoryFormError").classList.remove("flex");
      document.getElementById("categoryModal").classList.remove("hidden");
      document.body.style.overflow = "hidden";
    }

    function openEditCategoryModal(id) {
      const c = categories.find((x) => x.category_id === id);
      if (!c) return;
      editingCategoryId = id;
      document.getElementById("categoryModalTitle").textContent = "Edit Category";
      document.getElementById("fieldCategoryName").value = c.category_name;
      document.querySelector(`input[name='categoryStatus'][value='${c.status}']`).checked = true;
      document.getElementById("categoryFormError").classList.add("hidden");
      document.getElementById("categoryFormError").classList.remove("flex");
      document.getElementById("categoryModal").classList.remove("hidden");
      document.body.style.overflow = "hidden";
    }

    function closeCategoryModal() {
      document.getElementById("categoryModal").classList.add("hidden");
      document.body.style.overflow = "";
      editingCategoryId = null;
    }

    function openDeleteCategoryModal(id) {
      const c = categories.find((x) => x.category_id === id);
      if (!c) return;
      deletingCategoryId = id;
      document.getElementById("deleteCategoryName").textContent = c.category_name;
      document.getElementById("deleteCategoryModal").classList.remove("hidden");
      document.body.style.overflow = "hidden";
    }

    function closeDeleteCategoryModal() {
      document.getElementById("deleteCategoryModal").classList.add("hidden");
      document.body.style.overflow = "";
      deletingCategoryId = null;
    }

    async function saveCategory() {
      const categoryName = document.getElementById("fieldCategoryName").value.trim();
      const status = document.querySelector("input[name='categoryStatus']:checked").value;

      const errEl = document.getElementById("categoryFormError");
      const errMsg = document.getElementById("categoryFormErrorMsg");

      const isEditing = !!editingCategoryId;

      if (!categoryName) {
        errMsg.textContent = "Please enter a category name.";
        errEl.classList.remove("hidden");
        errEl.classList.add("flex");
        return;
      }
      errEl.classList.add("hidden");
      errEl.classList.remove("flex");

      const payload = {
        category_name: categoryName,
        status: status,
      };

      const saveBtn = document.getElementById("saveCategoryBtn");
      saveBtn.disabled = true;

      const res = isEditing ?
        await postAction("edit_category", {
          category_id: editingCategoryId,
          ...payload
        }) :
        await postAction("add_category", payload);

      saveBtn.disabled = false;

      if (!res.success) {
        errMsg.textContent = res.message || "Something went wrong. Please try again.";
        errEl.classList.remove("hidden");
        errEl.classList.add("flex");
        return;
      }

      closeCategoryModal();
      await refreshCategories();
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
      updateStatusFilterWidth();

      document.getElementById("statusFilterSelect").addEventListener("change", (e) => {
        currentStatus = e.target.value;
        updateStatusFilterWidth();
        renderList();
      });

      document.getElementById("addCategoryBtn").addEventListener("click", openAddCategoryModal);
      document.getElementById("closeCategoryModalBtn").addEventListener("click", closeCategoryModal);
      document.getElementById("closeCategoryOverlay").addEventListener("click", closeCategoryModal);
      document.getElementById("cancelCategoryBtn").addEventListener("click", closeCategoryModal);
      document.getElementById("saveCategoryBtn").addEventListener("click", saveCategory);

      document.getElementById("closeDeleteCategoryOverlay").addEventListener("click", closeDeleteCategoryModal);
      document.getElementById("cancelDeleteCategoryBtn").addEventListener("click", closeDeleteCategoryModal);
      document.getElementById("confirmDeleteCategoryBtn").addEventListener("click", async () => {
        const res = await postAction("delete_category", {
          category_id: deletingCategoryId
        });
        closeDeleteCategoryModal();
        if (res.success) {
          await refreshCategories();
        }
      });

      document.getElementById("searchInput").addEventListener("input", (e) => {
        searchQuery = e.target.value;
        document.getElementById("clearSearchBtn").classList.toggle("hidden", searchQuery.length === 0);
        renderList();
      });

      document.getElementById("clearSearchBtn").addEventListener("click", () => {
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