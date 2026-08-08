<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['customer_id'])) {
    header('Location: ../auth/login.php');
    exit;
}

$customerId = $_SESSION['customer_id'];

$statusCheckStmt = $conn->prepare("SELECT status, email, contact_number FROM customers WHERE customer_id = ? LIMIT 1");
$statusCheckStmt->bind_param("i", $customerId);
$statusCheckStmt->execute();
$statusCheckRow = $statusCheckStmt->get_result()->fetch_assoc();
$statusCheckStmt->close();

if (!$statusCheckRow || $statusCheckRow['status'] === 'inactive') {
    session_destroy();
    header('Location: ../auth/login.php?deactivated=1');
    exit;
}

if (empty($statusCheckRow['email']) || empty($statusCheckRow['contact_number'])) {
    header('Location: ../auth/complete-profile.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_favorite') {
    header('Content-Type: application/json');

    $menuItemId = (int) ($_POST['menu_item_id'] ?? 0);

    if ($menuItemId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid item.']);
        $conn->close();
        exit;
    }

    $stmt = $conn->prepare("SELECT favorite_id FROM favorites WHERE customer_id = ? AND menu_item_id = ? LIMIT 1");
    $stmt->bind_param("ii", $customerId, $menuItemId);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($existing) {
        $stmt = $conn->prepare("DELETE FROM favorites WHERE favorite_id = ?");
        $stmt->bind_param("i", $existing['favorite_id']);
        $ok = $stmt->execute();
        $stmt->close();

        if (!$ok) {
            echo json_encode(['success' => false, 'message' => 'Failed to remove favorite.']);
            $conn->close();
            exit;
        }

        echo json_encode(['success' => true, 'favorited' => false]);
        $conn->close();
        exit;
    }

    $stmt = $conn->prepare("SELECT menu_item_id FROM menu_items WHERE menu_item_id = ? LIMIT 1");
    $stmt->bind_param("i", $menuItemId);
    $stmt->execute();
    $menuItemExists = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$menuItemExists) {
        echo json_encode(['success' => false, 'message' => 'Item not found.']);
        $conn->close();
        exit;
    }

    $stmt = $conn->prepare("INSERT INTO favorites (customer_id, menu_item_id) VALUES (?, ?)");
    $stmt->bind_param("ii", $customerId, $menuItemId);
    $ok = $stmt->execute();
    $stmt->close();

    if (!$ok) {
        echo json_encode(['success' => false, 'message' => 'Failed to add favorite.']);
        $conn->close();
        exit;
    }

    echo json_encode(['success' => true, 'favorited' => true]);
    $conn->close();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'clear_favorites') {
    header('Content-Type: application/json');

    $stmt = $conn->prepare("DELETE FROM favorites WHERE customer_id = ?");
    $stmt->bind_param("i", $customerId);
    $ok = $stmt->execute();
    $stmt->close();

    echo json_encode(['success' => $ok]);
    $conn->close();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_to_cart') {
    header('Content-Type: application/json');

    $menuItemId = (int) ($_POST['menu_item_id'] ?? 0);

    if ($menuItemId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid item.']);
        $conn->close();
        exit;
    }

    $stmt = $conn->prepare("
        SELECT mi.stall_id
        FROM menu_items mi
        JOIN stalls s ON mi.stall_id = s.stall_id
        WHERE mi.menu_item_id = ?
          AND mi.status = 'available'
          AND mi.owner_id = s.owner_id
        LIMIT 1
    ");
    $stmt->bind_param("i", $menuItemId);
    $stmt->execute();
    $menuItemRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$menuItemRow) {
        echo json_encode(['success' => false, 'message' => 'Item not found.']);
        $conn->close();
        exit;
    }

    $stallId = (int) $menuItemRow['stall_id'];

    $stmt = $conn->prepare("
        INSERT INTO carts (customer_id, menu_item_id, stall_id, quantity)
        VALUES (?, ?, ?, 1)
        ON DUPLICATE KEY UPDATE quantity = quantity + 1
    ");
    $stmt->bind_param("iii", $customerId, $menuItemId, $stallId);
    $ok = $stmt->execute();
    $stmt->close();

    if (!$ok) {
        echo json_encode(['success' => false, 'message' => 'Failed to add item to cart.']);
        $conn->close();
        exit;
    }

    echo json_encode(['success' => true]);
    $conn->close();
    exit;
}

$stmt = $conn->prepare("SELECT first_name, profile_image FROM customers WHERE customer_id = ? LIMIT 1");
$stmt->bind_param("i", $customerId);
$stmt->execute();
$customer = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$customer) {
    $conn->close();
    header('Location: ../auth/login.php');
    exit;
}

$favoritesResult = $conn->prepare("
    SELECT f.favorite_id, mi.menu_item_id, mi.item_name, mi.price, mi.image, mi.status AS item_status,
           s.stall_id, s.stall_name, s.status AS stall_status, s.opens_at, s.closes_at,
           (mi.owner_id = s.owner_id) AS owner_matches
    FROM favorites f
    JOIN menu_items mi ON f.menu_item_id = mi.menu_item_id
    JOIN stalls s ON mi.stall_id = s.stall_id
    WHERE f.customer_id = ?
    ORDER BY f.created_at DESC
");
$favoritesResult->bind_param("i", $customerId);
$favoritesResult->execute();
$favoritesRows = $favoritesResult->get_result();

$currentTime = date('H:i:s');

function isStallOpenNow($opensAt, $closesAt, $currentTime)
{
    if (!$opensAt || !$closesAt) {
        return true;
    }
    return $currentTime >= $opensAt && $currentTime <= $closesAt;
}

$favoriteItems = [];
while ($row = $favoritesRows->fetch_assoc()) {
    $favoriteItems[] = [
        'menu_item_id' => (int) $row['menu_item_id'],
        'item_name' => $row['item_name'],
        'price' => (float) $row['price'],
        'image' => $row['image'] ? '../' . $row['image'] : null,
        'stall_id' => (int) $row['stall_id'],
        'stall_name' => $row['stall_name'],
        'is_available' => $row['item_status'] === 'available'
            && $row['stall_status'] === 'open'
            && (int) $row['owner_matches'] === 1
            && isStallOpenNow($row['opens_at'], $row['closes_at'], $currentTime),
    ];
}
$favoritesResult->close();

$conn->close();

$firstName = $customer['first_name'];
$profileImage = $customer['profile_image'] ? '../' . $customer['profile_image'] : null;
$avatarInitial = mb_strtoupper(mb_substr($firstName, 0, 1));
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>NWSSU Food Court - My Favorites</title>
    <link rel="icon" href="../assets/images/nwssu-logo.png" type="image/png" />
    <link rel="manifest" href="/manifest.json" />
    <meta name="apple-mobile-web-app-capable" content="yes" />
    <meta name="apple-mobile-web-app-status-bar-style" content="default" />
    <meta name="apple-mobile-web-app-title" content="Norwesso Eats" />
    <link rel="apple-touch-icon" href="../assets/images/icon-192.png" />
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
        }

        #mainContent::-webkit-scrollbar-thumb {
            background: #059669;
        }

        .skeleton-bg {
            animation: skeleton-loading 1.5s infinite;
        }

        @keyframes skeleton-loading {

            0%,
            100% {
                background-color: #e5e7eb;
            }

            50% {
                background-color: #f3f4f6;
            }
        }

        .added-state {
            background-color: #047857 !important;
        }

        .card-removing {
            opacity: 0;
            transform: scale(0.95);
            transition: opacity 0.2s ease, transform 0.2s ease;
        }

        .item-image-fade {
            opacity: 0;
            transition: opacity 0.35s ease;
        }

        .item-image-fade.loaded {
            opacity: 1;
        }

        .item-card-enter {
            opacity: 0;
            transform: translateY(6px);
            animation: item-card-fade-in 0.3s ease forwards;
        }

        @keyframes item-card-fade-in {
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .modal-overlay {
            background-color: rgba(0, 0, 0, 0.5);
        }
    </style>
</head>

<body class="bg-white">
    <div class="flex flex-col h-screen">

        <div class="bg-white flex-shrink-0 fixed top-0 left-0 right-0 z-20">
            <div class="max-w-5xl mx-auto px-4 py-2 grid grid-cols-3 items-center">
                <a
                    href="./home.php"
                    id="backButton"
                    class="p-1.5 bg-white border border-gray-200 hover:border-emerald-500 hover:bg-slate-50 transition-all justify-self-start flex items-center justify-center shrink-0"
                    style="width: 34px; height: 34px; border-radius: 6px">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 text-gray-600">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" />
                    </svg>
                </a>
                <h1 class="text-base font-semibold text-emerald-600 text-center">My Favorites</h1>
                <button
                    id="clearFavoritesBtn"
                    class="p-1.5 bg-white border border-gray-200 hover:border-emerald-500 hover:bg-slate-50 transition-all justify-self-end flex items-center justify-center shrink-0"
                    style="width: 34px; height: 34px; border-radius: 6px"
                    title="Clear favorites">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 text-gray-600">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                    </svg>
                </button>
            </div>
        </div>

        <div class="flex-1 overflow-y-auto mt-12" id="mainContent">
            <div class="max-w-5xl mx-auto px-4 pt-3 pb-4">

                <div id="favoritesSkeleton">
                    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-2.5">
                        <div class="bg-white border border-gray-200 overflow-hidden shadow-sm rounded-md">
                            <div class="w-full h-32 skeleton-bg"></div>
                            <div class="p-3 space-y-2">
                                <div class="flex items-start justify-between gap-2">
                                    <div class="flex-1 space-y-1.5">
                                        <div class="h-3 skeleton-bg rounded-[3px]"></div>
                                        <div class="h-2 skeleton-bg w-1/2 rounded-[3px]"></div>
                                    </div>
                                    <div class="h-4 skeleton-bg w-10 shrink-0 rounded-[3px]"></div>
                                </div>
                                <div class="w-full h-8 skeleton-bg rounded-[3px]"></div>
                            </div>
                        </div>
                        <div class="bg-white border border-gray-200 overflow-hidden shadow-sm rounded-md">
                            <div class="w-full h-32 skeleton-bg"></div>
                            <div class="p-3 space-y-2">
                                <div class="flex items-start justify-between gap-2">
                                    <div class="flex-1 space-y-1.5">
                                        <div class="h-3 skeleton-bg rounded-[3px]"></div>
                                        <div class="h-2 skeleton-bg w-1/2 rounded-[3px]"></div>
                                    </div>
                                    <div class="h-4 skeleton-bg w-10 shrink-0 rounded-[3px]"></div>
                                </div>
                                <div class="w-full h-8 skeleton-bg rounded-[3px]"></div>
                            </div>
                        </div>
                        <div class="bg-white border border-gray-200 overflow-hidden shadow-sm rounded-md">
                            <div class="w-full h-32 skeleton-bg"></div>
                            <div class="p-3 space-y-2">
                                <div class="flex items-start justify-between gap-2">
                                    <div class="flex-1 space-y-1.5">
                                        <div class="h-3 skeleton-bg rounded-[3px]"></div>
                                        <div class="h-2 skeleton-bg w-1/2 rounded-[3px]"></div>
                                    </div>
                                    <div class="h-4 skeleton-bg w-10 shrink-0 rounded-[3px]"></div>
                                </div>
                                <div class="w-full h-8 skeleton-bg rounded-[3px]"></div>
                            </div>
                        </div>
                        <div class="bg-white border border-gray-200 overflow-hidden shadow-sm rounded-md">
                            <div class="w-full h-32 skeleton-bg"></div>
                            <div class="p-3 space-y-2">
                                <div class="flex items-start justify-between gap-2">
                                    <div class="flex-1 space-y-1.5">
                                        <div class="h-3 skeleton-bg rounded-[3px]"></div>
                                        <div class="h-2 skeleton-bg w-1/2 rounded-[3px]"></div>
                                    </div>
                                    <div class="h-4 skeleton-bg w-10 shrink-0 rounded-[3px]"></div>
                                </div>
                                <div class="w-full h-8 skeleton-bg rounded-[3px]"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="hidden" id="favorites-content">
                    <div id="favoritesGrid" class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-2.5"></div>
                </div>

                <div class="hidden" id="empty-favorites">
                    <div class="flex flex-col items-center justify-center py-16 text-center">
                        <div class="w-40 h-40 mb-4">
                            <img src="../assets/illustrations/empty-favorites.svg" alt="No favorites yet" class="w-full h-full" />
                        </div>
                        <p class="text-base font-semibold text-gray-800">No favorites yet</p>
                        <p class="text-gray-500 text-sm mt-1 mb-5">Tap the heart icon on any item to save it here.</p>
                        <a href="./home.php" class="px-6 py-2.5 bg-emerald-600 text-white font-medium text-sm hover:bg-emerald-700 transition rounded-[3px]">Browse Menu</a>
                    </div>
                </div>

            </div>
        </div>

    </div>

    <div
        id="clearFavoritesModal"
        class="fixed inset-0 z-[60] hidden flex items-center justify-center px-4">
        <div class="modal-overlay absolute inset-0" id="closeClearFavoritesOverlay"></div>
        <div
            class="bg-white w-full max-w-sm relative z-10 shadow-2xl p-5 space-y-4 text-center rounded-md">
            <div class="w-12 h-12 bg-red-50 flex items-center justify-center mx-auto rounded-full">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6 text-red-500">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                </svg>
            </div>
            <div>
                <p class="text-sm font-bold text-gray-800">Clear Favorites</p>
                <p class="text-xs text-gray-500 mt-1">Remove all items from your favorites? This cannot be undone.</p>
            </div>
            <div class="flex gap-2 pt-1">
                <button id="clearFavoritesKeepBtn" class="flex-1 py-2.5 border border-gray-200 text-gray-700 text-xs font-semibold hover:bg-gray-50 transition-colors rounded-[3px]">
                    Cancel
                </button>
                <button id="clearFavoritesConfirmBtn" class="flex-1 py-2.5 bg-red-500 hover:bg-red-600 text-white text-xs font-semibold transition-colors rounded-[3px]">
                    Clear Favorites
                </button>
            </div>
        </div>
    </div>

    <script>
        let favoriteItems = <?php echo json_encode($favoriteItems); ?>;

        const ADD_BTN_DEFAULT_HTML = `
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-3.5 h-3.5">
          <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 3h1.386c.51 0 .955.343 1.087.835l.383 1.437M7.5 14.25a3 3 0 0 0-3 3h15.75m-12.75-3h11.218c1.121-2.3 2.1-4.684 2.924-7.138a60.114 60.114 0 0 0-16.536-1.84M7.5 14.25 5.106 5.272M6 20.25a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Zm12.75 0a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Z" />
        </svg>
        Add to Cart
      `;

        const ADD_BTN_ADDED_HTML = `
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-3.5 h-3.5">
          <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
        </svg>
        Added
      `;

        const ADD_BTN_UNAVAILABLE_HTML = `Unavailable`;

        const HEART_FILLED_PATH =
            "M21 8.25c0-2.485-2.099-4.5-4.688-4.5-1.935 0-3.597 1.126-4.312 2.733-.715-1.607-2.377-2.733-4.313-2.733C5.1 3.75 3 5.765 3 8.25c0 7.22 9 12 9 12s9-4.78 9-12Z";

        function escapeHtml(str) {
            if (!str) return "";
            return String(str)
                .replace(/&/g, "&amp;")
                .replace(/</g, "&lt;")
                .replace(/>/g, "&gt;")
                .replace(/"/g, "&quot;")
                .replace(/'/g, "&#039;");
        }

        async function postAction(action, data = {}) {
            const formData = new FormData();
            formData.append("action", action);
            for (const key in data) {
                const val = data[key];
                formData.append(key, val === null || val === undefined ? "" : val);
            }
            try {
                const response = await fetch(window.location.href, {
                    method: "POST",
                    body: formData,
                });
                return await response.json();
            } catch (err) {
                return {
                    success: false,
                    message: "Something went wrong. Please try again."
                };
            }
        }

        function buildFavoriteCardHTML(item) {
            return `
          <div class="bg-white border border-gray-200 overflow-hidden hover:shadow-md transition-all shadow-sm rounded-md item-card-enter" data-card-id="${item.menu_item_id}">
            <div class="w-full h-32 bg-gray-100 overflow-hidden relative">
              ${item.image ? `<img src="${escapeHtml(item.image)}" alt="${escapeHtml(item.item_name)}" class="w-full h-full object-cover item-image-fade" loading="lazy" onload="this.classList.add('loaded')" />` : ""}
              <button type="button" data-item-id="${item.menu_item_id}" class="favorite-btn absolute top-1.5 right-1.5 w-7 h-7 bg-white/90 hover:bg-white flex items-center justify-center shadow-sm transition-all rounded-full">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" fill="currentColor" class="w-4 h-4 text-red-500 transition-colors">
                  <path stroke-linecap="round" stroke-linejoin="round" d="${HEART_FILLED_PATH}" />
                </svg>
              </button>
              ${!item.is_available ? `<div class="absolute inset-0 bg-white/60 flex items-center justify-center"><span class="text-[10px] font-semibold text-gray-600 bg-white px-2 py-1 rounded-[3px]">Unavailable</span></div>` : ""}
            </div>
            <div class="p-3">
              <div class="flex items-start justify-between gap-2 mb-1">
                <div class="flex-1 min-w-0">
                  <h3 class="text-sm font-semibold text-gray-900 truncate">${escapeHtml(item.item_name)}</h3>
                  <p class="text-xs text-gray-500 mt-0.5 truncate">${escapeHtml(item.stall_name)}</p>
                </div>
                <span class="text-base font-bold text-emerald-600 shrink-0">&#8369;${item.price.toFixed(0)}</span>
              </div>
              <button type="button" data-item-id="${item.menu_item_id}" ${item.is_available ? "" : "disabled"} class="w-full mt-2 py-2 ${item.is_available ? "bg-emerald-600 hover:bg-emerald-700" : "bg-gray-300 cursor-not-allowed"} text-white text-xs font-medium flex items-center justify-center gap-1 transition-all add-btn rounded-[3px]">
                ${item.is_available ? ADD_BTN_DEFAULT_HTML : ADD_BTN_UNAVAILABLE_HTML}
              </button>
            </div>
          </div>
        `;
        }

        function attachFavoriteCardListeners(grid) {
            grid.querySelectorAll(".favorite-btn").forEach((btn) => {
                btn.addEventListener("click", async function() {
                    if (this.disabled) return;
                    this.disabled = true;

                    const menuItemId = parseInt(this.getAttribute("data-item-id"));
                    const card = grid.querySelector(`[data-card-id="${menuItemId}"]`);

                    const res = await postAction("toggle_favorite", {
                        menu_item_id: menuItemId
                    });

                    if (res.success) {
                        if (card) {
                            card.classList.add("card-removing");
                            setTimeout(() => {
                                favoriteItems = favoriteItems.filter((i) => i.menu_item_id !== menuItemId);
                                renderFavorites();
                            }, 200);
                        }
                    } else {
                        this.disabled = false;
                    }
                });
            });

            grid.querySelectorAll(".add-btn").forEach((btn) => {
                btn.addEventListener("click", async function() {
                    if (this.disabled) return;
                    this.disabled = true;

                    const menuItemId = this.getAttribute("data-item-id");

                    const res = await postAction("add_to_cart", {
                        menu_item_id: menuItemId
                    });

                    if (res.success) {
                        this.classList.add("added-state");
                        this.innerHTML = ADD_BTN_ADDED_HTML;
                        setTimeout(() => {
                            this.innerHTML = ADD_BTN_DEFAULT_HTML;
                            this.classList.remove("added-state");
                            this.disabled = false;
                        }, 1500);
                    } else {
                        this.disabled = false;
                    }
                });
            });
        }

        function renderFavorites() {
            const emptyDiv = document.getElementById("empty-favorites");
            const contentDiv = document.getElementById("favorites-content");
            const grid = document.getElementById("favoritesGrid");

            if (favoriteItems.length === 0) {
                emptyDiv.classList.remove("hidden");
                contentDiv.classList.add("hidden");
                return;
            }

            emptyDiv.classList.add("hidden");
            contentDiv.classList.remove("hidden");

            grid.innerHTML = favoriteItems.map(buildFavoriteCardHTML).join("");
            attachFavoriteCardListeners(grid);
        }

        function loadFavorites() {
            document.getElementById("favoritesSkeleton").classList.add("hidden");
            renderFavorites();
        }

        function openClearFavoritesModal() {
            document.getElementById("clearFavoritesModal").classList.remove("hidden");
            document.body.style.overflow = "hidden";
        }

        function closeClearFavoritesModal() {
            document.getElementById("clearFavoritesModal").classList.add("hidden");
            document.body.style.overflow = "";
        }

        function setupClearFavorites() {
            document.getElementById("clearFavoritesBtn").addEventListener("click", () => {
                if (favoriteItems.length > 0) {
                    openClearFavoritesModal();
                }
            });

            document.getElementById("closeClearFavoritesOverlay").addEventListener("click", closeClearFavoritesModal);
            document.getElementById("clearFavoritesKeepBtn").addEventListener("click", closeClearFavoritesModal);

            document.getElementById("clearFavoritesConfirmBtn").addEventListener("click", async () => {
                const confirmBtn = document.getElementById("clearFavoritesConfirmBtn");
                confirmBtn.disabled = true;
                const res = await postAction("clear_favorites");
                confirmBtn.disabled = false;
                if (res.success) {
                    favoriteItems = [];
                    renderFavorites();
                    closeClearFavoritesModal();
                }
            });
        }

        function init() {
            setupClearFavorites();
            setTimeout(() => loadFavorites(), 200);
        }

        window.addEventListener("load", init);
    </script>
</body>

</html>