<?php
session_start();
require_once 'config/database.php';

if (isset($_SESSION['user_type'])) {
  switch ($_SESSION['user_type']) {
    case 'admin':
      header('Location: admin/dashboard.php');
      exit;
    case 'stall_owner':
      header('Location: stall/dashboard.php');
      exit;
    case 'delivery_staff':
      header('Location: delivery/dashboard.php');
      exit;
    case 'customer':
      header('Location: customer/home.php');
      exit;
  }
}

$categoriesResult = $conn->query("SELECT category_id, category_name FROM categories WHERE status = 'active' ORDER BY category_name ASC");
$categories = [];
while ($row = $categoriesResult->fetch_assoc()) {
  $categories[] = [
    'category_id' => (int) $row['category_id'],
    'category_name' => $row['category_name'],
  ];
}

$menuItemsResult = $conn->query("
    SELECT mi.menu_item_id, mi.item_name, mi.price, mi.image, mi.stall_id, s.stall_name, mi.category_id, c.category_name
    FROM menu_items mi
    JOIN stalls s ON mi.stall_id = s.stall_id
    JOIN categories c ON mi.category_id = c.category_id
    WHERE mi.status = 'available' AND s.status = 'open' AND c.status = 'active' AND mi.owner_id = s.owner_id
    ORDER BY mi.created_at DESC
");
$menuItems = [];
while ($row = $menuItemsResult->fetch_assoc()) {
  $menuItems[] = [
    'menu_item_id' => (int) $row['menu_item_id'],
    'item_name' => $row['item_name'],
    'price' => (float) $row['price'],
    'image' => $row['image'] ? $row['image'] : null,
    'stall_id' => (int) $row['stall_id'],
    'stall_name' => $row['stall_name'],
    'category_id' => (int) $row['category_id'],
    'category_name' => $row['category_name'],
  ];
}

$conn->close();
?>
<!doctype html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>NWSSU Food Court - Browse Menu</title>
  <link rel="icon" href="assets/images/nwssu-logo.svg" type="image/svg+xml" />
  <link rel="manifest" href="manifest.json" />
  <meta name="apple-mobile-web-app-capable" content="yes" />
  <meta name="apple-mobile-web-app-status-bar-style" content="default" />
  <meta name="apple-mobile-web-app-title" content="Norwesso Eats" />
  <link rel="apple-touch-icon" href="assets/images/icon-192.png" />
  <link href="assets/css/tailwind.css" rel="stylesheet" />
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

    .no-scrollbar::-webkit-scrollbar {
      display: none;
    }

    .no-scrollbar {
      -ms-overflow-style: none;
      scrollbar-width: none;
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

    .category-active-custom {
      background: linear-gradient(135deg, #059669 0%, #047857 100%);
      border-color: #059669;
      color: white;
    }

    input:focus {
      outline: none;
      border-color: #059669;
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
      <div class="max-w-5xl mx-auto px-4 py-2 flex items-center justify-between gap-3">
        <div class="flex items-center gap-2.5 min-w-0">
          <img src="assets/images/nwssu-logo.svg" alt="NWSSU Food Court" class="w-9 h-9 object-contain shrink-0" />
          <div class="min-w-0">
            <p class="text-sm font-bold text-gray-800 leading-tight truncate">
              NWSSU <span class="text-emerald-600">Food Court</span>
            </p>
            <p class="text-[10px] text-gray-400 leading-none mt-0.5 truncate">
              Browse the menu — sign in to order
            </p>
          </div>
        </div>
        <a
          href="auth/login.php"
          class="shrink-0 px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold transition-colors rounded-[3px] flex items-center gap-1.5">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4">
            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6a2.25 2.25 0 0 0-2.25 2.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15m3 0 3-3m0 0-3-3m3 3H9" />
          </svg>
          Login
        </a>
      </div>
    </div>

    <div class="flex-1 overflow-y-auto mt-12" id="mainContent">
      <div class="max-w-5xl mx-auto px-4 pt-3 pb-6 space-y-3">

        <div class="bg-white border border-gray-200 p-3 rounded-md">
          <div class="flex gap-2">
            <div class="relative flex-1">
              <input
                type="text"
                id="searchInput"
                placeholder="Search foods or stalls..."
                class="w-full px-4 py-2 pl-10 pr-10 bg-white border border-gray-200 text-xs text-gray-900 placeholder-gray-400 transition-all focus:outline-none focus:border-emerald-600 rounded-[3px]" />
              <div class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4">
                  <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
                </svg>
              </div>
              <button type="button" id="clearSearch" class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-700 transition-colors hidden items-center justify-center rounded-[3px]">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                </svg>
              </button>
            </div>
            <button type="button" id="openFilterBtn" class="relative h-full px-3 py-2 bg-white border border-gray-200 text-gray-700 flex items-center justify-center focus:outline-none focus:border-emerald-600 rounded-[3px]">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4">
                <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 6h9.75M10.5 6a1.5 1.5 0 1 1-3 0m3 0a1.5 1.5 0 1 0-3 0M3.75 6H7.5m3 12h9.75m-9.75 0a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m-3.75 0H7.5m9-6h3.75m-3.75 0a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m-9.75 0h9.75" />
              </svg>
            </button>
          </div>
        </div>

        <div class="bg-white border border-gray-200 p-3 rounded-md">
          <div class="flex gap-2 overflow-x-auto no-scrollbar">
            <button class="category-btn category-active-custom px-4 py-2 border border-gray-200 bg-white flex-shrink-0 text-xs font-semibold whitespace-nowrap rounded-[3px]" data-cat-id="all">All</button>
            <?php foreach ($categories as $cat): ?>
              <button class="category-btn px-4 py-2 border border-gray-200 bg-white text-gray-500 hover:border-emerald-500 hover:text-emerald-600 hover:bg-emerald-50 flex-shrink-0 text-xs font-semibold whitespace-nowrap rounded-[3px]" data-cat-id="<?php echo $cat['category_id']; ?>"><?php echo htmlspecialchars($cat['category_name']); ?></button>
            <?php endforeach; ?>
          </div>
        </div>

        <div id="featured-skeleton">
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

        <div class="hidden" id="featured-content">
          <div id="menuItemsGrid" class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-2.5"></div>
          <div class="flex justify-center mt-4" id="loadMoreWrapper">
            <button type="button" id="loadMoreBtn" class="px-6 py-2.5 bg-white border border-gray-200 text-gray-700 text-xs font-semibold hover:border-emerald-500 hover:text-emerald-600 transition-all rounded-[3px]">
              Load More
            </button>
          </div>
        </div>

        <div class="hidden" id="no-results">
          <div class="flex flex-col items-center justify-center py-16 text-center">
            <div class="w-24 h-24 bg-gray-100 flex items-center justify-center mb-4 rounded-[3px]">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-10 h-10 text-gray-400">
                <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
              </svg>
            </div>
            <p class="text-base font-semibold text-gray-800">No foods found</p>
            <p class="text-gray-500 text-sm mt-1">Try searching with different keywords</p>
          </div>
        </div>

      </div>
    </div>

  </div>

  <div
    id="signInRequiredModal"
    class="fixed inset-0 z-50 hidden flex items-center justify-center px-4">
    <div class="modal-overlay absolute inset-0" id="closeSignInRequiredOverlay"></div>
    <div
      class="bg-white w-full max-w-sm relative z-10 shadow-2xl p-5 space-y-4 text-center rounded-md">
      <div class="w-12 h-12 bg-emerald-50 flex items-center justify-center mx-auto rounded-full">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6 text-emerald-600">
          <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" />
        </svg>
      </div>
      <div>
        <p class="text-sm font-bold text-gray-800">Sign In Required</p>
        <p class="text-xs text-gray-500 mt-1">Please sign in or create an account to add items to your cart and place an order.</p>
      </div>
      <div class="flex gap-2 pt-1">
        <button type="button" id="closeSignInRequiredBtn" class="flex-1 py-2.5 border border-gray-200 text-gray-700 text-xs font-semibold hover:bg-gray-50 transition-colors rounded-[3px]">
          Maybe Later
        </button>
        <a href="auth/login.php" class="flex-1 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold transition-colors rounded-[3px] flex items-center justify-center">
          Sign In
        </a>
      </div>
    </div>
  </div>

  <div
    id="filterModal"
    class="fixed inset-0 z-50 hidden items-end sm:items-center justify-center">
    <div class="modal-overlay absolute inset-0" id="closeFilterOverlay"></div>
    <div class="bg-white w-full sm:max-w-md relative z-10 shadow-2xl max-h-[85vh] flex flex-col rounded-t-2xl sm:rounded-md">
      <div class="p-4 border-b border-gray-100 flex items-center justify-between shrink-0">
        <h2 class="font-bold text-gray-800 text-sm">Sort By</h2>
        <button id="closeFilterBtn" class="p-1 hover:bg-gray-100 rounded-[3px]">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 text-gray-500">
            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
          </svg>
        </button>
      </div>

      <div class="p-4 flex flex-col gap-2 overflow-y-auto">
        <button type="button" class="sort-option-btn category-active-custom px-3 py-2.5 border border-gray-200 bg-white text-xs font-semibold text-gray-700 text-left rounded-[3px]" data-sort="newest">Newest</button>
        <button type="button" class="sort-option-btn px-3 py-2.5 border border-gray-200 bg-white text-xs font-semibold text-gray-700 text-left hover:border-emerald-500 hover:text-emerald-600 hover:bg-emerald-50 rounded-[3px]" data-sort="price_low">Price: Low to High</button>
        <button type="button" class="sort-option-btn px-3 py-2.5 border border-gray-200 bg-white text-xs font-semibold text-gray-700 text-left hover:border-emerald-500 hover:text-emerald-600 hover:bg-emerald-50 rounded-[3px]" data-sort="price_high">Price: High to Low</button>
        <button type="button" class="sort-option-btn px-3 py-2.5 border border-gray-200 bg-white text-xs font-semibold text-gray-700 text-left hover:border-emerald-500 hover:text-emerald-600 hover:bg-emerald-50 rounded-[3px]" data-sort="name_az">Name: A-Z</button>
        <button type="button" class="sort-option-btn px-3 py-2.5 border border-gray-200 bg-white text-xs font-semibold text-gray-700 text-left hover:border-emerald-500 hover:text-emerald-600 hover:bg-emerald-50 rounded-[3px]" data-sort="name_za">Name: Z-A</button>
      </div>

      <div class="p-4 border-t border-gray-100 flex gap-2 shrink-0">
        <button type="button" id="resetFilterBtn" class="flex-1 py-2.5 border border-gray-200 text-gray-700 text-xs font-semibold hover:bg-gray-50 transition-colors rounded-[3px]">
          Reset
        </button>
        <button type="button" id="applyFilterBtn" class="flex-1 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold transition-colors rounded-[3px]">
          Apply
        </button>
      </div>
    </div>
  </div>

  <script>
    const ALL_MENU_ITEMS = <?php echo json_encode($menuItems); ?>;

    function escapeHtml(str) {
      if (!str) return "";
      return String(str)
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
    }

    const PAGE_SIZE = 12;
    let currentFilteredItems = [];
    let displayedCount = 0;

    function buildItemCardHTML(item) {
      return `
          <div class="bg-white border border-gray-200 overflow-hidden hover:shadow-md transition-all shadow-sm rounded-md item-card-enter">
            <div class="w-full h-32 bg-gray-100 overflow-hidden relative">
              ${item.image ? `<img src="${escapeHtml(item.image)}" alt="${escapeHtml(item.item_name)}" class="w-full h-full object-cover item-image-fade" loading="lazy" onload="this.classList.add('loaded')" />` : ""}
            </div>
            <div class="p-3">
              <div class="flex items-start justify-between gap-2 mb-1">
                <div class="flex-1 min-w-0">
                  <h3 class="text-sm font-semibold text-gray-900 truncate">${escapeHtml(item.item_name)}</h3>
                  <p class="text-xs text-gray-500 mt-0.5 truncate">${escapeHtml(item.stall_name)}</p>
                </div>
                <span class="text-base font-bold text-emerald-600 shrink-0">&#8369;${item.price.toFixed(0)}</span>
              </div>
              <button type="button" class="w-full mt-2 py-2 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-medium flex items-center justify-center gap-1 transition-all add-btn rounded-[3px]">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-3.5 h-3.5">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 3h1.386c.51 0 .955.343 1.087.836l.383 1.437M7.5 14.25a3 3 0 0 0-3 3h15.75m-12.75-3h11.218c1.121-2.3 1.994-4.694 2.602-7.152.126-.51-.26-1.006-.786-1.006H5.106M7.5 14.25 5.106 5.272M6 20.25a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Zm12.75 0a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Z" />
                </svg>
                Add to Cart
              </button>
            </div>
          </div>
        `;
    }

    function attachCardListeners(cardElements) {
      cardElements.forEach((card) => {
        const addBtn = card.querySelector(".add-btn");
        if (addBtn) {
          addBtn.addEventListener("click", function() {
            openSignInRequiredModal();
          });
        }
      });
    }

    function openSignInRequiredModal() {
      document.getElementById("signInRequiredModal").classList.remove("hidden");
      document.body.style.overflow = "hidden";
    }

    function closeSignInRequiredModal() {
      document.getElementById("signInRequiredModal").classList.add("hidden");
      document.body.style.overflow = "";
    }

    function setupSignInRequiredModal() {
      document.getElementById("closeSignInRequiredBtn").addEventListener("click", closeSignInRequiredModal);
      document.getElementById("closeSignInRequiredOverlay").addEventListener("click", closeSignInRequiredModal);
    }

    function updateLoadMoreVisibility() {
      const wrapper = document.getElementById("loadMoreWrapper");
      const hasMore = displayedCount < currentFilteredItems.length;
      wrapper.classList.toggle("hidden", !hasMore);
    }

    function renderMenuItems(items) {
      const grid = document.getElementById("menuItemsGrid");
      currentFilteredItems = items;
      displayedCount = Math.min(PAGE_SIZE, items.length);

      grid.innerHTML = items.slice(0, displayedCount).map(buildItemCardHTML).join("");
      attachCardListeners(Array.from(grid.children));
      updateLoadMoreVisibility();
    }

    function appendMoreItems() {
      const grid = document.getElementById("menuItemsGrid");
      const previousChildCount = grid.children.length;
      const nextSlice = currentFilteredItems.slice(displayedCount, displayedCount + PAGE_SIZE);

      grid.insertAdjacentHTML("beforeend", nextSlice.map(buildItemCardHTML).join(""));
      displayedCount += nextSlice.length;

      const newCards = Array.from(grid.children).slice(previousChildCount);
      attachCardListeners(newCards);
      updateLoadMoreVisibility();
    }

    function loadContent() {
      document.getElementById("featured-skeleton").classList.add("hidden");
      document.getElementById("featured-content").classList.remove("hidden");
      initializeEventListeners();
    }

    function initializeEventListeners() {
      setupSignInRequiredModal();

      const filterModal = document.getElementById("filterModal");
      const openFilterBtn = document.getElementById("openFilterBtn");
      const closeFilterBtn = document.getElementById("closeFilterBtn");
      const closeFilterOverlay = document.getElementById("closeFilterOverlay");
      const applyFilterBtn = document.getElementById("applyFilterBtn");
      const resetFilterBtn = document.getElementById("resetFilterBtn");
      const sortOptionBtns = document.querySelectorAll(".sort-option-btn");

      function openFilterModal() {
        filterModal.classList.remove("hidden");
        filterModal.classList.add("flex");
        document.body.style.overflow = "hidden";
      }

      function closeFilterModal() {
        filterModal.classList.add("hidden");
        filterModal.classList.remove("flex");
        document.body.style.overflow = "";
      }

      function setActiveSortBtn(activeBtn) {
        sortOptionBtns.forEach((btn) => {
          btn.classList.remove("category-active-custom");
          btn.classList.add("bg-white", "border-gray-200", "text-gray-700", "hover:border-emerald-500", "hover:text-emerald-600", "hover:bg-emerald-50");
        });
        activeBtn.classList.add("category-active-custom");
        activeBtn.classList.remove("bg-white", "border-gray-200", "text-gray-700", "hover:border-emerald-500", "hover:text-emerald-600", "hover:bg-emerald-50");
      }

      openFilterBtn.addEventListener("click", openFilterModal);
      closeFilterBtn.addEventListener("click", closeFilterModal);
      closeFilterOverlay.addEventListener("click", closeFilterModal);

      sortOptionBtns.forEach((btn) => {
        btn.addEventListener("click", function() {
          setActiveSortBtn(this);
        });
      });

      applyFilterBtn.addEventListener("click", function() {
        applyFilters();
        closeFilterModal();
      });

      resetFilterBtn.addEventListener("click", function() {
        setActiveSortBtn(document.querySelector('.sort-option-btn[data-sort="newest"]'));
        applyFilters();
        closeFilterModal();
      });

      const categoryBtns = document.querySelectorAll(".category-btn");

      function setActiveCategory(activeBtn) {
        categoryBtns.forEach(btn => {
          btn.classList.remove("category-active-custom");
          btn.classList.add("bg-white", "border-gray-200", "text-gray-500", "hover:border-emerald-500", "hover:text-emerald-600", "hover:bg-emerald-50");
        });
        activeBtn.classList.add("category-active-custom");
        activeBtn.classList.remove("bg-white", "border-gray-200", "text-gray-500", "hover:border-emerald-500", "hover:text-emerald-600", "hover:bg-emerald-50");
      }

      categoryBtns.forEach(btn => {
        btn.addEventListener("click", function() {
          setActiveCategory(this);
          applyFilters();
        });
      });

      const searchInput = document.getElementById("searchInput");
      const clearSearchBtn = document.getElementById("clearSearch");
      let debounceTimer;

      searchInput.addEventListener("input", function() {
        const hasVal = this.value.length > 0;
        clearSearchBtn.classList.toggle("hidden", !hasVal);
        clearSearchBtn.classList.toggle("flex", hasVal);
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(() => applyFilters(), 300);
      });

      clearSearchBtn.addEventListener("click", function(e) {
        e.preventDefault();
        searchInput.value = "";
        clearSearchBtn.classList.add("hidden");
        clearSearchBtn.classList.remove("flex");
        searchInput.focus();
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(() => applyFilters(), 300);
      });

      function sortItems(items, sortBy) {
        const sorted = [...items];
        if (sortBy === "price_low") {
          sorted.sort((a, b) => a.price - b.price);
        } else if (sortBy === "price_high") {
          sorted.sort((a, b) => b.price - a.price);
        } else if (sortBy === "name_az") {
          sorted.sort((a, b) => a.item_name.localeCompare(b.item_name));
        } else if (sortBy === "name_za") {
          sorted.sort((a, b) => b.item_name.localeCompare(a.item_name));
        }
        return sorted;
      }

      function applyFilters() {
        const query = searchInput.value.toLowerCase().trim();
        const activeCategoryBtn = document.querySelector(".category-btn.category-active-custom");
        const activeCategoryId = activeCategoryBtn ? activeCategoryBtn.getAttribute("data-cat-id") : "all";
        const activeSortBtn = document.querySelector(".sort-option-btn.category-active-custom");
        const sortValue = activeSortBtn ? activeSortBtn.getAttribute("data-sort") : "newest";

        const filtered = ALL_MENU_ITEMS.filter(item => {
          const nameMatch = query === "" ||
            item.item_name.toLowerCase().includes(query) ||
            item.stall_name.toLowerCase().includes(query);
          const categoryMatch = activeCategoryId === "all" || item.category_id === parseInt(activeCategoryId);
          return nameMatch && categoryMatch;
        });

        const sorted = sortItems(filtered, sortValue);

        renderMenuItems(sorted);

        const showNoResults = sorted.length === 0;
        document.getElementById("no-results").classList.toggle("hidden", !showNoResults);
        document.getElementById("featured-content").classList.toggle("hidden", showNoResults);
      }

      applyFilters();

      document.getElementById("loadMoreBtn").addEventListener("click", function() {
        appendMoreItems();
      });
    }

    window.addEventListener("load", function() {
      setTimeout(() => loadContent(), 200);
    });
  </script>
</body>

</html>