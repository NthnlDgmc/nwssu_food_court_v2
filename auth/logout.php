<?php
session_start();
require_once '../config/database.php';

$userType = null;
$userId = null;

if (isset($_SESSION['admin_id'])) {
  $userType = 'admin';
  $userId = $_SESSION['admin_id'];
} elseif (isset($_SESSION['owner_id'])) {
  $userType = 'stall_owner';
  $userId = $_SESSION['owner_id'];
} elseif (isset($_SESSION['staff_id'])) {
  $userType = 'delivery_staff';
  $userId = $_SESSION['staff_id'];
} elseif (isset($_SESSION['customer_id'])) {
  $userType = 'customer';
  $userId = $_SESSION['customer_id'];
}

if ($userType !== null) {
  $stmt = $conn->prepare("DELETE FROM push_subscriptions WHERE user_type = ? AND user_id = ?");
  $stmt->bind_param("si", $userType, $userId);
  $stmt->execute();
  $stmt->close();
}

$conn->close();

$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

session_destroy();

header('Location: ../auth/login.php');
exit;