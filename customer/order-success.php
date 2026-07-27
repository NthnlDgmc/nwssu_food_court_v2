<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['customer_id'])) {
  header('Location: ../auth/login.php');
  exit;
}

$customerId = $_SESSION['customer_id'];

$statusCheckStmt = $conn->prepare("SELECT status FROM customers WHERE customer_id = ? LIMIT 1");
$statusCheckStmt->bind_param("i", $customerId);
$statusCheckStmt->execute();
$statusCheckRow = $statusCheckStmt->get_result()->fetch_assoc();
$statusCheckStmt->close();

if (!$statusCheckRow || $statusCheckRow['status'] === 'inactive') {
  session_destroy();
  header('Location: ../auth/login.php?deactivated=1');
  exit;
}

$requestedCount = isset($_GET['count']) ? (int) $_GET['count'] : 1;
if ($requestedCount < 1) {
  $requestedCount = 1;
}
if ($requestedCount > 20) {
  $requestedCount = 20;
}

function refValues($arr)
{
  $refs = [];
  foreach ($arr as $key => $value) {
    $refs[$key] = &$arr[$key];
  }
  return $refs;
}

function fetchRecentOrders($conn, $customerId, $limit)
{
  $stmt = $conn->prepare("
        SELECT o.order_id, o.order_type, o.status, o.payment_method,
               o.grand_total, o.created_at, s.stall_name
        FROM orders o
        JOIN stalls s ON o.stall_id = s.stall_id
        WHERE o.customer_id = ?
        ORDER BY o.order_id DESC
        LIMIT ?
    ");
  $stmt->bind_param("ii", $customerId, $limit);
  $stmt->execute();
  $result = $stmt->get_result();

  $orderIds = [];
  $orders = [];
  while ($row = $result->fetch_assoc()) {
    $orderIdRaw = (int) $row['order_id'];
    $orderIds[] = $orderIdRaw;
    $orders[$orderIdRaw] = [
      'code' => 'FC-' . str_pad($orderIdRaw, 6, '0', STR_PAD_LEFT),
      'stallName' => $row['stall_name'],
      'orderType' => $row['order_type'],
      'paymentMethod' => $row['payment_method'],
      'total' => (float) $row['grand_total'],
      'itemCount' => 0,
    ];
  }
  $stmt->close();

  if (empty($orderIds)) {
    return [];
  }

  $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
  $types = str_repeat('i', count($orderIds));

  $itemStmt = $conn->prepare("
        SELECT order_id, SUM(quantity) AS total_qty
        FROM order_items
        WHERE order_id IN ($placeholders)
        GROUP BY order_id
    ");
  $params = array_merge([$types], $orderIds);
  call_user_func_array([$itemStmt, 'bind_param'], refValues($params));
  $itemStmt->execute();
  $itemResult = $itemStmt->get_result();

  while ($itemRow = $itemResult->fetch_assoc()) {
    $oid = (int) $itemRow['order_id'];
    if (isset($orders[$oid])) {
      $orders[$oid]['itemCount'] = (int) $itemRow['total_qty'];
    }
  }
  $itemStmt->close();

  return array_values($orders);
}

$recentOrders = fetchRecentOrders($conn, $customerId, $requestedCount);
$conn->close();

$orderCount = count($recentOrders);
$isDeliveryOrder = $orderCount > 0 && $recentOrders[0]['orderType'] === 'delivery';
?>
<!doctype html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta
    name="viewport"
    content="width=device-width, initial-scale=1.0, user-scalable=yes" />
  <title>Customer - Order Placed</title>
  <link rel="icon" href="../assets/images/nwssu-logo.png" type="image/png" />
  <link rel="manifest" href="../manifest.json" />
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

    @keyframes check-pop {
      0% {
        transform: scale(0.4);
        opacity: 0;
      }

      60% {
        transform: scale(1.08);
        opacity: 1;
      }

      100% {
        transform: scale(1);
        opacity: 1;
      }
    }

    .check-pop {
      animation: check-pop 0.5s cubic-bezier(0.16, 1, 0.3, 1) forwards;
    }
  </style>
</head>

<body class="bg-white">
  <div class="flex flex-col h-screen">
    <div class="flex-1 overflow-y-auto mb-16" id="mainContent">
      <div class="max-w-5xl mx-auto px-4 pt-10 pb-4">
        <div class="flex flex-col items-center text-center mb-6">
          <div class="w-16 h-16 bg-emerald-50 flex items-center justify-center rounded-full check-pop">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-8 h-8 text-emerald-600">
              <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
            </svg>
          </div>
          <h1 class="text-lg font-bold text-gray-800 mt-4">Order Placed Successfully!</h1>
          <p class="text-xs text-gray-500 mt-1.5 max-w-xs">
            <?php if ($orderCount > 1): ?>
              Your <?php echo $orderCount; ?> orders have been sent to the stalls. They'll start preparing your food shortly.
            <?php elseif ($isDeliveryOrder): ?>
              Your order has been sent to the stall. A delivery staff will bring it to your drop-off location once ready.
            <?php else: ?>
              Your order has been sent to the stall. You'll be notified once it's ready for pickup.
            <?php endif; ?>
          </p>
        </div>

        <?php if ($orderCount > 0): ?>
          <div class="bg-white border border-gray-200 shadow-sm overflow-hidden rounded-md mb-4">
            <div class="p-4 border-b border-gray-100">
              <p class="text-[11px] font-bold text-gray-500 uppercase tracking-wide">
                Order Summary
                <span class="text-gray-400 font-normal">(<?php echo $orderCount; ?>)</span>
              </p>
            </div>
            <div class="divide-y divide-gray-100">
              <?php foreach ($recentOrders as $order): ?>
                <div class="px-4 py-3 flex items-center gap-3">
                  <div class="w-9 h-9 bg-gradient-to-br from-emerald-500 to-emerald-700 flex items-center justify-center text-white shrink-0 rounded-full">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M3 7.5 4.5 3h15L21 7.5m-18 0v12a1.5 1.5 0 0 0 1.5 1.5h15a1.5 1.5 0 0 0 1.5-1.5v-12m-18 0h18M9 12h6" />
                    </svg>
                  </div>
                  <div class="flex-1 min-w-0">
                    <p class="text-xs font-semibold text-gray-800 truncate"><?php echo htmlspecialchars($order['stallName']); ?></p>
                    <p class="text-[11px] text-gray-400 mt-0.5">
                      <?php echo htmlspecialchars($order['code']); ?> &middot; <?php echo (int) $order['itemCount']; ?> item<?php echo $order['itemCount'] === 1 ? '' : 's'; ?>
                    </p>
                  </div>
                  <span class="text-xs font-bold text-emerald-600 shrink-0">&#8369;<?php echo number_format($order['total'], 2); ?></span>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>

        <div class="flex flex-col gap-2">
          <a
            href="./order.php"
            class="w-full py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold transition-colors flex items-center justify-center gap-1.5 rounded-[3px]">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4">
              <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 0 0 2.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 0 0-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 0 0 .75-.75 2.25 2.25 0 0 0-.1-.664m-5.8 0A2.251 2.251 0 0 1 13.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25Z" />
            </svg>
            View Order Status
          </a>
          <a
            href="./home.php"
            class="w-full py-2.5 border border-gray-200 text-gray-700 text-xs font-semibold hover:bg-gray-50 transition-colors flex items-center justify-center gap-1.5 rounded-[3px]">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4">
              <path stroke-linecap="round" stroke-linejoin="round" d="m2.25 12 8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" />
            </svg>
            Continue Shopping
          </a>
        </div>
      </div>
    </div>

    <div class="bg-white border-t border-gray-200 flex-shrink-0 fixed bottom-0 left-0 right-0 z-20">
      <div class="max-w-5xl mx-auto px-4 flex justify-around py-2">
        <a href="./home.php" class="flex flex-col items-center justify-center py-2 px-3 transition-all duration-200 group text-gray-500 hover:text-gray-900 hover:bg-gray-50 rounded-[3px]">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 transition-transform group-hover:scale-110">
            <path stroke-linecap="round" stroke-linejoin="round" d="m2.25 12 8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" />
          </svg>
          <span class="text-xs font-medium mt-1">Home</span>
        </a>
        <a href="./cart.php" class="flex flex-col items-center justify-center py-2 px-3 transition-all duration-200 group text-gray-500 hover:text-gray-900 hover:bg-gray-50 relative rounded-[3px]">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 transition-transform group-hover:scale-110">
            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 10.5V6a3.75 3.75 0 1 0-7.5 0v4.5m11.356-1.993 1.263 12c.07.665-.45 1.243-1.119 1.243H4.25a1.125 1.125 0 0 1-1.12-1.243l1.264-12A1.125 1.125 0 0 1 5.513 7.5h12.974c.576 0 1.059.435 1.119 1.007ZM8.625 10.5a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm7.5 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z" />
          </svg>
          <span class="text-xs font-medium mt-1">Cart</span>
        </a>
        <a href="./order.php" class="flex flex-col items-center justify-center py-2 px-3 transition-all duration-200 group text-emerald-600 bg-emerald-50 rounded-[3px]">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 0 0 2.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 0 0-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 0 0 .75-.75 2.25 2.25 0 0 0-.1-.664m-5.8 0A2.251 2.251 0 0 1 13.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25Z" />
          </svg>
          <span class="text-xs font-medium mt-1">Orders</span>
        </a>
        <a href="./chat.php" class="flex flex-col items-center justify-center py-2 px-3 transition-all duration-200 group text-gray-500 hover:text-gray-900 hover:bg-gray-50 relative rounded-[3px]">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 transition-transform group-hover:scale-110">
            <path stroke-linecap="round" stroke-linejoin="round" d="M20.25 8.511c.884.284 1.5 1.128 1.5 2.097v4.286c0 1.136-.847 2.1-1.98 2.193-.34.027-.68.052-1.02.072v3.091l-3-3c-1.354 0-2.694-.055-4.02-.163a2.115 2.115 0 0 1-.825-.242m9.345-8.334a2.126 2.126 0 0 0-.476-.095 48.64 48.64 0 0 0-8.048 0c-1.131.094-1.976 1.057-1.976 2.192v4.286c0 .837.46 1.58 1.155 1.951m9.345-8.334V6.637c0-1.621-1.152-3.026-2.76-3.235A48.455 48.455 0 0 0 11.25 3c-2.115 0-4.198.137-6.24.402-1.608.209-2.76 1.614-2.76 3.235v6.226c0 1.621 1.152 3.026 2.76 3.235.577.075 1.157.14 1.74.194V21l4.155-4.155" />
          </svg>
          <span class="text-xs font-medium mt-1">Chats</span>
        </a>
        <a href="./account.php" class="flex flex-col items-center justify-center py-2 px-3 transition-all duration-200 group text-gray-500 hover:text-gray-900 hover:bg-gray-50 rounded-[3px]">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 transition-transform group-hover:scale-110">
            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" />
          </svg>
          <span class="text-xs font-medium mt-1">Account</span>
        </a>
      </div>
    </div>
  </div>
</body>

</html>