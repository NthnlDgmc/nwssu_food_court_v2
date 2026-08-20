<?php
session_start();

if (!isset($_SESSION['owner_id'])) {
  header('Location: ../auth/login.php');
  exit;
}
?>
<!doctype html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes" />
  <title>Stall - Analytics</title>
  <link rel="icon" href="../assets/images/nwssu-logo.png" type="image/png" />
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

    .bar {
      transform-origin: bottom;
      animation: grow .7s cubic-bezier(.23, 1, .32, 1) both;
    }

    .line-draw {
      stroke-dasharray: 900;
      stroke-dashoffset: 900;
      animation: draw 1.1s cubic-bezier(.23, 1, .32, 1) forwards;
    }

    .fade-up {
      animation: fade-in .45s ease both;
    }

    .analytics-chart-grid {
      display: grid;
      grid-template-columns: minmax(0, 1fr);
      gap: .75rem;
      margin-bottom: .75rem;
      align-items: stretch;
    }

    @media (min-width: 1024px) {
      .analytics-chart-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
      }
    }

    .analytics-chart-grid > .analytics-card {
      min-width: 0;
      height: 100%;
    }

    .chart-shell {
      position: relative;
      background: linear-gradient(180deg, #f8fafc 0%, #ffffff 100%);
      border: 1px solid #f1f5f9;
      border-radius: 10px;
      padding: 12px 10px 10px;
    }

    .chart-grid-lines {
      position: absolute;
      inset: 12px 10px 34px;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      pointer-events: none;
    }

    .chart-grid-lines span {
      border-top: 1px dashed #e2e8f0;
      width: 100%;
    }

    .hour-chart {
      height: 170px;
      position: relative;
      z-index: 1;
      display: flex;
      align-items: stretch;
      gap: 7px;
    }

    .hour-slot {
      flex: 1;
      min-width: 0;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: flex-end;
      gap: 7px;
      cursor: pointer;
    }

    .hour-bar-wrap {
      width: 100%;
      height: 138px;
      display: flex;
      align-items: flex-end;
    }

    .hour-bar {
      width: 100%;
      min-height: 10px;
      border-radius: 6px 6px 3px 3px;
      background: linear-gradient(180deg, #34d399 0%, #059669 100%);
      box-shadow: 0 3px 8px rgba(5, 150, 105, .12);
      transition: height .45s cubic-bezier(.23, 1, .32, 1), transform .2s ease, filter .2s ease;
    }

    .hour-slot:hover .hour-bar,
    .hour-slot:focus-visible .hour-bar {
      transform: translateY(-4px);
      filter: brightness(.92);
      box-shadow: 0 8px 16px rgba(5, 150, 105, .22);
    }

    .hour-slot.is-peak .hour-bar {
      background: linear-gradient(180deg, #10b981 0%, #047857 100%);
    }

    .chart-label {
      font-size: 9px;
      color: #94a3b8;
      white-space: nowrap;
    }

    .chart-tooltip {
      position: absolute;
      z-index: 10;
      pointer-events: none;
      opacity: 0;
      transform: translate(-50%, 5px);
      transition: opacity .16s ease, transform .16s ease;
      white-space: nowrap;
      background: #0f172a;
      color: #fff;
      border-radius: 7px;
      padding: 7px 9px;
      font-size: 10px;
      line-height: 1.35;
      box-shadow: 0 8px 18px rgba(15, 23, 42, .18);
    }

    .chart-tooltip strong {
      display: block;
      font-size: 11px;
    }

    .chart-tooltip.is-visible {
      opacity: 1;
      transform: translate(-50%, 0);
    }

    .trend-svg {
      overflow: visible;
    }

    .trend-area {
      opacity: .72;
    }

    .trend-line {
      filter: drop-shadow(0 3px 4px rgba(5, 150, 105, .16));
    }

    .trend-point {
      fill: #fff;
      stroke: #059669;
      stroke-width: 2.5;
      cursor: pointer;
      transition: r .18s ease, stroke-width .18s ease;
    }

    .trend-point:hover,
    .trend-point:focus {
      r: 6;
      stroke-width: 3;
    }

    @media (prefers-reduced-motion: reduce) {
      .bar,
      .line-draw,
      .fade-up {
        animation-duration: .01ms !important;
        animation-iteration-count: 1 !important;
      }

      .hour-bar,
      .chart-tooltip,
      .trend-point {
        transition-duration: .01ms !important;
      }
    }

    @keyframes grow {
      from {
        transform: scaleY(0);
        opacity: .2;
      }

      to {
        transform: scaleY(1);
        opacity: 1;
      }
    }

    @keyframes draw {
      to {
        stroke-dashoffset: 0;
      }
    }

    @keyframes fade-in {
      from {
        opacity: 0;
      }

      to {
        opacity: 1;
      }
    }
  </style>
</head>

<body class="bg-white">
  <div class="flex flex-col h-screen">
    <div class="bg-white flex-shrink-0 fixed top-0 left-0 right-0 z-20">
      <div class="max-w-5xl mx-auto px-4 py-2 grid grid-cols-3 items-center">
        <button
          id="backButton"
          type="button"
          class="p-1.5 bg-white border border-gray-200 hover:border-emerald-500 hover:bg-slate-50 transition-all justify-self-start flex items-center justify-center shrink-0"
          style="width:34px;height:34px;border-radius:6px"
          title="Go back"
          aria-label="Go back">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 text-gray-600" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" />
          </svg>
        </button>
        <h1 class="text-base font-semibold text-emerald-600 text-center">Analytics</h1>
        <div class="justify-self-end" style="width:34px" aria-hidden="true"></div>
      </div>
    </div>

    <div class="flex-1 overflow-y-auto mt-12 mb-0" id="mainContent">
      <div class="max-w-5xl mx-auto px-4 pt-3 pb-4">
        <div class="bg-white border border-gray-200 overflow-hidden p-3 mb-3 fade-up" style="border-radius:6px">
          <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <div class="min-w-0">
              <p class="text-[11px] font-bold text-gray-500 uppercase tracking-wide">Stall Analytics</p>
              <p id="dateSummary" class="text-[10px] text-gray-400 mt-0.5">Analytics for today</p>
            </div>
          </div>

          <div class="mt-3 flex flex-wrap items-center gap-1.5" role="group" aria-label="Analytics date range">
            <button data-range="today" type="button" class="range-btn h-7 inline-flex items-center justify-center px-3 py-0 leading-[16px] bg-emerald-600 text-white text-[11px] font-semibold hover:bg-emerald-700 transition-colors rounded-[3px]">Today</button>
            <button data-range="yesterday" type="button" class="range-btn h-7 inline-flex items-center justify-center px-3 py-0 leading-[16px] bg-white border border-gray-200 text-gray-600 text-[11px] font-semibold hover:border-emerald-500 hover:text-emerald-600 transition-colors rounded-[3px]">Yesterday</button>
            <button data-range="week" type="button" class="range-btn h-7 inline-flex items-center justify-center px-3 py-0 leading-[16px] bg-white border border-gray-200 text-gray-600 text-[11px] font-semibold hover:border-emerald-500 hover:text-emerald-600 transition-colors rounded-[3px]">This Week</button>
            <button data-range="month" type="button" class="range-btn h-7 inline-flex items-center justify-center px-3 py-0 leading-[16px] bg-white border border-gray-200 text-gray-600 text-[11px] font-semibold hover:border-emerald-500 hover:text-emerald-600 transition-colors rounded-[3px]">This Month</button>
            <label
              id="analyticsDateControl"
              class="relative h-7 w-[100px] flex-shrink-0 bg-white border border-gray-200 hover:border-emerald-500 focus-within:border-emerald-500 rounded-[3px] cursor-pointer flex items-center justify-between gap-1.5 px-1.5 py-0 leading-[16px]"
              aria-label="Select analytics date"
              title="Select analytics date">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-3.5 h-3.5 text-gray-600 pointer-events-none shrink-0" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3.75 8.25h16.5M5.25 5.25h13.5a1.5 1.5 0 0 1 1.5 1.5v12a1.5 1.5 0 0 1-1.5 1.5H5.25a1.5 1.5 0 0 1-1.5-1.5v-12a1.5 1.5 0 0 1 1.5-1.5Z" />
              </svg>
              <span id="selectedDateLabel" class="text-[10px] text-gray-600 whitespace-nowrap pointer-events-none"></span>
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-3 h-3 text-gray-500 pointer-events-none shrink-0" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="m6.75 9 5.25 5.25L17.25 9" />
              </svg>
              <input
                id="analyticsDate"
                type="date"
                class="absolute inset-0 w-full h-full opacity-0 pointer-events-none"
                aria-label="Select analytics date" />
            </label>
          </div>
        </div>

        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-3">
          <div class="relative overflow-hidden bg-gradient-to-br from-emerald-600 via-emerald-600 to-teal-700 text-white border border-emerald-500 p-3.5 fade-up" style="border-radius:10px;animation-delay:.12s;box-shadow:0 10px 24px rgba(5,150,105,.16)">
            <div class="absolute -right-5 -top-5 w-20 h-20 bg-white/10 rounded-full"></div>
            <div class="relative flex items-start justify-between gap-2">
              <div class="w-8 h-8 bg-white/15 border border-white/15 flex items-center justify-center shrink-0" style="border-radius:8px">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor" class="w-4 h-4" aria-hidden="true">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 18.75h-9m9 0a3 3 0 0 0 3-3v-7.5a3 3 0 0 0-3-3h-9a3 3 0 0 0-3 3v7.5a3 3 0 0 0 3 3m9 0v1.5a.75.75 0 0 1-.75.75h-7.5a.75.75 0 0 1-.75-.75v-1.5m5.25-9.75h.008v.008H12V9Zm0 3.75h.008v.008H12v-.008Zm0 3.75h.008v.008H12V16.5Z" />
                </svg>
              </div>
              <span id="topItemBadge" class="inline-flex items-center gap-1 text-[9px] font-semibold bg-white/15 px-1.5 py-1" style="border-radius:999px"><span class="w-1.5 h-1.5 bg-emerald-200 rounded-full"></span>Best seller</span>
            </div>
            <p class="relative text-[10px] font-medium text-emerald-100 mt-4 uppercase tracking-wide">Top Item</p>
            <p id="topItemName" class="relative text-sm font-bold mt-1 truncate">Chicken Rice</p>
            <p id="topItemOrders" class="relative text-[10px] text-emerald-100 mt-1">64 orders</p>
          </div>

          <div class="relative overflow-hidden bg-white border border-gray-200 p-3.5 fade-up" style="border-radius:10px;animation-delay:.16s;box-shadow:0 8px 22px rgba(15,23,42,.05)">
            <div class="absolute -right-6 -top-6 w-20 h-20 bg-amber-50 rounded-full"></div>
            <div class="relative flex items-start justify-between gap-2">
              <div class="w-8 h-8 bg-amber-50 text-amber-600 border border-amber-100 flex items-center justify-center shrink-0" style="border-radius:8px">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor" class="w-4 h-4" aria-hidden="true">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v12m3-9.75c0-1.243-1.343-2.25-3-2.25s-3 1.007-3 2.25 1.343 2.25 3 2.25 3 1.007 3 2.25-1.343 2.25-3 2.25-3-1.007-3-2.25" />
                </svg>
              </div>
              <span id="salesBadge" class="inline-flex items-center gap-1 text-[9px] font-semibold text-emerald-600 bg-emerald-50 px-1.5 py-1" style="border-radius:999px"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-3 h-3" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 10.5 3 3 6-6" /></svg>On track</span>
            </div>
            <p class="relative text-[10px] font-bold text-gray-500 mt-4 uppercase tracking-wide">Total Sales</p>
            <p id="totalSalesValue" class="relative text-base font-bold text-gray-900 mt-1 truncate">₱12,480.00</p>
            <p id="totalSalesMeta" class="relative text-[10px] text-gray-400 mt-1">This month</p>
          </div>

          <div class="relative overflow-hidden bg-white border border-gray-200 p-3.5 fade-up" style="border-radius:10px;animation-delay:.20s;box-shadow:0 8px 22px rgba(15,23,42,.05)">
            <div class="absolute -right-6 -top-6 w-20 h-20 bg-blue-50 rounded-full"></div>
            <div class="relative flex items-start justify-between gap-2">
              <div class="w-8 h-8 bg-blue-50 text-blue-600 border border-blue-100 flex items-center justify-center shrink-0" style="border-radius:8px">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor" class="w-4 h-4" aria-hidden="true">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
                </svg>
              </div>
              <span id="ordersBadge" class="inline-flex items-center gap-1 text-[9px] font-semibold text-blue-600 bg-blue-50 px-1.5 py-1" style="border-radius:999px">This month</span>
            </div>
            <p class="relative text-[10px] font-bold text-gray-500 mt-4 uppercase tracking-wide">Total Orders</p>
            <p id="totalOrdersValue" class="relative text-base font-bold text-gray-900 mt-1">186</p>
            <p id="totalOrdersMeta" class="relative text-[10px] text-gray-400 mt-1">Completed orders</p>
          </div>

          <div class="relative overflow-hidden bg-white border border-gray-200 p-3.5 fade-up" style="border-radius:10px;animation-delay:.24s;box-shadow:0 8px 22px rgba(15,23,42,.05)">
            <div class="absolute -right-6 -top-6 w-20 h-20 bg-violet-50 rounded-full"></div>
            <div class="relative flex items-start justify-between gap-2">
              <div class="w-8 h-8 bg-violet-50 text-violet-600 border border-violet-100 flex items-center justify-center shrink-0" style="border-radius:8px">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor" class="w-4 h-4" aria-hidden="true">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M12 6.75v10.5m5.25-5.25H6.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                </svg>
              </div>
              <span id="averageBadge" class="inline-flex items-center gap-1 text-[9px] font-semibold text-violet-600 bg-violet-50 px-1.5 py-1" style="border-radius:999px">Per order</span>
            </div>
            <p class="relative text-[10px] font-bold text-gray-500 mt-4 uppercase tracking-wide">Avg. Order</p>
            <p id="averageOrderValue" class="relative text-base font-bold text-gray-900 mt-1">₱67.10</p>
            <p id="averageOrderMeta" class="relative text-[10px] text-gray-400 mt-1">Average order value</p>
          </div>
        </div>

        <div class="analytics-chart-grid">
          <div class="analytics-card bg-white border border-gray-200 overflow-hidden fade-up" style="border-radius:6px;animation-delay:.28s">
            <div class="p-4 border-b border-gray-100 flex items-center justify-between gap-3">
              <div>
                <p class="text-[11px] font-bold text-gray-500 uppercase tracking-wide">Orders by Hour</p>
                <p id="hourChartSubtitle" class="text-[10px] text-gray-400 mt-0.5">Today’s customer order volume</p>
              </div>
              <span id="hourPeakLabel" class="text-[10px] font-semibold text-emerald-600">Peak 5 PM</span>
            </div>
            <div class="p-4">
              <div class="chart-shell" id="hourChartShell">
                <div class="chart-grid-lines"><span></span><span></span><span></span><span></span></div>
                <div class="hour-chart" id="hourChart" aria-label="Orders by hour chart"></div>
                <div class="chart-tooltip" id="hourTooltip" role="status"></div>
              </div>
              <div class="mt-2 flex items-center justify-between text-[9px] text-gray-400"><span id="hourChartLowerLabel">Lower volume</span><span class="flex items-center gap-1"><i class="w-1.5 h-1.5 rounded-full bg-emerald-600"></i><span id="hourChartLegendLabel">Peak hour</span></span></div>
            </div>
          </div>

          <div class="analytics-card bg-white border border-gray-200 overflow-hidden fade-up" style="border-radius:6px;animation-delay:.32s">
            <div class="p-4 border-b border-gray-100 flex items-center justify-between gap-3">
              <div>
                <p class="text-[11px] font-bold text-gray-500 uppercase tracking-wide">Sales Trend</p>
                <p class="text-[10px] text-gray-400 mt-0.5">Sales performance this week</p>
              </div>
              <span class="text-[10px] font-semibold text-emerald-600">+8.7%</span>
            </div>
            <div class="p-4">
              <div class="chart-shell" id="trendChartShell">
                <div class="chart-grid-lines"><span></span><span></span><span></span><span></span></div>
                <svg id="trendChart" viewBox="0 0 500 160" preserveAspectRatio="none" class="trend-svg w-full h-[170px] relative z-[1]" role="img" aria-label="Sales trend chart"></svg>
                <div class="chart-tooltip" id="trendTooltip" role="status"></div>
              </div>
              <div class="mt-2 flex items-center justify-between text-[9px] text-gray-400"><span>Mon</span><span>Tue</span><span>Wed</span><span>Thu</span><span>Fri</span><span>Sat</span><span>Sun</span></div>
            </div>
          </div>
        </div>

      </div>
    </div>
  </div>

  <script>
    (function () {
      const dateInput = document.getElementById("analyticsDate");
      const dateControl = document.getElementById("analyticsDateControl");
      const dateSummary = document.getElementById("dateSummary");
      const selectedDateLabel = document.getElementById("selectedDateLabel");
      const rangeButtons = document.querySelectorAll(".range-btn");
      const topItemBadge = document.getElementById("topItemBadge");
      const topItemName = document.getElementById("topItemName");
      const topItemOrders = document.getElementById("topItemOrders");
      const salesBadge = document.getElementById("salesBadge");
      const totalSalesValue = document.getElementById("totalSalesValue");
      const totalSalesMeta = document.getElementById("totalSalesMeta");
      const ordersBadge = document.getElementById("ordersBadge");
      const totalOrdersValue = document.getElementById("totalOrdersValue");
      const totalOrdersMeta = document.getElementById("totalOrdersMeta");
      const averageBadge = document.getElementById("averageBadge");
      const averageOrderValue = document.getElementById("averageOrderValue");
      const averageOrderMeta = document.getElementById("averageOrderMeta");
      const hourChartSubtitle = document.getElementById("hourChartSubtitle");
      const hourPeakLabel = document.getElementById("hourPeakLabel");
      const hourChartLowerLabel = document.getElementById("hourChartLowerLabel");
      const hourChartLegendLabel = document.getElementById("hourChartLegendLabel");
      const hourChart = document.getElementById("hourChart");

      function pad(value) {
        return String(value).padStart(2, "0");
      }

      function formatInputDate(date) {
        return date.getFullYear() + "-" + pad(date.getMonth() + 1) + "-" + pad(date.getDate());
      }

      function parseInputDate(value) {
        if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) {
          return null;
        }

        const parts = value.split("-").map(Number);
        const date = new Date(parts[0], parts[1] - 1, parts[2]);

        if (
          date.getFullYear() !== parts[0] ||
          date.getMonth() !== parts[1] - 1 ||
          date.getDate() !== parts[2]
        ) {
          return null;
        }

        date.setHours(0, 0, 0, 0);
        return date;
      }

      function formatDisplayDate(date) {
        return date.toLocaleDateString(undefined, {
          year: "numeric",
          month: "long",
          day: "numeric"
        });
      }

      function setActiveButton(activeButton) {
        rangeButtons.forEach(function (button) {
          button.classList.remove("bg-emerald-600", "text-white", "hover:bg-emerald-700");
          button.classList.add("bg-white", "border", "border-gray-200", "text-gray-600", "hover:border-emerald-500", "hover:text-emerald-600");
          button.setAttribute("aria-pressed", "false");
        });

        if (activeButton) {
          activeButton.classList.remove("bg-white", "border", "border-gray-200", "text-gray-600", "hover:border-emerald-500", "hover:text-emerald-600");
          activeButton.classList.add("bg-emerald-600", "text-white", "hover:bg-emerald-700");
          activeButton.setAttribute("aria-pressed", "true");
        }
      }

      function applyDate(date, summaryText) {
        dateInput.value = formatInputDate(date);
        selectedDateLabel.textContent = formatInputDate(date);
        dateSummary.textContent = summaryText || "Analytics for " + formatDisplayDate(date);
      }

      const chartData = {
        today: { topItem: "Chicken Rice", topItemOrders: 18, sales: 28460, orders: 64, average: 444.69, hours: [8, 14, 28, 20, 16, 31, 18, 12] },
        yesterday: { topItem: "Chicken Rice", topItemOrders: 16, sales: 24980, orders: 57, average: 438.25, hours: [7, 12, 24, 18, 15, 26, 17, 11] },
        week: { topItem: "Chicken Rice", topItemOrders: 64, sales: 154620, orders: 386, average: 400.57, hours: [42, 68, 112, 96, 88, 126, 74, 51] },
        month: { topItem: "Chicken Rice", topItemOrders: 248, sales: 638490, orders: 1594, average: 400.56, hours: [174, 286, 438, 371, 328, 492, 301, 226] }
      };

      function showChartTooltip(tooltip, html, x, y) {
        tooltip.innerHTML = html;
        tooltip.style.left = x + "px";
        tooltip.style.top = Math.max(8, y) + "px";
        tooltip.classList.add("is-visible");
      }

      function hideChartTooltip(tooltip) {
        tooltip.classList.remove("is-visible");
      }

      function renderBars(data, range, customDate) {
        const labels = ["7 AM", "9 AM", "11 AM", "1 PM", "3 PM", "5 PM", "7 PM", "9 PM"];
        const max = Math.max.apply(null, data.hours);
        const peakIndex = data.hours.indexOf(max);
        const context = range === "custom" ? formatDisplayDate(customDate) : range === "today" ? "Today’s" : range === "yesterday" ? "Yesterday’s" : range === "week" ? "This week’s" : "This month’s";
        const tooltip = document.getElementById("hourTooltip");
        hourChartSubtitle.textContent = context + " customer order volume";
        hourPeakLabel.textContent = "Peak " + labels[peakIndex];
        hourChartLowerLabel.textContent = "Lower volume in " + context.toLowerCase();
        hourChartLegendLabel.textContent = "Peak hour";
        hourChart.setAttribute("aria-label", "Orders by hour for " + context);
        hourChart.innerHTML = data.hours.map(function (value, index) {
          return '<div class="hour-slot ' + (index === peakIndex ? "is-peak" : "") + '" tabindex="0" aria-label="' + labels[index] + ': ' + value + ' orders" data-label="' + labels[index] + '" data-value="' + value + '"><div class="hour-bar-wrap"><div class="hour-bar bar" style="height:' + Math.max(10, value / max * 100) + '%;animation-delay:' + index * 45 + 'ms"></div></div><span class="chart-label">' + labels[index] + '</span></div>';
        }).join("");

        const chart = hourChart;

        chart.querySelectorAll(".hour-slot").forEach(function (slot) {
          const show = function () {
            const chartRect = chart.parentElement.getBoundingClientRect();
            const rect = slot.getBoundingClientRect();
            showChartTooltip(tooltip, "<strong>" + slot.dataset.value + " orders</strong><span>" + slot.dataset.label + "</span>", rect.left - chartRect.left + rect.width / 2, rect.top - chartRect.top - 42);
          };
          slot.addEventListener("mouseenter", show);
          slot.addEventListener("focus", show);
          slot.addEventListener("mouseleave", function () { hideChartTooltip(tooltip); });
          slot.addEventListener("blur", function () { hideChartTooltip(tooltip); });
        });
      }

      function renderSalesTrend(data) {
        const trend = [0.72, 0.84, 0.68, 0.91, 0.79, 1, 0.88].map(function (multiplier) {
          return Math.round(data.sales * multiplier / 5);
        });
        const labels = ["Mon", "Tue", "Wed", "Thu", "Fri", "Sat", "Sun"];
        const svg = document.getElementById("trendChart");
        const tooltip = document.getElementById("trendTooltip");
        const points = trend.map(function (value, index) {
          return { x: index * 83.33, y: 132 - value / Math.max.apply(null, trend) * 102, value: value };
        });
        const line = points.map(function (point, index) {
          return (index === 0 ? "M" : "L") + point.x + " " + point.y;
        }).join(" ");
        const area = line + " L500 150 L0 150 Z";
        svg.innerHTML = '<defs><linearGradient id="trendFill" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="#6ee7b7" stop-opacity=".62"/><stop offset="100%" stop-color="#d1fae5" stop-opacity=".12"/></linearGradient></defs><path d="' + area + '" fill="url(#trendFill)" class="trend-area"></path><path d="' + line + '" fill="none" stroke="#059669" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" class="trend-line line-draw"></path>' + points.map(function (point, index) {
          return '<circle class="trend-point" cx="' + point.x + '" cy="' + point.y + '" r="4" tabindex="0" aria-label="' + labels[index] + ': ₱' + point.value.toLocaleString("en-PH") + '" data-label="' + labels[index] + '" data-value="₱' + point.value.toLocaleString("en-PH") + '"></circle>';
        }).join("");

        svg.querySelectorAll(".trend-point").forEach(function (point) {
          const show = function () {
            const chartRect = svg.parentElement.getBoundingClientRect();
            const rect = point.getBoundingClientRect();
            showChartTooltip(tooltip, "<strong>" + point.dataset.value + "</strong><span>" + point.dataset.label + "</span>", rect.left - chartRect.left + rect.width / 2, rect.top - chartRect.top - 44);
          };
          point.addEventListener("mouseenter", show);
          point.addEventListener("focus", show);
          point.addEventListener("mouseleave", function () { hideChartTooltip(tooltip); });
          point.addEventListener("blur", function () { hideChartTooltip(tooltip); });
        });
      }

      function formatPeso(value) {
        return "₱" + Number(value).toLocaleString("en-PH", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
      }

      function formatRangeLabel(range) {
        return range === "today" ? "Today" : range === "yesterday" ? "Yesterday" : range === "week" ? "This week" : range === "month" ? "This month" : "Selected date";
      }

      function animateNumber(element, end, prefix, decimals, suffix) {
        if (element._numberAnimationFrame) cancelAnimationFrame(element._numberAnimationFrame);
        const start = Number(element.dataset.value || 0);
        const duration = 700;
        const started = performance.now();
        const formatter = new Intl.NumberFormat("en-PH", {
          minimumFractionDigits: decimals,
          maximumFractionDigits: decimals
        });
        const tick = function (now) {
          const progress = Math.min((now - started) / duration, 1);
          const eased = 1 - Math.pow(1 - progress, 3);
          const current = start + (end - start) * eased;
          const displayValue = progress < 1 ? (decimals ? current : Math.round(current)) : end;
          element.textContent = (prefix || "") + formatter.format(displayValue) + (suffix || "");
          if (progress < 1) {
            element._numberAnimationFrame = requestAnimationFrame(tick);
          } else {
            element.dataset.value = end;
            element._numberAnimationFrame = 0;
          }
        };
        element._numberAnimationFrame = requestAnimationFrame(tick);
      }

      function updateMetricCards(data, range) {
        const rangeLabel = formatRangeLabel(range);
        topItemBadge.innerHTML = '<span class="w-1.5 h-1.5 bg-emerald-200 rounded-full"></span>' + (range === "today" ? "Best seller" : rangeLabel);
        topItemName.textContent = data.topItem;
        animateNumber(topItemOrders, data.topItemOrders, "", 0, " orders");
        salesBadge.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-3 h-3" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 10.5 3 3 6-6" /></svg>' + rangeLabel;
        animateNumber(totalSalesValue, data.sales, "₱", 2, "");
        totalSalesMeta.textContent = rangeLabel;
        ordersBadge.textContent = rangeLabel;
        animateNumber(totalOrdersValue, data.orders, "", 0, "");
        totalOrdersMeta.textContent = range === "today" || range === "yesterday" ? "Completed orders" : "Completed orders in range";
        averageBadge.textContent = range === "custom" ? "Selected date" : "Per order";
        animateNumber(averageOrderValue, data.average, "₱", 2, "");
        averageOrderMeta.textContent = "Average order value";
      }

      function buildCustomData(date) {
        const seed = date.getFullYear() + date.getMonth() + date.getDate();
        const orders = 42 + (seed % 29);
        const average = 360 + (seed % 91);
        const sales = Math.round(orders * average);
        const baseHours = [6, 11, 19, 15, 13, 24, 16, 9];
        return {
          topItem: seed % 3 === 0 ? "Chicken Rice" : seed % 3 === 1 ? "Burger Meal" : "Pancit Canton",
          topItemOrders: Math.max(8, Math.round(orders * .28)),
          sales: sales,
          orders: orders,
          average: sales / orders,
          hours: baseHours.map(function (value, index) { return value + (seed % (index + 3)); })
        };
      }

      function renderCharts(range, customDate) {
        const data = range === "custom" ? buildCustomData(customDate) : chartData[range] || chartData.today;
        updateMetricCards(data, range);
        renderBars(data, range, customDate);
        renderSalesTrend(data);
      }

      const today = new Date();
      today.setHours(0, 0, 0, 0);
      dateInput.value = formatInputDate(today);
      dateInput.max = formatInputDate(today);
      selectedDateLabel.textContent = formatInputDate(today);
      dateSummary.textContent = "Analytics for " + formatDisplayDate(today);

      document.getElementById("backButton").addEventListener("click", function () {
        if (window.history.length > 1) {
          window.history.back();
        } else {
          window.location.href = "dashboard.php";
        }
      });

      dateControl.addEventListener("click", function (event) {
        event.preventDefault();

        if (typeof dateInput.showPicker === "function") {
          try {
            dateInput.showPicker();
          } catch (error) {
            dateInput.focus();
          }
        } else {
          dateInput.focus();
        }
      });

      dateInput.addEventListener("change", function () {
        const selectedDate = parseInputDate(dateInput.value);

        if (!selectedDate || selectedDate > today) {
          dateInput.setCustomValidity("Please select a valid date that is not in the future.");
          dateInput.reportValidity();
          return;
        }

        dateInput.setCustomValidity("");
        setActiveButton(null);
          applyDate(selectedDate, "Analytics for " + formatDisplayDate(selectedDate));
          renderCharts("custom", selectedDate);
      });

      rangeButtons.forEach(function (button) {
        button.setAttribute("aria-pressed", button.dataset.range === "today" ? "true" : "false");

        button.addEventListener("click", function () {
          const selectedDate = new Date(today);
          const range = this.dataset.range;
          let summaryText;

          if (range === "yesterday") {
            selectedDate.setDate(selectedDate.getDate() - 1);
            summaryText = "Analytics for yesterday — " + formatDisplayDate(selectedDate);
          } else if (range === "week") {
            const dayOfWeek = selectedDate.getDay();
            selectedDate.setDate(selectedDate.getDate() - dayOfWeek);
            summaryText = "Analytics for this week — starting " + formatDisplayDate(selectedDate);
          } else if (range === "month") {
            selectedDate.setDate(1);
            summaryText = "Analytics for this month — starting " + formatDisplayDate(selectedDate);
          } else {
            summaryText = "Analytics for today — " + formatDisplayDate(selectedDate);
          }

          setActiveButton(this);
          applyDate(selectedDate, summaryText);
          renderCharts(range);
        });
      });

      renderCharts("today");
    }());
  </script>
</body>

</html>

