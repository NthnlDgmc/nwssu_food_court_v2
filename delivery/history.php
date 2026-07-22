<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['staff_id'])) {
  header('Location: ../auth/login.php');
  exit;
}

$staffId = $_SESSION['staff_id'];

function formatPaymentLabel($method)
{
  if ($method === 'gcash') return 'GCash';
  if ($method === 'paymaya') return 'Maya';
  return 'Cash on Delivery';
}

function fetchHistoryData($conn, $staffId)
{
  $stmt = $conn->prepare("
        SELECT o.order_id, o.status, o.payment_method, o.total_delivery_fee,
               o.drop_off_location, o.cancel_reason, o.delivery_proof_image,
               o.customer_confirmed, o.created_at,
               s.stall_name,
               c.first_name AS cust_first_name, c.last_name AS cust_last_name,
               c.contact_number AS cust_contact, c.customer_type, c.profile_image AS cust_profile_image
        FROM orders o
        JOIN customers c ON o.customer_id = c.customer_id
        JOIN stalls s ON o.stall_id = s.stall_id
        WHERE o.order_type = 'delivery'
          AND o.staff_id = ?
          AND o.status IN ('delivered', 'cancelled')
        ORDER BY o.created_at DESC
    ");
  $stmt->bind_param("s", $staffId);
  $stmt->execute();
  $result = $stmt->get_result();

  $history = [];
  $orderIds = [];

  while ($row = $result->fetch_assoc()) {
    $orderIdRaw = (int) $row['order_id'];
    $orderIds[] = $orderIdRaw;

    $history[$orderIdRaw] = [
      'orderIdRaw' => $orderIdRaw,
      'id' => 'FC-' . str_pad($orderIdRaw, 6, '0', STR_PAD_LEFT),
      'date' => date('M j, Y', strtotime($row['created_at'])) . ' · ' . date('g:i A', strtotime($row['created_at'])),
      'customerName' => trim($row['cust_first_name'] . ' ' . $row['cust_last_name']),
      'customerType' => $row['customer_type'],
      'customerContact' => $row['cust_contact'],
      'customerImage' => $row['cust_profile_image'] ? '../' . $row['cust_profile_image'] : null,
      'stall' => $row['stall_name'],
      'location' => $row['drop_off_location'],
      'payment' => formatPaymentLabel($row['payment_method']),
      'status' => $row['status'],
      'cancelReason' => $row['cancel_reason'],
      'proofImage' => $row['delivery_proof_image'] ? '../' . $row['delivery_proof_image'] : null,
      'customerConfirmed' => $row['customer_confirmed'],
      'deliveryFee' => (float) $row['total_delivery_fee'],
      'items' => [],
    ];
  }
  $stmt->close();

  if (empty($orderIds)) {
    return [];
  }

  $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
  $types = str_repeat('i', count($orderIds));

  $itemsStmt = $conn->prepare("
        SELECT oi.order_id, oi.item_name, oi.unit_price, oi.quantity, mi.image
        FROM order_items oi
        LEFT JOIN menu_items mi ON oi.menu_item_id = mi.menu_item_id
        WHERE oi.order_id IN ($placeholders)
        ORDER BY oi.order_item_id ASC
    ");
  $params = [];
  $params[] = $types;
  foreach ($orderIds as $key => $id) {
    $params[] = $id;
  }
  $refs = [];
  foreach ($params as $key => $value) {
    $refs[$key] = &$params[$key];
  }
  call_user_func_array([$itemsStmt, 'bind_param'], $refs);
  $itemsStmt->execute();
  $itemsResult = $itemsStmt->get_result();

  while ($itemRow = $itemsResult->fetch_assoc()) {
    $oid = (int) $itemRow['order_id'];
    if (isset($history[$oid])) {
      $history[$oid]['items'][] = [
        'name' => $itemRow['item_name'],
        'price' => (float) $itemRow['unit_price'],
        'qty' => (int) $itemRow['quantity'],
        'img' => $itemRow['image'] ? '../' . $itemRow['image'] : null,
      ];
    }
  }
  $itemsStmt->close();

  return array_values($history);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
  header('Content-Type: application/json');
  $action = $_POST['action'];

  if ($action === 'get_history') {
    echo json_encode(['success' => true, 'history' => fetchHistoryData($conn, $staffId)]);
    $conn->close();
    exit;
  }

  echo json_encode(['success' => false, 'message' => 'Unknown action.']);
  $conn->close();
  exit;
}

$initialHistory = fetchHistoryData($conn, $staffId);
$conn->close();

$totalDelivered = 0;
$totalCancelled = 0;
$totalEarnings = 0.0;
foreach ($initialHistory as $h) {
  if ($h['status'] === 'delivered') {
    $totalDelivered++;
    $totalEarnings += $h['deliveryFee'];
  } elseif ($h['status'] === 'cancelled') {
    $totalCancelled++;
  }
}
?>
<!doctype html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta
    name="viewport"
    content="width=device-width, initial-scale=1.0, user-scalable=yes" />
  <title>Delivery Staff - History</title>
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

    html, body {
      overflow: hidden;
      height: 100%;
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

    .no-scrollbar::-webkit-scrollbar {
      display: none;
    }

    .no-scrollbar {
      -ms-overflow-style: none;
      scrollbar-width: none;
    }

    .status-tab-active {
      background: linear-gradient(135deg, #059669 0%, #047857 100%);
      border-color: #059669;
      color: #ffffff;
    }
  </style>
</head>

<body class="bg-white">
  <div class="flex flex-col h-screen">
    <div class="bg-white flex-shrink-0 fixed top-0 left-0 right-0 z-20">
      <div class="max-w-6xl mx-auto px-4 py-2 grid grid-cols-3 items-center">
        <button
          id="backButton"
          class="rounded-md p-1.5 bg-white border border-slate-200 hover:border-emerald-500 hover:bg-slate-50 transition-all justify-self-start flex items-center justify-center shrink-0"
          style="width: 34px; height: 34px"
          title="Go back">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 text-gray-600">
            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" />
          </svg>
        </button>
        <h1 class="text-base font-semibold text-emerald-600 text-center">
          History
        </h1>
        <div class="justify-self-end" style="width: 34px"></div>
      </div>
    </div>

    <div class="flex-1 overflow-y-auto mt-12 mb-16" id="mainContent">
      <div class="max-w-6xl mx-auto px-4 pt-3 pb-4 space-y-3">
        <div class="grid grid-cols-3 gap-2.5">
          <div class="rounded-md bg-emerald-50 border border-emerald-100 p-3">
            <p class="text-lg font-bold text-emerald-900" id="statDelivered">0</p>
            <p class="text-[10px] text-emerald-600 mt-0.5">Delivered</p>
          </div>
          <div class="rounded-md bg-amber-50 border border-amber-100 p-3">
            <p class="text-lg font-bold text-amber-900" id="statEarnings">₱0.00</p>
            <p class="text-[10px] text-amber-600 mt-0.5">Total Earnings</p>
          </div>
          <div class="rounded-md bg-gray-100 border border-gray-200 p-3">
            <p class="text-lg font-bold text-gray-700" id="statCancelled">0</p>
            <p class="text-[10px] text-gray-500 mt-0.5">Cancelled</p>
          </div>
        </div>

        <div class="rounded-md bg-white border border-gray-200 p-3 shadow-sm space-y-3">
          <div class="flex items-center gap-2">
            <div class="relative flex-1 min-w-0">
              <input
                type="text"
                id="searchHistory"
                placeholder="Search history..."
                class="w-full pl-9 pr-9 py-2 bg-white border border-gray-200 text-xs text-gray-900 placeholder-gray-400 focus:outline-none focus:border-emerald-600 rounded-[3px]" />
              <div class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4">
                  <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
                </svg>
              </div>
              <button
                type="button"
                id="clearSearchBtn"
                class="hidden absolute right-2.5 top-1/2 -translate-y-1/2 p-0.5 text-gray-400 hover:text-gray-600 transition-colors rounded-[3px]"
                title="Clear search">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-4 h-4">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                </svg>
              </button>
            </div>
          </div>
          <div id="statusTabsContainer" class="flex items-center gap-2 overflow-x-auto no-scrollbar"></div>
        </div>

        <div id="historyContainer" class="space-y-3"></div>

        <div id="emptyView" class="hidden flex flex-col items-center justify-center py-16 text-center">
          <div class="w-40 h-40 mb-4">
            <img src="../assets/illustrations/empty-orders.svg" alt="No history found" class="w-full h-full" />
          </div>
          <h3 class="text-base font-semibold text-gray-800">No history found</h3>
          <p class="text-gray-500 text-sm mt-1 mb-5">Try adjusting your filter or search.</p>
        </div>
      </div>
    </div>

    <div class="bg-white border-t border-gray-200 flex-shrink-0 fixed bottom-0 left-0 right-0 z-20">
      <div class="max-w-6xl mx-auto px-4 flex justify-around py-2">
        <a
          href="./dashboard.php"
          class="flex flex-col items-center justify-center py-2 px-3 transition-all duration-200 group text-gray-500 hover:text-gray-900 hover:bg-gray-50"
          style="border-radius: 3px">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 transition-transform group-hover:scale-110">
            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 0 1 6 3.75h2.25A2.25 2.25 0 0 1 10.5 6v2.25a2.25 2.25 0 0 1-2.25 2.25H6a2.25 2.25 0 0 1-2.25-2.25V6ZM3.75 15.75A2.25 2.25 0 0 1 6 13.5h2.25a2.25 2.25 0 0 1 2.25 2.25V18a2.25 2.25 0 0 1-2.25 2.25H6A2.25 2.25 0 0 1 3.75 18v-2.25ZM13.5 6a2.25 2.25 0 0 1 2.25-2.25H18A2.25 2.25 0 0 1 20.25 6v2.25A2.25 2.25 0 0 1 18 10.5h-2.25a2.25 2.25 0 0 1-2.25-2.25V6ZM13.5 15.75a2.25 2.25 0 0 1 2.25-2.25H18a2.25 2.25 0 0 1 2.25 2.25V18A2.25 2.25 0 0 1 18 20.25h-2.25A2.25 2.25 0 0 1 13.5 18v-2.25Z" />
          </svg>
          <span class="text-xs font-medium mt-1">Dashboard</span>
        </a>
        <a
          href="./deliveries.php"
          class="flex flex-col items-center justify-center py-2 px-3 transition-all duration-200 group text-gray-500 hover:text-gray-900 hover:bg-gray-50"
          style="border-radius: 3px">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 transition-transform group-hover:scale-110">
            <path stroke-linecap="round" stroke-linejoin="round" d="M6.429 9.75 2.25 12l4.179 2.25m0-4.5 5.571 3 5.571-3m-11.142 0L2.25 7.5 12 2.25l9.75 5.25-4.179 2.25m0 0L21.75 12l-4.179 2.25m0 0 4.179 2.25L12 21.75 2.25 16.5l4.179-2.25m11.142 0-5.571 3-5.571-3" />
          </svg>
          <span class="text-xs font-medium mt-1">Deliveries</span>
        </a>
        <a
          href="./history.php"
          class="flex flex-col items-center justify-center py-2 px-3 transition-all duration-200 group text-emerald-600 bg-emerald-50"
          style="border-radius: 3px">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
          </svg>
          <span class="text-xs font-medium mt-1">History</span>
        </a>
        <a
          href="./account.php"
          class="flex flex-col items-center justify-center py-2 px-3 transition-all duration-200 group text-gray-500 hover:text-gray-900 hover:bg-gray-50"
          style="border-radius: 3px">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 transition-transform group-hover:scale-110">
            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" />
          </svg>
          <span class="text-xs font-medium mt-1">Account</span>
        </a>
      </div>
    </div>
  </div>

  <div id="detailsModal" class="fixed inset-0 z-50 hidden flex items-center justify-center px-4">
    <div class="modal-overlay absolute inset-0" id="closeModalOverlay"></div>
    <div class="bg-white w-full max-w-md max-h-[90vh] overflow-y-auto relative z-10 shadow-2xl rounded-md">
      <div class="p-4 border-b border-gray-100 flex items-center justify-between sticky top-0 bg-white z-10">
        <h2 class="font-bold text-gray-800 text-sm">Delivery Details</h2>
        <button id="closeModalBtn" class="p-1 hover:bg-gray-100 rounded-[3px]">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 text-gray-500">
            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
          </svg>
        </button>
      </div>
      <div id="modalContent" class="p-4 space-y-4"></div>
    </div>
  </div>

  <div id="imageLightbox" class="fixed inset-0 z-[70] hidden flex items-center justify-center px-4">
    <div class="absolute inset-0 bg-black/80" id="closeLightboxOverlay"></div>
    <button id="closeLightboxBtn" class="absolute top-4 right-4 z-10 p-2 bg-white/10 hover:bg-white/20 transition-colors rounded-full">
      <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6 text-white">
        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
      </svg>
    </button>
    <img id="lightboxImage" src="" alt="" class="relative z-10 max-w-full max-h-[85vh] object-contain rounded-[6px]" />
  </div>

  <script>
    const STATUS_META = {
      delivered: { label: "Delivered", cls: "bg-emerald-50 text-emerald-700 border-emerald-200" },
      cancelled: { label: "Cancelled", cls: "bg-gray-100 text-gray-500 border-gray-200" },
    };

    const STATUS_TABS = [
      { value: "all", label: "All" },
      { value: "delivered", label: "Delivered" },
      { value: "cancelled", label: "Cancelled" },
    ];

    const CUSTOMER_TYPE_MAP = {
      student: { label: "Student", cls: "bg-sky-50 text-sky-700 border-sky-200" },
      faculty: { label: "Faculty", cls: "bg-violet-50 text-violet-700 border-violet-200" },
      staff: { label: "Staff", cls: "bg-teal-50 text-teal-700 border-teal-200" },
    };

    let ALL_HISTORY = <?php echo json_encode($initialHistory); ?>;

    let searchQuery = "";
    let currentStatusFilter = "all";

    function escapeHtml(str) {
      if (!str) return "";
      return String(str)
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
    }

    function getInitials(name) {
      return String(name || "")
        .split(" ")
        .filter(Boolean)
        .map((w) => w[0])
        .join("")
        .slice(0, 2)
        .toUpperCase();
    }

    function personAvatarHtml(imageUrl, name, sizeCls) {
      if (imageUrl) {
        return `<img src="${escapeHtml(imageUrl)}" alt="${escapeHtml(name)}" class="${sizeCls} object-cover shrink-0 rounded-full" />`;
      }
      return `<div class="${sizeCls} bg-gradient-to-br from-emerald-500 to-emerald-700 flex items-center justify-center text-white text-xs font-bold shrink-0 rounded-full">${getInitials(name)}</div>`;
    }

    function statusBadgeHtml(status) {
      const meta = STATUS_META[status] || STATUS_META.cancelled;
      return `<span class="text-[10px] font-semibold px-2 py-0.5 border ${meta.cls} shrink-0" style="border-radius:3px">${meta.label}</span>`;
    }

    function customerTypeBadgeHtml(type) {
      const t = CUSTOMER_TYPE_MAP[type];
      if (!t) return "";
      return `<span class="text-[9px] font-semibold px-1.5 py-0.5 border ${t.cls} shrink-0" style="border-radius:3px">${t.label}</span>`;
    }

    function calcSubtotal(entry) {
      return entry.items.reduce((s, i) => s + i.price * i.qty, 0);
    }

    function updateStats() {
      let delivered = 0;
      let cancelled = 0;
      let earnings = 0;
      ALL_HISTORY.forEach((h) => {
        if (h.status === "delivered") {
          delivered++;
          earnings += h.deliveryFee;
        } else if (h.status === "cancelled") {
          cancelled++;
        }
      });
      document.getElementById("statDelivered").textContent = delivered;
      document.getElementById("statEarnings").textContent = "₱" + earnings.toFixed(2);
      document.getElementById("statCancelled").textContent = cancelled;
    }

    function buildHistoryCard(entry) {
      const subtotal = calcSubtotal(entry);
      const total = subtotal + entry.deliveryFee;
      const card = document.createElement("div");
      card.className = "rounded-md bg-white border border-gray-200 overflow-hidden shadow-sm";
      card.setAttribute("data-order-id", entry.orderIdRaw);

      let reviewNote = "";
      if (entry.status === "delivered" && entry.customerConfirmed === "confirmed") {
        reviewNote = `<p class="text-[10px] text-emerald-600 font-medium mt-0.5">Confirmed by customer</p>`;
      } else if (entry.status === "delivered" && entry.customerConfirmed === "issue") {
        reviewNote = `<p class="text-[10px] text-red-500 font-medium mt-0.5">Customer reported an issue</p>`;
      }

      card.innerHTML = `
        <div class="p-4 flex items-center gap-3">
          ${personAvatarHtml(entry.customerImage, entry.customerName, "w-10 h-10")}
          <div class="flex-1 min-w-0">
            <p class="text-xs font-semibold text-gray-800 flex items-center gap-1.5">
              <span class="truncate min-w-0">${escapeHtml(entry.customerName)}</span>
              ${customerTypeBadgeHtml(entry.customerType)}
            </p>
            <p class="text-[10px] text-gray-400 mt-0.5">${escapeHtml(entry.id)} &middot; ${escapeHtml(entry.date)}</p>
            <p class="text-[10px] text-emerald-600 font-medium mt-0.5">${escapeHtml(entry.stall)}</p>
            ${reviewNote}
          </div>
          <div class="flex flex-col items-end gap-1 shrink-0">
            ${statusBadgeHtml(entry.status)}
            ${entry.status === "delivered" ? `<span class="text-xs font-bold text-emerald-600">+₱${entry.deliveryFee.toFixed(2)}</span>` : ""}
          </div>
        </div>
        <div class="px-4 pb-4">
          <button class="view-details-btn w-full py-2 border border-gray-200 text-gray-700 text-xs font-semibold hover:bg-gray-50 transition-colors rounded-[3px]" data-id="${entry.orderIdRaw}">
            View Details
          </button>
        </div>
      `;
      return card;
    }

    function showHistoryDetails(orderIdRaw) {
      const entry = ALL_HISTORY.find((h) => h.orderIdRaw === orderIdRaw);
      if (!entry) return;
      const modal = document.getElementById("detailsModal");
      const content = document.getElementById("modalContent");
      const subtotal = calcSubtotal(entry);
      const total = subtotal + entry.deliveryFee;

      content.innerHTML = `
        <div>
          <h3 class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-2">Customer</h3>
          <div class="border border-gray-100 p-3 space-y-3 rounded-md">
            <div class="flex items-center gap-3">
              ${personAvatarHtml(entry.customerImage, entry.customerName, "w-9 h-9")}
              <div class="flex-1 min-w-0">
                <p class="text-xs font-semibold text-gray-800 flex items-center gap-1.5">
                  <span class="truncate min-w-0">${escapeHtml(entry.customerName)}</span>
                  ${customerTypeBadgeHtml(entry.customerType)}
                </p>
                <p class="text-[10px] text-gray-400 mt-0.5">${escapeHtml(entry.customerContact)}</p>
              </div>
            </div>
          </div>
        </div>
        <div>
          <h3 class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-2">Delivery Information</h3>
          <div class="border border-gray-100 p-3 space-y-2 rounded-md">
            <div class="flex items-center justify-between">
              <span class="text-[10px] text-gray-500">Stall</span>
              <span class="text-xs font-medium text-gray-800">${escapeHtml(entry.stall)}</span>
            </div>
            <div class="flex items-center justify-between">
              <span class="text-[10px] text-gray-500">Drop-off Location</span>
              <span class="text-xs font-medium text-gray-800 text-right">${escapeHtml(entry.location)}</span>
            </div>
            <div class="flex items-center justify-between">
              <span class="text-[10px] text-gray-500">Payment Method</span>
              <span class="text-xs font-medium text-gray-800">${escapeHtml(entry.payment)}</span>
            </div>
            <div class="flex items-center justify-between">
              <span class="text-[10px] text-gray-500">Delivery Fee</span>
              <span class="text-xs font-medium text-emerald-600">₱${entry.deliveryFee.toFixed(2)}</span>
            </div>
          </div>
        </div>
        <div>
          <h3 class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-2">Order Summary</h3>
          <div class="border border-gray-100 p-3 space-y-3 rounded-md">
            ${entry.items
              .map(
                (item) => `
              <div class="flex items-center gap-3">
                ${item.img ? `<img src="${item.img}" alt="${escapeHtml(item.name)}" class="w-12 h-12 object-cover bg-gray-100 shrink-0 rounded-[3px]" />` : `<div class="w-12 h-12 bg-gray-100 shrink-0 rounded-[3px]"></div>`}
                <div class="flex-1 flex justify-between">
                  <div class="flex flex-col">
                    <span class="text-xs text-gray-600">${escapeHtml(item.name)} x${item.qty}</span>
                    <span class="text-[10px] text-gray-400">₱${item.price.toFixed(2)} each</span>
                  </div>
                  <span class="text-xs font-medium text-gray-700">₱${(item.price * item.qty).toFixed(2)}</span>
                </div>
              </div>
            `,
              )
              .join("")}
            <div class="pt-2 border-t border-gray-200 mt-2 space-y-1">
              <div class="flex justify-between">
                <span class="text-[11px] text-gray-500">Subtotal</span>
                <span class="text-[11px] text-gray-700">₱${subtotal.toFixed(2)}</span>
              </div>
              <div class="flex justify-between">
                <span class="text-[11px] text-gray-500">Delivery Fee</span>
                <span class="text-[11px] text-gray-700">₱${entry.deliveryFee.toFixed(2)}</span>
              </div>
              <div class="flex justify-between pt-1">
                <span class="text-xs font-bold text-gray-800">Total</span>
                <span class="text-sm font-bold text-emerald-600">₱${total.toFixed(2)}</span>
              </div>
            </div>
          </div>
        </div>
        ${
          entry.proofImage
            ? `<div>
                <h3 class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-2">Proof of Handover</h3>
                <div class="border border-gray-100 p-3 rounded-md">
                  <img src="${escapeHtml(entry.proofImage)}" alt="Proof of handover" class="proof-image-view w-full h-40 object-cover rounded-[6px] cursor-pointer" />
                </div>
              </div>`
            : ""
        }
        ${
          entry.status === "cancelled" && entry.cancelReason
            ? `<div>
                <h3 class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-2">Cancellation Reason</h3>
                <div class="border border-gray-100 p-3 rounded-md">
                  <p class="text-xs text-gray-600 italic">"${escapeHtml(entry.cancelReason)}"</p>
                </div>
              </div>`
            : ""
        }
      `;

      modal.classList.remove("hidden");
      document.body.style.overflow = "hidden";

      const proofImg = content.querySelector(".proof-image-view");
      if (proofImg) {
        proofImg.addEventListener("click", () => openLightbox(proofImg.src));
      }
    }

    function openLightbox(src) {
      document.getElementById("lightboxImage").src = src;
      document.getElementById("imageLightbox").classList.remove("hidden");
    }

    function closeLightbox() {
      document.getElementById("imageLightbox").classList.add("hidden");
      document.getElementById("lightboxImage").src = "";
    }

    function closeModal() {
      document.getElementById("detailsModal").classList.add("hidden");
      document.body.style.overflow = "";
    }

    function renderStatusTabs() {
      const container = document.getElementById("statusTabsContainer");
      container.innerHTML = STATUS_TABS.map(
        (t) => `
          <button
            type="button"
            class="status-tab-btn rounded-[3px] shrink-0 px-3 py-1.5 text-[11px] font-semibold border whitespace-nowrap transition-colors ${currentStatusFilter === t.value ? "status-tab-active" : "bg-white border-gray-200 text-gray-500 hover:border-emerald-400 hover:text-emerald-600 hover:bg-emerald-50"}"
            data-value="${t.value}"
          >${t.label}</button>
        `,
      ).join("");
      container.querySelectorAll(".status-tab-btn").forEach((btn) => {
        btn.addEventListener("click", () => {
          currentStatusFilter = btn.getAttribute("data-value");
          renderStatusTabs();
          renderHistory();
        });
      });
    }

    function renderHistory() {
      const container = document.getElementById("historyContainer");
      const emptyView = document.getElementById("emptyView");
      container.innerHTML = "";

      const filtered = ALL_HISTORY.filter((h) => {
        const q = searchQuery.toLowerCase();
        const matchesSearch =
          !q ||
          h.id.toLowerCase().includes(q) ||
          h.customerName.toLowerCase().includes(q) ||
          h.stall.toLowerCase().includes(q) ||
          h.items.some((i) => i.name.toLowerCase().includes(q));
        const matchesStatus = currentStatusFilter === "all" || h.status === currentStatusFilter;
        return matchesSearch && matchesStatus;
      });

      if (filtered.length === 0) {
        emptyView.classList.remove("hidden");
      } else {
        emptyView.classList.add("hidden");
        filtered.forEach((h) => container.appendChild(buildHistoryCard(h)));
      }
      bindEvents();
    }

    function bindEvents() {
      document.querySelectorAll(".view-details-btn").forEach((btn) => {
        btn.addEventListener("click", () => showHistoryDetails(parseInt(btn.getAttribute("data-id"))));
      });
    }

    window.addEventListener("load", function () {
      updateStats();
      renderStatusTabs();
      renderHistory();

      document.getElementById("backButton").addEventListener("click", () => window.history.back());

      document.getElementById("searchHistory").addEventListener("input", (e) => {
        searchQuery = e.target.value;
        document.getElementById("clearSearchBtn").classList.toggle("hidden", searchQuery.length === 0);
        renderHistory();
      });

      document.getElementById("clearSearchBtn").addEventListener("click", () => {
        const input = document.getElementById("searchHistory");
        input.value = "";
        searchQuery = "";
        document.getElementById("clearSearchBtn").classList.add("hidden");
        input.focus();
        renderHistory();
      });

      document.getElementById("closeModalBtn").addEventListener("click", closeModal);
      document.getElementById("closeModalOverlay").addEventListener("click", closeModal);

      document.getElementById("closeLightboxBtn").addEventListener("click", closeLightbox);
      document.getElementById("closeLightboxOverlay").addEventListener("click", closeLightbox);
    });
  </script>
</body>

</html>