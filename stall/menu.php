<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['owner_id'])) {
  header('Location: ../auth/login.php');
  exit;
}

$ownerId = $_SESSION['owner_id'];

$stallId = null;
$stmt = $conn->prepare("SELECT stall_id FROM stalls WHERE owner_id = ? LIMIT 1");
$stmt->bind_param("i", $ownerId);
$stmt->execute();
$stallResult = $stmt->get_result()->fetch_assoc();
$stmt->close();
if ($stallResult) {
  $stallId = (int) $stallResult['stall_id'];
}

function fetchCategoriesData($conn)
{
  $result = $conn->query("SELECT category_id, category_name FROM categories WHERE status = 'active' ORDER BY category_name ASC");
  $categories = [];
  while ($row = $result->fetch_assoc()) {
    $categories[] = [
      'category_id'   => (int) $row['category_id'],
      'category_name' => $row['category_name'],
    ];
  }
  return $categories;
}

function fetchMenuItemsData($conn, $ownerId)
{
  $items = [];
  $stmt = $conn->prepare("SELECT mi.menu_item_id, mi.category_id, c.category_name, mi.item_name, mi.price, mi.image, mi.status FROM menu_items mi JOIN categories c ON mi.category_id = c.category_id WHERE mi.owner_id = ? ORDER BY mi.menu_item_id DESC");
  $stmt->bind_param("i", $ownerId);
  $stmt->execute();
  $result = $stmt->get_result();
  while ($row = $result->fetch_assoc()) {
    $items[] = [
      'item_id'       => (int) $row['menu_item_id'],
      'category_id'   => (int) $row['category_id'],
      'category_name' => $row['category_name'],
      'name'          => $row['item_name'],
      'price'         => (float) $row['price'],
      'image'         => $row['image'] ? '../' . $row['image'] : null,
      'status'        => $row['status'],
    ];
  }
  $stmt->close();
  return $items;
}

function categoryExists($conn, $categoryId)
{
  $stmt = $conn->prepare("SELECT category_id FROM categories WHERE category_id = ? LIMIT 1");
  $stmt->bind_param("i", $categoryId);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  return (bool) $row;
}

function saveMenuItemImage($base64Data)
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

  $uploadDirFs = __DIR__ . '/../uploads/menu_items/';
  if (!is_dir($uploadDirFs)) {
    mkdir($uploadDirFs, 0755, true);
  }

  $filename = 'item_' . uniqid() . '_' . time() . '.' . $ext;
  file_put_contents($uploadDirFs . $filename, $data);

  return 'uploads/menu_items/' . $filename;
}

function deleteMenuItemImage($dbRelativePath)
{
  if (!$dbRelativePath) return;
  $fsPath = __DIR__ . '/../' . $dbRelativePath;
  if (is_file($fsPath)) {
    @unlink($fsPath);
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
  header('Content-Type: application/json');
  $action = $_POST['action'];

  if ($action === 'add_item') {
    if (!$stallId) {
      echo json_encode(['success' => false, 'message' => 'No stall assigned to your account.']);
      $conn->close();
      exit;
    }

    $itemName   = trim($_POST['item_name'] ?? '');
    $categoryId = (int) ($_POST['category_id'] ?? 0);
    $priceRaw   = $_POST['price'] ?? '';
    $status     = ($_POST['status'] ?? 'available') === 'unavailable' ? 'unavailable' : 'available';
    $imageData  = $_POST['image_data'] ?? '';

    if ($itemName === '' || $categoryId <= 0 || $priceRaw === '' || !is_numeric($priceRaw) || (float) $priceRaw < 0) {
      echo json_encode(['success' => false, 'message' => 'Please fill in all required fields correctly.']);
      $conn->close();
      exit;
    }

    if (!categoryExists($conn, $categoryId)) {
      echo json_encode(['success' => false, 'message' => 'Invalid category selected.']);
      $conn->close();
      exit;
    }

    $price = (float) $priceRaw;

    $imagePath = null;
    if (strpos($imageData, 'data:image') === 0) {
      $imagePath = saveMenuItemImage($imageData);
    }

    $stmt = $conn->prepare("INSERT INTO menu_items (stall_id, owner_id, category_id, item_name, price, image, status) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("iiisdss", $stallId, $ownerId, $categoryId, $itemName, $price, $imagePath, $status);
    $ok = $stmt->execute();
    $stmt->close();

    echo json_encode($ok
      ? ['success' => true]
      : ['success' => false, 'message' => 'Failed to add menu item.']);
    $conn->close();
    exit;
  }

  if ($action === 'edit_item') {
    if (!$stallId) {
      echo json_encode(['success' => false, 'message' => 'No stall assigned to your account.']);
      $conn->close();
      exit;
    }

    $itemId     = (int) ($_POST['menu_item_id'] ?? 0);
    $itemName   = trim($_POST['item_name'] ?? '');
    $categoryId = (int) ($_POST['category_id'] ?? 0);
    $priceRaw   = $_POST['price'] ?? '';
    $status     = ($_POST['status'] ?? 'available') === 'unavailable' ? 'unavailable' : 'available';
    $imageData  = $_POST['image_data'] ?? '';
    $removeImage = ($_POST['remove_image'] ?? '0') === '1';

    if ($itemId <= 0 || $itemName === '' || $categoryId <= 0 || $priceRaw === '' || !is_numeric($priceRaw) || (float) $priceRaw < 0) {
      echo json_encode(['success' => false, 'message' => 'Please fill in all required fields correctly.']);
      $conn->close();
      exit;
    }

    if (!categoryExists($conn, $categoryId)) {
      echo json_encode(['success' => false, 'message' => 'Invalid category selected.']);
      $conn->close();
      exit;
    }

    $stmt = $conn->prepare("SELECT image FROM menu_items WHERE menu_item_id = ? AND owner_id = ? LIMIT 1");
    $stmt->bind_param("ii", $itemId, $ownerId);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$existing) {
      echo json_encode(['success' => false, 'message' => 'Menu item not found.']);
      $conn->close();
      exit;
    }

    $price = (float) $priceRaw;
    $imagePath = $existing['image'];

    if ($removeImage) {
      deleteMenuItemImage($imagePath);
      $imagePath = null;
    } elseif (strpos($imageData, 'data:image') === 0) {
      deleteMenuItemImage($imagePath);
      $imagePath = saveMenuItemImage($imageData);
    }

    $stmt = $conn->prepare("UPDATE menu_items SET category_id = ?, item_name = ?, price = ?, image = ?, status = ? WHERE menu_item_id = ? AND owner_id = ?");
    $stmt->bind_param("isdssii", $categoryId, $itemName, $price, $imagePath, $status, $itemId, $ownerId);
    $ok = $stmt->execute();
    $stmt->close();

    echo json_encode($ok
      ? ['success' => true]
      : ['success' => false, 'message' => 'Failed to update menu item.']);
    $conn->close();
    exit;
  }

  if ($action === 'delete_item') {
    if (!$stallId) {
      echo json_encode(['success' => false, 'message' => 'No stall assigned to your account.']);
      $conn->close();
      exit;
    }

    $itemId = (int) ($_POST['menu_item_id'] ?? 0);

    if ($itemId <= 0) {
      echo json_encode(['success' => false, 'message' => 'Invalid menu item.']);
      $conn->close();
      exit;
    }

    $stmt = $conn->prepare("SELECT image FROM menu_items WHERE menu_item_id = ? AND owner_id = ? LIMIT 1");
    $stmt->bind_param("ii", $itemId, $ownerId);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $stmt = $conn->prepare("DELETE FROM menu_items WHERE menu_item_id = ? AND owner_id = ?");
    $stmt->bind_param("ii", $itemId, $ownerId);
    $ok = $stmt->execute();
    $stmt->close();

    if ($ok && $existing) {
      deleteMenuItemImage($existing['image']);
    }

    echo json_encode($ok
      ? ['success' => true]
      : ['success' => false, 'message' => 'Failed to delete menu item.']);
    $conn->close();
    exit;
  }

  if ($action === 'get_items') {
    echo json_encode([
      'success' => true,
      'items'   => fetchMenuItemsData($conn, $ownerId),
    ]);
    $conn->close();
    exit;
  }

  echo json_encode(['success' => false, 'message' => 'Unknown action.']);
  $conn->close();
  exit;
}

$initialCategories = fetchCategoriesData($conn);
$initialItems = fetchMenuItemsData($conn, $ownerId);
$conn->close();
?>
<!doctype html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta
    name="viewport"
    content="width=device-width, initial-scale=1.0, user-scalable=yes" />
  <title>Stall - Menu Items</title>
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
  </style>
</head>

<body class="bg-white">
  <div class="flex flex-col h-screen">
    <div class="bg-white flex-shrink-0 fixed top-0 left-0 right-0 z-20">
      <div class="max-w-5xl mx-auto px-4 py-2 grid grid-cols-3 items-center">
        <div class="flex items-center gap-2 justify-self-start">
          <button
            id="backButton"
            class="p-1.5 bg-white border border-gray-200 hover:border-emerald-500 hover:bg-slate-50 transition-all flex items-center justify-center shrink-0"
            style="width: 34px; height: 34px; border-radius: 6px"
            title="Go back">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 text-gray-600">
              <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" />
            </svg>
          </button>
        </div>
        <h1 class="text-base font-semibold text-emerald-600 text-center">
          Menu Items
        </h1>
        <button
          id="addItemBtn"
          class="p-1.5 bg-white border border-gray-200 hover:border-emerald-500 hover:bg-slate-50 transition-all justify-self-end flex items-center justify-center shrink-0"
          style="width: 34px; height: 34px; border-radius: 6px"
          title="Add menu item">
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

    <div class="flex-1 overflow-y-auto mt-12 mb-16" id="mainContent">
      <div class="max-w-5xl mx-auto px-4 pt-3 pb-4 space-y-3">
        <div class="rounded-md bg-white border border-gray-200 shadow-sm p-3 space-y-3">
          <div class="relative">
            <input
              type="text"
              id="searchInput"
              placeholder="Search menu items by name..."
              class="w-full pl-9 pr-9 py-2 bg-white border border-gray-200 text-xs text-gray-900 placeholder-gray-400 focus:outline-none focus:border-emerald-600"
              style="border-radius: 3px" />
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
              class="hidden absolute right-2.5 top-1/2 -translate-y-1/2 p-0.5 text-gray-400 hover:text-gray-600 transition-colors"
              style="border-radius: 3px"
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
                id="categoryFilterSelect"
                class="pl-2.5 pr-6 py-2 bg-white border border-gray-200 text-xs font-normal text-gray-700 focus:outline-none focus:border-emerald-600 appearance-none cursor-pointer"
                style="border-radius: 3px">
                <option value="all">All Categories</option>
              </select>
              <span id="categoryFilterMeasure" class="text-xs font-normal" style="position: absolute; visibility: hidden; white-space: pre; left: -9999px; top: -9999px;"></span>
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
            <div class="relative inline-block">
              <select
                id="statusFilterSelect"
                class="pl-2.5 pr-6 py-2 bg-white border border-gray-200 text-xs font-normal text-gray-700 focus:outline-none focus:border-emerald-600 appearance-none cursor-pointer"
                style="border-radius: 3px">
                <option value="all">All Items</option>
                <option value="available">Available</option>
                <option value="unavailable">Unavailable</option>
              </select>
              <span id="statusFilterMeasure" class="text-xs font-normal" style="position: absolute; visibility: hidden; white-space: pre; left: -9999px; top: -9999px;"></span>
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
              All Menu Items
              <span class="text-gray-400 font-normal" id="itemCount"></span>
            </p>
          </div>
          <div id="itemList" class="divide-y divide-gray-100"></div>
          <div id="emptyState" class="hidden py-12 text-center">
            <div class="w-32 h-32 mx-auto mb-3">
              <img src="../assets/illustrations/empty-menu.svg" alt="No menu items found" class="w-full h-full" />
            </div>
            <p class="text-sm font-semibold text-gray-500">No menu items found</p>
            <p class="text-xs text-gray-400 mt-0.5">
              Try adjusting your search or filter
            </p>
          </div>
        </div>
      </div>
    </div>

    <div
      class="bg-white border-t border-gray-200 flex-shrink-0 fixed bottom-0 left-0 right-0 z-20">
      <div class="max-w-5xl mx-auto px-4 flex justify-around py-2">
        <a
          href="./dashboard.php"
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
              d="M3.75 6A2.25 2.25 0 0 1 6 3.75h2.25A2.25 2.25 0 0 1 10.5 6v2.25a2.25 2.25 0 0 1-2.25 2.25H6a2.25 2.25 0 0 1-2.25-2.25V6ZM3.75 15.75A2.25 2.25 0 0 1 6 13.5h2.25a2.25 2.25 0 0 1 2.25 2.25V18a2.25 2.25 0 0 1-2.25 2.25H6A2.25 2.25 0 0 1 3.75 18v-2.25ZM13.5 6a2.25 2.25 0 0 1 2.25-2.25H18A2.25 2.25 0 0 1 20.25 6v2.25A2.25 2.25 0 0 1 18 10.5h-2.25a2.25 2.25 0 0 1-2.25-2.25V6ZM13.5 15.75a2.25 2.25 0 0 1 2.25-2.25H18a2.25 2.25 0 0 1 2.25 2.25V18A2.25 2.25 0 0 1 18 20.25h-2.25A2.25 2.25 0 0 1 13.5 18v-2.25Z" />
          </svg>
          <span class="text-xs font-medium mt-1">Dashboard</span>
        </a>
        <a
          href="./menu.php"
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

  <div
    id="itemModal"
    class="fixed inset-0 z-50 hidden flex items-center justify-center px-4">
    <div class="modal-overlay absolute inset-0" id="closeItemOverlay"></div>
    <div
      class="bg-white w-full max-w-md max-h-[90vh] overflow-y-auto relative z-10 shadow-2xl"
      style="border-radius: 6px">
      <div
        class="p-4 border-b border-gray-100 flex items-center justify-between sticky top-0 bg-white z-10">
        <h2 class="font-bold text-gray-800 text-sm" id="itemModalTitle">
          Add Menu Item
        </h2>
        <button
          id="closeItemModalBtn"
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
            class="block text-[11px] font-semibold text-gray-500 uppercase tracking-wide mb-1.5">Item Photo</label>
          <div
            id="itemImageDropzone"
            class="relative w-full h-36 bg-gray-50 border border-dashed border-gray-300 hover:border-emerald-500 transition-all cursor-pointer overflow-hidden flex items-center justify-center"
            style="border-radius: 6px">
            <img
              id="itemImagePreview"
              src=""
              alt=""
              class="hidden w-full h-full object-cover" />
            <div id="itemImagePlaceholder" class="flex flex-col items-center gap-1.5 text-gray-400 px-4 text-center">
              <svg
                xmlns="http://www.w3.org/2000/svg"
                fill="none"
                viewBox="0 0 24 24"
                stroke-width="1.5"
                stroke="currentColor"
                class="w-7 h-7">
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 0 0 1.5-1.5V6a1.5 1.5 0 0 0-1.5-1.5H3.75A1.5 1.5 0 0 0 2.25 6v12a1.5 1.5 0 0 0 1.5 1.5Zm10.5-11.25h.008v.008h-.008V8.25Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z" />
              </svg>
              <p class="text-[11px] font-medium">Tap to upload a photo</p>
              <p class="text-[10px] text-gray-300">
                Automatically optimized for fast loading
              </p>
            </div>
            <button
              type="button"
              id="removeItemImageBtn"
              class="hidden absolute top-2 right-2 w-6 h-6 bg-white/90 hover:bg-white text-red-500 flex items-center justify-center shadow"
              style="border-radius: 999px"
              title="Remove photo">
              <svg
                xmlns="http://www.w3.org/2000/svg"
                fill="none"
                viewBox="0 0 24 24"
                stroke-width="2"
                stroke="currentColor"
                class="w-3.5 h-3.5">
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  d="M6 18 18 6M6 6l12 12" />
              </svg>
            </button>
          </div>
          <input
            type="file"
            id="itemImageInput"
            accept="image/*"
            class="hidden" />
          <p
            id="itemImgSizeWarning"
            class="hidden text-[10px] text-emerald-600 font-medium text-center mt-1.5"></p>
          <p
            id="itemImgSizeError"
            class="hidden text-[10px] text-red-500 font-medium text-center mt-1.5"></p>
        </div>


        <div>
          <label
            class="block text-[11px] font-semibold text-gray-500 uppercase tracking-wide mb-1.5">Item Name</label>
          <input
            type="text"
            id="fieldItemName"
            placeholder="e.g. Chicken Adobo"
            class="w-full px-3 py-2.5 bg-white border border-gray-200 text-xs text-gray-900 placeholder-gray-400 focus:outline-none focus:border-emerald-600"
            style="border-radius: 3px" />
        </div>

        <div>
          <label
            class="block text-[11px] font-semibold text-gray-500 uppercase tracking-wide mb-1.5">Category</label>
          <div class="relative">
            <select
              id="fieldItemCategory"
              class="w-full pl-3 pr-8 py-2.5 bg-white border border-gray-200 text-xs text-gray-900 focus:outline-none focus:border-emerald-600 appearance-none cursor-pointer"
              style="border-radius: 3px">
              <?php foreach ($initialCategories as $cat): ?>
                <option value="<?php echo (int) $cat['category_id']; ?>"><?php echo htmlspecialchars($cat['category_name']); ?></option>
              <?php endforeach; ?>
            </select>
            <div
              class="absolute right-2.5 top-1/2 -translate-y-1/2 text-gray-400 pointer-events-none">
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

        <div>
          <label
            class="block text-[11px] font-semibold text-gray-500 uppercase tracking-wide mb-1.5">Price (&#8369;)</label>
          <input
            type="number"
            id="fieldItemPrice"
            placeholder="0.00"
            min="0"
            step="0.01"
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
                name="itemStatus"
                value="available"
                checked
                class="accent-emerald-600 shrink-0" />
              <span class="text-xs font-medium text-gray-700">Available</span>
            </label>
            <label
              class="flex items-center gap-2 p-2.5 flex-1 border border-gray-200 cursor-pointer hover:border-emerald-500 transition-all has-[:checked]:border-emerald-500 has-[:checked]:bg-emerald-50/40"
              style="border-radius: 3px">
              <input
                type="radio"
                name="itemStatus"
                value="unavailable"
                class="accent-emerald-600 shrink-0" />
              <span class="text-xs font-medium text-gray-700">Unavailable</span>
            </label>
          </div>
        </div>

        <label
          id="addAnotherItemWrapper"
          class="hidden items-center gap-2 cursor-pointer px-1">
          <input
            type="checkbox"
            id="addAnotherItemCheckbox"
            class="w-4 h-4 accent-emerald-600" />
          <span class="text-xs text-gray-600">Add another item after saving</span>
        </label>

        <div
          id="itemFormError"
          class="hidden flex items-start gap-2 p-3 bg-red-50 border border-red-200"
          style="border-radius: 3px">
          <svg
            xmlns="http://www.w3.org/2000/svg"
            fill="none"
            viewBox="0 0 24 24"
            stroke-width="1.5"
            stroke="currentColor"
            class="w-4 h-4 text-red-500 shrink-0 mt-0.5">
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
          </svg>
          <p
            class="text-[10px] text-red-600 font-medium"
            id="itemFormErrorMsg"></p>
        </div>
      </div>
      <div class="px-4 pb-4 flex gap-2">
        <button
          id="cancelItemBtn"
          class="flex-1 py-2.5 border border-gray-200 text-gray-700 text-xs font-semibold hover:bg-gray-50 transition-colors"
          style="border-radius: 3px">
          Cancel
        </button>
        <button
          id="saveItemBtn"
          class="flex-1 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold transition-colors"
          style="border-radius: 3px">
          Save Item
        </button>
      </div>
    </div>
  </div>

  <div
    id="deleteItemModal"
    class="fixed inset-0 z-[60] hidden flex items-center justify-center px-4">
    <div class="modal-overlay absolute inset-0" id="closeDeleteItemOverlay"></div>
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
          <p class="text-sm font-bold text-gray-800">Delete Menu Item</p>
          <p class="text-[10px] text-gray-400 mt-0.5" id="deleteItemName"></p>
        </div>
      </div>
      <p class="text-xs text-gray-500">
        This menu item will be permanently removed. This cannot be
        undone.
      </p>
      <div class="flex gap-2 pt-1">
        <button
          id="cancelDeleteItemBtn"
          class="flex-1 py-2 border border-gray-200 text-gray-700 text-xs font-semibold hover:bg-gray-50"
          style="border-radius: 3px">
          Cancel
        </button>
        <button
          id="confirmDeleteItemBtn"
          class="flex-1 py-2 bg-red-500 hover:bg-red-600 text-white text-xs font-semibold transition-colors"
          style="border-radius: 3px">
          Delete
        </button>
      </div>
    </div>
  </div>

  <script>
    const initialCategories = <?php echo json_encode($initialCategories); ?>;
    let menuItems = <?php echo json_encode($initialItems); ?>;

    const MAX_IMAGE_DIMENSION = 700;
    const IMAGE_QUALITY = 0.78;
    const MAX_SOURCE_FILE_MB = 15;

    let searchQuery = "";
    let currentStatus = "all";
    let currentCategory = "all";
    let editingItemId = null;
    let deletingItemId = null;
    let currentItemImage = null;
    let removeItemImageFlag = false;

    function escapeHtml(str) {
      if (!str && str !== 0) return "";
      return String(str)
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;");
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

    function formatPrice(price) {
      return "\u20B1" + Number(price).toFixed(2);
    }

    function statusBadge(status) {
      if (status === "available")
        return `<span class="text-[10px] font-semibold px-2 py-0.5 border bg-emerald-50 text-emerald-700 border-emerald-200" style="border-radius:3px">Available</span>`;
      return `<span class="text-[10px] font-semibold px-2 py-0.5 border bg-red-50 text-red-500 border-red-200" style="border-radius:3px">Unavailable</span>`;
    }

    function categoryBadge(categoryName) {
      return `<span class="text-[10px] font-semibold px-2 py-0.5 border bg-indigo-50 text-indigo-700 border-indigo-200" style="border-radius:3px">${escapeHtml(categoryName)}</span>`;
    }

    function itemImageHtml(item) {
      if (item.image) {
        return `<img src="${escapeHtml(item.image)}" class="w-12 h-12 object-cover shrink-0" style="border-radius:6px" />`;
      }
      return `<div class="w-12 h-12 bg-gradient-to-br from-emerald-500 to-emerald-700 flex items-center justify-center text-white text-xs font-bold shrink-0" style="border-radius:6px">${getInitials(item.name)}</div>`;
    }

    function getAllCategories() {
      const set = new Set(menuItems.map((i) => i.category_name));
      return Array.from(set).sort();
    }

    function updateCategoryFilterWidth() {
      const selectEl = document.getElementById("categoryFilterSelect");
      const measureEl = document.getElementById("categoryFilterMeasure");
      if (!selectEl || !measureEl) return;
      const selectedText = selectEl.options[selectEl.selectedIndex].text;
      measureEl.textContent = selectedText;
      const textWidth = measureEl.offsetWidth;
      selectEl.style.width = (textWidth + 38) + "px";
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

    function populateCategoryFilter() {
      const select = document.getElementById("categoryFilterSelect");
      const prevValue = select.value || "all";
      const cats = getAllCategories();
      select.innerHTML =
        `<option value="all">All Categories</option>` +
        cats
        .map(
          (c) =>
          `<option value="${escapeHtml(c)}">${escapeHtml(c)}</option>`,
        )
        .join("");
      if (cats.includes(prevValue) || prevValue === "all") {
        select.value = prevValue;
        currentCategory = prevValue;
      } else {
        select.value = "all";
        currentCategory = "all";
      }
      updateCategoryFilterWidth();
    }

    function renderList() {
      const container = document.getElementById("itemList");
      const empty = document.getElementById("emptyState");
      const q = searchQuery.toLowerCase();

      let filtered = menuItems.filter((item) => {
        const matchSearch = !q || item.name.toLowerCase().includes(q);
        const matchStatus =
          currentStatus === "all" || item.status === currentStatus;
        const matchCategory =
          currentCategory === "all" || item.category_name === currentCategory;
        return matchSearch && matchStatus && matchCategory;
      });

      document.getElementById("itemCount").textContent =
        `(${filtered.length})`;

      if (filtered.length === 0) {
        container.innerHTML = "";
        empty.classList.remove("hidden");
        return;
      }
      empty.classList.add("hidden");

      container.innerHTML = filtered
        .map((item) => {
          return `
            <div class="px-4 py-3 flex items-center gap-3">
              ${itemImageHtml(item)}
              <div class="flex-1 min-w-0">
                <div class="flex items-center gap-1.5 flex-nowrap overflow-hidden">
                  <p class="text-xs font-semibold text-gray-800 truncate min-w-0">${escapeHtml(item.name)}</p>
                  <span class="shrink-0">${statusBadge(item.status)}</span>
                </div>
                <div class="flex items-center gap-1.5 mt-1 flex-wrap">
                  ${categoryBadge(item.category_name)}
                  <span class="text-[11px] font-semibold text-gray-600">${formatPrice(item.price)}</span>
                </div>
              </div>
              <button class="p-1 hover:bg-gray-100 transition-colors edit-item-btn shrink-0" data-id="${item.item_id}" title="Edit item" style="border-radius:3px">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-3.5 h-3.5 text-gray-400 pointer-events-none">
                  <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0 1 15.75 21H5.25A2.25 2.25 0 0 1 3 18.75V8.25A2.25 2.25 0 0 1 5.25 6H10" />
                </svg>
              </button>
              <button class="p-1 hover:bg-red-50 transition-colors delete-item-btn shrink-0" data-id="${item.item_id}" title="Delete item" style="border-radius:3px">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-3.5 h-3.5 text-red-400 pointer-events-none">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                </svg>
              </button>
            </div>
          `;
        })
        .join("");

      container.querySelectorAll(".edit-item-btn").forEach((btn) => {
        btn.addEventListener("click", () =>
          openEditItemModal(parseInt(btn.dataset.id)),
        );
      });
      container.querySelectorAll(".delete-item-btn").forEach((btn) => {
        btn.addEventListener("click", () =>
          openDeleteItemModal(parseInt(btn.dataset.id)),
        );
      });
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

    async function refreshItems() {
      const res = await postAction("get_items");
      if (res.success) {
        menuItems = res.items;
        populateCategoryFilter();
        renderList();
      }
    }

    function updateImagePreview(src) {
      const img = document.getElementById("itemImagePreview");
      const placeholder = document.getElementById("itemImagePlaceholder");
      const removeBtn = document.getElementById("removeItemImageBtn");
      if (src) {
        img.src = src;
        img.classList.remove("hidden");
        placeholder.classList.add("hidden");
        removeBtn.classList.remove("hidden");
      } else {
        img.src = "";
        img.classList.add("hidden");
        placeholder.classList.remove("hidden");
        removeBtn.classList.add("hidden");
      }
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
            if (maxSide > MAX_IMAGE_DIMENSION) {
              const scale = MAX_IMAGE_DIMENSION / maxSide;
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
            resolve(canvas.toDataURL("image/jpeg", IMAGE_QUALITY));
          };
          img.onerror = () => reject(new Error("Unable to read image."));
          img.src = e.target.result;
        };
        reader.onerror = () => reject(new Error("Unable to read file."));
        reader.readAsDataURL(file);
      });
    }

    function resetItemImageUI() {
      document.getElementById("itemImageInput").value = "";
      document.getElementById("itemImgSizeWarning").classList.add("hidden");
      document.getElementById("itemImgSizeError").classList.add("hidden");
      updateImagePreview(currentItemImage);
    }

    function openAddItemModal() {
      editingItemId = null;
      currentItemImage = null;
      removeItemImageFlag = false;
      document.getElementById("itemModalTitle").textContent = "Add Menu Item";
      document.getElementById("fieldItemName").value = "";
      document.getElementById("fieldItemCategory").selectedIndex = 0;
      document.getElementById("fieldItemPrice").value = "";
      document.querySelector(
        "input[name='itemStatus'][value='available']",
      ).checked = true;
      document.getElementById("itemFormError").classList.add("hidden");
      resetItemImageUI();
      const addAnotherWrapper = document.getElementById(
        "addAnotherItemWrapper",
      );
      addAnotherWrapper.classList.remove("hidden");
      addAnotherWrapper.classList.add("flex");
      document.getElementById("addAnotherItemCheckbox").checked = false;
      document.getElementById("itemModal").classList.remove("hidden");
      document.body.style.overflow = "hidden";
      setTimeout(() => document.getElementById("fieldItemName").focus(), 100);
    }

    function openEditItemModal(id) {
      const item = menuItems.find((x) => x.item_id === id);
      if (!item) return;
      editingItemId = id;
      currentItemImage = item.image || null;
      removeItemImageFlag = false;
      document.getElementById("itemModalTitle").textContent = "Edit Menu Item";
      document.getElementById("fieldItemName").value = item.name;
      document.getElementById("fieldItemCategory").value = item.category_id;
      document.getElementById("fieldItemPrice").value = item.price;
      document.querySelector(
        `input[name='itemStatus'][value='${item.status}']`,
      ).checked = true;
      document.getElementById("itemFormError").classList.add("hidden");
      resetItemImageUI();
      const addAnotherWrapper = document.getElementById(
        "addAnotherItemWrapper",
      );
      addAnotherWrapper.classList.add("hidden");
      addAnotherWrapper.classList.remove("flex");
      document.getElementById("itemModal").classList.remove("hidden");
      document.body.style.overflow = "hidden";
    }

    function closeItemModal() {
      document.getElementById("itemModal").classList.add("hidden");
      document.body.style.overflow = "";
      editingItemId = null;
    }

    function openDeleteItemModal(id) {
      const item = menuItems.find((x) => x.item_id === id);
      if (!item) return;
      deletingItemId = id;
      document.getElementById("deleteItemName").textContent = item.name;
      document.getElementById("deleteItemModal").classList.remove("hidden");
      document.body.style.overflow = "hidden";
    }

    function closeDeleteItemModal() {
      document.getElementById("deleteItemModal").classList.add("hidden");
      document.body.style.overflow = "";
      deletingItemId = null;
    }

    function resetFormForNextItem() {
      document.getElementById("fieldItemName").value = "";
      document.getElementById("fieldItemPrice").value = "";
      currentItemImage = null;
      removeItemImageFlag = false;
      resetItemImageUI();
      document.getElementById("itemFormError").classList.add("hidden");
      setTimeout(() => document.getElementById("fieldItemName").focus(), 100);
    }

    async function saveItem() {
      const name = document.getElementById("fieldItemName").value.trim();
      const categoryId = document.getElementById("fieldItemCategory").value;
      const priceRaw = document.getElementById("fieldItemPrice").value;
      const price = parseFloat(priceRaw);
      const status = document.querySelector(
        "input[name='itemStatus']:checked",
      ).value;

      const errEl = document.getElementById("itemFormError");
      const errMsg = document.getElementById("itemFormErrorMsg");

      if (!name) {
        errMsg.textContent = "Please enter the item name.";
        errEl.classList.remove("hidden");
        return;
      }

      if (!categoryId) {
        errMsg.textContent = "Please select a category.";
        errEl.classList.remove("hidden");
        return;
      }

      if (priceRaw === "" || isNaN(price) || price < 0) {
        errMsg.textContent = "Please enter a valid price.";
        errEl.classList.remove("hidden");
        return;
      }
      errEl.classList.add("hidden");

      const isNewUpload =
        typeof currentItemImage === "string" &&
        currentItemImage.startsWith("data:image");

      const payload = {
        item_name: name,
        category_id: categoryId,
        price: price,
        status: status,
        image_data: isNewUpload ? currentItemImage : "",
        remove_image: removeItemImageFlag ? "1" : "0",
      };

      const saveBtn = document.getElementById("saveItemBtn");
      saveBtn.disabled = true;

      const res = editingItemId ?
        await postAction("edit_item", {
          menu_item_id: editingItemId,
          ...payload
        }) :
        await postAction("add_item", payload);

      saveBtn.disabled = false;

      if (!res.success) {
        errMsg.textContent = res.message || "Something went wrong. Please try again.";
        errEl.classList.remove("hidden");
        return;
      }

      await refreshItems();

      const addAnother = !editingItemId &&
        document.getElementById("addAnotherItemCheckbox").checked;

      if (addAnother) {
        resetFormForNextItem();
      } else {
        closeItemModal();
      }
    }

    function setupFilters() {
      document
        .getElementById("statusFilterSelect")
        .addEventListener("change", (e) => {
          currentStatus = e.target.value;
          updateStatusFilterWidth();
          renderList();
        });

      document
        .getElementById("categoryFilterSelect")
        .addEventListener("change", (e) => {
          currentCategory = e.target.value;
          updateCategoryFilterWidth();
          renderList();
        });
    }

    function setupSearch() {
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
    }

    function setupItemModal() {
      document
        .getElementById("addItemBtn")
        .addEventListener("click", openAddItemModal);
      document
        .getElementById("closeItemModalBtn")
        .addEventListener("click", closeItemModal);
      document
        .getElementById("closeItemOverlay")
        .addEventListener("click", closeItemModal);
      document
        .getElementById("cancelItemBtn")
        .addEventListener("click", closeItemModal);
      document
        .getElementById("saveItemBtn")
        .addEventListener("click", saveItem);
    }

    function setupItemImageUpload() {
      document
        .getElementById("itemImageDropzone")
        .addEventListener("click", () =>
          document.getElementById("itemImageInput").click(),
        );
      document
        .getElementById("itemImageInput")
        .addEventListener("change", async (e) => {
          const file = e.target.files[0];
          if (!file) return;

          const warnEl = document.getElementById("itemImgSizeWarning");
          const errEl = document.getElementById("itemImgSizeError");
          warnEl.classList.add("hidden");
          errEl.classList.add("hidden");

          if (!file.type.startsWith("image/")) {
            errEl.textContent = "Please select an image file.";
            errEl.classList.remove("hidden");
            document.getElementById("itemImageInput").value = "";
            return;
          }

          const sizeMB = file.size / (1024 * 1024);
          if (sizeMB > MAX_SOURCE_FILE_MB) {
            errEl.textContent = `This photo is too large (${sizeMB.toFixed(1)}MB). Please choose a photo under ${MAX_SOURCE_FILE_MB}MB.`;
            errEl.classList.remove("hidden");
            document.getElementById("itemImageInput").value = "";
            return;
          }

          try {
            const resizedDataUrl = await resizeImageFile(file);
            removeItemImageFlag = false;
            currentItemImage = resizedDataUrl;
            updateImagePreview(currentItemImage);

            const approxKB = Math.round((resizedDataUrl.length * 0.75) / 1024);
            warnEl.textContent = `Photo optimized automatically (~${approxKB}KB) for fast loading.`;
            warnEl.classList.remove("hidden");
          } catch (err) {
            errEl.textContent = "Something went wrong processing that photo. Please try another.";
            errEl.classList.remove("hidden");
            document.getElementById("itemImageInput").value = "";
          }
        });
      document
        .getElementById("removeItemImageBtn")
        .addEventListener("click", (e) => {
          e.stopPropagation();
          currentItemImage = null;
          removeItemImageFlag = true;
          document.getElementById("itemImageInput").value = "";
          document
            .getElementById("itemImgSizeWarning")
            .classList.add("hidden");
          document
            .getElementById("itemImgSizeError")
            .classList.add("hidden");
          updateImagePreview(null);
        });
    }

    function setupDeleteItemModal() {
      document
        .getElementById("closeDeleteItemOverlay")
        .addEventListener("click", closeDeleteItemModal);
      document
        .getElementById("cancelDeleteItemBtn")
        .addEventListener("click", closeDeleteItemModal);
      document
        .getElementById("confirmDeleteItemBtn")
        .addEventListener("click", async () => {
          const res = await postAction("delete_item", {
            menu_item_id: deletingItemId,
          });
          closeDeleteItemModal();
          if (res.success) {
            await refreshItems();
          }
        });
    }

    function setupBackButton() {
      document
        .getElementById("backButton")
        .addEventListener("click", () => window.history.back());
    }

    function init() {
      populateCategoryFilter();
      updateStatusFilterWidth();
      renderList();
      setupFilters();
      setupSearch();
      setupItemModal();
      setupItemImageUpload();
      setupDeleteItemModal();
      setupBackButton();
    }

    window.addEventListener("load", init);
  </script>
</body>

</html>