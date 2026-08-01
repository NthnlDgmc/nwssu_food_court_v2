<?php
session_start();
require_once '../config/database.php';
$conn->close();

if (!isset($_SESSION['customer_id'])) {
  header('Location: ../auth/login.php');
  exit;
}

$orderCount = (int) ($_GET['count'] ?? 1);
if ($orderCount < 1) {
  $orderCount = 1;
}
?>
<!doctype html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Order Placed - NWSSU Food Court</title>
  <link rel="icon" href="../assets/images/nwssu-logo.png" type="image/png" />
  <script src="https://cdn.tailwindcss.com"></script>
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
    <div class="w-48 h-48 mb-6">
      <dotlottie-wc
        src="../assets/illustrations/success.lottie"
        style="width: 100%; height: 100%"
        autoplay>
      </dotlottie-wc>
    </div>

    <h1 class="text-xl font-bold text-gray-800">
      Order Placed!
    </h1>
    <p class="text-sm text-gray-500 mt-2 max-w-xs">
      <?php if ($orderCount > 1): ?>
        Your <?php echo $orderCount; ?> orders have been sent to the stalls. You can track their progress anytime.
      <?php else: ?>
        Your order has been sent to the stall. You can track its progress anytime.
      <?php endif; ?>
    </p>

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
</body>

</html>