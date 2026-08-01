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

$stallsResult = $conn->query("SELECT stall_id, stall_name FROM stalls ORDER BY stall_name ASC");
$stalls = [];
while ($row = $stallsResult->fetch_assoc()) {
  $stalls[] = [
    'stall_id' => (int) $row['stall_id'],
    'stall_name' => $row['stall_name'],
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
  <link rel="icon" href="assets/images/nwssu-logo.png" type="image/png" />
  <link rel="manifest" href="manifest.json" />
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
  </style>
</head>

<body class="bg-white">
  <div class="flex flex-col h-screen">

    <div class="bg-white flex-shrink-0 fixed top-0 left-0 right-0 z-20">
      <div class="max-w-5xl mx-auto px-4 py-2 flex items-center justify-between gap-3">
        <div class="flex items-center gap-2.5 min-w-0">
          <img src="assets/images/nwssu-logo.png" alt="NWSSU Food Court" class="w-9 h-9 object-contain shrink-0" />
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

        <div class="bg-white border border-gray-200 p-3 shadow-sm rounded-md">
          <div class="flex gap-2">
            <div class="relative flex-1">
              <input
                type="text"
                id="searchInput"
                placeholder="Search foods..."
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
            <div class="relative">
              <button type="button" id="stallFilterBtn" class="h-full px-3.5 py-2 bg-white border border-gray-200 text-xs font-medium text-gray-700 hover:border-emerald-500 transition-all flex items-center gap-1.5 whitespace-nowrap focus:outline-none focus:border-emerald-600 rounded-[3px]">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M12 3c2.755 0 5.455.232 8.083.678.533.09.917.556.917 1.096v1.044a2.25 2.25 0 0 1-.659 1.591l-5.432 5.432a2.25 2.25 0 0 0-.659 1.591v2.927a2.25 2.25 0 0 1-1.244 2.013L9.75 21v-6.568a2.25 2.25 0 0 0-.659-1.591L3.659 7.409A2.25 2.25 0 0 1 3 5.818V4.774c0-.54.384-1.006.917-1.096A48.32 48.32 0 0 1 12 3Z" />
                </svg>
                <span class="text-xs">Stall</span>
              </button>
              <div id="stallFilterDropdown" class="absolute right-0 mt-2 w-48 bg-white border border-gray-200 shadow-lg z-30 hidden rounded-[3px]">
                <div class="p-3">
                  <h3 class="text-[11px] font-semibold text-gray-500 uppercase tracking-wide mb-2 flex items-center gap-1.5">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-3.5 h-3.5">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M12 3c2.755 0 5.455.232 8.083.678.533.09.917.556.917 1.096v1.044a2.25 2.25 0 0 1-.659 1.591l-5.432 5.432a2.25 2.25 0 0 0-.659 1.591v2.927a2.25 2.25 0 0 1-1.244 2.013L9.75 21v-6.568a2.25 2.25 0 0 0-.659-1.591L3.659 7.409A2.25 2.25 0 0 1 3 5.818V4.774c0-.54.384-1.006.917-1.096A48.32 48.32 0 0 1 12 3Z" />
                    </svg>
                    Filter by Stall
                  </h3>
                  <div class="space-y-0.5 max-h-60 overflow-y-auto">
                    <label class="flex items-center gap-2 cursor-pointer hover:bg-gray-50 px-2 py-1.5 rounded-[3px]">
                      <input type="checkbox" class="stall-filter-checkbox" value="all" checked style="width:1rem;height:1rem;cursor:pointer;accent-color:#059669;" />
                      <span class="text-xs text-gray-700">All Stalls</span>
                    </label>
                    <?php foreach ($stalls as $stall): ?>
                      <label class="flex items-center gap-2 cursor-pointer hover:bg-gray-50 px-2 py-1.5 rounded-[3px]">
                        <input type="checkbox" class="stall-filter-checkbox" value="<?php echo $stall['stall_id']; ?>" style="width:1rem;height:1rem;cursor:pointer;accent-color:#059669;" />
                        <span class="text-xs text-gray-700"><?php echo htmlspecialchars($stall['stall_name']); ?></span>
                      </label>
                    <?php endforeach; ?>
                  </div>
                  <button type="button" id="resetStallFilter" class="w-full mt-2 py-1.5 px-3 bg-white border border-gray-200 text-gray-700 text-xs font-medium hover:border-emerald-500 transition-colors flex items-center justify-center gap-1.5 rounded-[3px]">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-3.5 h-3.5">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99" />
                    </svg>
                    Reset
                  </button>
                </div>
              </div>
            </div>
          </div>
          <div class="flex justify-end gap-2 mt-2">
            <div class="relative inline-block">
              <select
                id="sortSelect"
                class="pl-2.5 pr-6 py-2 bg-white border border-gray-200 text-xs font-normal text-gray-700 focus:outline-none focus:border-emerald-600 appearance-none cursor-pointer rounded-[3px]">
                <option value="newest">Newest</option>
                <option value="price_low">Price: Low to High</option>
                <option value="price_high">Price: High to Low</option>
                <option value="name_az">Name: A-Z</option>
                <option value="name_za">Name: Z-A</option>
              </select>
              <span id="sortSelectMeasure" class="text-xs font-normal" style="position: absolute; visibility: hidden; white-space: pre; left: -9999px; top: -9999px;"></span>
              <script>
                function updateSortSelectWidth() {
                  const sortSelectEl = document.getElementById("sortSelect");
                  const sortSelectMeasureEl = document.getElementById("sortSelectMeasure");
                  if (!sortSelectEl || !sortSelectMeasureEl) return;
                  const selectedText = sortSelectEl.options[sortSelectEl.selectedIndex].text;
                  sortSelectMeasureEl.textContent = selectedText;
                  const textWidth = sortSelectMeasureEl.offsetWidth;
                  sortSelectEl.style.width = (textWidth + 38) + "px";
                }
                updateSortSelectWidth();
              </script>
              <div class="absolute right-2 top-1/2 -translate-y-1/2 text-gray-400 pointer-events-none">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4">
                  <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                </svg>
              </div>
            </div>
          </div>
        </div>

        <div class="bg-white border border-gray-200 p-3 shadow-sm rounded-md">
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
                  <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6a2.25 2.25 0 0 0-2.25 2.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15m3 0 3-3m0 0-3-3m3 3H9" />
                </svg>
                Login to Order
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
            window.location.href = "auth/login.php";
          });
        }
      });
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
      const stallFilterBtn = document.getElementById("stallFilterBtn");
      const stallFilterDropdown = document.getElementById("stallFilterDropdown");

      stallFilterBtn.addEventListener("click", function() {
        stallFilterDropdown.classList.toggle("hidden");
        const isOpen = !stallFilterDropdown.classList.contains("hidden");
        stallFilterBtn.classList.toggle("border-emerald-600", isOpen);
        stallFilterBtn.classList.toggle("text-emerald-700", isOpen);
      });

      document.addEventListener("click", function(e) {
        if (!e.target.closest("#stallFilterBtn") && !e.target.closest("#stallFilterDropdown")) {
          stallFilterDropdown.classList.add("hidden");
          stallFilterBtn.classList.remove("border-emerald-600", "text-emerald-700");
        }
      });

      const stallCheckboxes = document.querySelectorAll(".stall-filter-checkbox");
      const allStallCheckbox = document.querySelector('.stall-filter-checkbox[value="all"]');
      const specificStallCheckboxes = document.querySelectorAll('.stall-filter-checkbox:not([value="all"])');

      allStallCheckbox.addEventListener("change", function() {
        specificStallCheckboxes.forEach(cb => cb.checked = this.checked);
        applyFilters();
      });

      specificStallCheckboxes.forEach(cb => {
        cb.addEventListener("change", function() {
          const allChecked = Array.from(specificStallCheckboxes).every(c => c.checked);
          const someChecked = Array.from(specificStallCheckboxes).some(c => c.checked);
          allStallCheckbox.checked = allChecked;
          if (!someChecked) allStallCheckbox.checked = true;
          applyFilters();
        });
      });

      document.getElementById("resetStallFilter").addEventListener("click", function() {
        stallCheckboxes.forEach(cb => cb.checked = cb.value === "all");
        applyFilters();
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

      const sortSelect = document.getElementById("sortSelect");

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

      sortSelect.addEventListener("change", function() {
        updateSortSelectWidth();
        applyFilters();
      });

      function applyFilters() {
        const query = searchInput.value.toLowerCase().trim();
        const selectedStalls = Array.from(stallCheckboxes)
          .filter(cb => cb.checked && cb.value !== "all")
          .map(cb => parseInt(cb.value));
        const allStallsSelected = allStallCheckbox.checked && selectedStalls.length === 0;
        const activeCategoryBtn = document.querySelector(".category-active-custom");
        const activeCategoryId = activeCategoryBtn ? activeCategoryBtn.getAttribute("data-cat-id") : "all";

        const filtered = ALL_MENU_ITEMS.filter(item => {
          const nameMatch = query === "" || item.item_name.toLowerCase().includes(query);
          const categoryMatch = activeCategoryId === "all" || item.category_id === parseInt(activeCategoryId);
          const stallMatch = allStallsSelected || selectedStalls.includes(item.stall_id);
          return nameMatch && categoryMatch && stallMatch;
        });

        const sorted = sortItems(filtered, sortSelect.value);

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