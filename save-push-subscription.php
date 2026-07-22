<?php
session_start();
require_once 'config/database.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
  exit;
}

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

if ($userType === null) {
  echo json_encode(['success' => false, 'message' => 'Not logged in.']);
  $conn->close();
  exit;
}

$rawBody = file_get_contents('php://input');
$subscription = json_decode($rawBody, true);

if (
  !$subscription ||
  !isset($subscription['endpoint']) ||
  !isset($subscription['keys']['p256dh']) ||
  !isset($subscription['keys']['auth'])
) {
  echo json_encode(['success' => false, 'message' => 'Invalid subscription data.']);
  $conn->close();
  exit;
}

$endpoint = $subscription['endpoint'];
$p256dh = $subscription['keys']['p256dh'];
$auth = $subscription['keys']['auth'];

$stmt = $conn->prepare("SELECT subscription_id FROM push_subscriptions WHERE endpoint = ? LIMIT 1");
$stmt->bind_param("s", $endpoint);
$stmt->execute();
$existing = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($existing) {
  $stmt = $conn->prepare("UPDATE push_subscriptions SET user_type = ?, user_id = ?, p256dh_key = ?, auth_key = ? WHERE subscription_id = ?");
  $stmt->bind_param("sissi", $userType, $userId, $p256dh, $auth, $existing['subscription_id']);
  $ok = $stmt->execute();
  $stmt->close();
} else {
  $stmt = $conn->prepare("INSERT INTO push_subscriptions (user_type, user_id, endpoint, p256dh_key, auth_key) VALUES (?, ?, ?, ?, ?)");
  $stmt->bind_param("sisss", $userType, $userId, $endpoint, $p256dh, $auth);
  $ok = $stmt->execute();
  $stmt->close();
}

echo json_encode($ok
  ? ['success' => true]
  : ['success' => false, 'message' => 'Failed to save subscription.']);
$conn->close();