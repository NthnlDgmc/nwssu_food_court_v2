<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['admin_id'])) {
    header('Location: ../auth/login.php');
    exit;
}

$adminId = $_SESSION['admin_id'];

function fetchAdminProfile($conn, $adminId) {
    $stmt = $conn->prepare("SELECT first_name, last_name, contact_number, email, profile_image, created_at FROM admins WHERE admin_id = ? LIMIT 1");
    $stmt->bind_param("i", $adminId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row) {
        $row['member_since'] = date('M Y', strtotime($row['created_at']));
        $row['profile_image'] = $row['profile_image'] ? '../' . $row['profile_image'] : null;
    }

    return $row;
}

function handleProfileImageUpload($file) {
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

    $uploadDir = __DIR__ . '/../uploads/admin/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $filename = uniqid('admin_', true) . '.' . $ext;
    $destination = $uploadDir . $filename;

    if (move_uploaded_file($file['tmp_name'], $destination)) {
        return 'uploads/admin/' . $filename;
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
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success' => false, 'message' => 'Please enter a valid email address.']);
            exit;
        }

        $stmt = $conn->prepare("UPDATE admins SET first_name = ?, last_name = ?, contact_number = ?, email = ? WHERE admin_id = ?");
        $stmt->bind_param("ssssi", $firstName, $lastName, $contact, $email, $adminId);
        $ok = $stmt->execute();
        $errNo = $stmt->errno;
        $stmt->close();

        if (!$ok) {
            $message = $errNo === 1062 ? 'Email address is already registered.' : 'Failed to update profile.';
            echo json_encode(['success' => false, 'message' => $message]);
            $conn->close();
            exit;
        }

        $newProfileImageRaw = null;
        $hasNewImage = false;

        if (isset($_FILES['profile_image_file']) && $_FILES['profile_image_file']['error'] !== UPLOAD_ERR_NO_FILE) {
            $imagePath = handleProfileImageUpload($_FILES['profile_image_file']);
            if ($imagePath) {
                $imgStmt = $conn->prepare("UPDATE admins SET profile_image = ? WHERE admin_id = ?");
                $imgStmt->bind_param("si", $imagePath, $adminId);
                $imgStmt->execute();
                $imgStmt->close();
                $newProfileImageRaw = $imagePath;
                $hasNewImage = true;
            }
        } elseif ($removeImage) {
            $imgStmt = $conn->prepare("UPDATE admins SET profile_image = NULL WHERE admin_id = ?");
            $imgStmt->bind_param("i", $adminId);
            $imgStmt->execute();
            $imgStmt->close();
            $newProfileImageRaw = null;
            $hasNewImage = true;
        }

        if ($hasNewImage) {
            $newProfileImage = $newProfileImageRaw ? '../' . $newProfileImageRaw : null;
        } else {
            $currentProfile = fetchAdminProfile($conn, $adminId);
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
            echo json_encode(['success' => false, 'message' => 'New password must be at least 8 characters and include an uppercase letter, a number, and a symbol.']);
            exit;
        }
        if ($newPw !== $confirmPw) {
            echo json_encode(['success' => false, 'message' => 'Passwords do not match.']);
            exit;
        }

        $stmt = $conn->prepare("SELECT password FROM admins WHERE admin_id = ? LIMIT 1");
        $stmt->bind_param("i", $adminId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row || !password_verify($currentPw, $row['password'])) {
            echo json_encode(['success' => false, 'message' => 'Current password is incorrect.']);
            $conn->close();
            exit;
        }

        $newPw = password_hash($newPw, PASSWORD_DEFAULT);

        $updateStmt = $conn->prepare("UPDATE admins SET password = ? WHERE admin_id = ?");
        $updateStmt->bind_param("si", $newPw, $adminId);
        $ok = $updateStmt->execute();
        $updateStmt->close();

        echo json_encode($ok
            ? ['success' => true]
            : ['success' => false, 'message' => 'Failed to update password.']);
        $conn->close();
        exit;
    }

    if ($action === 'get_profile') {
        $profile = fetchAdminProfile($conn, $adminId);
        echo json_encode(['success' => true, 'profile' => $profile]);
        $conn->close();
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action.']);
    $conn->close();
    exit;
}

$initialProfile = fetchAdminProfile($conn, $adminId);
$conn->close();

if (!$initialProfile) {
    header('Location: ../auth/login.php');
    exit;
}

$adminFullName = $initialProfile['first_name'] . ' ' . $initialProfile['last_name'];
$adminProfileImage = $initialProfile['profile_image'];

function getAdminInitials($first, $last)
{
    $f = mb_substr(trim($first), 0, 1);
    $l = mb_substr(trim($last), 0, 1);
    return mb_strtoupper($f . $l);
}

$adminInitials = getAdminInitials($initialProfile['first_name'], $initialProfile['last_name']);
?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <meta
      name="viewport"
      content="width=device-width, initial-scale=1.0, user-scalable=yes"
    />
    <title>Admin - My Account</title>
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
      input:focus {
        outline: none;
        border-color: #059669;
      }
      input.error {
        border-color: #f87171;
      }

      #toast {
        opacity: 0;
        transform: translate(-50%, 8px);
        transition: opacity 0.25s ease, transform 0.25s ease;
      }

      #toast.toast-visible {
        opacity: 1;
        transform: translate(-50%, 0);
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
              aria-controls="sidebar"
            >
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
              <span class="text-emerald-600 font-medium truncate">My Account</span>
            </nav>
          </div>
        </div>
      </div>

      <div id="sidebarOverlay"></div>

      <aside id="sidebar">
        <div class="flex items-center justify-between px-4 py-4 border-b border-gray-100 shrink-0">
          <a href="./account.php" class="flex items-center gap-2.5 min-w-0">
            <div id="sidebarAvatar" class="w-9 h-9 bg-gradient-to-br from-emerald-500 to-emerald-700 flex items-center justify-center text-white font-bold text-xs shrink-0 rounded-full overflow-hidden">
              <?php if ($adminProfileImage): ?>
                <img src="<?php echo htmlspecialchars($adminProfileImage); ?>" alt="<?php echo htmlspecialchars($adminFullName); ?>" class="w-full h-full object-cover" />
              <?php else: ?>
                <?php echo htmlspecialchars($adminInitials); ?>
              <?php endif; ?>
            </div>
            <div class="min-w-0">
              <p id="sidebarName" class="text-sm font-bold text-gray-800 truncate"><?php echo htmlspecialchars($adminFullName); ?></p>
              <p class="text-[10px] text-gray-400 truncate">System Administrator</p>
            </div>
          </a>
          <button id="closeSidebar" class="p-1.5 hover:bg-gray-100 transition-colors text-gray-500" style="border-radius: 3px" aria-label="Close menu">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5">
              <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
            </svg>
          </button>
        </div>

        <nav class="flex-1 overflow-y-auto py-3 px-3 space-y-1">
          <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest px-2 pt-1 pb-1.5">Main</p>

          <a href="./dashboard.php" class="sidebar-link flex items-center gap-3 px-3 py-2.5 text-gray-600 hover:bg-gray-50 hover:text-gray-900 border border-transparent font-medium transition-colors" style="border-radius: 6px">
            <span class="w-8 h-8 flex items-center justify-center bg-gray-100 shrink-0" style="border-radius: 3px">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 text-gray-500">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 0 1 6 3.75h2.25A2.25 2.25 0 0 1 10.5 6v2.25a2.25 2.25 0 0 1-2.25 2.25H6a2.25 2.25 0 0 1-2.25-2.25V6ZM3.75 15.75A2.25 2.25 0 0 1 6 13.5h2.25a2.25 2.25 0 0 1 2.25 2.25V18a2.25 2.25 0 0 1-2.25 2.25H6A2.25 2.25 0 0 1 3.75 18v-2.25ZM13.5 6a2.25 2.25 0 0 1 2.25-2.25H18A2.25 2.25 0 0 1 20.25 6v2.25A2.25 2.25 0 0 1 18 10.5h-2.25a2.25 2.25 0 0 1-2.25-2.25V6ZM13.5 15.75a2.25 2.25 0 0 1 2.25-2.25H18a2.25 2.25 0 0 1 2.25 2.25V18A2.25 2.25 0 0 1 18 20.25h-2.25A2.25 2.25 0 0 1 13.5 18v-2.25Z" />
              </svg>
            </span>
            <span class="text-sm">Dashboard</span>
          </a>

          <a href="./chat.php" class="sidebar-link flex items-center gap-3 px-3 py-2.5 text-gray-600 hover:bg-gray-50 hover:text-gray-900 border border-transparent font-medium transition-colors" style="border-radius: 6px">
            <span class="w-8 h-8 flex items-center justify-center bg-gray-100 shrink-0 relative" style="border-radius: 3px">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 text-gray-500">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 20.25c4.97 0 9-3.694 9-8.25s-4.03-8.25-9-8.25S3 7.444 3 12c0 2.104.859 4.023 2.273 5.48.432.447.74 1.04.586 1.641a4.483 4.483 0 0 1-.923 1.785A5.969 5.969 0 0 0 6 21c1.282 0 2.47-.402 3.445-1.087.81.22 1.668.337 2.555.337Z" />
              </svg>
            </span>
            <span class="text-sm flex-1">Chats</span>
          </a>

          <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest px-2 pt-3 pb-1.5">Manage</p>

          <a href="./stalls.php" class="sidebar-link flex items-center gap-3 px-3 py-2.5 text-gray-600 hover:bg-gray-50 hover:text-gray-900 border border-transparent font-medium transition-colors" style="border-radius: 6px">
            <span class="w-8 h-8 flex items-center justify-center bg-gray-100 shrink-0" style="border-radius: 3px">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 text-gray-500">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3 7.5 4.5 3h15L21 7.5m-18 0v12a1.5 1.5 0 0 0 1.5 1.5h15a1.5 1.5 0 0 0 1.5-1.5v-12m-18 0h18M9 12h6" />
              </svg>
            </span>
            <span class="text-sm">Stalls</span>
          </a>

          <a href="./stall-owners.php" class="sidebar-link flex items-center gap-3 px-3 py-2.5 text-gray-600 hover:bg-gray-50 hover:text-gray-900 border border-transparent font-medium transition-colors" style="border-radius: 6px">
            <span class="w-8 h-8 flex items-center justify-center bg-gray-100 shrink-0" style="border-radius: 3px">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 text-gray-500">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" />
              </svg>
            </span>
            <span class="text-sm">Stall Owners</span>
          </a>

          <a href="./delivery-staff.php" class="sidebar-link flex items-center gap-3 px-3 py-2.5 text-gray-600 hover:bg-gray-50 hover:text-gray-900 border border-transparent font-medium transition-colors" style="border-radius: 6px">
            <span class="w-8 h-8 flex items-center justify-center bg-gray-100 shrink-0" style="border-radius: 3px">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 text-gray-500">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" />
              </svg>
            </span>
            <span class="text-sm">Delivery Staff</span>
          </a>

          <a href="./customers.php" class="sidebar-link flex items-center gap-3 px-3 py-2.5 text-gray-600 hover:bg-gray-50 hover:text-gray-900 border border-transparent font-medium transition-colors" style="border-radius: 6px">
            <span class="w-8 h-8 flex items-center justify-center bg-gray-100 shrink-0" style="border-radius: 3px">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 text-gray-500">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z" />
              </svg>
            </span>
            <span class="text-sm">Customers</span>
          </a>

          <a href="./categories.php" class="sidebar-link flex items-center gap-3 px-3 py-2.5 text-gray-600 hover:bg-gray-50 hover:text-gray-900 border border-transparent font-medium transition-colors" style="border-radius: 6px">
            <span class="w-8 h-8 flex items-center justify-center bg-gray-100 shrink-0" style="border-radius: 3px">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 text-gray-500">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 12h16.5m-16.5 3.75h16.5M3.75 19.5h16.5M5.625 4.5h12.75a1.875 1.875 0 0 1 0 3.75H5.625a1.875 1.875 0 0 1 0-3.75Z" />
              </svg>
            </span>
            <span class="text-sm">Categories</span>
          </a>
          <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest px-2 pt-3 pb-1.5">Account</p>

          <a href="./account.php" class="sidebar-link active flex items-center gap-3 px-3 py-2.5 text-emerald-700 bg-emerald-50 border border-emerald-100 font-semibold transition-colors" style="border-radius: 6px">
            <span class="w-8 h-8 flex items-center justify-center bg-emerald-600 shrink-0" style="border-radius: 3px">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 text-white">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" />
              </svg>
            </span>
            <span class="text-sm">My Account</span>
          </a>
        </nav>
      </aside>

      <div class="flex-1 overflow-y-auto mt-12" id="mainContent">
        <div class="max-w-5xl mx-auto px-4 pt-3 pb-6 space-y-3">
          <div class="rounded-md bg-white border border-gray-200 shadow-sm p-4">
            <div class="flex items-center gap-3">
              <div
                id="profileAvatar"
                class="w-16 h-16 bg-gradient-to-br from-emerald-500 to-emerald-700 flex items-center justify-center text-white font-bold text-xl shrink-0 rounded-full overflow-hidden"
              ></div>
              <div class="flex-1 min-w-0">
                <p
                  id="profileName"
                  class="text-sm font-bold text-gray-800 truncate"
                ></p>
                <p id="profileSubtext" class="text-[11px] text-gray-400 mt-1 truncate"></p>
              </div>
            </div>
            <button
              id="editProfileBtn"
              class="w-full mt-4 py-2 border border-gray-200 text-gray-700 text-xs font-semibold hover:bg-gray-50 transition-colors flex items-center justify-center gap-1.5"
              style="border-radius: 3px"
            >
              <svg
                xmlns="http://www.w3.org/2000/svg"
                fill="none"
                viewBox="0 0 24 24"
                stroke-width="1.5"
                stroke="currentColor"
                class="w-3.5 h-3.5"
              >
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0 1 15.75 21H5.25A2.25 2.25 0 0 1 3 18.75V8.25A2.25 2.25 0 0 1 5.25 6H10"
                />
              </svg>
              Edit Profile
            </button>
          </div>

          <div class="grid grid-cols-2 gap-2">
            <div class="rounded-md bg-white border border-gray-200 shadow-sm p-3 text-center">
              <p class="text-base font-bold text-emerald-600">System Admin</p>
              <p class="text-[10px] text-gray-400 mt-0.5">Role</p>
            </div>
            <div class="rounded-md bg-white border border-gray-200 shadow-sm p-3 text-center">
              <p id="statMemberSince" class="text-base font-bold text-emerald-600"></p>
              <p class="text-[10px] text-gray-400 mt-0.5">Member Since</p>
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
                class="account-row w-full flex items-center gap-3 px-4 py-3 hover:bg-gray-50 transition-colors text-left"
              >
                <span class="w-8 h-8 bg-gray-100 flex items-center justify-center shrink-0" style="border-radius:3px">
                  <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 text-gray-500">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" />
                  </svg>
                </span>
                <span class="flex-1 text-xs font-medium text-gray-700">Change Password</span>
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 text-gray-300 shrink-0">
                  <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
                </svg>
              </button>
              <button
                class="account-row w-full flex items-center gap-3 px-4 py-3 hover:bg-gray-50 transition-colors text-left"
                data-info="Notification preferences are coming soon."
              >
                <span class="w-8 h-8 bg-gray-100 flex items-center justify-center shrink-0" style="border-radius:3px">
                  <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 text-gray-500">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0" />
                  </svg>
                </span>
                <span class="flex-1 text-xs font-medium text-gray-700">Notifications</span>
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
                class="account-row w-full flex items-center gap-3 px-4 py-3 hover:bg-gray-50 transition-colors text-left"
                data-info="For assistance, please message the development team via the Chats tab."
              >
                <span class="w-8 h-8 bg-gray-100 flex items-center justify-center shrink-0" style="border-radius:3px">
                  <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 text-gray-500">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 5.25h.008v.008H12v-.008Z" />
                  </svg>
                </span>
                <span class="flex-1 text-xs font-medium text-gray-700">Help Center</span>
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 text-gray-300 shrink-0">
                  <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
                </svg>
              </button>
              <button
                class="account-row w-full flex items-center gap-3 px-4 py-3 hover:bg-gray-50 transition-colors text-left"
                data-info="Terms of Service and Privacy Policy are coming soon."
              >
                <span class="w-8 h-8 bg-gray-100 flex items-center justify-center shrink-0" style="border-radius:3px">
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
                <span class="w-8 h-8 bg-gray-100 flex items-center justify-center shrink-0" style="border-radius:3px">
                  <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 text-gray-500">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.008v.008H12V8.25Z" />
                  </svg>
                </span>
                <span class="flex-1 text-xs font-medium text-gray-700">App Version</span>
                <span class="text-xs text-gray-400">1.0.0</span>
              </div>
            </div>
          </div>

          <button
            id="logoutBtn"
            class="w-full py-2.5 border border-red-200 text-red-500 text-xs font-semibold hover:bg-red-50 transition-colors flex items-center justify-center gap-1.5"
            style="border-radius: 3px"
          >
            <svg
              xmlns="http://www.w3.org/2000/svg"
              fill="none"
              viewBox="0 0 24 24"
              stroke-width="1.5"
              stroke="currentColor"
              class="w-4 h-4"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6a2.25 2.25 0 0 0-2.25 2.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15M12 9l-3 3m0 0 3 3m-3-3h12.75"
              />
            </svg>
            Log Out
          </button>
        </div>
      </div>
    </div>

    <div
      id="editProfileModal"
      class="fixed inset-0 z-50 hidden flex items-center justify-center px-4"
    >
      <div class="modal-overlay absolute inset-0" id="closeEditProfileOverlay"></div>
      <div
        class="bg-white w-full max-w-md max-h-[90vh] overflow-y-auto relative z-10 shadow-2xl"
        style="border-radius: 6px"
      >
        <div class="p-4 border-b border-gray-100 flex items-center justify-between sticky top-0 bg-white z-10">
          <h2 class="font-bold text-gray-800 text-sm">Edit Profile</h2>
          <button id="closeEditProfileBtn" class="p-1 hover:bg-gray-100" style="border-radius: 3px">
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
                class="w-16 h-16 bg-gradient-to-br from-emerald-500 to-emerald-700 flex items-center justify-center text-white font-bold text-lg rounded-full overflow-hidden"
              >
                <span id="editProfileInitials">?</span>
                <img
                  id="editProfilePreviewImg"
                  src=""
                  alt=""
                  class="hidden w-full h-full object-cover"
                  style="border-radius: 50%"
                />
              </div>
              <button
                type="button"
                id="editProfileImageBtn"
                class="absolute -bottom-1 -right-1 w-6 h-6 bg-emerald-600 hover:bg-emerald-700 flex items-center justify-center shadow transition-colors rounded-full"
              >
                <svg
                  xmlns="http://www.w3.org/2000/svg"
                  fill="none"
                  viewBox="0 0 24 24"
                  stroke-width="2"
                  stroke="currentColor"
                  class="w-3 h-3 text-white"
                >
                  <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    d="M6.827 6.175A2.31 2.31 0 0 1 5.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 0 0-1.134-.175 2.31 2.31 0 0 1-1.64-1.055l-.822-1.316a2.192 2.192 0 0 0-1.736-1.039 48.774 48.774 0 0 0-5.232 0 2.192 2.192 0 0 0-1.736 1.039l-.821 1.316Z"
                  />
                  <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    d="M16.5 12.75a4.5 4.5 0 1 1-9 0 4.5 4.5 0 0 1 9 0ZM18.75 10.5h.008v.008h-.008V10.5Z"
                  />
                </svg>
              </button>
            </div>
            <input
              type="file"
              id="editProfileImageInput"
              accept="image/*"
              class="hidden"
            />
            <div class="text-center">
              <p class="text-[10px] text-gray-400">
                Profile Photo <span class="text-gray-300">(optional)</span>
              </p>
              <button
                type="button"
                id="removeEditProfileImageBtn"
                class="hidden text-[10px] text-red-400 hover:text-red-600 font-semibold transition-colors mt-0.5"
              >
                Remove photo
              </button>
            </div>
          </div>

          <div
            id="editProfileError"
            class="hidden items-center gap-2 p-3 bg-red-50 border border-red-200"
            style="border-radius: 3px"
          >
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 text-red-500 shrink-0">
              <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
            </svg>
            <p class="text-[10px] text-red-600 font-medium leading-none" id="editProfileErrorMsg"></p>
          </div>

          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="block text-[11px] font-semibold text-gray-500 uppercase tracking-wide mb-1.5">First Name</label>
              <input type="text" id="fieldFirstName" class="w-full px-3 py-2.5 bg-white border border-gray-200 text-xs text-gray-900 placeholder-gray-400 focus:outline-none focus:border-emerald-600" style="border-radius: 3px" />
            </div>
            <div>
              <label class="block text-[11px] font-semibold text-gray-500 uppercase tracking-wide mb-1.5">Last Name</label>
              <input type="text" id="fieldLastName" class="w-full px-3 py-2.5 bg-white border border-gray-200 text-xs text-gray-900 placeholder-gray-400 focus:outline-none focus:border-emerald-600" style="border-radius: 3px" />
            </div>
          </div>

          <div>
            <label class="block text-[11px] font-semibold text-gray-500 uppercase tracking-wide mb-1.5">Contact Number</label>
            <input type="tel" id="fieldContact" placeholder="0917 123 4567" class="w-full px-3 py-2.5 bg-white border border-gray-200 text-xs text-gray-900 placeholder-gray-400 focus:outline-none focus:border-emerald-600" style="border-radius: 3px" />
          </div>

          <div>
            <label class="block text-[11px] font-semibold text-gray-500 uppercase tracking-wide mb-1.5">Email Address</label>
            <input type="email" id="fieldEmail" placeholder="example@email.com" class="w-full px-3 py-2.5 bg-white border border-gray-200 text-xs text-gray-900 placeholder-gray-400 focus:outline-none focus:border-emerald-600" style="border-radius: 3px" />
          </div>
        </div>
        <div class="px-4 pb-4 flex gap-2">
          <button id="cancelEditProfileBtn" class="flex-1 py-2.5 border border-gray-200 text-gray-700 text-xs font-semibold hover:bg-gray-50 transition-colors" style="border-radius: 3px">
            Cancel
          </button>
          <button id="saveEditProfileBtn" disabled class="flex-1 py-2.5 bg-emerald-600 hover:bg-emerald-700 disabled:opacity-50 disabled:cursor-not-allowed text-white text-xs font-semibold transition-colors" style="border-radius: 3px">
            Save Changes
          </button>
        </div>
      </div>
    </div>

    <div
      id="changePasswordModal"
      class="fixed inset-0 z-50 hidden flex items-center justify-center px-4"
    >
      <div class="modal-overlay absolute inset-0" id="closeChangePasswordOverlay"></div>
      <div
        class="bg-white w-full max-w-md max-h-[90vh] overflow-y-auto relative z-10 shadow-2xl"
        style="border-radius: 6px"
      >
        <div class="p-4 border-b border-gray-100 flex items-center justify-between sticky top-0 bg-white z-10">
          <h2 class="font-bold text-gray-800 text-sm">Change Password</h2>
          <button id="closeChangePasswordBtn" class="p-1 hover:bg-gray-100" style="border-radius: 3px">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 text-gray-500">
              <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
            </svg>
          </button>
        </div>
        <div class="p-4 space-y-3">
          <div
            id="passwordError"
            class="hidden items-center gap-2 p-3 bg-red-50 border border-red-200"
            style="border-radius: 3px"
          >
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
              <input type="password" id="fieldCurrentPassword" placeholder="Enter current password" class="w-full pl-9 pr-9 py-2.5 bg-white border border-gray-200 text-xs text-gray-900 placeholder-gray-400 focus:outline-none focus:border-emerald-600" style="border-radius: 3px" />
              <button
                type="button"
                id="toggleCurrentPasswordBtn"
                class="absolute right-2.5 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 transition-colors"
              >
                <svg
                  xmlns="http://www.w3.org/2000/svg"
                  fill="none"
                  viewBox="0 0 24 24"
                  stroke-width="1.5"
                  stroke="currentColor"
                  class="w-4 h-4"
                  id="currentPwEyeIcon"
                >
                  <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z"
                  />
                  <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"
                  />
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
              <input type="password" id="fieldNewPassword" placeholder="Enter your new password" class="w-full pl-9 pr-9 py-2.5 bg-white border border-gray-200 text-xs text-gray-900 placeholder-gray-400 focus:outline-none focus:border-emerald-600" style="border-radius: 3px" />
              <button
                type="button"
                id="toggleNewPasswordBtn"
                class="absolute right-2.5 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 transition-colors"
              >
                <svg
                  xmlns="http://www.w3.org/2000/svg"
                  fill="none"
                  viewBox="0 0 24 24"
                  stroke-width="1.5"
                  stroke="currentColor"
                  class="w-4 h-4"
                  id="newPwEyeIcon"
                >
                  <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z"
                  />
                  <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"
                  />
                </svg>
              </button>
            </div>
            <p class="text-[10px] text-gray-400 mt-1.5">
              At least 8 characters, with an uppercase letter, a number, and a symbol.
            </p>
            <div class="mt-2">
              <div class="flex gap-1">
                <div class="h-1 flex-1 bg-gray-200 transition-colors" style="border-radius: 999px" id="pwBar1"></div>
                <div class="h-1 flex-1 bg-gray-200 transition-colors" style="border-radius: 999px" id="pwBar2"></div>
                <div class="h-1 flex-1 bg-gray-200 transition-colors" style="border-radius: 999px" id="pwBar3"></div>
                <div class="h-1 flex-1 bg-gray-200 transition-colors" style="border-radius: 999px" id="pwBar4"></div>
              </div>
              <p class="text-[10px] mt-1" id="pwStrengthLabel"></p>
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
              <input type="password" id="fieldConfirmPassword" placeholder="Repeat new password" class="w-full pl-9 pr-9 py-2.5 bg-white border border-gray-200 text-xs text-gray-900 placeholder-gray-400 focus:outline-none focus:border-emerald-600" style="border-radius: 3px" />
              <button
                type="button"
                id="toggleConfirmPasswordBtn"
                class="absolute right-2.5 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 transition-colors"
              >
                <svg
                  xmlns="http://www.w3.org/2000/svg"
                  fill="none"
                  viewBox="0 0 24 24"
                  stroke-width="1.5"
                  stroke="currentColor"
                  class="w-4 h-4"
                  id="confirmPwEyeIcon"
                >
                  <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z"
                  />
                  <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"
                  />
                </svg>
              </button>
            </div>
            <p class="text-[10px] mt-1.5 hidden" id="pwMatchMsg"></p>
          </div>
        </div>
        <div class="px-4 pb-4 flex gap-2">
          <button id="cancelChangePasswordBtn" class="flex-1 py-2.5 border border-gray-200 text-gray-700 text-xs font-semibold hover:bg-gray-50 transition-colors" style="border-radius: 3px">
            Cancel
          </button>
          <button id="saveChangePasswordBtn" class="flex-1 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold transition-colors" style="border-radius: 3px">
            Update Password
          </button>
        </div>
      </div>
    </div>

    <div
      id="logoutModal"
      class="fixed inset-0 z-[60] hidden flex items-center justify-center px-4"
    >
      <div class="modal-overlay absolute inset-0" id="closeLogoutOverlay"></div>
      <div
        class="bg-white w-full max-w-sm relative z-10 shadow-2xl p-5 space-y-4 text-center"
        style="border-radius: 6px"
      >
        <div class="w-12 h-12 bg-red-50 flex items-center justify-center mx-auto" style="border-radius: 50%">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6 text-red-500">
            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6a2.25 2.25 0 0 0-2.25 2.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15M12 9l-3 3m0 0 3 3m-3-3h12.75" />
          </svg>
        </div>
        <div>
          <p class="text-sm font-bold text-gray-800">Log Out</p>
          <p class="text-xs text-gray-500 mt-1">Are you sure you want to log out of your account?</p>
        </div>
        <div class="flex gap-2 pt-1">
          <button id="cancelLogoutBtn" class="flex-1 py-2.5 border border-gray-200 text-gray-700 text-xs font-semibold hover:bg-gray-50 transition-colors" style="border-radius: 3px">
            Cancel
          </button>
          <button id="confirmLogoutBtn" class="flex-1 py-2.5 bg-red-500 hover:bg-red-600 text-white text-xs font-semibold transition-colors" style="border-radius: 3px">
            Log Out
          </button>
        </div>
      </div>
    </div>

    <div
      id="toast"
      class="hidden items-center gap-2 fixed left-1/2 bottom-6 z-40 -translate-x-1/2 max-w-[calc(100%-2rem)] bg-gray-900 text-white text-xs font-medium px-4 py-2.5 shadow-lg"
      style="border-radius: 6px">
      <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-4 h-4 text-emerald-400 shrink-0">
        <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
      </svg>
      <span id="toastMessage" class="truncate"></span>
    </div>

    <script>
      let adminAccount = {
        firstName: <?php echo json_encode($initialProfile['first_name']); ?>,
        lastName: <?php echo json_encode($initialProfile['last_name']); ?>,
        contactNumber: <?php echo json_encode($initialProfile['contact_number']); ?>,
        email: <?php echo json_encode($initialProfile['email']); ?>,
        memberSince: <?php echo json_encode($initialProfile['member_since']); ?>,
        profileImage: <?php echo json_encode($initialProfile['profile_image']); ?>,
      };

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

      let toastHideTimeout = null;
      let toastRemoveTimeout = null;

      function showToast(message) {
        const toast = document.getElementById("toast");
        const toastMessage = document.getElementById("toastMessage");
        toastMessage.textContent = message;

        if (toastHideTimeout) clearTimeout(toastHideTimeout);
        if (toastRemoveTimeout) clearTimeout(toastRemoveTimeout);

        toast.classList.remove("hidden");
        toast.classList.add("flex");

        requestAnimationFrame(() => {
          requestAnimationFrame(() => {
            toast.classList.add("toast-visible");
          });
        });

        toastHideTimeout = setTimeout(() => {
          toast.classList.remove("toast-visible");
          toastRemoveTimeout = setTimeout(() => {
            toast.classList.add("hidden");
            toast.classList.remove("flex");
          }, 250);
        }, 2000);
      }

      function getInitials(first, last) {
        return ((first[0] || "") + (last[0] || "")).toUpperCase();
      }

      function renderProfile() {
        const initials = getInitials(adminAccount.firstName, adminAccount.lastName);
        const avatarHtml = adminAccount.profileImage
          ? `<img src="${escapeHtml(adminAccount.profileImage)}" class="w-full h-full object-cover" style="border-radius:50%" />`
          : initials;
        document.getElementById("profileAvatar").innerHTML = avatarHtml;
        document.getElementById("sidebarAvatar").innerHTML = avatarHtml;
        document.getElementById("profileName").textContent =
          adminAccount.firstName + " " + adminAccount.lastName;
        document.getElementById("sidebarName").textContent =
          adminAccount.firstName + " " + adminAccount.lastName;
        document.getElementById("profileSubtext").textContent = adminAccount.email;
        document.getElementById("statMemberSince").textContent = adminAccount.memberSince;
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
        document.getElementById("fieldFirstName").value = adminAccount.firstName;
        document.getElementById("fieldLastName").value = adminAccount.lastName;
        document.getElementById("fieldContact").value = adminAccount.contactNumber;
        document.getElementById("fieldEmail").value = adminAccount.email;
        document.getElementById("editProfileError").classList.add("hidden");
        document.getElementById("editProfileError").classList.remove("flex");
        document
          .querySelectorAll("#editProfileModal input")
          .forEach((el) => el.classList.remove("error"));
        currentProfileImageFile = null;
        removeImageFlag = false;
        document.getElementById("editProfileImageInput").value = "";
        updateEditProfilePreview(
          adminAccount.profileImage || null,
          getInitials(adminAccount.firstName, adminAccount.lastName),
        );
        initialEditProfileState = {
          firstName: adminAccount.firstName,
          lastName: adminAccount.lastName,
          contact: adminAccount.contactNumber,
          email: adminAccount.email,
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
        document.getElementById("editProfileError").classList.add("flex");
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

      async function saveEditProfile() {
        const firstName = document.getElementById("fieldFirstName").value.trim();
        const lastName = document.getElementById("fieldLastName").value.trim();
        const contact = document.getElementById("fieldContact").value.trim();
        const email = document.getElementById("fieldEmail").value.trim();

        document
          .querySelectorAll("#editProfileModal input")
          .forEach((el) => el.classList.remove("error"));
        document.getElementById("editProfileError").classList.add("hidden");
        document.getElementById("editProfileError").classList.remove("flex");

        if (!firstName) {
          showEditProfileError("First name is required.");
          document.getElementById("fieldFirstName").classList.add("error");
          return;
        }
        if (!isValidName(firstName)) {
          showEditProfileError("First name can only contain letters.");
          document.getElementById("fieldFirstName").classList.add("error");
          return;
        }
        if (!lastName) {
          showEditProfileError("Last name is required.");
          document.getElementById("fieldLastName").classList.add("error");
          return;
        }
        if (!isValidName(lastName)) {
          showEditProfileError("Last name can only contain letters.");
          document.getElementById("fieldLastName").classList.add("error");
          return;
        }
        if (!contact) {
          showEditProfileError("Please enter a contact number.");
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
          res = { success: false, message: "Something went wrong. Please try again." };
        }

        saveBtn.disabled = false;

        if (!res.success) {
          showEditProfileError(res.message || "Something went wrong. Please try again.");
          return;
        }

        adminAccount.firstName = firstName;
        adminAccount.lastName = lastName;
        adminAccount.contactNumber = contact;
        adminAccount.email = email;
        if (res.profile_image !== undefined) {
          adminAccount.profileImage = res.profile_image;
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
        document.getElementById("passwordError").classList.remove("flex");
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
        [1, 2, 3, 4].forEach((n) => {
          const b = document.getElementById("pwBar" + n);
          b.className = "h-1 flex-1 bg-gray-200 transition-colors";
          b.style.borderRadius = "999px";
        });
        document.getElementById("pwStrengthLabel").textContent = "";
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
        document.getElementById("passwordError").classList.add("flex");
      }

      const pwLevels = [
        { label: "", color: "bg-gray-200" },
        { label: "Weak", color: "bg-red-400", textCls: "text-red-500" },
        { label: "Fair", color: "bg-amber-400", textCls: "text-amber-500" },
        { label: "Good", color: "bg-emerald-500", textCls: "text-emerald-600" },
        { label: "Strong", color: "bg-emerald-700", textCls: "text-emerald-700" },
      ];

      function getPwStrength(pw) {
        let s = 0;
        if (pw.length >= 8) s++;
        if (/[A-Z]/.test(pw)) s++;
        if (/[0-9]/.test(pw)) s++;
        if (/[^A-Za-z0-9]/.test(pw)) s++;
        return s;
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
          /[0-9]/.test(pw) &&
          /[^A-Za-z0-9]/.test(pw)
        );
      }

      async function saveChangePassword() {
        const currentPw = document.getElementById("fieldCurrentPassword").value;
        const newPw = document.getElementById("fieldNewPassword").value;
        const confirmPw = document.getElementById("fieldConfirmPassword").value;

        document.getElementById("passwordError").classList.add("hidden");
        document.getElementById("passwordError").classList.remove("flex");
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
          showPasswordError("New password must be at least 8 characters and include an uppercase letter, a number, and a symbol.");
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

      window.addEventListener("load", function () {
        renderProfile();
        setupSidebar();

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
            checkForEditProfileChanges();
          }
        });

        document.getElementById("changePasswordBtn").addEventListener("click", openChangePasswordModal);
        document.getElementById("closeChangePasswordBtn").addEventListener("click", closeChangePasswordModal);
        document.getElementById("closeChangePasswordOverlay").addEventListener("click", closeChangePasswordModal);
        document.getElementById("cancelChangePasswordBtn").addEventListener("click", closeChangePasswordModal);
        document.getElementById("saveChangePasswordBtn").addEventListener("click", saveChangePassword);

        function makePasswordToggle(btnId, inputId, iconId) {
          const btn = document.getElementById(btnId);
          const input = document.getElementById(inputId);
          const icon = document.getElementById(iconId);
          btn.addEventListener("click", () => {
            const show = input.type === "password";
            input.type = show ? "text" : "password";
            icon.innerHTML = show
              ? `<path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 0 0 1.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.451 10.451 0 0 1 12 4.5c4.756 0 8.773 3.162 10.065 7.498a10.522 10.522 0 0 1-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65m0 0a3 3 0 1 0-4.243-4.243m4.242 4.242L9.88 9.88"/>`
              : `<path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/>`;
          });
        }
        makePasswordToggle("toggleCurrentPasswordBtn", "fieldCurrentPassword", "currentPwEyeIcon");
        makePasswordToggle("toggleNewPasswordBtn", "fieldNewPassword", "newPwEyeIcon");
        makePasswordToggle("toggleConfirmPasswordBtn", "fieldConfirmPassword", "confirmPwEyeIcon");

        document.getElementById("fieldNewPassword").addEventListener("input", () => {
          const pw = document.getElementById("fieldNewPassword").value;
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
          checkPwMatch();
        });
        document.getElementById("fieldConfirmPassword").addEventListener("input", checkPwMatch);

        document.querySelectorAll(".account-row[data-info]").forEach((row) => {
          row.addEventListener("click", () => alert(row.getAttribute("data-info")));
        });

        document.getElementById("logoutBtn").addEventListener("click", openLogoutModal);
        document.getElementById("closeLogoutOverlay").addEventListener("click", closeLogoutModal);
        document.getElementById("cancelLogoutBtn").addEventListener("click", closeLogoutModal);
        document.getElementById("confirmLogoutBtn").addEventListener("click", () => {
          window.location.href = "../auth/logout.php";
        });
      });
    </script>
  </body>
</html>