<?php
session_start();
require_once 'config/database.php';

header('Content-Type: application/json');

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

if (!$userType || !$userId) {
  echo json_encode(['success' => false, 'message' => 'Not logged in.']);
  $conn->close();
  exit;
}

$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);

$endpoint = $data['endpoint'] ?? '';

if ($endpoint === '') {
  $stmt = $conn->prepare("DELETE FROM push_subscriptions WHERE user_type = ? AND user_id = ?");
  $stmt->bind_param("si", $userType, $userId);
} else {
  $stmt = $conn->prepare("DELETE FROM push_subscriptions WHERE user_type = ? AND user_id = ? AND endpoint = ?");
  $stmt->bind_param("sis", $userType, $userId, $endpoint);
}

$ok = $stmt->execute();
$stmt->close();

echo json_encode($ok
  ? ['success' => true]
  : ['success' => false, 'message' => 'Failed to remove subscription.']);
$conn->close();