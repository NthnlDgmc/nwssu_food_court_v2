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

    .checkmark-wrap {
      width: 96px;
      height: 96px;
    }

    .checkmark {
      width: 100%;
      height: 100%;
      transform: scale(0.9);
      opacity: 0;
      animation: pop-in 0.35s cubic-bezier(0.34, 1.56, 0.64, 1) 0.1s forwards;
    }

    @keyframes pop-in {
      to {
        transform: scale(1);
        opacity: 1;
      }
    }

    .checkmark-circle {
      stroke-dasharray: 166;
      stroke-dashoffset: 166;
      stroke-width: 3;
      stroke: #059669;
      fill: none;
      animation: stroke-draw 0.6s cubic-bezier(0.65, 0, 0.45, 1) 0.15s forwards;
    }

    .checkmark-check {
      stroke-dasharray: 48;
      stroke-dashoffset: 48;
      stroke: #059669;
      stroke-width: 4;
      stroke-linecap: round;
      stroke-linejoin: round;
      animation: stroke-draw 0.35s cubic-bezier(0.65, 0, 0.45, 1) 0.7s forwards;
    }

    @keyframes stroke-draw {
      to {
        stroke-dashoffset: 0;
      }
    }

    .fade-up {
      opacity: 0;
      transform: translateY(8px);
      animation: fade-up-in 0.4s ease-out forwards;
    }

    @keyframes fade-up-in {
      to {
        opacity: 1;
        transform: translateY(0);
      }
    }
  </style>
</head>

<body class="bg-white">
  <div class="flex flex-col items-center justify-center min-h-screen px-6 text-center">
    <div class="checkmark-wrap mb-6">
      <svg class="checkmark" viewBox="0 0 52 52">
        <circle class="checkmark-circle" cx="26" cy="26" r="24" />
        <path class="checkmark-check" d="M14.1 27.2l7.1 7.2 16.7-16.8" />
      </svg>
    </div>

    <h1 class="fade-up text-xl font-bold text-gray-800" style="animation-delay: 0.85s">
      Order Placed!
    </h1>
    <p class="fade-up text-sm text-gray-500 mt-2 max-w-xs" style="animation-delay: 0.95s">
      <?php if ($orderCount > 1): ?>
        Your <?php echo $orderCount; ?> orders have been sent to the stalls. You can track their progress anytime.
      <?php else: ?>
        Your order has been sent to the stall. You can track its progress anytime.
      <?php endif; ?>
    </p>

    <div class="fade-up w-full max-w-xs mt-8 space-y-2.5" style="animation-delay: 1.05s">
      <a
        href="./order.php"
        class="block w-full py-3 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-semibold transition-colors rounded-[3px]">
        View My Orders
      </a>
      <a
        href="./home.php"
        class="block w-full py-3 border border-gray-200 text-gray-700 text-sm font-semibold hover:bg-gray-50 transition-colors rounded-[3px]">
        Continue Shopping
      </a>
    </div>
  </div>
</body>

</html>