<!doctype html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta
    name="viewport"
    content="width=device-width, initial-scale=1.0, user-scalable=yes" />
  <title>Customer - Chats</title>
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

    .scroll-area::-webkit-scrollbar {
      width: 5px;
    }

    .scroll-area::-webkit-scrollbar-track {
      background: #e2e8f0;
      border-radius: 3px;
    }

    .scroll-area::-webkit-scrollbar-thumb {
      background: #059669;
      border-radius: 3px;
    }

    input:focus,
    textarea:focus {
      outline: none;
      border-color: #059669 !important;
    }

    @media (max-width: 1023.98px) {
      .mobile-hide {
        display: none !important;
      }

      #chatThreadPanel.mobile-fullscreen-active {
        position: fixed;
        inset: 0;
        height: 100vh;
        height: 100dvh;
        z-index: 30;
        background: #ffffff;
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
          class="p-1.5 bg-white border border-gray-200 hover:border-emerald-500 hover:bg-slate-50 transition-all justify-self-start flex items-center justify-center shrink-0"
          style="width: 34px; height: 34px; border-radius: 6px">
          <svg
            xmlns="http://www.w3.org/2000/svg"
            fill="none"
            viewBox="0 0 24 24"
            stroke-width="1.5"
            stroke="currentColor"
            class="w-5 h-5 text-gray-600">
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              d="M15.75 19.5 8.25 12l7.5-7.5" />
          </svg>
        </button>
        <h1 class="text-base font-semibold text-emerald-600 text-center">
          Chats
        </h1>
        <div class="justify-self-end" style="width: 34px"></div>
      </div>
    </div>

    <div class="flex-1 overflow-hidden mt-12 mb-16" id="mainContent">
      <div class="max-w-5xl mx-auto h-full flex flex-col lg:flex-row">
        <div
          id="chatListPanel"
          class="w-full lg:w-[340px] lg:border-r lg:border-gray-200 flex flex-col h-full shrink-0 px-4 pt-3 pb-4">
          <div class="bg-white border border-gray-200 shadow-sm rounded-md flex flex-col flex-1 min-h-0 overflow-hidden">
            <div class="p-3 border-b border-gray-100 shrink-0">
              <div class="relative">
                <input
                  type="text"
                  id="searchConversations"
                  placeholder="Search conversations..."
                  class="w-full pl-9 pr-9 py-2 bg-white border border-gray-200 text-xs text-gray-900 placeholder-gray-400 focus:outline-none focus:border-emerald-600"
                  style="border-radius: 3px" />
                <div class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400">
                  <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
                  </svg>
                </div>
                <button
                  type="button"
                  id="clearSearchBtn"
                  class="hidden absolute right-2.5 top-1/2 -translate-y-1/2 p-0.5 text-gray-400 hover:text-gray-600 transition-colors"
                  style="border-radius: 3px">
                  <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-4 h-4">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                  </svg>
                </button>
              </div>
            </div>
            <div class="flex-1 overflow-y-auto scroll-area" id="conversationList"></div>
            <div id="conversationEmpty" class="hidden flex-1 flex-col items-center justify-center text-center px-4">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-10 h-10 text-gray-300 mb-2">
                <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
              </svg>
              <p class="text-sm font-semibold text-gray-500">No conversations found</p>
              <p class="text-xs text-gray-400 mt-0.5">Try a different search</p>
            </div>
          </div>
        </div>

        <div
          id="chatThreadPanel"
          class="mobile-hide w-full flex-1 flex flex-col h-full lg:px-4 lg:pt-3 lg:pb-4">
          <div class="flex flex-col h-full min-h-0 lg:bg-white lg:border lg:border-gray-200 lg:shadow-sm lg:rounded-md lg:overflow-hidden">
            <div class="px-4 py-2 flex items-center gap-3 shrink-0">
              <button
                id="backToListBtn"
                class="lg:hidden p-1.5 bg-white border border-gray-200 hover:border-emerald-500 hover:bg-slate-50 transition-all flex items-center justify-center shrink-0"
                style="width: 34px; height: 34px; border-radius: 6px">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 text-gray-600">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" />
                </svg>
              </button>
              <div id="threadAvatar" class="w-8 h-8 flex items-center justify-center text-white text-xs font-bold shrink-0 rounded-full"></div>
              <div class="flex-1 min-w-0">
                <p id="threadName" class="text-sm font-semibold text-gray-800 truncate"></p>
              </div>
              <button id="callContactBtn" class="p-2 hover:bg-gray-100 transition-colors shrink-0" style="border-radius: 3px" title="Call">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 text-emerald-600">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 0 0 2.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 0 1-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 0 0-1.091-.852H4.5A2.25 2.25 0 0 0 2.25 4.5v2.25Z" />
                </svg>
              </button>
            </div>
            <div class="flex-1 overflow-y-auto scroll-area p-4 space-y-3" id="messagesContainer"></div>
            <div class="border-t border-gray-100 p-3 flex items-center gap-2 shrink-0">
              <input
                type="text"
                id="messageInput"
                placeholder="Type a message..."
                class="flex-1 px-3 py-2.5 bg-white border border-gray-200 text-xs text-gray-900 placeholder-gray-400 focus:outline-none focus:border-emerald-600"
                style="border-radius: 3px" />
              <button
                id="sendMessageBtn"
                class="w-9 h-9 bg-emerald-600 hover:bg-emerald-700 text-white flex items-center justify-center transition-colors shrink-0"
                style="border-radius: 3px">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M6 12 3.269 3.125A59.769 59.769 0 0 1 21.485 12 59.768 59.768 0 0 1 3.27 20.875L5.999 12Zm0 0h7.5" />
                </svg>
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="bg-white border-t border-gray-200 flex-shrink-0 fixed bottom-0 left-0 right-0 z-20">
      <div class="max-w-5xl mx-auto px-4 flex justify-around py-2">
        <a
          href="./home.php"
          class="flex flex-col items-center justify-center py-2 px-3 transition-all duration-200 group text-gray-500 hover:text-gray-900 hover:bg-gray-50"
          style="border-radius: 3px">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 transition-transform group-hover:scale-110">
            <path stroke-linecap="round" stroke-linejoin="round" d="m2.25 12 8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" />
          </svg>
          <span class="text-xs font-medium mt-1">Home</span>
        </a>
        <a
          href="./cart.php"
          class="flex flex-col items-center justify-center py-2 px-3 transition-all duration-200 group text-gray-500 hover:text-gray-900 hover:bg-gray-50 relative"
          style="border-radius: 3px">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 transition-transform group-hover:scale-110">
            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 10.5V6a3.75 3.75 0 1 0-7.5 0v4.5m11.356-1.993 1.263 12c.07.665-.45 1.243-1.119 1.243H4.25a1.125 1.125 0 0 1-1.12-1.243l1.264-12A1.125 1.125 0 0 1 5.513 7.5h12.974c.576 0 1.059.435 1.119 1.007ZM8.625 10.5a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm7.5 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z" />
          </svg>
          <span class="text-xs font-medium mt-1">Cart</span>
        </a>
        <a
          href="./order.php"
          class="flex flex-col items-center justify-center py-2 px-3 transition-all duration-200 group text-gray-500 hover:text-gray-900 hover:bg-gray-50"
          style="border-radius: 3px">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 transition-transform group-hover:scale-110">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 0 0 2.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 0 0-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 0 0 .75-.75 2.25 2.25 0 0 0-.1-.664m-5.8 0A2.251 2.251 0 0 1 13.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25Z" />
          </svg>
          <span class="text-xs font-medium mt-1">Orders</span>
        </a>
        <a
          href="./chat.php"
          class="flex flex-col items-center justify-center py-2 px-3 transition-all duration-200 group text-emerald-600 bg-emerald-50 relative"
          style="border-radius: 3px">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 transition-transform group-hover:scale-110">
            <path stroke-linecap="round" stroke-linejoin="round" d="M20.25 8.511c.884.284 1.5 1.128 1.5 2.097v4.286c0 1.136-.847 2.1-1.98 2.193-.34.027-.68.052-1.02.072v3.091l-3-3c-1.354 0-2.694-.055-4.02-.163a2.115 2.115 0 0 1-.825-.242m9.345-8.334a2.126 2.126 0 0 0-.476-.095 48.64 48.64 0 0 0-8.048 0c-1.131.094-1.976 1.057-1.976 2.192v4.286c0 .837.46 1.58 1.155 1.951m9.345-8.334V6.637c0-1.621-1.152-3.026-2.76-3.235A48.455 48.455 0 0 0 11.25 3c-2.115 0-4.198.137-6.24.402-1.608.209-2.76 1.614-2.76 3.235v6.226c0 1.621 1.152 3.026 2.76 3.235.577.075 1.157.14 1.74.194V21l4.155-4.155" />
          </svg>
          <span class="text-xs font-medium mt-1">Chats</span>
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

  <script>
    const TYPE_META = {
      stall: {
        badgeCls: "bg-emerald-50 text-emerald-700 border-emerald-200",
        avatarCls: "bg-gradient-to-br from-emerald-500 to-emerald-700"
      },
      delivery: {
        badgeCls: "bg-blue-50 text-blue-700 border-blue-200",
        avatarCls: "bg-gradient-to-br from-emerald-500 to-emerald-700"
      },
      admin: {
        badgeCls: "bg-purple-50 text-purple-700 border-purple-200",
        avatarCls: "bg-gradient-to-br from-emerald-500 to-emerald-700"
      },
    };

    let conversations = [{
        id: "c1",
        type: "stall",
        name: "Sheryl Caibog",
        subLabel: "Stall 1",
        phone: "+639123456789",
        unread: 1,
        messages: [{
            sender: "them",
            text: "Hi! Thanks for your order, we're preparing it now.",
            time: "10:02 AM"
          },
          {
            sender: "me",
            text: "Okay, please add extra rice if possible.",
            time: "10:03 AM"
          },
          {
            sender: "them",
            text: "Your order is being prepared!",
            time: "10:15 AM"
          },
        ],
      },
      {
        id: "c2",
        type: "stall",
        name: "Rina Baga",
        subLabel: "Stall 2",
        phone: "+639172345678",
        unread: 0,
        messages: [{
            sender: "them",
            text: "Your burger order is ready for pickup.",
            time: "Yesterday"
          },
          {
            sender: "me",
            text: "Thank you!",
            time: "Yesterday"
          },
          {
            sender: "them",
            text: "Thank you for ordering!",
            time: "Yesterday"
          },
        ],
      },
      {
        id: "c3",
        type: "delivery",
        name: "Jenuel Castillo",
        subLabel: "Delivery Staff",
        phone: "+639187654321",
        unread: 1,
        messages: [{
            sender: "them",
            text: "Hi, I have your order. Heading to CCIS now.",
            time: "11:20 AM"
          },
          {
            sender: "them",
            text: "I'm on my way to your location",
            time: "11:24 AM"
          },
        ],
      },
      {
        id: "c4",
        type: "delivery",
        name: "Jio Canaman",
        subLabel: "Delivery Staff",
        phone: "+639201112233",
        unread: 0,
        messages: [{
            sender: "them",
            text: "Order delivered, enjoy!",
            time: "Yesterday"
          },
          {
            sender: "me",
            text: "Got it, thank you so much!",
            time: "Yesterday"
          },
        ],
      },
      {
        id: "c5",
        type: "admin",
        name: "Food Court Support",
        subLabel: "Admin",
        phone: "+639000000000",
        unread: 1,
        messages: [{
            sender: "them",
            text: "Hello! Welcome to NWSSU Food Court.",
            time: "Mon"
          },
          {
            sender: "them",
            text: "How can we help you today?",
            time: "9:00 AM"
          },
        ],
      },
    ];

    let searchQuery = "";
    let activeConversationId = null;

    function escapeHtml(str) {
      if (!str) return "";
      return String(str)
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;");
    }

    function getInitials(name) {
      return name
        .split(" ")
        .filter(Boolean)
        .map((w) => w[0])
        .join("")
        .slice(0, 2)
        .toUpperCase();
    }

    function getConversation(id) {
      return conversations.find((c) => c.id === id);
    }

    function renderConversationList() {
      const listEl = document.getElementById("conversationList");
      const emptyEl = document.getElementById("conversationEmpty");
      const q = searchQuery.toLowerCase();

      const filtered = conversations.filter((c) => {
        return !q || c.name.toLowerCase().includes(q) || c.subLabel.toLowerCase().includes(q);
      });

      if (filtered.length === 0) {
        listEl.innerHTML = "";
        listEl.classList.add("hidden");
        emptyEl.classList.remove("hidden");
        emptyEl.classList.add("flex");
        return;
      }
      listEl.classList.remove("hidden");
      emptyEl.classList.add("hidden");
      emptyEl.classList.remove("flex");

      listEl.innerHTML = filtered
        .map((c) => {
          const meta = TYPE_META[c.type];
          const lastMsg = c.messages[c.messages.length - 1];
          const isActive = c.id === activeConversationId;
          return `
            <button class="conversation-row w-full flex items-center gap-3 px-4 py-3 hover:bg-gray-50 transition-colors text-left border-b border-gray-100 ${isActive ? "bg-emerald-50/60" : ""}" data-id="${c.id}">
              <div class="w-11 h-11 ${meta.avatarCls} flex items-center justify-center text-white text-xs font-bold shrink-0 rounded-full">${getInitials(c.name)}</div>
              <div class="flex-1 min-w-0">
                <div class="flex items-center justify-between gap-2">
                  <div class="flex items-center gap-1.5 min-w-0">
                    <p class="text-xs font-semibold text-gray-800 truncate">${escapeHtml(c.name)}</p>
                    <span class="text-[9px] font-semibold px-1.5 py-0.5 border shrink-0 ${meta.badgeCls}" style="border-radius:3px">${escapeHtml(c.subLabel)}</span>
                  </div>
                  <span class="text-[10px] text-gray-400 shrink-0">${escapeHtml(lastMsg ? lastMsg.time : "")}</span>
                </div>
                <div class="flex items-center justify-between gap-2 mt-0.5">
                  <p class="text-[11px] text-gray-400 truncate">${escapeHtml(lastMsg ? lastMsg.text : "No messages yet")}</p>
                  ${c.unread > 0 ? `<span class="text-[10px] font-bold px-1.5 py-0.5 bg-emerald-600 text-white shrink-0" style="border-radius:999px">${c.unread}</span>` : ""}
                </div>
              </div>
            </button>
          `;
        })
        .join("");

      listEl.querySelectorAll(".conversation-row").forEach((row) => {
        row.addEventListener("click", () => openConversation(row.getAttribute("data-id")));
      });
    }

    function renderThread() {
      const conv = getConversation(activeConversationId);
      if (!conv) return;
      const meta = TYPE_META[conv.type];

      const avatarEl = document.getElementById("threadAvatar");
      avatarEl.textContent = getInitials(conv.name);
      avatarEl.className = `w-8 h-8 ${meta.avatarCls} flex items-center justify-center text-white text-xs font-bold shrink-0 rounded-full`;

      document.getElementById("threadName").textContent = conv.name;

      document.getElementById("callContactBtn").setAttribute("data-phone", conv.phone || "");

      const container = document.getElementById("messagesContainer");
      if (conv.messages.length === 0) {
        container.innerHTML = `
            <div class="h-full flex flex-col items-center justify-center text-center py-10">
              <p class="text-sm font-semibold text-gray-500">No messages yet</p>
              <p class="text-xs text-gray-400 mt-1">Say hi to start the conversation</p>
            </div>
          `;
        return;
      }
      container.innerHTML = conv.messages
        .map((m) => {
          if (m.sender === "me") {
            return `
                <div class="flex justify-end">
                  <div class="max-w-[75%]">
                    <div class="bg-emerald-600 text-white text-xs px-3 py-2" style="border-radius:6px">${escapeHtml(m.text)}</div>
                    <p class="text-[10px] text-gray-400 mt-1 text-right">${escapeHtml(m.time)}</p>
                  </div>
                </div>
              `;
          }
          return `
              <div class="flex justify-start">
                <div class="max-w-[75%]">
                  <div class="bg-gray-100 text-gray-800 text-xs px-3 py-2" style="border-radius:6px">${escapeHtml(m.text)}</div>
                  <p class="text-[10px] text-gray-400 mt-1">${escapeHtml(m.time)}</p>
                </div>
              </div>
            `;
        })
        .join("");
      container.scrollTop = container.scrollHeight;
    }

    function openConversation(id) {
      activeConversationId = id;
      const conv = getConversation(id);
      if (conv) conv.unread = 0;
      renderConversationList();
      renderThread();

      document.getElementById("chatListPanel").classList.add("mobile-hide");
      document.getElementById("chatThreadPanel").classList.remove("mobile-hide");
      document.getElementById("chatThreadPanel").classList.add("mobile-fullscreen-active");
    }

    function closeThread() {
      document.getElementById("chatListPanel").classList.remove("mobile-hide");
      document.getElementById("chatThreadPanel").classList.add("mobile-hide");
      document.getElementById("chatThreadPanel").classList.remove("mobile-fullscreen-active");
    }

    function sendMessage() {
      const input = document.getElementById("messageInput");
      const text = input.value.trim();
      if (!text || !activeConversationId) return;
      const conv = getConversation(activeConversationId);
      if (!conv) return;
      conv.messages.push({
        sender: "me",
        text,
        time: "Just now"
      });
      input.value = "";
      renderThread();
      renderConversationList();
    }

    window.addEventListener("load", function() {
      renderConversationList();

      if (conversations.length > 0) {
        activeConversationId = conversations[0].id;
        renderThread();
      }

      document.getElementById("backButton").addEventListener("click", () => window.history.back());

      document.getElementById("searchConversations").addEventListener("input", (e) => {
        searchQuery = e.target.value;
        document.getElementById("clearSearchBtn").classList.toggle("hidden", searchQuery.length === 0);
        renderConversationList();
      });

      document.getElementById("clearSearchBtn").addEventListener("click", () => {
        const input = document.getElementById("searchConversations");
        input.value = "";
        searchQuery = "";
        document.getElementById("clearSearchBtn").classList.add("hidden");
        input.focus();
        renderConversationList();
      });

      document.getElementById("backToListBtn").addEventListener("click", closeThread);

      document.getElementById("callContactBtn").addEventListener("click", () => {
        const phone = document.getElementById("callContactBtn").getAttribute("data-phone");
        if (phone) window.location.href = "tel:" + phone;
      });

      document.getElementById("sendMessageBtn").addEventListener("click", sendMessage);
      document.getElementById("messageInput").addEventListener("keydown", (e) => {
        if (e.key === "Enter") sendMessage();
      });
    });
  </script>
</body>

</html>