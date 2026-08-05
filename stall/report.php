<?php
date_default_timezone_set('Asia/Manila');
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['owner_id'])) {
  header('Location: ../auth/login.php');
  exit;
}

$ownerId = $_SESSION['owner_id'];

$statusCheckStmt = $conn->prepare("SELECT status FROM stall_owners WHERE owner_id = ? LIMIT 1");
$statusCheckStmt->bind_param("i", $ownerId);
$statusCheckStmt->execute();
$statusCheckRow = $statusCheckStmt->get_result()->fetch_assoc();
$statusCheckStmt->close();

if (!$statusCheckRow || $statusCheckRow['status'] === 'inactive') {
  session_destroy();
  header('Location: ../auth/login.php?deactivated=1');
  exit;
}

$period = $_GET['period'] ?? 'today';
$customDate = $_GET['date'] ?? '';

if ($customDate !== '' && strtotime($customDate)) {
  $startDate = $customDate;
  $endDate = $customDate;
  $periodLabel = date('M j, Y', strtotime($customDate));
  $isSingleDay = true;
  $period = 'custom';
} elseif ($period === 'yesterday') {
  $startDate = date('Y-m-d', strtotime('-1 day'));
  $endDate = $startDate;
  $periodLabel = 'Yesterday';
  $isSingleDay = true;
} elseif ($period === 'this_month') {
  $startDate = date('Y-m-01');
  $endDate = date('Y-m-d');
  $periodLabel = date('F Y');
  $isSingleDay = false;
} else {
  $period = 'today';
  $startDate = date('Y-m-d');
  $endDate = $startDate;
  $periodLabel = 'Today';
  $isSingleDay = true;
}

$statusBreakdown = [];
$stmt = $conn->prepare("SELECT status, COUNT(*) AS cnt FROM orders WHERE owner_id = ? AND DATE(created_at) BETWEEN ? AND ? GROUP BY status");
$stmt->bind_param("iss", $ownerId, $startDate, $endDate);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
  $statusBreakdown[$row['status']] = (int) $row['cnt'];
}
$stmt->close();

$stmt = $conn->prepare("SELECT COUNT(*) AS cnt, COALESCE(SUM(grand_total), 0) AS total FROM orders WHERE owner_id = ? AND DATE(created_at) BETWEEN ? AND ? AND status != 'cancelled'");
$stmt->bind_param("iss", $ownerId, $startDate, $endDate);
$stmt->execute();
$summaryRow = $stmt->get_result()->fetch_assoc();
$totalOrders = (int) $summaryRow['cnt'];
$totalRevenue = (float) $summaryRow['total'];
$stmt->close();

$trendData = [];
if ($isSingleDay) {
  $hourBlocks = [
    ['label' => '6AM', 'start' => 6, 'end' => 9],
    ['label' => '9AM', 'start' => 9, 'end' => 12],
    ['label' => '12PM', 'start' => 12, 'end' => 15],
    ['label' => '3PM', 'start' => 15, 'end' => 18],
    ['label' => '6PM', 'start' => 18, 'end' => 21],
    ['label' => '9PM', 'start' => 21, 'end' => 24],
  ];
  $stmt = $conn->prepare("SELECT HOUR(created_at) AS hr, COALESCE(SUM(grand_total), 0) AS total FROM orders WHERE owner_id = ? AND DATE(created_at) = ? AND status != 'cancelled' GROUP BY HOUR(created_at)");
  $stmt->bind_param("is", $ownerId, $startDate);
  $stmt->execute();
  $result = $stmt->get_result();
  $hourlyTotals = [];
  while ($row = $result->fetch_assoc()) {
    $hourlyTotals[(int) $row['hr']] = (float) $row['total'];
  }
  $stmt->close();

  foreach ($hourBlocks as $block) {
    $sum = 0.00;
    for ($h = $block['start']; $h < $block['end']; $h++) {
      $sum += $hourlyTotals[$h] ?? 0.00;
    }
    $trendData[] = ['label' => $block['label'], 'value' => $sum];
  }
} else {
  $stmt = $conn->prepare("SELECT DATE(created_at) AS day, COALESCE(SUM(grand_total), 0) AS total FROM orders WHERE owner_id = ? AND DATE(created_at) BETWEEN ? AND ? AND status != 'cancelled' GROUP BY DATE(created_at)");
  $stmt->bind_param("iss", $ownerId, $startDate, $endDate);
  $stmt->execute();
  $result = $stmt->get_result();
  $dailyTotals = [];
  while ($row = $result->fetch_assoc()) {
    $dailyTotals[$row['day']] = (float) $row['total'];
  }
  $stmt->close();

  $weekSums = [0.00];
  $weekIndex = 0;
  $current = strtotime($startDate);
  $endTs = strtotime($endDate);
  while ($current <= $endTs) {
    $dateStr = date('Y-m-d', $current);
    $weekSums[$weekIndex] += $dailyTotals[$dateStr] ?? 0.00;
    if ((int) date('N', $current) === 7) {
      $weekIndex++;
      $weekSums[$weekIndex] = 0.00;
    }
    $current = strtotime('+1 day', $current);
  }

  $wi = 1;
  foreach ($weekSums as $sum) {
    $trendData[] = ['label' => 'Wk ' . $wi, 'value' => $sum];
    $wi++;
  }
}

$stmt = $conn->prepare("
    SELECT oi.menu_item_id, oi.item_name, COUNT(*) AS order_count, mi.image
    FROM order_items oi
    JOIN orders o ON oi.order_id = o.order_id
    LEFT JOIN menu_items mi ON oi.menu_item_id = mi.menu_item_id
    WHERE o.owner_id = ? AND DATE(o.created_at) BETWEEN ? AND ? AND o.status != 'cancelled'
    GROUP BY oi.menu_item_id, oi.item_name
    ORDER BY order_count DESC
    LIMIT 5
");
$stmt->bind_param("iss", $ownerId, $startDate, $endDate);
$stmt->execute();
$result = $stmt->get_result();
$topItems = [];
while ($row = $result->fetch_assoc()) {
  $topItems[] = [
    'name' => $row['item_name'],
    'orders' => (int) $row['order_count'],
    'img' => $row['image'] ? '../' . $row['image'] : null,
  ];
}
$stmt->close();

$conn->close();
?>
<!doctype html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes" />
  <title>Stall - Analytics</title>
  <link rel="icon" href="../assets/images/nwssu-logo.png" type="image/png" />
  <link rel="manifest" href="../manifest.json" />
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    @import url("https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap");

    * {
      font-family: "Poppins", sans-serif;
    }

    body {
      background: #f8fafc;
      min-height: 100vh;
      margin: 0;
    }

    #mainContent::-webkit-scrollbar {
      width: 5px;
    }

    #mainContent::-webkit-scrollbar-thumb {
      background: #059669;
      border-radius: 3px;
    }

    .donut-segment {
      transition: stroke-dasharray 0.6s ease;
    }
  </style>
</head>

<body class="bg-gray-50">
  <div class="flex flex-col h-screen">
    <div class="bg-white flex-shrink-0 fixed top-0 left-0 right-0 z-20 border-b border-gray-100">
      <div class="max-w-5xl mx-auto px-4 py-2 grid grid-cols-3 items-center">
        <div class="flex items-center gap-2 justify-self-start">
          <button
            id="backButton"
            class="p-1.5 bg-white border border-gray-200 hover:border-emerald-500 hover:bg-slate-50 transition-all flex items-center justify-center shrink-0"
            style="width: 34px; height: 34px; border-radius: 6px"
            title="Go back">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 text-gray-600">
              <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" />
            </svg>
          </button>
        </div>
        <h1 class="text-base font-semibold text-emerald-600 text-center">Analytics</h1>
        <div class="justify-self-end relative">
          <button
            id="calendarBtn"
            class="p-1.5 bg-white border border-gray-200 hover:border-emerald-500 hover:bg-slate-50 transition-all flex items-center justify-center shrink-0"
            style="width: 34px; height: 34px; border-radius: 6px"
            title="Pick a date">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 text-gray-600">
              <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5m-9-6h.008v.008H12v-.008ZM12 15h.008v.008H12V15Zm0 2.25h.008v.008H12v-.008ZM9.75 15h.008v.008H9.75V15Zm0 2.25h.008v.008H9.75v-.008ZM7.5 15h.008v.008H7.5V15Zm0 2.25h.008v.008H7.5v-.008Zm6.75-4.5h.008v.008h-.008v-.008Zm0 2.25h.008v.008h-.008V15Zm0 2.25h.008v.008h-.008v-.008Zm2.25-4.5h.008v.008H16.5v-.008Zm0 2.25h.008v.008H16.5V15Z" />
            </svg>
          </button>
          <input type="date" id="dateInput" class="absolute opacity-0 pointer-events-none" style="width: 1px; height: 1px" value="<?php echo $customDate !== '' ? $customDate : ''; ?>" />
        </div>
      </div>
    </div>

    <div class="flex-1 overflow-y-auto mt-12 mb-4" id="mainContent">
      <div class="max-w-5xl mx-auto px-4 pt-4 pb-6 flex flex-col gap-4">

        <div class="flex items-center justify-between gap-3">
          <p class="text-xs text-gray-500">Showing data for <span class="font-semibold text-gray-800"><?php echo htmlspecialchars($periodLabel); ?></span></p>
          <div class="flex bg-gray-100 p-1 rounded-[3px] shrink-0">
            <a href="?period=today" class="px-3 py-1.5 text-[11px] font-semibold transition-colors rounded-[3px] <?php echo $period === 'today' ? 'bg-white text-emerald-700 shadow-sm' : 'text-gray-500'; ?>">Today</a>
            <a href="?period=yesterday" class="px-3 py-1.5 text-[11px] font-semibold transition-colors rounded-[3px] <?php echo $period === 'yesterday' ? 'bg-white text-emerald-700 shadow-sm' : 'text-gray-500'; ?>">Yesterday</a>
            <a href="?period=this_month" class="px-3 py-1.5 text-[11px] font-semibold transition-colors rounded-[3px] <?php echo $period === 'this_month' ? 'bg-white text-emerald-700 shadow-sm' : 'text-gray-500'; ?>">This Month</a>
          </div>
        </div>

        <div class="bg-white border border-gray-200 shadow-sm overflow-hidden" style="border-radius:6px">
          <div class="p-4 border-b border-gray-100">
            <p class="text-[11px] font-bold text-gray-500 uppercase tracking-wide">Order Status</p>
            <p class="text-[10px] text-gray-400 mt-0.5"><?php echo array_sum($statusBreakdown); ?> total orders</p>
          </div>
          <div class="p-4 flex items-center gap-5">
            <div class="relative shrink-0" style="width: 132px; height: 132px">
              <svg viewBox="0 0 120 120" class="w-full h-full -rotate-90" id="statusDonutSvg"></svg>
              <div class="absolute inset-0 flex flex-col items-center justify-center">
                <p class="text-xl font-bold text-gray-800"><?php echo array_sum($statusBreakdown); ?></p>
                <p class="text-[9px] text-gray-400 uppercase tracking-wide">Orders</p>
              </div>
            </div>
            <div class="flex-1 min-w-0 space-y-2" id="statusLegendList"></div>
          </div>
        </div>

        <div class="grid grid-cols-2 gap-3">
          <div class="bg-white border border-gray-200 shadow-sm p-4" style="border-radius:6px">
            <div class="w-8 h-8 bg-emerald-50 flex items-center justify-center mb-2 rounded-[3px]">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 text-emerald-600">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v12m-3-2.818.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182.553-.44 1.278-.659 2.003-.659.725 0 1.45.22 2.003.659l.879.659" />
              </svg>
            </div>
            <p class="text-[10px] font-semibold text-gray-500 uppercase tracking-wide">Total Revenue</p>
            <p class="text-lg font-bold text-gray-800 mt-0.5">₱<?php echo number_format($totalRevenue, 2); ?></p>
          </div>
          <div class="bg-white border border-gray-200 shadow-sm p-4" style="border-radius:6px">
            <div class="w-8 h-8 bg-blue-50 flex items-center justify-center mb-2 rounded-[3px]">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 text-blue-600">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 0 0 2.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 0 0-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 0 0 .75-.75 2.25 2.25 0 0 0-.1-.664m-5.8 0A2.251 2.251 0 0 1 13.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25Z" />
              </svg>
            </div>
            <p class="text-[10px] font-semibold text-gray-500 uppercase tracking-wide">Total Orders</p>
            <p class="text-lg font-bold text-gray-800 mt-0.5"><?php echo $totalOrders; ?></p>
          </div>
        </div>

        <div class="bg-white border border-gray-200 shadow-sm overflow-hidden" style="border-radius:6px">
          <div class="p-4 border-b border-gray-100">
            <p class="text-[11px] font-bold text-gray-500 uppercase tracking-wide">Sales Trend</p>
            <p class="text-[10px] text-gray-400 mt-0.5"><?php echo $isSingleDay ? 'By hour' : 'By week'; ?></p>
          </div>
          <div class="p-4">
            <svg viewBox="0 0 320 180" class="w-full" id="trendSvg"></svg>
          </div>
        </div>

        <div class="bg-white border border-gray-200 shadow-sm overflow-hidden" style="border-radius:6px">
          <div class="p-4 border-b border-gray-100">
            <p class="text-[11px] font-bold text-gray-500 uppercase tracking-wide">Best Selling Items</p>
            <p class="text-[10px] text-gray-400 mt-0.5">Top 5 for this period</p>
          </div>
          <div class="divide-y divide-gray-100" id="topItemsList">
            <?php if (empty($topItems)): ?>
              <p class="text-xs text-gray-400 text-center py-6">No orders for this period.</p>
            <?php else: ?>
              <?php foreach ($topItems as $i => $item): ?>
                <div class="flex items-center gap-3 px-4 py-3">
                  <span class="w-6 h-6 bg-gray-100 flex items-center justify-center text-[10px] font-bold text-gray-500 shrink-0 rounded-full"><?php echo $i + 1; ?></span>
                  <div class="w-9 h-9 bg-gray-100 flex items-center justify-center shrink-0 overflow-hidden rounded-[3px]">
                    <?php if ($item['img']): ?>
                      <img src="<?php echo htmlspecialchars($item['img']); ?>" class="w-full h-full object-cover" />
                    <?php else: ?>
                      <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 text-gray-400">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909M18 10.5h.008v.008H18V10.5Zm-12 9h12a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 18 4.5H6a2.25 2.25 0 0 0-2.25 2.25v10.5A2.25 2.25 0 0 0 6 19.5Z" />
                      </svg>
                    <?php endif; ?>
                  </div>
                  <p class="flex-1 text-xs font-medium text-gray-800 truncate"><?php echo htmlspecialchars($item['name']); ?></p>
                  <span class="text-xs font-semibold text-gray-900 shrink-0 whitespace-nowrap"><?php echo $item['orders']; ?> orders</span>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>

      </div>
    </div>
  </div>

  <script>
    const STATUS_BREAKDOWN = <?php echo json_encode($statusBreakdown); ?>;
    const TREND_DATA = <?php echo json_encode($trendData); ?>;

    const STATUS_CHART_META = {
      pending: { label: "Pending", hex: "#f59e0b", dot: "bg-amber-500" },
      preparing: { label: "Preparing", hex: "#3b82f6", dot: "bg-blue-500" },
      ready_for_dispatch: { label: "Ready for Dispatch", hex: "#6366f1", dot: "bg-indigo-500" },
      ready_for_pickup: { label: "Ready for Pickup", hex: "#6366f1", dot: "bg-indigo-500" },
      collected: { label: "Collected", hex: "#0ea5e9", dot: "bg-sky-500" },
      out_for_delivery: { label: "Out for Delivery", hex: "#0284c7", dot: "bg-sky-600" },
      delivered: { label: "Delivered", hex: "#10b981", dot: "bg-emerald-500" },
      completed: { label: "Completed", hex: "#10b981", dot: "bg-emerald-500" },
      cancelled: { label: "Cancelled", hex: "#d1d5db", dot: "bg-gray-300" },
    };

    function escapeHtml(str) {
      if (!str) return "";
      return String(str)
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;");
    }

    function renderStatusDonut() {
      const svg = document.getElementById("statusDonutSvg");
      const legend = document.getElementById("statusLegendList");
      const totalCount = Object.values(STATUS_BREAKDOWN).reduce((sum, v) => sum + v, 0);

      const entries = Object.keys(STATUS_BREAKDOWN)
        .map((key) => ({
          key,
          count: STATUS_BREAKDOWN[key],
          meta: STATUS_CHART_META[key] || { label: key, hex: "#d1d5db", dot: "bg-gray-300" },
        }))
        .filter((e) => e.count > 0);

      if (totalCount === 0 || entries.length === 0) {
        svg.innerHTML = `<circle cx="60" cy="60" r="45" fill="none" stroke="#f3f4f6" stroke-width="14" />`;
        legend.innerHTML = `<p class="text-xs text-gray-400 text-center py-6">No orders for this period.</p>`;
        return;
      }

      const radius = 45;
      const circumference = 2 * Math.PI * radius;
      let cumulative = 0;

      const segmentsHtml = entries.map((e) => {
        const fraction = e.count / totalCount;
        const arcLength = fraction * circumference;
        const dashOffset = -cumulative;
        cumulative += arcLength;
        return `<circle
            cx="60" cy="60" r="${radius}" fill="none"
            stroke="${e.meta.hex}" stroke-width="14" stroke-linecap="butt"
            stroke-dashoffset="${dashOffset}"
            class="donut-segment"
            style="stroke-dasharray: ${arcLength} ${circumference - arcLength};"></circle>`;
      });

      svg.innerHTML = `<circle cx="60" cy="60" r="${radius}" fill="none" stroke="#f3f4f6" stroke-width="14" />` + segmentsHtml.join("");

      legend.innerHTML = entries.map((e) => {
        const pct = Math.round((e.count / totalCount) * 100);
        return `
            <div class="flex items-center justify-between text-[11px]">
              <div class="flex items-center gap-1.5 min-w-0">
                <span class="w-2 h-2 rounded-full ${e.meta.dot} shrink-0"></span>
                <span class="text-gray-600 truncate">${escapeHtml(e.meta.label)}</span>
              </div>
              <span class="font-semibold text-gray-800 shrink-0 whitespace-nowrap">${e.count} <span class="text-gray-400 font-normal">(${pct}%)</span></span>
            </div>
          `;
      }).join("");
    }

    function renderTrendChart() {
      const svg = document.getElementById("trendSvg");
      const rawMax = Math.max(...TREND_DATA.map((d) => d.value), 1);

      const magnitude = Math.pow(10, Math.floor(Math.log10(rawMax)));
      const niceMax = Math.max(Math.ceil(rawMax / magnitude) * magnitude, magnitude);

      const chartLeft = 35;
      const chartRight = 310;
      const chartTop = 10;
      const chartBottom = 145;
      const chartHeight = chartBottom - chartTop;
      const chartWidth = chartRight - chartLeft;

      const barCount = TREND_DATA.length;
      const barSlot = chartWidth / barCount;
      const barWidth = barSlot * 0.5;

      let gridlinesHTML = "";
      let yLabelsHTML = "";
      const steps = 4;
      for (let i = 0; i <= steps; i++) {
        const y = chartBottom - (chartHeight * i) / steps;
        const value = Math.round((niceMax * i) / steps);
        gridlinesHTML += `<line x1="${chartLeft}" y1="${y}" x2="${chartRight}" y2="${y}" stroke="#f3f4f6" stroke-width="1" />`;
        yLabelsHTML += `<text x="${chartLeft - 6}" y="${y + 3}" text-anchor="end" font-size="8" fill="#9ca3af">${value}</text>`;
      }

      let barsHTML = "";
      let xLabelsHTML = "";
      TREND_DATA.forEach((d, i) => {
        const barHeight = niceMax > 0 ? (d.value / niceMax) * chartHeight : 0;
        const x = chartLeft + i * barSlot + (barSlot - barWidth) / 2;
        const y = chartBottom - barHeight;
        barsHTML += `<rect x="${x}" y="${y}" width="${barWidth}" height="${Math.max(barHeight, 1)}" fill="#059669" rx="2" />`;
        const labelX = x + barWidth / 2;
        xLabelsHTML += `<text x="${labelX}" y="${chartBottom + 14}" text-anchor="middle" font-size="9" fill="#9ca3af">${escapeHtml(d.label)}</text>`;
      });

      svg.innerHTML = gridlinesHTML + yLabelsHTML + barsHTML + xLabelsHTML;
    }

    window.addEventListener("load", function() {
      renderStatusDonut();
      renderTrendChart();

      document.getElementById("backButton").addEventListener("click", () => window.history.back());

      const dateInput = document.getElementById("dateInput");
      document.getElementById("calendarBtn").addEventListener("click", () => {
        if (dateInput.showPicker) {
          dateInput.showPicker();
        } else {
          dateInput.click();
        }
      });
      dateInput.addEventListener("change", () => {
        if (dateInput.value) {
          window.location.href = "?date=" + dateInput.value;
        }
      });
    });
  </script>
</body>

</html>