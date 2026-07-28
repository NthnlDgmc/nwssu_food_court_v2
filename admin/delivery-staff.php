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

function fetchStaffData($conn)
{
  $result = $conn->query("SELECT staff_id, profile_image, first_name, last_name, contact_number, email, status FROM delivery_staff ORDER BY staff_id DESC");
  $staff = [];
  while ($row = $result->fetch_assoc()) {
    $staff[] = [
      'staff_id'       => (int) $row['staff_id'],
      'profile_image'  => $row['profile_image'] ? '../' . $row['profile_image'] : null,
      'first_name'     => $row['first_name'],
      'last_name'      => $row['last_name'],
      'contact_number' => $row['contact_number'],
      'email'          => $row['email'],
      'status'         => $row['status'],
    ];
  }
  return $staff;
}

function saveStaffProfileImage($base64Data)
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

  $uploadDirFs = __DIR__ . '/../uploads/delivery/';
  if (!is_dir($uploadDirFs)) {
    mkdir($uploadDirFs, 0755, true);
  }

  $filename = 'staff_' . uniqid() . '_' . time() . '.' . $ext;
  file_put_contents($uploadDirFs . $filename, $data);

  return 'uploads/delivery/' . $filename;
}

function deleteStaffProfileImage($dbRelativePath)
{
  if (!$dbRelativePath) return;
  $fsPath = __DIR__ . '/../' . $dbRelativePath;
  if (is_file($fsPath)) {
    @unlink($fsPath);
  }
}

function emailTakenByOther($conn, $email, $excludeStaffId = 0)
{
  if ($email === '' || $email === null) return false;

  $stmt = $conn->prepare("SELECT admin_id FROM admins WHERE email = ? LIMIT 1");
  $stmt->bind_param("s", $email);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  if ($row) return true;

  $stmt = $conn->prepare("SELECT owner_id FROM stall_owners WHERE email = ? LIMIT 1");
  $stmt->bind_param("s", $email);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  if ($row) return true;

  $stmt = $conn->prepare("SELECT staff_id FROM delivery_staff WHERE email = ? AND staff_id != ? LIMIT 1");
  $stmt->bind_param("si", $email, $excludeStaffId);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  if ($row) return true;

  $stmt = $conn->prepare("SELECT customer_id FROM customers WHERE email = ? LIMIT 1");
  $stmt->bind_param("s", $email);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  if ($row) return true;

  return false;
}

function toTitleCase($str)
{
  $str = preg_replace('/\s+/', ' ', trim($str));
  if ($str === '') {
    return '';
  }
  $str = mb_strtolower($str, 'UTF-8');
  return preg_replace_callback(
    "/(^|[\s'\-])(\p{L})/u",
    function ($m) {
      return $m[1] . mb_strtoupper($m[2], 'UTF-8');
    },
    $str
  );
}

function isStrongPassword($password)
{
  if (strlen($password) < 8) return false;
  if (!preg_match('/[A-Z]/', $password)) return false;
  if (!preg_match('/[0-9]/', $password)) return false;
  if (!preg_match('/[^A-Za-z0-9]/', $password)) return false;
  return true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
  header('Content-Type: application/json');
  $action = $_POST['action'];

  if ($action === 'add_staff') {
    $firstName = toTitleCase(trim($_POST['first_name'] ?? ''));
    $lastName  = toTitleCase(trim($_POST['last_name'] ?? ''));
    $contact   = trim($_POST['contact_number'] ?? '');
    $email     = strtolower(preg_replace('/\s+/', '', $_POST['email'] ?? ''));
    $password  = trim($_POST['password'] ?? '');
    $status    = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';
    $imageData = $_POST['profile_image_data'] ?? '';

    if ($firstName === '' && $lastName === '' && $contact === '' && $email === '' && $password === '') {
      echo json_encode(['success' => false, 'message' => 'Please fill in all required fields.']);
      $conn->close();
      exit;
    }

    if ($firstName === '') {
      echo json_encode(['success' => false, 'message' => 'Please enter a first name.']);
      $conn->close();
      exit;
    }

    if (!preg_match("/^[\p{L}\s'\-]+$/u", $firstName)) {
      echo json_encode(['success' => false, 'message' => 'First name can only contain letters.']);
      $conn->close();
      exit;
    }

    if ($lastName === '') {
      echo json_encode(['success' => false, 'message' => 'Please enter a last name.']);
      $conn->close();
      exit;
    }

    if (!preg_match("/^[\p{L}\s'\-]+$/u", $lastName)) {
      echo json_encode(['success' => false, 'message' => 'Last name can only contain letters.']);
      $conn->close();
      exit;
    }

    if ($contact === '') {
      echo json_encode(['success' => false, 'message' => 'Please enter a contact number.']);
      $conn->close();
      exit;
    }

    if ($email === '') {
      echo json_encode(['success' => false, 'message' => 'Please enter an email address.']);
      $conn->close();
      exit;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
      echo json_encode(['success' => false, 'message' => 'Please enter a valid email address.']);
      $conn->close();
      exit;
    }

    if (emailTakenByOther($conn, $email)) {
      echo json_encode(['success' => false, 'message' => 'This email address is already in use.']);
      $conn->close();
      exit;
    }

    if ($password === '') {
      echo json_encode(['success' => false, 'message' => 'Please enter a password.']);
      $conn->close();
      exit;
    }

    if (!isStrongPassword($password)) {
      echo json_encode(['success' => false, 'message' => 'Password must be at least 8 characters and include an uppercase letter, a number, and a symbol.']);
      $conn->close();
      exit;
    }

    $password = password_hash($password, PASSWORD_DEFAULT);

    $profileImagePath = null;
    if (strpos($imageData, 'data:image') === 0) {
      $profileImagePath = saveStaffProfileImage($imageData);
    }

    $stmt = $conn->prepare("INSERT INTO delivery_staff (profile_image, first_name, last_name, contact_number, email, password, status) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("sssssss", $profileImagePath, $firstName, $lastName, $contact, $email, $password, $status);
    $ok = $stmt->execute();
    $stmt->close();

    echo json_encode($ok
      ? ['success' => true]
      : ['success' => false, 'message' => 'Failed to add delivery staff.']);
    $conn->close();
    exit;
  }

  if ($action === 'edit_staff') {
    $staffId   = (int) ($_POST['staff_id'] ?? 0);
    $firstName = toTitleCase(trim($_POST['first_name'] ?? ''));
    $lastName  = toTitleCase(trim($_POST['last_name'] ?? ''));
    $contact   = trim($_POST['contact_number'] ?? '');
    $email     = strtolower(preg_replace('/\s+/', '', $_POST['email'] ?? ''));
    $password  = trim($_POST['password'] ?? '');
    $status    = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';
    $imageData = $_POST['profile_image_data'] ?? '';
    $removeImage = ($_POST['remove_image'] ?? '0') === '1';

    if ($staffId <= 0) {
      echo json_encode(['success' => false, 'message' => 'Invalid delivery staff.']);
      $conn->close();
      exit;
    }

    if ($firstName === '' && $lastName === '' && $contact === '' && $email === '') {
      echo json_encode(['success' => false, 'message' => 'Please fill in all required fields.']);
      $conn->close();
      exit;
    }

    if ($firstName === '') {
      echo json_encode(['success' => false, 'message' => 'Please enter a first name.']);
      $conn->close();
      exit;
    }

    if (!preg_match("/^[\p{L}\s'\-]+$/u", $firstName)) {
      echo json_encode(['success' => false, 'message' => 'First name can only contain letters.']);
      $conn->close();
      exit;
    }

    if ($lastName === '') {
      echo json_encode(['success' => false, 'message' => 'Please enter a last name.']);
      $conn->close();
      exit;
    }

    if (!preg_match("/^[\p{L}\s'\-]+$/u", $lastName)) {
      echo json_encode(['success' => false, 'message' => 'Last name can only contain letters.']);
      $conn->close();
      exit;
    }

    if ($contact === '') {
      echo json_encode(['success' => false, 'message' => 'Please enter a contact number.']);
      $conn->close();
      exit;
    }

    if ($email === '') {
      echo json_encode(['success' => false, 'message' => 'Please enter an email address.']);
      $conn->close();
      exit;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
      echo json_encode(['success' => false, 'message' => 'Please enter a valid email address.']);
      $conn->close();
      exit;
    }

    if (emailTakenByOther($conn, $email, $staffId)) {
      echo json_encode(['success' => false, 'message' => 'This email address is already in use.']);
      $conn->close();
      exit;
    }

    if ($password !== '' && !isStrongPassword($password)) {
      echo json_encode(['success' => false, 'message' => 'Password must be at least 8 characters and include an uppercase letter, a number, and a symbol.']);
      $conn->close();
      exit;
    }

    $stmt = $conn->prepare("SELECT profile_image, password FROM delivery_staff WHERE staff_id = ? LIMIT 1");
    $stmt->bind_param("i", $staffId);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$existing) {
      echo json_encode(['success' => false, 'message' => 'Delivery staff not found.']);
      $conn->close();
      exit;
    }

    $profileImagePath = $existing['profile_image'];

    if ($removeImage) {
      deleteStaffProfileImage($profileImagePath);
      $profileImagePath = null;
    } elseif (strpos($imageData, 'data:image') === 0) {
      deleteStaffProfileImage($profileImagePath);
      $profileImagePath = saveStaffProfileImage($imageData);
    }

    $passwordToSave = $existing['password'];
    if ($password !== '') {
      $passwordToSave = password_hash($password, PASSWORD_DEFAULT);
    }

    $stmt = $conn->prepare("UPDATE delivery_staff SET profile_image = ?, first_name = ?, last_name = ?, contact_number = ?, email = ?, password = ?, status = ? WHERE staff_id = ?");
    $stmt->bind_param("sssssssi", $profileImagePath, $firstName, $lastName, $contact, $email, $passwordToSave, $status, $staffId);
    $ok = $stmt->execute();
    $stmt->close();

    echo json_encode($ok
      ? ['success' => true]
      : ['success' => false, 'message' => 'Failed to update delivery staff.']);
    $conn->close();
    exit;
  }

  if ($action === 'delete_staff') {
    $staffId = (int) ($_POST['staff_id'] ?? 0);

    if ($staffId <= 0) {
      echo json_encode(['success' => false, 'message' => 'Invalid delivery staff.']);
      $conn->close();
      exit;
    }

    $stmt = $conn->prepare("SELECT profile_image FROM delivery_staff WHERE staff_id = ? LIMIT 1");
    $stmt->bind_param("i", $staffId);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $stmt = $conn->prepare("UPDATE stalls SET staff_id = NULL WHERE staff_id = ?");
    $stmt->bind_param("i", $staffId);
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare("DELETE FROM delivery_staff WHERE staff_id = ?");
    $stmt->bind_param("i", $staffId);
    $ok = $stmt->execute();
    $stmt->close();

    if ($ok && $existing) {
      deleteStaffProfileImage($existing['profile_image']);
    }

    echo json_encode($ok
      ? ['success' => true]
      : ['success' => false, 'message' => 'Failed to delete delivery staff.']);
    $conn->close();
    exit;
  }

  if ($action === 'get_staff') {
    echo json_encode([
      'success' => true,
      'staff'   => fetchStaffData($conn),
    ]);
    $conn->close();
    exit;
  }

  echo json_encode(['success' => false, 'message' => 'Unknown action.']);
  $conn->close();
  exit;
}

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
  <title>Admin - Delivery Staff</title>
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
            <a href="./dashboard.php" class="hover:text-gray-900 shrink-0">Dashboard</a>
            <span class="text-gray-300 shrink-0">/</span>
            <span class="text-emerald-600 font-medium truncate">Delivery Staff</span>
          </nav>
        </div>
        <button
          id="addStaffBtn"
          class="p-1.5 bg-white border border-gray-200 hover:border-emerald-500 hover:bg-slate-50 transition-all flex items-center justify-center shrink-0"
          style="width: 34px; height: 34px; border-radius: 6px"
          title="Add delivery staff">
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

        <a href="./delivery-staff.php" class="sidebar-link active flex items-center gap-3 px-3 py-2.5 text-emerald-700 bg-emerald-50 border border-emerald-100 font-semibold transition-colors" style="border-radius:6px">
          <span class="w-8 h-8 flex items-center justify-center bg-emerald-600 shrink-0" style="border-radius:3px">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 text-white">
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
              placeholder="Search delivery staff..."
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
                <option value="all">All Staff</option>
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
              All Delivery Staff
              <span class="text-gray-400 font-normal" id="staffCount"></span>
            </p>
          </div>
          <div id="staffList" class="divide-y divide-gray-100"></div>
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
                d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" />
            </svg>
            <p class="text-sm font-semibold text-gray-500">No delivery staff found</p>
            <p class="text-xs text-gray-400 mt-0.5">
              Try adjusting your search or filter
            </p>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div
    id="staffModal"
    class="fixed inset-0 z-50 hidden flex items-center justify-center px-4">
    <div class="modal-overlay absolute inset-0" id="closeStaffOverlay"></div>
    <div
      class="bg-white w-full max-w-md max-h-[90vh] overflow-y-auto relative z-10 shadow-2xl"
      style="border-radius: 6px">
      <div
        class="p-4 border-b border-gray-100 flex items-center justify-between sticky top-0 bg-white z-10">
        <h2 class="font-bold text-gray-800 text-sm" id="staffModalTitle">
          Add Delivery Staff
        </h2>
        <button
          id="closeStaffModalBtn"
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
        <div class="flex flex-col items-center gap-2">
          <div class="relative">
            <div
              id="profilePreview"
              class="w-16 h-16 bg-gradient-to-br from-emerald-500 to-emerald-700 flex items-center justify-center text-white font-bold text-lg rounded-full overflow-hidden">
              <span id="profileInitials">?</span>
              <img
                id="profilePreviewImg"
                src=""
                alt=""
                class="hidden w-full h-full object-cover"
                style="border-radius: 50%" />
            </div>
            <button
              type="button"
              id="profileImageBtn"
              class="absolute -bottom-1 -right-1 w-6 h-6 bg-emerald-600 hover:bg-emerald-700 flex items-center justify-center shadow transition-colors rounded-full">
              <svg
                xmlns="http://www.w3.org/2000/svg"
                fill="none"
                viewBox="0 0 24 24"
                stroke-width="2"
                stroke="currentColor"
                class="w-3 h-3 text-white">
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  d="M6.827 6.175A2.31 2.31 0 0 1 5.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 0 0-1.134-.175 2.31 2.31 0 0 1-1.64-1.055l-.822-1.316a2.192 2.192 0 0 0-1.736-1.039 48.774 48.774 0 0 0-5.232 0 2.192 2.192 0 0 0-1.736 1.039l-.821 1.316Z" />
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  d="M16.5 12.75a4.5 4.5 0 1 1-9 0 4.5 4.5 0 0 1 9 0ZM18.75 10.5h.008v.008h-.008V10.5Z" />
              </svg>
            </button>
          </div>
          <input
            type="file"
            id="profileImageInput"
            accept="image/*"
            class="hidden" />
          <div class="text-center">
            <p class="text-[10px] text-gray-400">
              Profile Photo <span class="text-gray-300">(optional)</span>
            </p>
            <button
              type="button"
              id="removeProfileImageBtn"
              class="hidden text-[10px] text-red-400 hover:text-red-600 font-semibold transition-colors mt-0.5">
              Remove photo
            </button>
          </div>
        </div>

        <div class="grid grid-cols-2 gap-3">
          <div>
            <label
              class="block text-[11px] font-semibold text-gray-500 uppercase tracking-wide mb-1.5">First Name</label>
            <input
              type="text"
              id="fieldFirstName"
              placeholder="First name"
              class="w-full px-3 py-2.5 bg-white border border-gray-200 text-xs text-gray-900 placeholder-gray-400 focus:outline-none focus:border-emerald-600"
              style="border-radius: 3px" />
          </div>
          <div>
            <label
              class="block text-[11px] font-semibold text-gray-500 uppercase tracking-wide mb-1.5">Last Name</label>
            <input
              type="text"
              id="fieldLastName"
              placeholder="Last name"
              class="w-full px-3 py-2.5 bg-white border border-gray-200 text-xs text-gray-900 placeholder-gray-400 focus:outline-none focus:border-emerald-600"
              style="border-radius: 3px" />
          </div>
        </div>

        <div>
          <label
            class="block text-[11px] font-semibold text-gray-500 uppercase tracking-wide mb-1.5">Contact Number</label>
          <input
            type="tel"
            id="fieldContact"
            placeholder="+63 9XX XXXX XXX"
            class="w-full px-3 py-2.5 bg-white border border-gray-200 text-xs text-gray-900 placeholder-gray-400 focus:outline-none focus:border-emerald-600"
            style="border-radius: 3px" />
        </div>

        <div>
          <label
            class="block text-[11px] font-semibold text-gray-500 uppercase tracking-wide mb-1.5">Email Address</label>
          <input
            type="email"
            id="fieldEmail"
            placeholder="name@example.com"
            class="w-full px-3 py-2.5 bg-white border border-gray-200 text-xs text-gray-900 placeholder-gray-400 focus:outline-none focus:border-emerald-600"
            style="border-radius: 3px" />
        </div>

        <div>
          <label
            class="block text-[11px] font-semibold text-gray-500 uppercase tracking-wide mb-1.5">Password</label>
          <div class="relative">
            <input
              type="password"
              id="fieldPassword"
              placeholder="Enter password"
              class="w-full px-3 py-2.5 pr-9 bg-white border border-gray-200 text-xs text-gray-900 placeholder-gray-400 focus:outline-none focus:border-emerald-600"
              style="border-radius: 3px" />
            <button
              type="button"
              id="togglePasswordBtn"
              class="absolute right-2.5 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 transition-colors">
              <svg
                xmlns="http://www.w3.org/2000/svg"
                fill="none"
                viewBox="0 0 24 24"
                stroke-width="1.5"
                stroke="currentColor"
                class="w-4 h-4"
                id="eyeIcon">
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" />
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
              </svg>
            </button>
          </div>
          <p
            class="text-[10px] text-gray-400 mt-1"
            id="passwordHint"></p>
          <div class="mt-2">
            <div class="flex gap-1">
              <div
                class="h-1 flex-1 bg-gray-200 transition-colors"
                style="border-radius: 999px"
                id="pwBar1"></div>
              <div
                class="h-1 flex-1 bg-gray-200 transition-colors"
                style="border-radius: 999px"
                id="pwBar2"></div>
              <div
                class="h-1 flex-1 bg-gray-200 transition-colors"
                style="border-radius: 999px"
                id="pwBar3"></div>
              <div
                class="h-1 flex-1 bg-gray-200 transition-colors"
                style="border-radius: 999px"
                id="pwBar4"></div>
            </div>
            <p class="text-[10px] mt-1" id="pwStrengthLabel"></p>
          </div>
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
                name="staffStatus"
                value="active"
                checked
                class="accent-emerald-600 shrink-0" />
              <span class="text-xs font-medium text-gray-700">Active</span>
            </label>
            <label
              class="flex items-center gap-2 p-2.5 flex-1 border border-gray-200 cursor-pointer hover:border-red-400 transition-all has-[:checked]:border-red-400 has-[:checked]:bg-red-50/40"
              style="border-radius: 3px">
              <input
                type="radio"
                name="staffStatus"
                value="inactive"
                class="accent-red-500 shrink-0" />
              <span class="text-xs font-medium text-gray-700">Inactive</span>
            </label>
          </div>
        </div>

        <div
          id="staffFormError"
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
            id="staffFormErrorMsg"></p>
        </div>
      </div>
      <div class="px-4 pb-4 flex gap-2">
        <button
          id="cancelStaffBtn"
          class="flex-1 py-2.5 border border-gray-200 text-gray-700 text-xs font-semibold hover:bg-gray-50 transition-colors"
          style="border-radius: 3px">
          Cancel
        </button>
        <button
          id="saveStaffBtn"
          class="flex-1 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold transition-colors"
          style="border-radius: 3px">
          Save Staff
        </button>
      </div>
    </div>
  </div>

  <div
    id="deleteStaffModal"
    class="fixed inset-0 z-[60] hidden flex items-center justify-center px-4">
    <div class="modal-overlay absolute inset-0" id="closeDeleteStaffOverlay"></div>
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
          <p class="text-sm font-bold text-gray-800">Delete Delivery Staff</p>
          <p class="text-[10px] text-gray-400 mt-0.5" id="deleteStaffName"></p>
        </div>
      </div>
      <p class="text-xs text-gray-500">
        This delivery staff record will be permanently removed and unassigned from any stalls. This cannot be
        undone.
      </p>
      <div class="flex gap-2 pt-1">
        <button
          id="cancelDeleteStaffBtn"
          class="flex-1 py-2 border border-gray-200 text-gray-700 text-xs font-semibold hover:bg-gray-50"
          style="border-radius: 3px">
          Cancel
        </button>
        <button
          id="confirmDeleteStaffBtn"
          class="flex-1 py-2 bg-red-500 hover:bg-red-600 text-white text-xs font-semibold transition-colors"
          style="border-radius: 3px">
          Delete
        </button>
      </div>
    </div>
  </div>
  <script>
    let staffMembers = <?php echo json_encode($initialStaff); ?>;

    let searchQuery = "";
    let currentStatus = "all";
    let editingStaffId = null;
    let deletingStaffId = null;
    let currentProfileImage = null;
    let removeImageFlag = false;

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

    async function refreshStaff() {
      const res = await postAction("get_staff");
      if (res.success) {
        staffMembers = res.staff;
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

    function getInitials(first, last) {
      return ((first[0] || "") + (last[0] || "")).toUpperCase();
    }

    function isValidEmail(val) {
      return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(val);
    }

    function toTitleCase(str) {
      return str
        .trim()
        .replace(/\s+/g, " ")
        .toLowerCase()
        .replace(/(^|[\s'-])\p{L}/gu, (c) => c.toUpperCase());
    }

    function isValidName(val) {
      return /^[\p{L}\s'-]+$/u.test(val);
    }

    function isStrongPassword(val) {
      return (
        val.length >= 8 &&
        /[A-Z]/.test(val) &&
        /[0-9]/.test(val) &&
        /[^A-Za-z0-9]/.test(val)
      );
    }

    function displayOrDash(val) {
      const v = (val || "").toString().trim();
      return v === "" ? "–" : escapeHtml(v);
    }

    function statusBadge(status) {
      if (status === "active")
        return `<span class="text-[10px] font-semibold px-2 py-0.5 border bg-emerald-50 text-emerald-700 border-emerald-200" style="border-radius:3px">Active</span>`;
      return `<span class="text-[10px] font-semibold px-2 py-0.5 border bg-red-50 text-red-500 border-red-200" style="border-radius:3px">Inactive</span>`;
    }

    function avatarHtml(person) {
      if (person.profile_image) {
        return `<img src="${escapeHtml(person.profile_image)}" class="w-9 h-9 rounded-full object-cover shrink-0" />`;
      }
      return `<div class="w-9 h-9 bg-gradient-to-br from-emerald-500 to-emerald-700 flex items-center justify-center text-white text-[10px] font-bold shrink-0 rounded-full">${getInitials(person.first_name, person.last_name)}</div>`;
    }

    function updateProfilePreview(src, initials) {
      const img = document.getElementById("profilePreviewImg");
      const span = document.getElementById("profileInitials");
      const removeBtn = document.getElementById("removeProfileImageBtn");
      if (src) {
        img.src = src;
        img.classList.remove("hidden");
        span.classList.add("hidden");
        removeBtn.classList.remove("hidden");
      } else {
        img.src = "";
        img.classList.add("hidden");
        span.classList.remove("hidden");
        span.textContent = initials || "?";
        removeBtn.classList.add("hidden");
      }
    }

    function renderList() {
      const container = document.getElementById("staffList");
      const empty = document.getElementById("emptyState");
      const q = searchQuery.toLowerCase();

      let filtered = staffMembers.filter((s) => {
        const fullName = `${s.first_name} ${s.last_name}`.toLowerCase();
        const matchSearch = !q ||
          fullName.includes(q) ||
          (s.contact_number || "").toLowerCase().includes(q) ||
          (s.email || "").toLowerCase().includes(q);
        const matchStatus = currentStatus === "all" || s.status === currentStatus;
        return matchSearch && matchStatus;
      });

      document.getElementById("staffCount").textContent = `(${filtered.length})`;

      if (filtered.length === 0) {
        container.innerHTML = "";
        empty.classList.remove("hidden");
        return;
      }
      empty.classList.add("hidden");

      container.innerHTML = filtered
        .map((s) => {
          return `
            <div class="px-4 py-3 flex items-center gap-3">
              ${avatarHtml(s)}
              <div class="flex-1 min-w-0">
                <p class="text-xs font-semibold text-gray-800 truncate">${escapeHtml(s.first_name)} ${escapeHtml(s.last_name)}</p>
                <div class="flex items-center gap-1.5 mt-1 flex-wrap">
                  ${statusBadge(s.status)}
                </div>
                <p class="text-[11px] text-gray-400 truncate mt-1">${displayOrDash(s.contact_number)} &middot; ${displayOrDash(s.email)}</p>
              </div>
              <button class="p-1 hover:bg-gray-100 transition-colors edit-staff-btn shrink-0" data-id="${s.staff_id}" title="Edit delivery staff" style="border-radius:3px">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-3.5 h-3.5 text-gray-400 pointer-events-none">
                  <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0 1 15.75 21H5.25A2.25 2.25 0 0 1 3 18.75V8.25A2.25 2.25 0 0 1 5.25 6H10" />
                </svg>
              </button>
              <button class="p-1 hover:bg-red-50 transition-colors delete-staff-btn shrink-0" data-id="${s.staff_id}" title="Delete delivery staff" style="border-radius:3px">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-3.5 h-3.5 text-red-400 pointer-events-none">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                </svg>
              </button>
            </div>
          `;
        })
        .join("");

      container.querySelectorAll(".edit-staff-btn").forEach((btn) => {
        btn.addEventListener("click", () => openEditStaffModal(parseInt(btn.dataset.id)));
      });
      container.querySelectorAll(".delete-staff-btn").forEach((btn) => {
        btn.addEventListener("click", () => openDeleteStaffModal(parseInt(btn.dataset.id)));
      });
    }

    function resetPasswordMeter() {
      [1, 2, 3, 4].forEach((n) => {
        const b = document.getElementById("pwBar" + n);
        b.className = "h-1 flex-1 bg-gray-200 transition-colors";
        b.style.borderRadius = "999px";
      });
      document.getElementById("pwStrengthLabel").textContent = "";
      document.getElementById("fieldPassword").type = "password";
      document.getElementById("eyeIcon").innerHTML =
        '<path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />';
    }

    function openAddStaffModal() {
      editingStaffId = null;
      currentProfileImage = null;
      removeImageFlag = false;
      document.getElementById("staffModalTitle").textContent = "Add Delivery Staff";
      document.getElementById("fieldFirstName").value = "";
      document.getElementById("fieldLastName").value = "";
      document.getElementById("fieldContact").value = "";
      document.getElementById("fieldEmail").value = "";
      document.getElementById("fieldPassword").value = "";
      document.getElementById("fieldPassword").placeholder = "Enter password";
      document.getElementById("passwordHint").textContent = "Must include uppercase, a number, and a symbol.";
      document.querySelector("input[name='staffStatus'][value='active']").checked = true;
      document.getElementById("staffFormError").classList.add("hidden");
      document.getElementById("staffFormError").classList.remove("flex");
      document.getElementById("profileImageInput").value = "";
      updateProfilePreview(null, "?");
      resetPasswordMeter();
      document.getElementById("staffModal").classList.remove("hidden");
      document.body.style.overflow = "hidden";
    }

    function openEditStaffModal(id) {
      const s = staffMembers.find((x) => x.staff_id === id);
      if (!s) return;
      editingStaffId = id;
      currentProfileImage = s.profile_image || null;
      removeImageFlag = false;
      document.getElementById("staffModalTitle").textContent = "Edit Delivery Staff";
      document.getElementById("fieldFirstName").value = s.first_name;
      document.getElementById("fieldLastName").value = s.last_name;
      document.getElementById("fieldContact").value = s.contact_number;
      document.getElementById("fieldEmail").value = s.email;
      document.getElementById("fieldPassword").value = "";
      document.getElementById("fieldPassword").placeholder = "••••••••";
      document.getElementById("passwordHint").textContent = "Leave blank to keep the current password.";
      document.querySelector(`input[name='staffStatus'][value='${s.status}']`).checked = true;
      document.getElementById("staffFormError").classList.add("hidden");
      document.getElementById("staffFormError").classList.remove("flex");
      document.getElementById("profileImageInput").value = "";
      updateProfilePreview(s.profile_image || null, getInitials(s.first_name, s.last_name));
      resetPasswordMeter();
      document.getElementById("staffModal").classList.remove("hidden");
      document.body.style.overflow = "hidden";
    }

    function closeStaffModal() {
      document.getElementById("staffModal").classList.add("hidden");
      document.body.style.overflow = "";
      editingStaffId = null;
    }

    function openDeleteStaffModal(id) {
      const s = staffMembers.find((x) => x.staff_id === id);
      if (!s) return;
      deletingStaffId = id;
      document.getElementById("deleteStaffName").textContent = `${s.first_name} ${s.last_name}`;
      document.getElementById("deleteStaffModal").classList.remove("hidden");
      document.body.style.overflow = "hidden";
    }

    function closeDeleteStaffModal() {
      document.getElementById("deleteStaffModal").classList.add("hidden");
      document.body.style.overflow = "";
      deletingStaffId = null;
    }

    async function saveStaff() {
      const firstName = document.getElementById("fieldFirstName").value.trim();
      const lastName = document.getElementById("fieldLastName").value.trim();
      const contact = document.getElementById("fieldContact").value.trim();
      const email = document.getElementById("fieldEmail").value.trim();
      const password = document.getElementById("fieldPassword").value.trim();
      const status = document.querySelector("input[name='staffStatus']:checked").value;

      const errEl = document.getElementById("staffFormError");
      const errMsg = document.getElementById("staffFormErrorMsg");

      const isEditing = !!editingStaffId;

      const allCoreBlank = isEditing
        ? (!firstName && !lastName && !contact && !email)
        : (!firstName && !lastName && !contact && !email && !password);

      if (allCoreBlank) {
        errMsg.textContent = "Please fill in all required fields.";
        errEl.classList.remove("hidden");
        errEl.classList.add("flex");
        return;
      }

      if (!firstName) {
        errMsg.textContent = "Please enter a first name.";
        errEl.classList.remove("hidden");
        errEl.classList.add("flex");
        return;
      }

      if (!isValidName(firstName)) {
        errMsg.textContent = "First name can only contain letters.";
        errEl.classList.remove("hidden");
        errEl.classList.add("flex");
        return;
      }

      if (!lastName) {
        errMsg.textContent = "Please enter a last name.";
        errEl.classList.remove("hidden");
        errEl.classList.add("flex");
        return;
      }

      if (!isValidName(lastName)) {
        errMsg.textContent = "Last name can only contain letters.";
        errEl.classList.remove("hidden");
        errEl.classList.add("flex");
        return;
      }

      if (!contact) {
        errMsg.textContent = "Please enter a contact number.";
        errEl.classList.remove("hidden");
        errEl.classList.add("flex");
        return;
      }

      if (!email) {
        errMsg.textContent = "Please enter an email address.";
        errEl.classList.remove("hidden");
        errEl.classList.add("flex");
        return;
      }

      if (!isValidEmail(email)) {
        errMsg.textContent = "Please enter a valid email address.";
        errEl.classList.remove("hidden");
        errEl.classList.add("flex");
        return;
      }

      if (!isEditing && !password) {
        errMsg.textContent = "Please enter a password.";
        errEl.classList.remove("hidden");
        errEl.classList.add("flex");
        return;
      }

      if (password && !isStrongPassword(password)) {
        errMsg.textContent = "Password must be at least 8 characters and include an uppercase letter, a number, and a symbol.";
        errEl.classList.remove("hidden");
        errEl.classList.add("flex");
        return;
      }
      errEl.classList.add("hidden");
      errEl.classList.remove("flex");

      const isNewUpload = typeof currentProfileImage === "string" && currentProfileImage.startsWith("data:image");

      const payload = {
        first_name: firstName,
        last_name: lastName,
        contact_number: contact,
        email: email,
        password: password,
        status: status,
        profile_image_data: isNewUpload ? currentProfileImage : "",
        remove_image: removeImageFlag ? "1" : "0",
      };

      const saveBtn = document.getElementById("saveStaffBtn");
      saveBtn.disabled = true;

      const res = isEditing ?
        await postAction("edit_staff", {
          staff_id: editingStaffId,
          ...payload
        }) :
        await postAction("add_staff", payload);

      saveBtn.disabled = false;

      if (!res.success) {
        errMsg.textContent = res.message || "Something went wrong. Please try again.";
        errEl.classList.remove("hidden");
        errEl.classList.add("flex");
        return;
      }

      closeStaffModal();
      await refreshStaff();
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

      document.getElementById("addStaffBtn").addEventListener("click", openAddStaffModal);
      document.getElementById("closeStaffModalBtn").addEventListener("click", closeStaffModal);
      document.getElementById("closeStaffOverlay").addEventListener("click", closeStaffModal);
      document.getElementById("cancelStaffBtn").addEventListener("click", closeStaffModal);
      document.getElementById("saveStaffBtn").addEventListener("click", saveStaff);

      document.getElementById("profileImageBtn").addEventListener("click", () =>
        document.getElementById("profileImageInput").click(),
      );
      document.getElementById("profileImageInput").addEventListener("change", (e) => {
        const file = e.target.files[0];
        if (!file) return;
        removeImageFlag = false;
        const reader = new FileReader();
        reader.onload = (ev) => {
          currentProfileImage = ev.target.result;
          updateProfilePreview(currentProfileImage, null);
        };
        reader.readAsDataURL(file);
      });
      document.getElementById("removeProfileImageBtn").addEventListener("click", () => {
        currentProfileImage = null;
        removeImageFlag = true;
        document.getElementById("profileImageInput").value = "";
        const first = document.getElementById("fieldFirstName").value.trim();
        const last = document.getElementById("fieldLastName").value.trim();
        updateProfilePreview(null, getInitials(first || "?", last || ""));
      });
      document.getElementById("fieldFirstName").addEventListener("input", () => {
        if (!currentProfileImage)
          document.getElementById("profileInitials").textContent =
          getInitials(
            document.getElementById("fieldFirstName").value.trim() || "?",
            document.getElementById("fieldLastName").value.trim() || "",
          ) || "?";
      });
      document.getElementById("fieldLastName").addEventListener("input", () => {
        if (!currentProfileImage)
          document.getElementById("profileInitials").textContent =
          getInitials(
            document.getElementById("fieldFirstName").value.trim() || "?",
            document.getElementById("fieldLastName").value.trim() || "",
          ) || "?";
      });

      [document.getElementById("fieldFirstName"), document.getElementById("fieldLastName")].forEach((el) => {
        el.addEventListener("input", () => {
          const cursorPos = el.selectionStart;
          const cleaned = el.value.replace(/[^\p{L}\s'-]/gu, "");
          if (cleaned !== el.value) {
            const removedCount = el.value.length - cleaned.length;
            el.value = cleaned;
            const newPos = Math.max(0, cursorPos - removedCount);
            el.setSelectionRange(newPos, newPos);
          }
        });

        el.addEventListener("blur", () => {
          if (el.value.trim()) {
            el.value = toTitleCase(el.value);
          }
        });
      });

      document.getElementById("fieldEmail").addEventListener("input", (e) => {
        const cursorPos = e.target.selectionStart;
        const cleaned = e.target.value.replace(/\s/g, "");
        if (cleaned !== e.target.value) {
          const removedCount = e.target.value.length - cleaned.length;
          e.target.value = cleaned;
          const newPos = Math.max(0, cursorPos - removedCount);
          e.target.setSelectionRange(newPos, newPos);
        }
      });

      document.getElementById("fieldEmail").addEventListener("blur", (e) => {
        if (e.target.value.trim()) {
          e.target.value = e.target.value.trim().toLowerCase();
        }
      });

      document.getElementById("togglePasswordBtn").addEventListener("click", () => {
        const input = document.getElementById("fieldPassword");
        const isHidden = input.type === "password";
        input.type = isHidden ? "text" : "password";
        document.getElementById("eyeIcon").innerHTML = isHidden ?
          `<path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 0 0 1.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.451 10.451 0 0 1 12 4.5c4.756 0 8.773 3.162 10.065 7.498a10.522 10.522 0 0 1-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65m0 0a3 3 0 1 0-4.243-4.243m4.242 4.242L9.88 9.88" />` :
          `<path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />`;
      });

      const pwLevels = [{
          label: "",
          color: "bg-gray-200"
        },
        {
          label: "Weak",
          color: "bg-red-400",
          textCls: "text-red-500"
        },
        {
          label: "Fair",
          color: "bg-amber-400",
          textCls: "text-amber-500"
        },
        {
          label: "Good",
          color: "bg-emerald-500",
          textCls: "text-emerald-600"
        },
        {
          label: "Strong",
          color: "bg-emerald-700",
          textCls: "text-emerald-700"
        },
      ];

      function getPwStrength(pw) {
        let s = 0;
        if (pw.length >= 8) s++;
        if (/[A-Z]/.test(pw)) s++;
        if (/[0-9]/.test(pw)) s++;
        if (/[^A-Za-z0-9]/.test(pw)) s++;
        return s;
      }
      document.getElementById("fieldPassword").addEventListener("input", () => {
        const pw = document.getElementById("fieldPassword").value;
        const score = pw.length === 0 ? 0 : Math.max(1, getPwStrength(pw));
        const level = pwLevels[score];
        [1, 2, 3, 4].forEach((n, i) => {
          const b = document.getElementById("pwBar" + n);
          b.className = `h-1 flex-1 transition-colors ${i < score ? level.color : "bg-gray-200"}`;
          b.style.borderRadius = "999px";
        });
        const lbl = document.getElementById("pwStrengthLabel");
        lbl.textContent = pw.length > 0 ? level.label : "";
        lbl.className = `text-[10px] mt-1 ${score > 0 ? level.textCls : "text-gray-400"}`;
      });

      document.getElementById("closeDeleteStaffOverlay").addEventListener("click", closeDeleteStaffModal);
      document.getElementById("cancelDeleteStaffBtn").addEventListener("click", closeDeleteStaffModal);
      document.getElementById("confirmDeleteStaffBtn").addEventListener("click", async () => {
        const res = await postAction("delete_staff", {
          staff_id: deletingStaffId
        });
        closeDeleteStaffModal();
        if (res.success) {
          await refreshStaff();
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