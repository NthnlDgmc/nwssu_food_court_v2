<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['customer_id'])) {
  $conn->close();
  header('Location: ../auth/login.php');
  exit;
}

$customerId = $_SESSION['customer_id'];
$profileCheckStmt = $conn->prepare("SELECT email, contact_number FROM customers WHERE customer_id = ? LIMIT 1");
$profileCheckStmt->bind_param("i", $customerId);
$profileCheckStmt->execute();
$profileCheckRow = $profileCheckStmt->get_result()->fetch_assoc();
$profileCheckStmt->close();

if (!$profileCheckRow || empty($profileCheckRow['email']) || empty($profileCheckRow['contact_number'])) {
  $conn->close();
  header('Location: ../auth/complete-profile.php');
  exit;
}

$conn->close();

$orderCount = (int) ($_GET['count'] ?? 1);
if ($orderCount < 1) {
  $orderCount = 1;
}

$orderIdsParam = (string) ($_GET['ids'] ?? '');
$orderIds = array_values(array_filter(array_map('trim', explode(',', $orderIdsParam)), function ($orderId) {
  return preg_match('/^ORD-[0-9]{4}-[A-Z0-9]{8}$/', $orderId) === 1;
}));
$displayOrderId = !empty($orderIds) ? implode(', ', $orderIds) : '—';

$displayAmount = (float) ($_GET['total'] ?? 0);
?>
<!doctype html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Order Placed - NWSSU Food Court</title>
  <link rel="icon" href="../assets/images/nwssu-logo.png" type="image/png" />
  <link rel="manifest" href="../manifest.json" />
  <meta name="apple-mobile-web-app-capable" content="yes" />
  <meta name="apple-mobile-web-app-status-bar-style" content="default" />
  <meta name="apple-mobile-web-app-title" content="Norwesso Eats" />
  <link rel="apple-touch-icon" href="../assets/images/icon-192.png" />
  <link href="../assets/css/tailwind.css" rel="stylesheet" />
  <script src="https://unpkg.com/@lottiefiles/dotlottie-wc@0.9.4/dist/dotlottie-wc.js" type="module"></script>
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
  </style>
</head>

<body class="bg-white">
  <div class="flex flex-col items-center justify-center min-h-screen px-6 text-center">
    <div class="w-48 h-48 mb-4">
      <dotlottie-wc
        src="../assets/illustrations/success.lottie"
        style="width: 100%; height: 100%"
        autoplay>
      </dotlottie-wc>
    </div>

    <h1 class="text-xl font-bold text-gray-800">Order Placed!</h1>
    <p class="text-sm text-gray-500 mt-2 max-w-xs">
      <?php echo $orderCount > 1 ? "Your {$orderCount} orders have" : "Your order has"; ?> been sent to the stall. You can track its progress anytime.
    </p>

    <div class="w-full max-w-xs border-t border-gray-100 my-4"></div>

    <div class="w-full max-w-xs space-y-3.5 text-left">
      <div class="flex items-center gap-3">
        <span class="w-8 h-8 bg-gray-100 flex items-center justify-center shrink-0 rounded-[3px]">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 text-gray-500">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 0 0 2.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 0 0-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 0 0 .75-.75 2.25 2.25 0 0 0-.1-.664m-5.8 0A2.251 2.251 0 0 1 13.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25Z" />
          </svg>
        </span>
        <div class="min-w-0">
          <p class="text-[10px] text-gray-400"><?php echo $orderCount > 1 ? 'Order IDs' : 'Order ID'; ?></p>
          <p class="text-xs font-medium text-gray-800 truncate"><?php echo htmlspecialchars($displayOrderId, ENT_QUOTES, 'UTF-8'); ?></p>
        </div>
      </div>

      <div class="flex items-center gap-3">
        <span class="w-8 h-8 bg-gray-100 flex items-center justify-center shrink-0 rounded-[3px]">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 text-gray-500">
            <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5" />
          </svg>
        </span>
        <div class="min-w-0">
          <p class="text-[10px] text-gray-400">Date &amp; Time</p>
          <p class="text-xs font-medium text-gray-800"><?php echo date('M j, Y \\· g:i A'); ?></p>
        </div>
      </div>

      <div class="flex items-center gap-3">
        <span class="w-8 h-8 bg-gray-100 flex items-center justify-center shrink-0 rounded-[3px]">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 text-gray-500">
            <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25v10.5A2.25 2.25 0 0 0 4.5 19.5Z" />
          </svg>
        </span>
        <div class="min-w-0">
          <p class="text-[10px] text-gray-400">Payment Method</p>
          <p class="text-xs font-medium text-gray-800">Cash &middot; ₱<?php echo number_format($displayAmount, 2); ?></p>
        </div>
      </div>
    </div>

    <div class="w-full max-w-xs mt-8 flex gap-2">
      <a
        href="./order.php"
        class="flex-1 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold transition-colors rounded-[3px] flex items-center justify-center">
        View My Orders
      </a>
      <a
        href="./home.php"
        class="flex-1 py-2.5 border border-gray-200 text-gray-700 text-xs font-semibold hover:bg-gray-50 transition-colors rounded-[3px] flex items-center justify-center">
        Continue Shopping
      </a>
    </div>
  </div>
  <script>
    localStorage.removeItem("cart_orderType");
    localStorage.removeItem("cart_location");
    localStorage.removeItem("cart_paymentMethod");
  </script>
</body>

</html>