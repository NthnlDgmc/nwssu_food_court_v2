<?php
session_start();
require_once '../config/database.php';
require_once '../config/version.php';
require_once '../config/app-url.php';

if (!isset($_SESSION['owner_id'])) {
  header('Location: ../auth/login.php');
  exit;
}

$ownerId = $_SESSION['owner_id'];

function fetchOwnerProfile($conn, $ownerId)
{
  $stmt = $conn->prepare("SELECT profile_image, first_name, last_name, contact_number, email, status, created_at FROM stall_owners WHERE owner_id = ? LIMIT 1");
  $stmt->bind_param("i", $ownerId);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if ($row) {
    $row['member_since'] = date('M Y', strtotime($row['created_at']));
    $row['profile_image'] = $row['profile_image'] ? '../' . $row['profile_image'] : null;
  }

  return $row;
}

function fetchAssignedStallName($conn, $ownerId)
{
  $stmt = $conn->prepare("SELECT stall_name FROM stalls WHERE owner_id = ? LIMIT 1");
  $stmt->bind_param("i", $ownerId);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  return $row ? $row['stall_name'] : null;
}

function fetchStallHours($conn, $ownerId)
{
  $stmt = $conn->prepare("SELECT stall_id, opens_at, closes_at FROM stalls WHERE owner_id = ? LIMIT 1");
  $stmt->bind_param("i", $ownerId);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  return $row ?: null;
}

function handleOwnerProfileImageUpload($file)
{
  if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
    return null;
  }
  if ($file['error'] !== UPLOAD_ERR_OK) {
    return null;
  }

  $allowedExt = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
  $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
  if (!in_array($ext, $allowedExt, true)) {
    return null;
  }

  $uploadDir = __DIR__ . '/../uploads/stall/';
  if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
  }

  $filename = uniqid('owner_', true) . '.' . $ext;
  $destination = $uploadDir . $filename;

  if (move_uploaded_file($file['tmp_name'], $destination)) {
    return 'uploads/stall/' . $filename;
  }

  return null;
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
  if (!preg_match('/[a-z]/', $password)) return false;
  if (!preg_match('/[0-9]/', $password)) return false;
  if (!preg_match('/[^A-Za-z0-9]/', $password)) return false;
  return true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
  header('Content-Type: application/json');
  $action = $_POST['action'];

  if ($action === 'edit_profile') {
    $firstName = toTitleCase(trim($_POST['first_name'] ?? ''));
    $lastName = toTitleCase(trim($_POST['last_name'] ?? ''));
    $contact = trim($_POST['contact_number'] ?? '');
    $email = strtolower(preg_replace('/\s+/', '', $_POST['email'] ?? ''));
    $removeImage = ($_POST['remove_image'] ?? '') === '1';

    if ($firstName === '') {
      echo json_encode(['success' => false, 'message' => 'First name is required.']);
      exit;
    }
    if (!preg_match("/^[\p{L}\s'\-]+$/u", $firstName)) {
      echo json_encode(['success' => false, 'message' => 'First name can only contain letters.']);
      exit;
    }
    if ($lastName === '') {
      echo json_encode(['success' => false, 'message' => 'Last name is required.']);
      exit;
    }
    if (!preg_match("/^[\p{L}\s'\-]+$/u", $lastName)) {
      echo json_encode(['success' => false, 'message' => 'Last name can only contain letters.']);
      exit;
    }
    if ($contact === '') {
      echo json_encode(['success' => false, 'message' => 'Please enter a contact number.']);
      exit;
    }
    if (!preg_match('/^09\d{9}$/', $contact)) {
      echo json_encode(['success' => false, 'message' => 'Please enter a valid mobile number.']);
      exit;
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
      echo json_encode(['success' => false, 'message' => 'Please enter a valid email address.']);
      exit;
    }

    $stmt = $conn->prepare("UPDATE stall_owners SET first_name = ?, last_name = ?, contact_number = ?, email = ? WHERE owner_id = ?");
    $stmt->bind_param("ssssi", $firstName, $lastName, $contact, $email, $ownerId);
    $ok = $stmt->execute();
    $stmt->close();

    if (!$ok) {
      echo json_encode(['success' => false, 'message' => 'Failed to update profile.']);
      $conn->close();
      exit;
    }

    $newProfileImageRaw = null;
    $hasNewImage = false;

    if (isset($_FILES['profile_image_file']) && $_FILES['profile_image_file']['error'] !== UPLOAD_ERR_NO_FILE) {
      $imagePath = handleOwnerProfileImageUpload($_FILES['profile_image_file']);
      if ($imagePath) {
        $imgStmt = $conn->prepare("UPDATE stall_owners SET profile_image = ? WHERE owner_id = ?");
        $imgStmt->bind_param("si", $imagePath, $ownerId);
        $imgStmt->execute();
        $imgStmt->close();
        $newProfileImageRaw = $imagePath;
        $hasNewImage = true;
      }
    } elseif ($removeImage) {
      $imgStmt = $conn->prepare("UPDATE stall_owners SET profile_image = NULL WHERE owner_id = ?");
      $imgStmt->bind_param("i", $ownerId);
      $imgStmt->execute();
      $imgStmt->close();
      $newProfileImageRaw = null;
      $hasNewImage = true;
    }

    if ($hasNewImage) {
      $newProfileImage = $newProfileImageRaw ? '../' . $newProfileImageRaw : null;
    } else {
      $currentProfile = fetchOwnerProfile($conn, $ownerId);
      $newProfileImage = $currentProfile['profile_image'] ?? null;
    }

    echo json_encode(['success' => true, 'profile_image' => $newProfileImage]);
    $conn->close();
    exit;
  }

  if ($action === 'change_password') {
    $currentPw = $_POST['current_password'] ?? '';
    $newPw = $_POST['new_password'] ?? '';
    $confirmPw = $_POST['confirm_password'] ?? '';

    if ($currentPw === '') {
      echo json_encode(['success' => false, 'message' => 'Please enter your current password.']);
      exit;
    }
    if ($newPw === '') {
      echo json_encode(['success' => false, 'message' => 'Please enter a new password.']);
      exit;
    }
    if (!isStrongPassword($newPw)) {
      echo json_encode(['success' => false, 'message' => 'New password must be at least 8 characters and include an uppercase letter, a lowercase letter, a number, and a symbol.']);
      exit;
    }
    if ($newPw !== $confirmPw) {
      echo json_encode(['success' => false, 'message' => 'Passwords do not match.']);
      exit;
    }

    $stmt = $conn->prepare("SELECT password FROM stall_owners WHERE owner_id = ? LIMIT 1");
    $stmt->bind_param("i", $ownerId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row || !password_verify($currentPw, $row['password'])) {
      echo json_encode(['success' => false, 'message' => 'Current password is incorrect.']);
      $conn->close();
      exit;
    }

    $newPw = password_hash($newPw, PASSWORD_DEFAULT);

    $updateStmt = $conn->prepare("UPDATE stall_owners SET password = ? WHERE owner_id = ?");
    $updateStmt->bind_param("si", $newPw, $ownerId);
    $ok = $updateStmt->execute();
    $updateStmt->close();

    echo json_encode($ok
      ? ['success' => true]
      : ['success' => false, 'message' => 'Failed to update password.']);
    $conn->close();
    exit;
  }

  if ($action === 'update_business_hours') {
    $opensAt = trim($_POST['opens_at'] ?? '');
    $closesAt = trim($_POST['closes_at'] ?? '');

    if ($opensAt === '' || $closesAt === '') {
      echo json_encode(['success' => false, 'message' => 'Please set both opening and closing time.']);
      exit;
    }

    $ownerStall = fetchStallHours($conn, $ownerId);
    if (!$ownerStall) {
      echo json_encode(['success' => false, 'message' => 'You do not have an assigned stall yet.']);
      $conn->close();
      exit;
    }

    $stmt = $conn->prepare("UPDATE stalls SET opens_at = ?, closes_at = ? WHERE owner_id = ?");
    $stmt->bind_param("ssi", $opensAt, $closesAt, $ownerId);
    $ok = $stmt->execute();
    $stmt->close();

    echo json_encode($ok
      ? ['success' => true]
      : ['success' => false, 'message' => 'Failed to update business hours.']);
    $conn->close();
    exit;
  }

  echo json_encode(['success' => false, 'message' => 'Unknown action.']);
  $conn->close();
  exit;
}

$initialProfile = fetchOwnerProfile($conn, $ownerId);
$assignedStallName = fetchAssignedStallName($conn, $ownerId);
$stallHours = fetchStallHours($conn, $ownerId);
$conn->close();

if (!$initialProfile) {
  header('Location: ../auth/login.php');
  exit;
}
?>
<!doctype html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta
    name="viewport"
    content="width=device-width, initial-scale=1.0, user-scalable=yes" />
  <title>Stall - My Account</title>
  <link rel="icon" href="../assets/images/nwssu-logo.png" type="image/png" />
  <link rel="manifest" href="/manifest.json" />
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

    input:focus {
      outline: none;
      border-color: #059669;
    }

    input.error {
      border-color: #f87171;
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
          style="width: 34px; height: 34px; border-radius: 6px">
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
              d="M15.75 19.5 8.25 12l7.5-7.5" />
          </svg>
        </button>
        <h1 class="text-base font-semibold text-emerald-600 text-center">
          My Account
        </h1>
        <div class="justify-self-end" style="width: 34px"></div>
      </div>
    </div>

    <div class="flex-1 overflow-y-auto mt-12 mb-16" id="mainContent">
      <div class="max-w-5xl mx-auto px-4 pt-3 pb-4 space-y-3">
        <div class="rounded-md bg-white border border-gray-200 shadow-sm p-4">
          <div id="profileSkeleton" class="flex items-center gap-3 animate-pulse">
            <div class="w-16 h-16 bg-gray-200 shrink-0 rounded-full"></div>
            <div class="flex-1 min-w-0 space-y-2">
              <div class="h-4 bg-gray-200 rounded w-32"></div>
              <div class="h-3 bg-gray-200 rounded w-40"></div>
            </div>
          </div>
          <div id="profileContent" class="hidden items-center gap-3">
            <div
              id="profileAvatar"
              class="w-16 h-16 bg-gradient-to-br from-emerald-500 to-emerald-700 flex items-center justify-center text-white font-bold text-xl shrink-0 rounded-full overflow-hidden"></div>
            <div class="flex-1 min-w-0">
              <p
                id="profileName"
                class="text-sm font-bold text-gray-800 truncate"></p>
              <p id="profileSubtext" class="text-[11px] text-gray-400 mt-1 truncate"></p>
            </div>
          </div>
          <button
            id="editProfileBtn"
            class="w-full mt-4 py-2 border border-gray-200 text-gray-700 text-xs font-semibold hover:bg-gray-50 transition-colors flex items-center justify-center gap-1.5 rounded-[3px]">
            <svg
              xmlns="http://www.w3.org/2000/svg"
              fill="none"
              viewBox="0 0 24 24"
              stroke-width="1.5"
              stroke="currentColor"
              class="w-3.5 h-3.5">
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0 1 15.75 21H5.25A2.25 2.25 0 0 1 3 18.75V8.25A2.25 2.25 0 0 1 5.25 6H10" />
            </svg>
            Edit Profile
          </button>
        </div>

        <div class="grid grid-cols-2 gap-2">
          <div class="rounded-md bg-white border border-gray-200 shadow-sm p-3 text-center">
            <p id="statStallName" class="text-base font-bold text-emerald-600"></p>
            <p class="text-[10px] text-gray-400 mt-0.5">Assigned Stall</p>
          </div>
          <div class="rounded-md bg-white border border-gray-200 shadow-sm p-3 text-center">
            <p id="statMemberSince" class="text-base font-bold text-emerald-600"></p>
            <p class="text-[10px] text-gray-400 mt-0.5">Member Since</p>
          </div>
        </div>

        <div class="rounded-md bg-white border border-gray-200 shadow-sm overflow-hidden">
          <div class="p-4 border-b border-gray-100">
            <p class="text-[11px] font-bold text-gray-500 uppercase tracking-wide">
              Stall Settings
            </p>
          </div>
          <div class="divide-y divide-gray-100">
            <button
              id="businessHoursBtn"
              class="account-row w-full flex items-center gap-3 px-4 py-3 hover:bg-gray-50 transition-colors text-left">
              <span class="w-8 h-8 bg-gray-100 flex items-center justify-center shrink-0 rounded-[3px]">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 text-gray-500">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                </svg>
              </span>
              <span class="flex-1 text-xs font-medium text-gray-700">Business Hours</span>
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 text-gray-300 shrink-0">
                <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
              </svg>
            </button>
          </div>
        </div>

        <div class="rounded-md bg-white border border-gray-200 shadow-sm overflow-hidden">
          <div class="p-4 border-b border-gray-100">
            <p class="text-[11px] font-bold text-gray-500 uppercase tracking-wide">
              Account Settings
            </p>
          </div>
          <div class="divide-y divide-gray-100">
            <button
              id="changePasswordBtn"
              class="account-row w-full flex items-center gap-3 px-4 py-3 hover:bg-gray-50 transition-colors text-left">
              <span class="w-8 h-8 bg-gray-100 flex items-center justify-center shrink-0 rounded-[3px]">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 text-gray-500">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" />
                </svg>
              </span>
              <span class="flex-1 text-xs font-medium text-gray-700">Change Password</span>
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 text-gray-300 shrink-0">
                <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
              </svg>
            </button>
          </div>
        </div>

        <div class="rounded-md bg-white border border-gray-200 shadow-sm overflow-hidden">
          <div class="p-4 border-b border-gray-100">
            <p class="text-[11px] font-bold text-gray-500 uppercase tracking-wide">
              Support
            </p>
          </div>
          <div class="divide-y divide-gray-100">
            <button
              id="shareAppBtn"
              class="account-row w-full flex items-center gap-3 px-4 py-3 hover:bg-gray-50 transition-colors text-left">
              <span class="w-8 h-8 bg-gray-100 flex items-center justify-center shrink-0 rounded-[3px]">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 text-gray-500">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M7.217 10.907a2.25 2.25 0 1 0 0 2.186m0-2.186c.18.324.283.696.283 1.093s-.103.77-.283 1.093m0-2.186 9.566-5.314m-9.566 7.5 9.566 5.314m0 0a2.25 2.25 0 1 0 3.935 2.186 2.25 2.25 0 0 0-3.935-2.186Zm0-12.814a2.25 2.25 0 1 0 3.933-2.185 2.25 2.25 0 0 0-3.933 2.185Z" />
                </svg>
              </span>
              <span class="flex-1 text-xs font-medium text-gray-700">Share App</span>
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 text-gray-300 shrink-0">
                <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
              </svg>
            </button>
            <button
              id="termsPrivacyBtn"
              class="account-row w-full flex items-center gap-3 px-4 py-3 hover:bg-gray-50 transition-colors text-left">
              <span class="w-8 h-8 bg-gray-100 flex items-center justify-center shrink-0 rounded-[3px]">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 text-gray-500">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" />
                </svg>
              </span>
              <span class="flex-1 text-xs font-medium text-gray-700">Terms &amp; Privacy Policy</span>
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 text-gray-300 shrink-0">
                <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
              </svg>
            </button>
            <div class="w-full flex items-center gap-3 px-4 py-3">
              <span class="w-8 h-8 bg-gray-100 flex items-center justify-center shrink-0 rounded-[3px]">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 text-gray-500">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.008v.008H12V8.25Z" />
                </svg>
              </span>
              <span class="flex-1 text-xs font-medium text-gray-700">App Version</span>
              <span class="text-xs text-gray-400"><?php echo APP_VERSION; ?></span>
            </div>
          </div>
        </div>

        <button
          id="logoutBtn"
          class="w-full py-2.5 border border-red-200 text-red-500 text-xs font-semibold hover:bg-red-50 transition-colors flex items-center justify-center gap-1.5 rounded-[3px]">
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
              d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6a2.25 2.25 0 0 0-2.25 2.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15M12 9l-3 3m0 0 3 3m-3-3h12.75" />
          </svg>
          Log Out
        </button>
      </div>
    </div>

    <div class="bg-white border-t border-gray-200 flex-shrink-0 fixed bottom-0 left-0 right-0 z-20">
      <div class="max-w-5xl mx-auto px-4 flex justify-around py-2">
        <a
          href="./dashboard.php"
          class="flex flex-col items-center justify-center py-2 px-3 transition-all duration-200 group text-gray-500 hover:text-gray-900 hover:bg-gray-50 rounded-[3px]">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 transition-transform group-hover:scale-110">
            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 0 1 6 3.75h2.25A2.25 2.25 0 0 1 10.5 6v2.25a2.25 2.25 0 0 1-2.25 2.25H6a2.25 2.25 0 0 1-2.25-2.25V6ZM3.75 15.75A2.25 2.25 0 0 1 6 13.5h2.25a2.25 2.25 0 0 1 2.25 2.25V18a2.25 2.25 0 0 1-2.25 2.25H6A2.25 2.25 0 0 1 3.75 18v-2.25ZM13.5 6a2.25 2.25 0 0 1 2.25-2.25H18A2.25 2.25 0 0 1 20.25 6v2.25A2.25 2.25 0 0 1 18 10.5h-2.25a2.25 2.25 0 0 1-2.25-2.25V6ZM13.5 15.75a2.25 2.25 0 0 1 2.25-2.25H18a2.25 2.25 0 0 1 2.25 2.25V18A2.25 2.25 0 0 1 18 20.25h-2.25A2.25 2.25 0 0 1 13.5 18v-2.25Z" />
          </svg>
          <span class="text-xs font-medium mt-1">Dashboard</span>
        </a>
        <a
          href="./menu.php"
          class="flex flex-col items-center justify-center py-2 px-3 transition-all duration-200 group text-gray-500 hover:text-gray-900 hover:bg-gray-50 rounded-[3px]">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 transition-transform group-hover:scale-110">
            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 12h16.5m-16.5 3.75h16.5M3.75 19.5h16.5M5.625 4.5h12.75a1.875 1.875 0 0 1 0 3.75H5.625a1.875 1.875 0 0 1 0-3.75Z" />
          </svg>
          <span class="text-xs font-medium mt-1">Menu</span>
        </a>
        <a
          href="./orders.php"
          class="flex flex-col items-center justify-center py-2 px-3 transition-all duration-200 group text-gray-500 hover:text-gray-900 hover:bg-gray-50 rounded-[3px]">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 transition-transform group-hover:scale-110">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 0 0 2.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 0 0-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 0 0 .75-.75 2.25 2.25 0 0 0-.1-.664m-5.8 0A2.251 2.251 0 0 1 13.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25Z" />
          </svg>
          <span class="text-xs font-medium mt-1">Orders</span>
        </a>
        <a
          href="./account.php"
          class="flex flex-col items-center justify-center py-2 px-3 transition-all duration-200 group text-emerald-600 bg-emerald-50 rounded-[3px]">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 transition-transform group-hover:scale-110">
            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" />
          </svg>
          <span class="text-xs font-medium mt-1">Account</span>
        </a>
      </div>
    </div>
  </div>

  <div
    id="editProfileModal"
    class="fixed inset-0 z-50 hidden flex items-center justify-center px-4">
    <div class="modal-overlay absolute inset-0" id="closeEditProfileOverlay"></div>
    <div
      class="bg-white w-full max-w-md max-h-[90vh] overflow-y-auto relative z-10 shadow-2xl rounded-md">
      <div class="p-4 border-b border-gray-100 flex items-center justify-between sticky top-0 bg-white z-10">
        <h2 class="font-bold text-gray-800 text-sm">Edit Profile</h2>
        <button id="closeEditProfileBtn" class="p-1 hover:bg-gray-100 rounded-[3px]">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 text-gray-500">
            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
          </svg>
        </button>
      </div>
      <div class="p-4 space-y-3">
        <div class="flex flex-col items-center gap-2">
          <div class="relative">
            <div
              id="editProfilePreview"
              class="w-16 h-16 bg-gradient-to-br from-emerald-500 to-emerald-700 flex items-center justify-center text-white font-bold text-lg rounded-full overflow-hidden">
              <span id="editProfileInitials">?</span>
              <img
                id="editProfilePreviewImg"
                src=""
                alt=""
                class="hidden w-full h-full object-cover"
                style="border-radius: 50%" />
            </div>
            <button
              type="button"
              id="editProfileImageBtn"
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
            id="editProfileImageInput"
            accept="image/*"
            class="hidden" />
          <div class="text-center">
            <p class="text-[10px] text-gray-400">
              Profile Photo <span class="text-gray-300">(optional)</span>
            </p>
            <button
              type="button"
              id="removeEditProfileImageBtn"
              class="hidden text-[10px] text-red-400 hover:text-red-600 font-semibold transition-colors mt-0.5">
              Remove photo
            </button>
          </div>
        </div>

        <div
          id="editProfileError"
          class="hidden flex items-center gap-2 p-3 bg-red-50 border border-red-200 rounded-[3px]">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 text-red-500 shrink-0">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
          </svg>
          <p class="text-[10px] text-red-600 font-medium leading-none" id="editProfileErrorMsg"></p>
        </div>

        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="block text-[11px] font-semibold text-gray-500 uppercase tracking-wide mb-1.5">First Name</label>
            <input type="text" id="fieldFirstName" class="w-full px-3 py-2.5 bg-white border border-gray-200 text-xs text-gray-900 placeholder-gray-400 focus:outline-none focus:border-emerald-600 rounded-[3px]" />
          </div>
          <div>
            <label class="block text-[11px] font-semibold text-gray-500 uppercase tracking-wide mb-1.5">Last Name</label>
            <input type="text" id="fieldLastName" class="w-full px-3 py-2.5 bg-white border border-gray-200 text-xs text-gray-900 placeholder-gray-400 focus:outline-none focus:border-emerald-600 rounded-[3px]" />
          </div>
        </div>

        <div>
          <label class="block text-[11px] font-semibold text-gray-500 uppercase tracking-wide mb-1.5">Contact Number</label>
          <input type="tel" id="fieldContact" placeholder="0917 123 4567" class="w-full px-3 py-2.5 bg-white border border-gray-200 text-xs text-gray-900 placeholder-gray-400 focus:outline-none focus:border-emerald-600 rounded-[3px]" />
          <p class="text-[10px] mt-1.5 hidden text-red-500" id="fieldContactErrorMsg"></p>
        </div>

        <div>
          <label class="block text-[11px] font-semibold text-gray-500 uppercase tracking-wide mb-1.5">Email Address</label>
          <input type="email" id="fieldEmail" placeholder="example@email.com" class="w-full px-3 py-2.5 bg-white border border-gray-200 text-xs text-gray-900 placeholder-gray-400 focus:outline-none focus:border-emerald-600 rounded-[3px]" />
          <p class="text-[10px] mt-1.5 hidden text-red-500" id="fieldEmailErrorMsg"></p>
        </div>
      </div>
      <div class="px-4 pb-4 flex gap-2">
        <button id="cancelEditProfileBtn" class="flex-1 py-2.5 border border-gray-200 text-gray-700 text-xs font-semibold hover:bg-gray-50 transition-colors rounded-[3px]">
          Cancel
        </button>
        <button id="saveEditProfileBtn" disabled class="flex-1 py-2.5 bg-emerald-600 hover:bg-emerald-700 disabled:opacity-70 disabled:cursor-not-allowed text-white text-xs font-semibold transition-colors rounded-[3px]">
          Save Changes
        </button>
      </div>
    </div>
  </div>

  <div
    id="changePasswordModal"
    class="fixed inset-0 z-50 hidden flex items-center justify-center px-4">
    <div class="modal-overlay absolute inset-0" id="closeChangePasswordOverlay"></div>
    <div
      class="bg-white w-full max-w-md max-h-[90vh] overflow-y-auto relative z-10 shadow-2xl rounded-md">
      <div class="p-4 border-b border-gray-100 flex items-center justify-between sticky top-0 bg-white z-10">
        <h2 class="font-bold text-gray-800 text-sm">Change Password</h2>
        <button id="closeChangePasswordBtn" class="p-1 hover:bg-gray-100 rounded-[3px]">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 text-gray-500">
            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
          </svg>
        </button>
      </div>
      <div class="p-4 space-y-3">
        <div
          id="passwordError"
          class="hidden flex items-center gap-2 p-3 bg-red-50 border border-red-200 rounded-[3px]">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 text-red-500 shrink-0">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
          </svg>
          <p class="text-[10px] text-red-600 font-medium leading-none" id="passwordErrorMsg"></p>
        </div>

        <div>
          <label class="block text-[11px] font-semibold text-gray-500 uppercase tracking-wide mb-1.5">Current Password</label>
          <div class="relative">
            <div class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 pointer-events-none">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4">
                <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" />
              </svg>
            </div>
            <input type="password" id="fieldCurrentPassword" placeholder="Enter current password" class="w-full pl-9 pr-9 py-2.5 bg-white border border-gray-200 text-xs text-gray-900 placeholder-gray-400 focus:outline-none focus:border-emerald-600 rounded-[3px]" />
            <button
              type="button"
              id="toggleCurrentPasswordBtn"
              class="absolute right-2.5 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 transition-colors">
              <svg
                xmlns="http://www.w3.org/2000/svg"
                fill="none"
                viewBox="0 0 24 24"
                stroke-width="1.5"
                stroke="currentColor"
                class="w-4 h-4"
                id="currentPwEyeIcon">
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
        </div>

        <div>
          <label class="block text-[11px] font-semibold text-gray-500 uppercase tracking-wide mb-1.5">New Password</label>
          <div class="relative">
            <div class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 pointer-events-none">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4">
                <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" />
              </svg>
            </div>
            <input type="password" id="fieldNewPassword" placeholder="Enter your new password" class="w-full pl-9 pr-9 py-2.5 bg-white border border-gray-200 text-xs text-gray-900 placeholder-gray-400 focus:outline-none focus:border-emerald-600 rounded-[3px]" />
            <button
              type="button"
              id="toggleNewPasswordBtn"
              class="absolute right-2.5 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 transition-colors">
              <svg
                xmlns="http://www.w3.org/2000/svg"
                fill="none"
                viewBox="0 0 24 24"
                stroke-width="1.5"
                stroke="currentColor"
                class="w-4 h-4"
                id="newPwEyeIcon">
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
          <div class="mt-2">
            <div class="flex items-center justify-between">
              <p class="text-[10px] font-medium text-gray-500">Password Strength</p>
              <p class="text-[10px] font-semibold" id="pwStrengthLabel"></p>
            </div>
            <div class="flex gap-1 mt-1.5">
              <div class="h-1 flex-1 bg-gray-200 transition-colors rounded-full" id="pwBar1"></div>
              <div class="h-1 flex-1 bg-gray-200 transition-colors rounded-full" id="pwBar2"></div>
              <div class="h-1 flex-1 bg-gray-200 transition-colors rounded-full" id="pwBar3"></div>
              <div class="h-1 flex-1 bg-gray-200 transition-colors rounded-full" id="pwBar4"></div>
              <div class="h-1 flex-1 bg-gray-200 transition-colors rounded-full" id="pwBar5"></div>
            </div>
            <div class="flex items-center justify-between gap-1 mt-2">
              <div class="flex items-center gap-1">
                <span id="pwReqLenIcon" class="w-3 h-3 rounded-full border border-gray-300 flex items-center justify-center shrink-0 transition-colors">
                  <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="4" stroke="currentColor" class="w-2 h-2 text-white opacity-0 transition-opacity">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                  </svg>
                </span>
                <span id="pwReqLenText" class="text-[10px] text-gray-400 transition-colors">8 Chars</span>
              </div>
              <div class="flex items-center gap-1">
                <span id="pwReqUpperIcon" class="w-3 h-3 rounded-full border border-gray-300 flex items-center justify-center shrink-0 transition-colors">
                  <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="4" stroke="currentColor" class="w-2 h-2 text-white opacity-0 transition-opacity">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                  </svg>
                </span>
                <span id="pwReqUpperText" class="text-[10px] text-gray-400 transition-colors">A-Z</span>
              </div>
              <div class="flex items-center gap-1">
                <span id="pwReqLowerIcon" class="w-3 h-3 rounded-full border border-gray-300 flex items-center justify-center shrink-0 transition-colors">
                  <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="4" stroke="currentColor" class="w-2 h-2 text-white opacity-0 transition-opacity">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                  </svg>
                </span>
                <span id="pwReqLowerText" class="text-[10px] text-gray-400 transition-colors">a-z</span>
              </div>
              <div class="flex items-center gap-1">
                <span id="pwReqNumIcon" class="w-3 h-3 rounded-full border border-gray-300 flex items-center justify-center shrink-0 transition-colors">
                  <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="4" stroke="currentColor" class="w-2 h-2 text-white opacity-0 transition-opacity">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                  </svg>
                </span>
                <span id="pwReqNumText" class="text-[10px] text-gray-400 transition-colors">123</span>
              </div>
              <div class="flex items-center gap-1">
                <span id="pwReqSymbolIcon" class="w-3 h-3 rounded-full border border-gray-300 flex items-center justify-center shrink-0 transition-colors">
                  <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="4" stroke="currentColor" class="w-2 h-2 text-white opacity-0 transition-opacity">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                  </svg>
                </span>
                <span id="pwReqSymbolText" class="text-[10px] text-gray-400 transition-colors">@#$</span>
              </div>
            </div>
          </div>
        </div>

        <div>
          <label class="block text-[11px] font-semibold text-gray-500 uppercase tracking-wide mb-1.5">Confirm New Password</label>
          <div class="relative">
            <div class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 pointer-events-none">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4">
                <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" />
              </svg>
            </div>
            <input type="password" id="fieldConfirmPassword" placeholder="Repeat new password" class="w-full pl-9 pr-9 py-2.5 bg-white border border-gray-200 text-xs text-gray-900 placeholder-gray-400 focus:outline-none focus:border-emerald-600 rounded-[3px]" />
            <button
              type="button"
              id="toggleConfirmPasswordBtn"
              class="absolute right-2.5 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 transition-colors">
              <svg
                xmlns="http://www.w3.org/2000/svg"
                fill="none"
                viewBox="0 0 24 24"
                stroke-width="1.5"
                stroke="currentColor"
                class="w-4 h-4"
                id="confirmPwEyeIcon">
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
          <p class="text-[10px] mt-1.5 hidden" id="pwMatchMsg"></p>
        </div>
      </div>
      <div class="px-4 pb-4 flex gap-2">
        <button id="cancelChangePasswordBtn" class="flex-1 py-2.5 border border-gray-200 text-gray-700 text-xs font-semibold hover:bg-gray-50 transition-colors rounded-[3px]">
          Cancel
        </button>
        <button id="saveChangePasswordBtn" class="flex-1 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold transition-colors rounded-[3px]">
          Update Password
        </button>
      </div>
    </div>
  </div>

  <div
    id="businessHoursModal"
    class="fixed inset-0 z-50 hidden flex items-center justify-center px-4">
    <div class="modal-overlay absolute inset-0" id="closeBusinessHoursOverlay"></div>
    <div
      class="bg-white w-full max-w-md max-h-[90vh] overflow-y-auto relative z-10 shadow-2xl rounded-md">
      <div class="p-4 border-b border-gray-100 flex items-center justify-between sticky top-0 bg-white z-10">
        <h2 class="font-bold text-gray-800 text-sm">Business Hours</h2>
        <button id="closeBusinessHoursBtn" class="p-1 hover:bg-gray-100 rounded-[3px]">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 text-gray-500">
            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
          </svg>
        </button>
      </div>
      <div class="p-4 space-y-3">
        <p class="text-[10px] text-gray-400 leading-relaxed">
          Set your daily opening and closing time. Your stall will automatically
          show as closed to customers outside these hours, even if the status
          toggle on your dashboard is set to open.
        </p>

        <div
          id="businessHoursError"
          class="hidden flex items-center gap-2 p-3 bg-red-50 border border-red-200 rounded-[3px]">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 text-red-500 shrink-0">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
          </svg>
          <p class="text-[10px] text-red-600 font-medium leading-none" id="businessHoursErrorMsg"></p>
        </div>

        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="block text-[11px] font-semibold text-gray-500 uppercase tracking-wide mb-1.5">Opens At</label>
            <input type="time" id="fieldOpensAt" class="w-full px-2 py-1.5 bg-white border border-gray-200 text-xs text-gray-900 focus:outline-none focus:border-emerald-600 rounded-[3px]" />
          </div>
          <div>
            <label class="block text-[11px] font-semibold text-gray-500 uppercase tracking-wide mb-1.5">Closes At</label>
            <input type="time" id="fieldClosesAt" class="w-full px-2 py-1.5 bg-white border border-gray-200 text-xs text-gray-900 focus:outline-none focus:border-emerald-600 rounded-[3px]" />
          </div>
        </div>
      </div>
      <div class="px-4 pb-4 flex gap-2">
        <button id="cancelBusinessHoursBtn" class="flex-1 py-2.5 border border-gray-200 text-gray-700 text-xs font-semibold hover:bg-gray-50 transition-colors rounded-[3px]">
          Cancel
        </button>
        <button id="saveBusinessHoursBtn" disabled class="flex-1 py-2.5 bg-emerald-600 hover:bg-emerald-700 disabled:opacity-70 disabled:cursor-not-allowed text-white text-xs font-semibold transition-colors rounded-[3px]">
          Save Hours
        </button>
      </div>
    </div>
  </div>

  <div
    id="shareAppModal"
    class="fixed inset-0 z-50 hidden flex items-center justify-center px-4">
    <div class="modal-overlay absolute inset-0" id="closeShareAppOverlay"></div>
    <div
      class="bg-white w-full max-w-sm relative z-10 shadow-2xl p-5 space-y-4 text-center rounded-md">
      <div>
        <p class="text-sm font-bold text-gray-800">Share NWSSU Food Court</p>
        <p class="text-xs text-gray-500 mt-1">Let a friend scan this code to open the app.</p>
      </div>
      <div class="flex items-center justify-center">
        <div class="p-3 border border-gray-200 rounded-md">
          <img
            id="shareAppQrImg"
            src="https://api.qrserver.com/v1/create-qr-code/?size=200x200&amp;data=<?php echo urlencode(APP_URL); ?>"
            alt="App QR Code"
            class="w-44 h-44" />
        </div>
      </div>
      <div class="flex items-center gap-2 bg-gray-50 border border-gray-200 px-3 py-2.5 rounded-[3px]">
        <p id="shareAppUrlText" class="flex-1 text-xs text-gray-600 truncate text-left"><?php echo htmlspecialchars(APP_URL); ?></p>
        <button id="copyShareAppUrlBtn" class="shrink-0 p-1 hover:bg-gray-200 transition-colors rounded-[3px]">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 text-gray-500">
            <path stroke-linecap="round" stroke-linejoin="round" d="M15.666 3.888A2.25 2.25 0 0 0 13.5 2.25h-3c-1.03 0-1.9.693-2.166 1.638m7.332 0c.055.194.084.4.084.612v0a.75.75 0 0 1-.75.75H9a.75.75 0 0 1-.75-.75v0c0-.212.03-.418.084-.612m7.332 0c.646.049 1.288.11 1.927.184 1.1.128 1.907 1.077 1.907 2.185V19.5a2.25 2.25 0 0 1-2.25 2.25H6.75A2.25 2.25 0 0 1 4.5 19.5V6.257c0-1.108.806-2.057 1.907-2.185a48.208 48.208 0 0 1 1.927-.184" />
          </svg>
        </button>
      </div>
      <div class="flex gap-2">
        <button id="downloadShareAppQrBtn" class="flex-1 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold transition-colors rounded-[3px] flex items-center justify-center">
          Download QR
        </button>
        <button id="closeShareAppBtn" class="flex-1 py-2.5 border border-gray-200 text-gray-700 text-xs font-semibold hover:bg-gray-50 transition-colors rounded-[3px]">
          Close
        </button>
      </div>
    </div>
  </div>

  <div
    id="termsPrivacyModal"
    class="fixed inset-0 z-50 hidden flex items-center justify-center px-4">
    <div class="modal-overlay absolute inset-0" id="closeTermsPrivacyOverlay"></div>
    <div
      class="bg-white w-full max-w-lg max-h-[90vh] overflow-y-auto relative z-10 shadow-2xl rounded-md">
      <div class="p-4 border-b border-gray-100 flex items-center justify-between sticky top-0 bg-white z-10">
        <h2 class="font-bold text-gray-800 text-sm">Terms of Service &amp; Privacy Policy</h2>
        <button id="closeTermsPrivacyBtn" class="p-1 hover:bg-gray-100 rounded-[3px]">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 text-gray-500">
            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
          </svg>
        </button>
      </div>
      <div class="p-4 space-y-4 text-[11px] text-gray-600 leading-relaxed">
        <p class="text-[10px] text-gray-400">Last updated: <?php echo date('F Y'); ?></p>

        <div>
          <p class="text-xs font-bold text-gray-800 mb-1.5">Terms of Service</p>
          <div class="space-y-2">
            <div>
              <p class="text-[11px] font-semibold text-gray-700">1. Your Account</p>
              <p>Stall owner accounts are created and assigned by the NWSSU Food Court admin. You are responsible for keeping your password secure and your contact information up to date, since customers and delivery staff rely on it to reach you.</p>
            </div>
            <div>
              <p class="text-[11px] font-semibold text-gray-700">2. Managing Your Stall</p>
              <p>You are responsible for the accuracy of your menu items, prices, availability status, business hours, and delivery fee. Please keep these updated so customers see correct information.</p>
            </div>
            <div>
              <p class="text-[11px] font-semibold text-gray-700">3. Order Fulfillment</p>
              <p>Orders placed to your stall should be accepted, prepared, and handed off (for pickup or delivery) promptly. Repeated unfulfilled or ignored orders may be reviewed by the admin.</p>
            </div>
            <div>
              <p class="text-[11px] font-semibold text-gray-700">4. Payment</p>
              <p>Customers may pay via cash, GCash, or PayMaya. We may also enable online payments through PayMongo in test mode as we continue developing this system; no real charges will be processed while it remains in test mode.</p>
            </div>
            <div>
              <p class="text-[11px] font-semibold text-gray-700">5. Acceptable Use</p>
              <p>Please do not use this app to submit false order updates, misrepresent your menu, or harass customers or delivery staff.</p>
            </div>
            <div>
              <p class="text-[11px] font-semibold text-gray-700">6. Changes to These Terms</p>
              <p>These terms may be updated as the system develops. Continued use of the app after changes means you accept the updated terms.</p>
            </div>
          </div>
        </div>

        <div>
          <p class="text-xs font-bold text-gray-800 mb-1.5">Privacy Policy</p>
          <div class="space-y-2">
            <div>
              <p class="text-[11px] font-semibold text-gray-700">1. Information We Collect</p>
              <p>We collect your name, contact number, email, business hours, and delivery fee, along with the orders placed to your stall, so the platform can operate and route orders to you correctly.</p>
            </div>
            <div>
              <p class="text-[11px] font-semibold text-gray-700">2. How We Use Your Information</p>
              <p>Your information is used to manage your stall account, display your stall to customers, process incoming orders, and send optional push notifications you choose to enable.</p>
            </div>
            <div>
              <p class="text-[11px] font-semibold text-gray-700">3. Who Can See Your Information</p>
              <p>Customers can see your stall name and contact details for orders they place with you. Delivery staff can see order details needed for handoff. Admins can access your account information to manage the platform. We do not sell or share your information with outside companies.</p>
            </div>
            <div>
              <p class="text-[11px] font-semibold text-gray-700">4. Payment Information</p>
              <p>If online payment via PayMongo is enabled, it currently runs in test mode only, meaning no real money or real card/GCash details are processed through it. Once live, payment details are handled directly by PayMongo and are not stored on our servers.</p>
            </div>
            <div>
              <p class="text-[11px] font-semibold text-gray-700">5. Data Security</p>
              <p>Passwords are encrypted (hashed) and cannot be viewed by anyone, including admins. Access to the system requires a valid login session.</p>
            </div>
            <div>
              <p class="text-[11px] font-semibold text-gray-700">6. Your Choices</p>
              <p>You can update your profile, delivery fee, and business hours anytime in Account Settings, and you can turn push notifications on or off from the Notifications setting.</p>
            </div>
            <div>
              <p class="text-[11px] font-semibold text-gray-700">7. Questions</p>
              <p>For concerns about your data, please contact the NWSSU Food Court administrator.</p>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div
    id="logoutModal"
    class="fixed inset-0 z-[60] hidden flex items-center justify-center px-4">
    <div class="modal-overlay absolute inset-0" id="closeLogoutOverlay"></div>
    <div
      class="bg-white w-full max-w-sm relative z-10 shadow-2xl p-5 space-y-4 text-center rounded-md">
      <div class="w-12 h-12 bg-red-50 flex items-center justify-center mx-auto rounded-full">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6 text-red-500">
          <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6a2.25 2.25 0 0 0-2.25 2.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15M12 9l-3 3m0 0 3 3m-3-3h12.75" />
        </svg>
      </div>
      <div>
        <p class="text-sm font-bold text-gray-800">Log Out</p>
        <p class="text-xs text-gray-500 mt-1">Are you sure you want to log out of your account?</p>
      </div>
      <div class="flex gap-2 pt-1">
        <button id="cancelLogoutBtn" class="flex-1 py-2.5 border border-gray-200 text-gray-700 text-xs font-semibold hover:bg-gray-50 transition-colors rounded-[3px]">
          Cancel
        </button>
        <button id="confirmLogoutBtn" class="flex-1 py-2.5 bg-red-500 hover:bg-red-600 text-white text-xs font-semibold transition-colors rounded-[3px]">
          Log Out
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
    let stallAccount = {
      firstName: <?php echo json_encode($initialProfile['first_name']); ?>,
      lastName: <?php echo json_encode($initialProfile['last_name']); ?>,
      contactNumber: <?php echo json_encode($initialProfile['contact_number']); ?>,
      email: <?php echo json_encode($initialProfile['email']); ?>,
      memberSince: <?php echo json_encode($initialProfile['member_since']); ?>,
      assignedStall: <?php echo json_encode($assignedStallName ?: 'Unassigned'); ?>,
      profileImage: <?php echo json_encode($initialProfile['profile_image']); ?>,
    };

    let stallHours = {
      opensAt: <?php echo json_encode($stallHours && $stallHours['opens_at'] ? substr($stallHours['opens_at'], 0, 5) : ''); ?>,
      closesAt: <?php echo json_encode($stallHours && $stallHours['closes_at'] ? substr($stallHours['closes_at'], 0, 5) : ''); ?>,
    };

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
        color: "bg-amber-500",
        textCls: "text-amber-600"
      },
      {
        label: "Strong",
        color: "bg-emerald-500",
        textCls: "text-emerald-600"
      },
      {
        label: "Very Strong",
        color: "bg-emerald-700",
        textCls: "text-emerald-700"
      },
    ];

    let currentProfileImageFile = null;
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

    function getInitials(first, last) {
      return ((first[0] || "") + (last[0] || "")).toUpperCase();
    }

    function renderProfile() {
      const initials = getInitials(stallAccount.firstName, stallAccount.lastName);
      document.getElementById("profileAvatar").innerHTML = stallAccount.profileImage ?
        `<img src="${escapeHtml(stallAccount.profileImage)}" class="w-full h-full object-cover" style="border-radius:50%" />` :
        initials;
      document.getElementById("profileName").textContent =
        stallAccount.firstName + " " + stallAccount.lastName;
      document.getElementById("profileSubtext").textContent = stallAccount.email;
      document.getElementById("statStallName").textContent = stallAccount.assignedStall;
      document.getElementById("statMemberSince").textContent = stallAccount.memberSince;

      document.getElementById("profileSkeleton").classList.add("hidden");
      document.getElementById("profileContent").classList.remove("hidden");
      document.getElementById("profileContent").classList.add("flex");
    }

    function updateEditProfilePreview(src, initials) {
      const img = document.getElementById("editProfilePreviewImg");
      const span = document.getElementById("editProfileInitials");
      const removeBtn = document.getElementById("removeEditProfileImageBtn");
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

    let initialEditProfileState = {};

    function checkForEditProfileChanges() {
      const changed =
        document.getElementById("fieldFirstName").value !== initialEditProfileState.firstName ||
        document.getElementById("fieldLastName").value !== initialEditProfileState.lastName ||
        document.getElementById("fieldContact").value !== initialEditProfileState.contact ||
        document.getElementById("fieldEmail").value !== initialEditProfileState.email ||
        currentProfileImageFile !== null ||
        removeImageFlag;

      document.getElementById("saveEditProfileBtn").disabled = !changed;
    }

    function openEditProfileModal() {
      document.getElementById("fieldFirstName").value = stallAccount.firstName;
      document.getElementById("fieldLastName").value = stallAccount.lastName;
      document.getElementById("fieldContact").value = stallAccount.contactNumber;
      document.getElementById("fieldEmail").value = stallAccount.email;
      document.getElementById("editProfileError").classList.add("hidden");
      document
        .querySelectorAll("#editProfileModal input")
        .forEach((el) => el.classList.remove("error"));
      currentProfileImageFile = null;
      removeImageFlag = false;
      document.getElementById("editProfileImageInput").value = "";
      updateEditProfilePreview(
        stallAccount.profileImage || null,
        getInitials(stallAccount.firstName, stallAccount.lastName),
      );
      initialEditProfileState = {
        firstName: stallAccount.firstName,
        lastName: stallAccount.lastName,
        contact: stallAccount.contactNumber,
        email: stallAccount.email,
      };
      document.getElementById("saveEditProfileBtn").disabled = true;
      document.getElementById("editProfileModal").classList.remove("hidden");
      document.body.style.overflow = "hidden";
    }

    function closeEditProfileModal() {
      document.getElementById("editProfileModal").classList.add("hidden");
      document.body.style.overflow = "";
    }

    function showEditProfileError(msg) {
      document.getElementById("editProfileErrorMsg").textContent = msg;
      document.getElementById("editProfileError").classList.remove("hidden");
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

    async function saveEditProfile() {
      const firstName = document.getElementById("fieldFirstName").value.trim();
      const lastName = document.getElementById("fieldLastName").value.trim();
      const contact = document.getElementById("fieldContact").value.trim();
      const email = document.getElementById("fieldEmail").value.trim();

      document
        .querySelectorAll("#editProfileModal input")
        .forEach((el) => el.classList.remove("error"));
      document.getElementById("editProfileError").classList.add("hidden");

      if (!firstName) {
        showEditProfileError("First name is required.");
        document.getElementById("fieldFirstName").classList.add("error");
        return;
      }
      if (!lastName) {
        showEditProfileError("Last name is required.");
        document.getElementById("fieldLastName").classList.add("error");
        return;
      }
      if (!contact) {
        showEditProfileError("Please enter a contact number.");
        document.getElementById("fieldContact").classList.add("error");
        return;
      }
      if (contact.length !== 11 || !contact.startsWith("09")) {
        showEditProfileError("Please enter a valid mobile number.");
        document.getElementById("fieldContact").classList.add("error");
        return;
      }
      if (!email || !isValidEmail(email)) {
        showEditProfileError("Please enter a valid email address.");
        document.getElementById("fieldEmail").classList.add("error");
        return;
      }

      const saveBtn = document.getElementById("saveEditProfileBtn");
      saveBtn.disabled = true;

      const formData = new FormData();
      formData.append("action", "edit_profile");
      formData.append("first_name", firstName);
      formData.append("last_name", lastName);
      formData.append("contact_number", contact);
      formData.append("email", email);
      if (currentProfileImageFile) {
        formData.append("profile_image_file", currentProfileImageFile);
      }
      if (removeImageFlag) {
        formData.append("remove_image", "1");
      }

      let res;
      try {
        const response = await fetch(window.location.href, {
          method: "POST",
          body: formData,
        });
        res = await response.json();
      } catch (err) {
        res = {
          success: false,
          message: "Something went wrong. Please try again."
        };
      }

      saveBtn.disabled = false;

      if (!res.success) {
        showEditProfileError(res.message || "Something went wrong. Please try again.");
        return;
      }

      stallAccount.firstName = firstName;
      stallAccount.lastName = lastName;
      stallAccount.contactNumber = contact;
      stallAccount.email = email;
      if (res.profile_image !== undefined) {
        stallAccount.profileImage = res.profile_image;
      }

      renderProfile();
      closeEditProfileModal();
      showToast("Profile updated successfully");
    }

    function resetPasswordModal() {
      document.getElementById("fieldCurrentPassword").value = "";
      document.getElementById("fieldNewPassword").value = "";
      document.getElementById("fieldConfirmPassword").value = "";
      document.getElementById("passwordError").classList.add("hidden");
      document.getElementById("pwMatchMsg").classList.add("hidden");
      [
        ["fieldCurrentPassword", "currentPwEyeIcon"],
        ["fieldNewPassword", "newPwEyeIcon"],
        ["fieldConfirmPassword", "confirmPwEyeIcon"],
      ].forEach(([inputId, iconId]) => {
        document.getElementById(inputId).type = "password";
        document.getElementById(iconId).innerHTML =
          `<path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/>`;
      });
      [1, 2, 3, 4, 5].forEach((n) => {
        const b = document.getElementById("pwBar" + n);
        b.className = "h-1 flex-1 bg-gray-200 transition-colors rounded-full";
      });
      document.getElementById("pwStrengthLabel").textContent = "";
      [
        ["pwReqLenIcon", "pwReqLenText"],
        ["pwReqUpperIcon", "pwReqUpperText"],
        ["pwReqLowerIcon", "pwReqLowerText"],
        ["pwReqNumIcon", "pwReqNumText"],
        ["pwReqSymbolIcon", "pwReqSymbolText"],
      ].forEach(([iconId, textId]) => {
        const icon = document.getElementById(iconId);
        const text = document.getElementById(textId);
        icon.classList.remove("border-red-400", "bg-emerald-600", "border-emerald-600");
        icon.classList.add("border-gray-300");
        icon.querySelector("svg").classList.add("opacity-0");
        text.classList.remove("text-red-500", "text-emerald-600");
        text.classList.add("text-gray-400");
      });
      document
        .querySelectorAll("#changePasswordModal input")
        .forEach((el) => el.classList.remove("error"));
    }

    function openChangePasswordModal() {
      resetPasswordModal();
      document.getElementById("changePasswordModal").classList.remove("hidden");
      document.body.style.overflow = "hidden";
    }

    function closeChangePasswordModal() {
      document.getElementById("changePasswordModal").classList.add("hidden");
      document.body.style.overflow = "";
    }

    function showPasswordError(msg) {
      document.getElementById("passwordErrorMsg").textContent = msg;
      document.getElementById("passwordError").classList.remove("hidden");
    }

    let initialBusinessHours = {};
    function checkForBusinessHoursChanges() {
      const changed =
        document.getElementById("fieldOpensAt").value !== initialBusinessHours.opensAt ||
        document.getElementById("fieldClosesAt").value !== initialBusinessHours.closesAt;
      document.getElementById("saveBusinessHoursBtn").disabled = !changed;
    }

    function openBusinessHoursModal() {
      document.getElementById("fieldOpensAt").value = stallHours.opensAt;
      document.getElementById("fieldClosesAt").value = stallHours.closesAt;
      document.getElementById("businessHoursError").classList.add("hidden");
      document
        .querySelectorAll("#businessHoursModal input")
        .forEach((el) => el.classList.remove("error"));
      initialBusinessHours = {
        opensAt: stallHours.opensAt,
        closesAt: stallHours.closesAt,
      };
      document.getElementById("saveBusinessHoursBtn").disabled = true;
      document.getElementById("businessHoursModal").classList.remove("hidden");
      document.body.style.overflow = "hidden";
    }

    function closeBusinessHoursModal() {
      document.getElementById("businessHoursModal").classList.add("hidden");
      document.body.style.overflow = "";
    }

    function showBusinessHoursError(msg) {
      document.getElementById("businessHoursErrorMsg").textContent = msg;
      document.getElementById("businessHoursError").classList.remove("hidden");
    }

    async function saveBusinessHours() {
      const opensAt = document.getElementById("fieldOpensAt").value;
      const closesAt = document.getElementById("fieldClosesAt").value;

      document.getElementById("businessHoursError").classList.add("hidden");
      document
        .querySelectorAll("#businessHoursModal input")
        .forEach((el) => el.classList.remove("error"));

      if (!opensAt) {
        showBusinessHoursError("Please set your opening time.");
        document.getElementById("fieldOpensAt").classList.add("error");
        return;
      }
      if (!closesAt) {
        showBusinessHoursError("Please set your closing time.");
        document.getElementById("fieldClosesAt").classList.add("error");
        return;
      }

      const saveBtn = document.getElementById("saveBusinessHoursBtn");
      saveBtn.disabled = true;

      const res = await postAction("update_business_hours", {
        opens_at: opensAt,
        closes_at: closesAt,
      });

      saveBtn.disabled = false;

      if (!res.success) {
        showBusinessHoursError(res.message || "Something went wrong. Please try again.");
        return;
      }

      stallHours.opensAt = opensAt;
      stallHours.closesAt = closesAt;

      closeBusinessHoursModal();
      showToast("Business hours updated successfully");
    }

    function setupBusinessHoursModal() {
      document.getElementById("businessHoursBtn").addEventListener("click", openBusinessHoursModal);
      document.getElementById("closeBusinessHoursBtn").addEventListener("click", closeBusinessHoursModal);
      document.getElementById("closeBusinessHoursOverlay").addEventListener("click", closeBusinessHoursModal);
      document.getElementById("cancelBusinessHoursBtn").addEventListener("click", closeBusinessHoursModal);
      document.getElementById("saveBusinessHoursBtn").addEventListener("click", saveBusinessHours);

      ["fieldOpensAt", "fieldClosesAt"].forEach((id) => {
        document.getElementById(id).addEventListener("input", checkForBusinessHoursChanges);
      });
    }

    function getPwStrength(pw) {
      let s = 0;
      if (pw.length >= 8) s++;
      if (/[A-Z]/.test(pw)) s++;
      if (/[a-z]/.test(pw)) s++;
      if (/[0-9]/.test(pw)) s++;
      if (/[^A-Za-z0-9]/.test(pw)) s++;
      return s;
    }

    function updatePwReqIcon(iconId, textId, met, hasInput) {
      const icon = document.getElementById(iconId);
      const text = document.getElementById(textId);
      const check = icon.querySelector("svg");

      icon.classList.remove("border-gray-300", "border-red-400", "bg-emerald-600", "border-emerald-600");
      text.classList.remove("text-gray-400", "text-red-500", "text-emerald-600");

      if (!hasInput) {
        icon.classList.add("border-gray-300");
        text.classList.add("text-gray-400");
        check.classList.add("opacity-0");
      } else if (met) {
        icon.classList.add("bg-emerald-600", "border-emerald-600");
        text.classList.add("text-emerald-600");
        check.classList.remove("opacity-0");
      } else {
        icon.classList.add("border-red-400");
        text.classList.add("text-red-500");
        check.classList.add("opacity-0");
      }
    }

    function checkPwMatch() {
      const pw = document.getElementById("fieldNewPassword").value;
      const cpw = document.getElementById("fieldConfirmPassword").value;
      const matchMsg = document.getElementById("pwMatchMsg");
      if (!cpw) {
        matchMsg.classList.add("hidden");
        return;
      }
      matchMsg.classList.remove("hidden");
      if (pw === cpw) {
        matchMsg.textContent = "Passwords match";
        matchMsg.className = "text-[10px] mt-1.5 text-emerald-600";
      } else {
        matchMsg.textContent = "Passwords do not match";
        matchMsg.className = "text-[10px] mt-1.5 text-red-500";
      }
    }

    function isStrongPassword(pw) {
      return (
        pw.length >= 8 &&
        /[A-Z]/.test(pw) &&
        /[a-z]/.test(pw) &&
        /[0-9]/.test(pw) &&
        /[^A-Za-z0-9]/.test(pw)
      );
    }

    async function saveChangePassword() {
      const currentPw = document.getElementById("fieldCurrentPassword").value;
      const newPw = document.getElementById("fieldNewPassword").value;
      const confirmPw = document.getElementById("fieldConfirmPassword").value;

      document.getElementById("passwordError").classList.add("hidden");
      document
        .querySelectorAll("#changePasswordModal input")
        .forEach((el) => el.classList.remove("error"));

      if (!currentPw) {
        showPasswordError("Please enter your current password.");
        document.getElementById("fieldCurrentPassword").classList.add("error");
        return;
      }
      if (!newPw) {
        showPasswordError("Please enter a new password.");
        document.getElementById("fieldNewPassword").classList.add("error");
        return;
      }
      if (!isStrongPassword(newPw)) {
        showPasswordError("New password must be at least 8 characters and include an uppercase letter, a lowercase letter, a number, and a symbol.");
        document.getElementById("fieldNewPassword").classList.add("error");
        return;
      }
      if (newPw !== confirmPw) {
        showPasswordError("Passwords do not match.");
        document.getElementById("fieldConfirmPassword").classList.add("error");
        return;
      }

      const saveBtn = document.getElementById("saveChangePasswordBtn");
      saveBtn.disabled = true;

      const res = await postAction("change_password", {
        current_password: currentPw,
        new_password: newPw,
        confirm_password: confirmPw,
      });

      saveBtn.disabled = false;

      if (!res.success) {
        showPasswordError(res.message || "Something went wrong. Please try again.");
        document.getElementById("fieldCurrentPassword").classList.add("error");
        return;
      }

      closeChangePasswordModal();
      showToast("Password updated successfully");
    }

    function openLogoutModal() {
      document.getElementById("logoutModal").classList.remove("hidden");
      document.body.style.overflow = "hidden";
    }

    function closeLogoutModal() {
      document.getElementById("logoutModal").classList.add("hidden");
      document.body.style.overflow = "";
    }

    function makePasswordToggle(btnId, inputId, iconId) {
      const btn = document.getElementById(btnId);
      const input = document.getElementById(inputId);
      const icon = document.getElementById(iconId);
      btn.addEventListener("click", () => {
        const show = input.type === "password";
        input.type = show ? "text" : "password";
        icon.innerHTML = show ?
          `<path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 0 0 1.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.451 10.451 0 0 1 12 4.5c4.756 0 8.773 3.162 10.065 7.498a10.522 10.522 0 0 1-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65m0 0a3 3 0 1 0-4.243-4.243m4.242 4.242L9.88 9.88"/>` :
          `<path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/>`;
      });
    }

    function setupBackButton() {
      document.getElementById("backButton").addEventListener("click", () => window.history.back());
    }

    function setupEditProfileModal() {
      document.getElementById("editProfileBtn").addEventListener("click", openEditProfileModal);
      document.getElementById("closeEditProfileBtn").addEventListener("click", closeEditProfileModal);
      document.getElementById("closeEditProfileOverlay").addEventListener("click", closeEditProfileModal);
      document.getElementById("cancelEditProfileBtn").addEventListener("click", closeEditProfileModal);
      document.getElementById("saveEditProfileBtn").addEventListener("click", saveEditProfile);

      document.getElementById("editProfileImageBtn").addEventListener("click", () =>
        document.getElementById("editProfileImageInput").click(),
      );
      document.getElementById("editProfileImageInput").addEventListener("change", (e) => {
        const file = e.target.files[0];
        if (!file) return;
        currentProfileImageFile = file;
        removeImageFlag = false;
        const reader = new FileReader();
        reader.onload = (ev) => {
          updateEditProfilePreview(ev.target.result, null);
          checkForEditProfileChanges();
        };
        reader.readAsDataURL(file);
      });
      document.getElementById("removeEditProfileImageBtn").addEventListener("click", () => {
        currentProfileImageFile = null;
        removeImageFlag = true;
        document.getElementById("editProfileImageInput").value = "";
        const first = document.getElementById("fieldFirstName").value.trim();
        const last = document.getElementById("fieldLastName").value.trim();
        updateEditProfilePreview(null, getInitials(first || "?", last || ""));
        checkForEditProfileChanges();
      });

      ["fieldFirstName", "fieldLastName", "fieldContact", "fieldEmail"].forEach((id) => {
        document.getElementById(id).addEventListener("input", checkForEditProfileChanges);
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
            checkForEditProfileChanges();
          }
        });
      });

      function checkFieldContactValidity() {
        const tel = document.getElementById("fieldContact").value.trim();
        const errMsg = document.getElementById("fieldContactErrorMsg");
        if (!tel) {
          errMsg.classList.add("hidden");
          return;
        }
        if (tel.length !== 11) {
          errMsg.textContent = "Mobile number must be 11 digits.";
          errMsg.classList.remove("hidden");
        } else if (!tel.startsWith("09")) {
          errMsg.textContent = "Please enter a valid mobile number.";
          errMsg.classList.remove("hidden");
        } else {
          errMsg.classList.add("hidden");
        }
      }

      document.getElementById("fieldContact").addEventListener("input", (e) => {
        const cursorPos = e.target.selectionStart;
        const cleaned = e.target.value.replace(/\D/g, "").slice(0, 11);
        if (cleaned !== e.target.value) {
          const removedCount = e.target.value.length - cleaned.length;
          e.target.value = cleaned;
          const newPos = Math.max(0, cursorPos - removedCount);
          e.target.setSelectionRange(newPos, newPos);
        }
      });

      document.getElementById("fieldContact").addEventListener("blur", () => {
        checkFieldContactValidity();
      });

      function checkFieldEmailValidity() {
        const em = document.getElementById("fieldEmail").value.trim();
        const errMsg = document.getElementById("fieldEmailErrorMsg");
        if (!em || isValidEmail(em)) {
          errMsg.classList.add("hidden");
        } else {
          errMsg.textContent = "Please enter a valid email address.";
          errMsg.classList.remove("hidden");
        }
      }

      document.getElementById("fieldEmail").addEventListener("blur", () => {
        checkFieldEmailValidity();
      });
    }

    function setupChangePasswordModal() {
      document.getElementById("changePasswordBtn").addEventListener("click", openChangePasswordModal);
      document.getElementById("closeChangePasswordBtn").addEventListener("click", closeChangePasswordModal);
      document.getElementById("closeChangePasswordOverlay").addEventListener("click", closeChangePasswordModal);
      document.getElementById("cancelChangePasswordBtn").addEventListener("click", closeChangePasswordModal);
      document.getElementById("saveChangePasswordBtn").addEventListener("click", saveChangePassword);

      makePasswordToggle("toggleCurrentPasswordBtn", "fieldCurrentPassword", "currentPwEyeIcon");
      makePasswordToggle("toggleNewPasswordBtn", "fieldNewPassword", "newPwEyeIcon");
      makePasswordToggle("toggleConfirmPasswordBtn", "fieldConfirmPassword", "confirmPwEyeIcon");

      document.getElementById("fieldNewPassword").addEventListener("input", () => {
        const pw = document.getElementById("fieldNewPassword").value;
        const score = pw.length === 0 ? 0 : Math.max(1, getPwStrength(pw));
        const level = pwLevels[score];
        [1, 2, 3, 4, 5].forEach((n, i) => {
          const b = document.getElementById("pwBar" + n);
          b.className = `h-1 flex-1 transition-colors rounded-full ${i < score ? level.color : "bg-gray-200"}`;
        });
        const lbl = document.getElementById("pwStrengthLabel");
        lbl.textContent = pw.length > 0 ? level.label : "";
        lbl.className = `text-[10px] font-semibold ${score > 0 ? level.textCls : "text-gray-400"}`;

        const hasInput = pw.length > 0;
        updatePwReqIcon("pwReqLenIcon", "pwReqLenText", pw.length >= 8, hasInput);
        updatePwReqIcon("pwReqUpperIcon", "pwReqUpperText", /[A-Z]/.test(pw), hasInput);
        updatePwReqIcon("pwReqLowerIcon", "pwReqLowerText", /[a-z]/.test(pw), hasInput);
        updatePwReqIcon("pwReqNumIcon", "pwReqNumText", /[0-9]/.test(pw), hasInput);
        updatePwReqIcon("pwReqSymbolIcon", "pwReqSymbolText", /[^A-Za-z0-9]/.test(pw), hasInput);

        checkPwMatch();
      });
      document.getElementById("fieldConfirmPassword").addEventListener("input", checkPwMatch);
    }

    function setupInfoRows() {
      document.querySelectorAll(".account-row[data-info]").forEach((row) => {
        row.addEventListener("click", () => alert(row.getAttribute("data-info")));
      });
    }

    function setupTermsPrivacyModal() {
      const modal = document.getElementById("termsPrivacyModal");
      const openModal = () => {
        modal.classList.remove("hidden");
        document.body.style.overflow = "hidden";
      };
      const closeModal = () => {
        modal.classList.add("hidden");
        document.body.style.overflow = "";
      };
      document.getElementById("termsPrivacyBtn").addEventListener("click", openModal);
      document.getElementById("closeTermsPrivacyBtn").addEventListener("click", closeModal);
      document.getElementById("closeTermsPrivacyOverlay").addEventListener("click", closeModal);
    }

    function setupShareAppModal() {
      const modal = document.getElementById("shareAppModal");
      const openModal = () => {
        modal.classList.remove("hidden");
        document.body.style.overflow = "hidden";
      };
      const closeModal = () => {
        modal.classList.add("hidden");
        document.body.style.overflow = "";
      };
      document.getElementById("shareAppBtn").addEventListener("click", openModal);
      document.getElementById("closeShareAppBtn").addEventListener("click", closeModal);
      document.getElementById("closeShareAppOverlay").addEventListener("click", closeModal);

      document.getElementById("copyShareAppUrlBtn").addEventListener("click", async () => {
        const url = document.getElementById("shareAppUrlText").textContent;
        try {
          await navigator.clipboard.writeText(url);
          showToast("Link copied to clipboard");
        } catch (err) {
          showToast("Failed to copy link", "warning");
        }
      });

      document.getElementById("downloadShareAppQrBtn").addEventListener("click", async () => {
        const img = document.getElementById("shareAppQrImg");
        try {
          const response = await fetch(img.src);
          const blob = await response.blob();
          const blobUrl = URL.createObjectURL(blob);
          const a = document.createElement("a");
          a.href = blobUrl;
          a.download = "nwssu-foodcourt-qr.png";
          document.body.appendChild(a);
          a.click();
          document.body.removeChild(a);
          URL.revokeObjectURL(blobUrl);
        } catch (err) {
          window.open(img.src, "_blank");
        }
      });
    }

    function setupLogoutModal() {
      document.getElementById("logoutBtn").addEventListener("click", openLogoutModal);
      document.getElementById("closeLogoutOverlay").addEventListener("click", closeLogoutModal);
      document.getElementById("cancelLogoutBtn").addEventListener("click", closeLogoutModal);
      document.getElementById("confirmLogoutBtn").addEventListener("click", () => {
        window.location.href = "../auth/logout.php";
      });
    }

    function init() {
      renderProfile();
      setupBackButton();
      setupEditProfileModal();
      setupChangePasswordModal();
      setupBusinessHoursModal();
      setupInfoRows();
      setupTermsPrivacyModal();
      setupShareAppModal();
      setupLogoutModal();
    }

    window.addEventListener("load", init);
  </script>
</body>

</html>