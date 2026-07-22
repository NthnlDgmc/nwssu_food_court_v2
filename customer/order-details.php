<!doctype html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <meta
      name="viewport"
      content="width=device-width, initial-scale=1.0, user-scalable=yes"
    />
    <title>Customer - Order Details</title>
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
      .status-badge {
        display: inline-flex;
        align-items: center;
        padding: 3px 10px;
        font-size: 11px;
        font-weight: 600;
        background: #ffffff;
        border: 1px solid #d1d5db;
        color: #374151;
      }
      .status-cancelled {
        color: #6b7280;
        border-color: #d1d5db;
      }
      .step-circle {
        width: 26px;
        height: 26px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-width: 2px;
        border-style: solid;
        font-size: 11px;
        font-weight: 700;
        flex-shrink: 0;
      }
      .step-done {
        background: #059669;
        border-color: #059669;
        color: white;
      }
      .step-active {
        background: white;
        border-color: #059669;
        color: #059669;
      }
      .step-idle {
        background: white;
        border-color: #d1d5db;
        color: #9ca3af;
      }
      .step-line {
        height: 2px;
        flex: 1;
        background: #d1d5db;
      }
      .step-line-done {
        background: #059669;
      }
      .modal-overlay {
        background-color: rgba(0, 0, 0, 0.5);
      }
      #cancelReasonsContainer {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
      }
      .reason-option {
        display: inline-flex;
      }
      .reason-option input[type="radio"] {
        display: none;
      }
      .reason-option label {
        display: inline-flex;
        align-items: center;
        padding: 7px 12px;
        border: 1px solid #e5e7eb;
        cursor: pointer;
        font-size: 12px;
        color: #4b5563;
        background: #f9fafb;
        line-height: 1.2;
      }
      .reason-option input[type="radio"]:checked + label {
        border-color: #059669;
        background: #059669;
        color: #fff;
        font-weight: 600;
      }
      #otherReasonWrapper {
        width: 100%;
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
            title="Go back"
          >
            <svg
              xmlns="http://www.w3.org/2000/svg"
              fill="none"
              viewBox="0 0 24 24"
              stroke-width="1.5"
              stroke="currentColor"
              class="w-5 h-5 text-gray-600"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                d="M15.75 19.5 8.25 12l7.5-7.5"
              />
            </svg>
          </button>
          <h1 class="text-base font-semibold text-emerald-600 text-center">
            Order Details
          </h1>
          <a
            href="./chat.php"
            id="chatShortcutBtn"
            class="rounded-md p-1.5 bg-white border border-slate-200 hover:border-emerald-500 hover:bg-slate-50 transition-all justify-self-end flex items-center justify-center shrink-0"
            style="width: 34px; height: 34px"
            title="Message support"
          >
            <svg
              xmlns="http://www.w3.org/2000/svg"
              fill="none"
              viewBox="0 0 24 24"
              stroke-width="1.5"
              stroke="currentColor"
              class="w-5 h-5 text-gray-600"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                d="M20.25 8.511c.884.284 1.5 1.128 1.5 2.097v4.286c0 1.136-.847 2.1-1.98 2.193-.34.027-.68.052-1.02.072v3.091l-3-3c-1.354 0-2.694-.055-4.02-.163a2.115 2.115 0 0 1-.825-.242m9.345-8.334a2.126 2.126 0 0 0-.476-.095 48.64 48.64 0 0 0-8.048 0c-1.131.094-1.976 1.057-1.976 2.192v4.286c0 .837.46 1.58 1.155 1.951m9.345-8.334V6.637c0-1.621-1.152-3.026-2.76-3.235A48.455 48.455 0 0 0 11.25 3c-2.115 0-4.198.137-6.24.402-1.608.209-2.76 1.614-2.76 3.235v6.226c0 1.621 1.152 3.026 2.76 3.235.577.075 1.157.14 1.74.194V21l4.155-4.155"
              />
            </svg>
          </a>
        </div>
      </div>

      <div class="flex-1 overflow-y-auto mt-12 mb-16" id="mainContent">
        <div class="max-w-6xl mx-auto px-4 pt-3 pb-6 space-y-3">
          <div id="loadingSkeleton" class="space-y-3">
            <div class="rounded-md bg-white border border-gray-200 p-4 shadow-sm space-y-3">
              <div class="h-3 w-32 skeleton-bg rounded-[3px]"></div>
              <div class="h-2 w-24 skeleton-bg rounded-[3px]"></div>
              <div class="h-8 w-full skeleton-bg rounded-[3px]"></div>
            </div>
            <div class="rounded-md bg-white border border-gray-200 p-4 shadow-sm space-y-3">
              <div class="h-3 w-40 skeleton-bg rounded-[3px]"></div>
              <div class="h-10 w-full skeleton-bg rounded-[3px]"></div>
            </div>
            <div class="rounded-md bg-white border border-gray-200 p-4 shadow-sm space-y-3">
              <div class="h-3 w-24 skeleton-bg rounded-[3px]"></div>
              <div class="h-10 w-full skeleton-bg rounded-[3px]"></div>
              <div class="h-10 w-full skeleton-bg rounded-[3px]"></div>
            </div>
          </div>

          <div id="notFoundView" class="hidden flex flex-col items-center justify-center py-16 text-center">
            <div class="w-24 h-24 bg-gray-100 flex items-center justify-center mb-4 rounded-[3px]">
              <svg
                xmlns="http://www.w3.org/2000/svg"
                fill="none"
                viewBox="0 0 24 24"
                stroke-width="1.5"
                stroke="currentColor"
                class="w-10 h-10 text-gray-400"
              >
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  d="m9.75 9.75 4.5 4.5m0-4.5-4.5 4.5M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"
                />
              </svg>
            </div>
            <h3 class="text-base font-semibold text-gray-800">Order not found</h3>
            <p class="text-gray-500 text-sm mt-1 mb-5">
              We couldn't find the order you're looking for.
            </p>
            <a
              href="./order.php"
              class="px-6 py-2.5 bg-emerald-600 text-white font-medium text-sm hover:bg-emerald-700 transition rounded-[3px]"
              >Back to My Orders</a
            >
          </div>

          <div id="detailsView" class="hidden space-y-3">
            <div class="rounded-md bg-white border border-gray-200 p-4 shadow-sm">
              <div class="flex items-start justify-between gap-3">
                <div>
                  <div
                    class="flex items-center gap-1.5 cursor-pointer hover:text-emerald-600 transition-colors"
                    id="copyIdBtn"
                  >
                    <p class="text-sm font-bold text-gray-800" id="orderIdLabel"></p>
                    <span
                      class="copy-icon w-3.5 h-3.5 text-gray-400 flex items-center justify-center shrink-0"
                    >
                      <svg
                        xmlns="http://www.w3.org/2000/svg"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke-width="1.5"
                        stroke="currentColor"
                        class="w-3.5 h-3.5"
                      >
                        <path
                          stroke-linecap="round"
                          stroke-linejoin="round"
                          d="M15.75 17.25v3.375c0 .621-.504 1.125-1.125 1.125h-9.75a1.125 1.125 0 0 1-1.125-1.125V7.875c0-.621.504-1.125 1.125-1.125H6.75a9.06 9.06 0 0 1 1.5.124m7.5 10.376h3.375c.621 0 1.125-.504 1.125-1.125V11.25c0-4.46-3.243-8.161-7.5-8.876a9.06 9.06 0 0 0-1.5-.124H9.375c-.621 0-1.125.504-1.125 1.125v3.5m7.5 10.375H9.375a1.125 1.125 0 0 1-1.125-1.125v-9.25m12 6.625v-1.875a3.375 3.375 0 0 0-3.375-3.375h-1.5a1.125 1.125 0 0 1-1.125-1.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H9.75"
                        />
                      </svg>
                    </span>
                  </div>
                  <p class="text-[11px] text-gray-500 mt-0.5" id="orderDateLabel"></p>
                  <p class="text-[11px] text-emerald-600 font-medium mt-0.5" id="orderStallLabel"></p>
                </div>
                <span class="status-badge rounded-[3px] shrink-0" id="orderStatusBadge"></span>
              </div>
            </div>

            <div id="progressCard" class="rounded-md bg-white border border-gray-200 p-4 shadow-sm">
              <p class="text-[11px] font-semibold text-gray-500 uppercase tracking-wide mb-4">
                Order Progress
              </p>
              <div id="progressSteps" class="flex items-start"></div>
            </div>

            <div id="cancelledCard" class="hidden rounded-md bg-white border border-gray-200 p-4 shadow-sm">
              <div class="flex items-start gap-3">
                <div class="w-9 h-9 bg-gray-100 flex items-center justify-center shrink-0 rounded-[3px]">
                  <svg
                    xmlns="http://www.w3.org/2000/svg"
                    fill="none"
                    viewBox="0 0 24 24"
                    stroke-width="1.5"
                    stroke="currentColor"
                    class="w-4 h-4 text-gray-500"
                  >
                    <path
                      stroke-linecap="round"
                      stroke-linejoin="round"
                      d="M6 18 18 6M6 6l12 12"
                    />
                  </svg>
                </div>
                <div class="flex-1 min-w-0">
                  <p class="text-sm font-semibold text-gray-800">This order was cancelled</p>
                  <p class="text-xs text-gray-500 mt-0.5" id="cancelReasonLabel"></p>
                </div>
              </div>
            </div>

            <div class="rounded-md bg-white border border-gray-200 p-4 shadow-sm">
              <p class="text-[11px] font-semibold text-gray-500 uppercase tracking-wide mb-3" id="fulfillmentTitle">
                Delivery Info
              </p>
              <div class="flex items-center gap-3 mb-3">
                <div class="w-9 h-9 bg-gray-100 flex items-center justify-center shrink-0 rounded-[3px]">
                  <svg
                    xmlns="http://www.w3.org/2000/svg"
                    fill="none"
                    viewBox="0 0 24 24"
                    stroke-width="1.5"
                    stroke="currentColor"
                    class="w-4 h-4 text-gray-500"
                  >
                    <path
                      stroke-linecap="round"
                      stroke-linejoin="round"
                      d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"
                    />
                    <path
                      stroke-linecap="round"
                      stroke-linejoin="round"
                      d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z"
                    />
                  </svg>
                </div>
                <div class="flex-1 min-w-0">
                  <p class="text-[10px] text-gray-400" id="fulfillmentLabel">Deliver to</p>
                  <p class="text-xs font-medium text-gray-800" id="fulfillmentValue"></p>
                </div>
              </div>
              <div
                id="contactRow"
                class="hidden flex items-center gap-3 pt-3 border-t border-gray-100"
              >
                <div class="w-9 h-9 bg-emerald-50 flex items-center justify-center shrink-0 rounded-[3px]">
                  <svg
                    xmlns="http://www.w3.org/2000/svg"
                    fill="none"
                    viewBox="0 0 24 24"
                    stroke-width="1.5"
                    stroke="currentColor"
                    class="w-4 h-4 text-emerald-600"
                  >
                    <path
                      stroke-linecap="round"
                      stroke-linejoin="round"
                      d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z"
                    />
                  </svg>
                </div>
                <div class="flex-1 min-w-0">
                  <p class="text-[10px] text-gray-400" id="contactRoleLabel"></p>
                  <p class="text-xs font-medium text-gray-800" id="contactNameLabel"></p>
                </div>
                <a
                  id="callContactBtn"
                  href="#"
                  class="w-9 h-9 border border-gray-200 hover:border-emerald-500 hover:bg-emerald-50 flex items-center justify-center shrink-0 rounded-[3px] transition-colors"
                  title="Call"
                >
                  <svg
                    xmlns="http://www.w3.org/2000/svg"
                    fill="none"
                    viewBox="0 0 24 24"
                    stroke-width="1.5"
                    stroke="currentColor"
                    class="w-4 h-4 text-gray-600"
                  >
                    <path
                      stroke-linecap="round"
                      stroke-linejoin="round"
                      d="M2.25 6.75c0 8.284 6.716 15 15 15h1.5a2.25 2.25 0 0 0 2.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 0 1-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 0 0-1.091-.852H4.5A2.25 2.25 0 0 0 2.25 4.5v2.25Z"
                    />
                  </svg>
                </a>
              </div>
            </div>

            <div class="rounded-md bg-white border border-gray-200 overflow-hidden shadow-sm">
              <div class="p-4 border-b border-gray-100 flex items-center justify-between">
                <p class="text-[11px] font-semibold text-gray-500 uppercase tracking-wide">
                  Items
                </p>
                <span class="text-[11px] text-gray-400" id="itemsCountLabel"></span>
              </div>
              <div id="itemsList" class="px-4 py-3 space-y-3"></div>
            </div>

            <div id="noteCard" class="hidden rounded-md bg-white border border-gray-200 p-4 shadow-sm">
              <p class="text-[11px] font-semibold text-gray-500 uppercase tracking-wide mb-2">
                Note to Stall
              </p>
              <p class="text-xs text-gray-700 bg-gray-50 border border-gray-100 rounded-[3px] px-3 py-2" id="noteValue"></p>
            </div>

            <div class="rounded-md bg-white border border-gray-200 p-4 shadow-sm">
              <p class="text-[11px] font-semibold text-gray-500 uppercase tracking-wide mb-3">
                Payment Method
              </p>
              <div class="flex items-center gap-3">
                <div class="w-9 h-9 bg-gray-100 flex items-center justify-center shrink-0 rounded-[3px]">
                  <svg
                    xmlns="http://www.w3.org/2000/svg"
                    fill="none"
                    viewBox="0 0 24 24"
                    stroke-width="1.5"
                    stroke="currentColor"
                    class="w-4 h-4 text-gray-500"
                  >
                    <path
                      stroke-linecap="round"
                      stroke-linejoin="round"
                      d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-9-10.5v10.5A2.25 2.25 0 0 0 5.25 18.75h13.5A2.25 2.25 0 0 0 21 16.5V6a2.25 2.25 0 0 0-2.25-2.25H5.25A2.25 2.25 0 0 0 3 6Z"
                    />
                  </svg>
                </div>
                <p class="text-xs font-medium text-gray-800" id="paymentValue"></p>
              </div>
            </div>

            <div class="rounded-md bg-white border border-gray-200 p-4 shadow-sm">
              <p class="text-[11px] font-semibold text-gray-500 uppercase tracking-wide mb-3">
                Price Summary
              </p>
              <div class="space-y-1.5">
                <div class="flex items-center justify-between">
                  <span class="text-xs text-gray-500">Subtotal</span>
                  <span class="text-xs text-gray-600" id="subtotalValue"></span>
                </div>
                <div class="flex items-center justify-between">
                  <span class="text-xs text-gray-500" id="feeLabel"></span>
                  <span class="text-xs text-gray-600" id="feeValue"></span>
                </div>
                <div class="flex items-center justify-between pt-1.5 border-t border-gray-100">
                  <span class="text-xs font-semibold text-gray-700">Total</span>
                  <span class="text-sm font-bold text-emerald-600" id="totalValue"></span>
                </div>
              </div>
            </div>

            <div id="actionRow" class="hidden flex gap-2.5 pt-1">
              <button
                id="cancelOrderBtn"
                class="hidden flex-1 py-2.5 border border-red-200 text-red-600 text-xs font-semibold hover:bg-red-50 transition-colors rounded-[3px]"
              >
                Cancel Order
              </button>
              <button
                id="reorderBtn"
                class="hidden flex-1 py-2.5 bg-gradient-to-r from-emerald-600 to-emerald-700 text-white text-xs font-semibold hover:from-emerald-700 hover:to-emerald-800 transition-colors rounded-[3px]"
              >
                Reorder
              </button>
            </div>
          </div>
        </div>
      </div>

      <div
        class="bg-white border-t border-gray-200 flex-shrink-0 fixed bottom-0 left-0 right-0 z-20"
      >
        <div class="max-w-6xl mx-auto px-4 flex justify-around py-2">
          <a
            href="./home.php"
            class="flex flex-col items-center justify-center py-2 px-3 transition-all duration-200 group text-gray-500 hover:text-gray-900 hover:bg-gray-50 rounded-[3px]"
          >
            <svg
              xmlns="http://www.w3.org/2000/svg"
              fill="none"
              viewBox="0 0 24 24"
              stroke-width="1.5"
              stroke="currentColor"
              class="w-5 h-5 transition-transform group-hover:scale-110"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                d="m2.25 12 8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25"
              />
            </svg>
            <span class="text-xs font-medium mt-1">Home</span>
          </a>
          <a
            href="./cart.php"
            class="flex flex-col items-center justify-center py-2 px-3 transition-all duration-200 group text-gray-500 hover:text-gray-900 hover:bg-gray-50 relative rounded-[3px]"
          >
            <svg
              xmlns="http://www.w3.org/2000/svg"
              fill="none"
              viewBox="0 0 24 24"
              stroke-width="1.5"
              stroke="currentColor"
              class="w-5 h-5 transition-transform group-hover:scale-110"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                d="M15.75 10.5V6a3.75 3.75 0 1 0-7.5 0v4.5m11.356-1.993 1.263 12c.07.665-.45 1.243-1.119 1.243H4.25a1.125 1.125 0 0 1-1.12-1.243l1.264-12A1.125 1.125 0 0 1 5.513 7.5h12.974c.576 0 1.059.435 1.119 1.007ZM8.625 10.5a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm7.5 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z"
              />
            </svg>
            <span class="text-xs font-medium mt-1">Cart</span>
          </a>
          <a
            href="./order.php"
            class="flex flex-col items-center justify-center py-2 px-3 transition-all duration-200 group text-emerald-600 bg-emerald-50 rounded-[3px]"
          >
            <svg
              xmlns="http://www.w3.org/2000/svg"
              fill="none"
              viewBox="0 0 24 24"
              stroke-width="1.5"
              stroke="currentColor"
              class="w-5 h-5 transition-transform group-hover:scale-110"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 0 0 2.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 0 0-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 0 0 .75-.75 2.25 2.25 0 0 0-.1-.664m-5.8 0A2.251 2.251 0 0 1 13.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25ZM6.75 12h.008v.008H6.75V12Zm0 3h.008v.008H6.75V15Zm0 3h.008v.008H6.75V18Z"
              />
            </svg>
            <span class="text-xs font-medium mt-1">Orders</span>
          </a>
          <a
            href="./chat.php"
            class="flex flex-col items-center justify-center py-2 px-3 transition-all duration-200 group text-gray-500 hover:text-gray-900 hover:bg-gray-50 relative rounded-[3px]"
          >
            <svg
              xmlns="http://www.w3.org/2000/svg"
              fill="none"
              viewBox="0 0 24 24"
              stroke-width="1.5"
              stroke="currentColor"
              class="w-5 h-5 transition-transform group-hover:scale-110"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                d="M20.25 8.511c.884.284 1.5 1.128 1.5 2.097v4.286c0 1.136-.847 2.1-1.98 2.193-.34.027-.68.052-1.02.072v3.091l-3-3c-1.354 0-2.694-.055-4.02-.163a2.115 2.115 0 0 1-.825-.242m9.345-8.334a2.126 2.126 0 0 0-.476-.095 48.64 48.64 0 0 0-8.048 0c-1.131.094-1.976 1.057-1.976 2.192v4.286c0 .837.46 1.58 1.155 1.951m9.345-8.334V6.637c0-1.621-1.152-3.026-2.76-3.235A48.455 48.455 0 0 0 11.25 3c-2.115 0-4.198.137-6.24.402-1.608.209-2.76 1.614-2.76 3.235v6.226c0 1.621 1.152 3.026 2.76 3.235.577.075 1.157.14 1.74.194V21l4.155-4.155"
              />
            </svg>
            <span class="text-xs font-medium mt-1">Chats</span>
          </a>
          <a
            href="./account.php"
            class="flex flex-col items-center justify-center py-2 px-3 transition-all duration-200 group text-gray-500 hover:text-gray-900 hover:bg-gray-50 rounded-[3px]"
          >
            <svg
              xmlns="http://www.w3.org/2000/svg"
              fill="none"
              viewBox="0 0 24 24"
              stroke-width="1.5"
              stroke="currentColor"
              class="w-5 h-5 transition-transform group-hover:scale-110"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z"
              />
            </svg>
            <span class="text-xs font-medium mt-1">Account</span>
          </a>
        </div>
      </div>
    </div>

    <div
      id="cancelModal"
      class="fixed inset-0 z-[60] hidden flex items-end justify-center sm:items-center sm:px-4"
    >
      <div class="modal-overlay absolute inset-0" id="cancelModalOverlay"></div>
      <div
        class="bg-white w-full sm:max-w-md relative z-10 shadow-2xl overflow-hidden rounded-md"
      >
        <div
          class="p-4 border-b border-gray-100 flex items-center justify-between"
        >
          <div class="flex items-center gap-2.5">
            <div
              class="w-8 h-8 bg-red-50 flex items-center justify-center rounded-[3px]"
            >
              <svg
                xmlns="http://www.w3.org/2000/svg"
                fill="none"
                viewBox="0 0 24 24"
                stroke-width="1.5"
                stroke="currentColor"
                class="w-4 h-4 text-red-500"
              >
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z"
                />
              </svg>
            </div>
            <h2 class="font-bold text-gray-800 text-sm">Cancel Order</h2>
          </div>
          <button
            id="closeCancelModalBtn"
            class="p-1 hover:bg-gray-100 rounded-[3px]"
          >
            <svg
              xmlns="http://www.w3.org/2000/svg"
              fill="none"
              viewBox="0 0 24 24"
              stroke-width="1.5"
              stroke="currentColor"
              class="w-5 h-5 text-gray-500"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                d="M6 18 18 6M6 6l12 12"
              />
            </svg>
          </button>
        </div>
        <div class="px-4 pt-3 pb-1">
          <p class="text-xs text-gray-500">
            Order
            <span
              id="cancelOrderIdLabel"
              class="font-semibold text-gray-700"
            ></span>
          </p>
          <p class="text-sm font-medium text-gray-800 mt-0.5">
            Why do you want to cancel?
          </p>
        </div>
        <div class="px-4 py-3" id="cancelReasonsContainer">
          <div class="reason-option">
            <input type="radio" name="cancelReason" id="reason1" value="I changed my mind" />
            <label for="reason1" class="rounded-full">I changed my mind</label>
          </div>
          <div class="reason-option">
            <input type="radio" name="cancelReason" id="reason2" value="I ordered the wrong item" />
            <label for="reason2" class="rounded-full">I ordered the wrong item</label>
          </div>
          <div class="reason-option">
            <input type="radio" name="cancelReason" id="reason3" value="I ordered from the wrong stall" />
            <label for="reason3" class="rounded-full">I ordered from the wrong stall</label>
          </div>
          <div class="reason-option">
            <input type="radio" name="cancelReason" id="reason4" value="I want to change my delivery location" />
            <label for="reason4" class="rounded-full">I want to change my delivery location</label>
          </div>
          <div class="reason-option">
            <input type="radio" name="cancelReason" id="reason5" value="I want to change my payment method" />
            <label for="reason5" class="rounded-full">I want to change my payment method</label>
          </div>
          <div class="reason-option">
            <input type="radio" name="cancelReason" id="reason6" value="The food takes too long" />
            <label for="reason6" class="rounded-full">The food takes too long</label>
          </div>
          <div class="reason-option">
            <input type="radio" name="cancelReason" id="reason7" value="I want to add more items" />
            <label for="reason7" class="rounded-full">I want to add more items</label>
          </div>
          <div class="reason-option">
            <input type="radio" name="cancelReason" id="reason8" value="other" />
            <label for="reason8" class="rounded-full">Other reason</label>
          </div>
          <div id="otherReasonWrapper" class="hidden pt-1">
            <textarea
              id="otherReasonText"
              rows="2"
              placeholder="Tell us more..."
              class="w-full px-3 py-2 text-xs border border-gray-200 focus:outline-none focus:border-emerald-600 resize-none text-gray-700 placeholder-gray-400 rounded-[3px]"
            ></textarea>
          </div>
        </div>
        <div class="px-4 pb-5 pt-2 flex gap-2.5">
          <button
            id="cancelModalKeepBtn"
            class="flex-1 py-2.5 border border-gray-200 text-gray-600 text-xs font-semibold rounded-[3px]"
          >
            Keep Order
          </button>
          <button
            id="cancelModalConfirmBtn"
            class="flex-1 py-2.5 bg-red-500 text-white text-xs font-semibold disabled:opacity-40 disabled:cursor-not-allowed rounded-[3px]"
            disabled
          >
            Confirm Cancel
          </button>
        </div>
      </div>
    </div>

    <script>
      const STATUS_META = {
        pending: { label: "Pending", cls: "" },
        preparing: { label: "Preparing", cls: "" },
        ready_for_pickup: { label: "Ready for Pickup", cls: "" },
        picked_up: { label: "Picked Up", cls: "" },
        out_for_delivery: { label: "Out for Delivery", cls: "" },
        completed: { label: "Completed", cls: "" },
        cancelled: { label: "Cancelled", cls: "status-cancelled" },
      };

      const DELIVERY_STEPS = [
        { value: "pending", label: "Pending" },
        { value: "ready_for_pickup", label: "Ready" },
        { value: "picked_up", label: "Collected" },
        { value: "out_for_delivery", label: "Out for Delivery" },
        { value: "completed", label: "Delivered" },
      ];

      const PICKUP_STEPS = [
        { value: "pending", label: "Pending" },
        { value: "preparing", label: "Ready" },
        { value: "ready_for_pickup", label: "Ready for Pickup" },
        { value: "completed", label: "Completed" },
      ];

      // Same dataset shape as My Orders, so any order id from that list resolves here.
      const ALL_ORDERS = [
        {
          id: "FC-250516-001",
          date: "May 16, 2025 · 2:45 PM",
          stall: "Stall 2",
          status: "pending",
          orderType: "delivery",
          location: "CCIS 2nd Floor, Lab 3",
          payment: "Cash on Pickup",
          note: "Less sugar sa drinks",
          deliveryStaff: null,
          items: [
            {
              name: "Lumpia",
              price: 15,
              qty: 3,
              img: "https://images.unsplash.com/photo-1626074353765-517a681e40be?q=80&w=200&auto=format&fit=crop",
            },
            {
              name: "Iced Tea",
              price: 20,
              qty: 1,
              img: "https://images.unsplash.com/photo-1556679343-c7306c1976bc?q=80&w=200&auto=format&fit=crop",
            },
          ],
          deliveryFee: 5,
        },
        {
          id: "FC-250514-001",
          date: "May 14, 2025 · 10:32 AM",
          stall: "Stall 1",
          status: "out_for_delivery",
          orderType: "delivery",
          location: "CCIS 2nd Floor, Lab 3",
          payment: "Cash on Pickup",
          note: "Extra rice please, no spicy",
          deliveryStaff: { name: "Jenuel Castillo", phone: "+63 912 345 6789" },
          items: [
            {
              name: "Spaghetti",
              price: 35,
              qty: 2,
              img: "https://images.unsplash.com/photo-1589227365533-dee630bb59bd?q=80&w=200&auto=format&fit=crop",
            },
            {
              name: "Fried Chicken",
              price: 50,
              qty: 1,
              img: "https://images.unsplash.com/photo-1626645738196-c2a7c87a8f58?q=80&w=200&auto=format&fit=crop",
            },
          ],
          deliveryFee: 10,
        },
        {
          id: "FC-250514-002",
          date: "May 14, 2025 · 10:35 AM",
          stall: "Stall 5",
          status: "preparing",
          orderType: "pickup",
          stallOwner: { name: "Mang Tonyo", phone: "+63 918 765 4321" },
          location: "CCIS 2nd Floor, Lab 3",
          payment: "GCash",
          note: "",
          deliveryStaff: null,
          items: [
            {
              name: "Burger",
              price: 55,
              qty: 1,
              img: "https://images.unsplash.com/photo-1568901346375-23c9450c58cd?q=80&w=200&auto=format&fit=crop",
            },
          ],
          deliveryFee: 0,
        },
        {
          id: "FC-250513-001",
          date: "May 13, 2025 · 12:15 PM",
          stall: "Stall 3",
          status: "completed",
          orderType: "delivery",
          location: "Engineering Bldg, Room 201",
          payment: "Cash on Pickup",
          note: "Call when arrived",
          deliveryStaff: { name: "Jio Canaman", phone: "+63 917 234 5678" },
          items: [
            {
              name: "Turon",
              price: 5,
              qty: 3,
              img: "https://images.unsplash.com/photo-1541518763669-27f704524cc0?q=80&w=200&auto=format&fit=crop",
            },
            {
              name: "Fried Chicken",
              price: 50,
              qty: 1,
              img: "https://images.unsplash.com/photo-1626645738196-c2a7c87a8f58?q=80&w=200&auto=format&fit=crop",
            },
          ],
          deliveryFee: 5,
        },
        {
          id: "FC-250512-001",
          date: "May 12, 2025 · 9:50 AM",
          stall: "Stall 4",
          status: "cancelled",
          orderType: "pickup",
          stallOwner: { name: "Aling Rosa", phone: "+63 927 112 3344" },
          location: "Admin Office",
          payment: "GCash",
          note: "",
          deliveryStaff: null,
          cancelReason: "I changed my mind",
          items: [
            {
              name: "Halo-Halo",
              price: 50,
              qty: 2,
              img: "https://images.unsplash.com/photo-1572490122747-3968b75cc699?q=80&w=200&auto=format&fit=crop",
            },
          ],
          deliveryFee: 0,
        },
      ];

      let currentOrder = null;

      function getParam(name) {
        return new URLSearchParams(window.location.search).get(name);
      }

      function escapeHtml(str) {
        if (!str) return "";
        return String(str)
          .replace(/&/g, "&amp;")
          .replace(/</g, "&lt;")
          .replace(/>/g, "&gt;")
          .replace(/"/g, "&quot;")
          .replace(/'/g, "&#039;");
      }

      function getStatusLabel(status, orderType) {
        if (status === "completed" && orderType === "delivery") return "Delivered";
        return STATUS_META[status] ? STATUS_META[status].label : status;
      }

      function calcSubtotal(order) {
        return order.items.reduce((s, i) => s + i.price * i.qty, 0);
      }

      function calcTotal(order) {
        return calcSubtotal(order) + (order.orderType === "delivery" ? order.deliveryFee : 0);
      }

      function copyToClipboard(text) {
        if (navigator.clipboard && window.isSecureContext) {
          navigator.clipboard.writeText(text);
        } else {
          const textArea = document.createElement("textarea");
          textArea.value = text;
          textArea.style.position = "fixed";
          textArea.style.left = "-999999px";
          textArea.style.top = "-999999px";
          document.body.appendChild(textArea);
          textArea.focus();
          textArea.select();
          try {
            document.execCommand("copy");
          } catch (err) {
            console.error("Fallback copy failed", err);
          }
          document.body.removeChild(textArea);
        }
      }

      function renderProgress(order) {
        const progressCard = document.getElementById("progressCard");
        const cancelledCard = document.getElementById("cancelledCard");

        if (order.status === "cancelled") {
          progressCard.classList.add("hidden");
          cancelledCard.classList.remove("hidden");
          document.getElementById("cancelReasonLabel").textContent = order.cancelReason
            ? `Reason: ${order.cancelReason}`
            : "No reason was provided.";
          return;
        }

        progressCard.classList.remove("hidden");
        cancelledCard.classList.add("hidden");

        const steps = order.orderType === "pickup" ? PICKUP_STEPS : DELIVERY_STEPS;
        const currentIndex = steps.findIndex((s) => s.value === order.status);

        const container = document.getElementById("progressSteps");
        container.innerHTML = steps
          .map((step, idx) => {
            let circleCls = "step-idle";
            if (currentIndex === -1) {
              circleCls = "step-idle";
            } else if (idx < currentIndex) {
              circleCls = "step-done";
            } else if (idx === currentIndex) {
              circleCls = "step-active";
            }
            const lineCls = idx < currentIndex ? "step-line-done" : "";
            const iconOrNumber =
              idx < currentIndex
                ? `<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="3" stroke="currentColor" class="w-3 h-3"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>`
                : idx + 1;
            const connector =
              idx < steps.length - 1
                ? `<div class="step-line ${lineCls}" style="margin-top:12px;"></div>`
                : "";
            return `
              <div class="flex flex-col items-center flex-1 min-w-0">
                <div class="flex items-center w-full">
                  <div class="flex-1"></div>
                  <div class="step-circle rounded-full ${circleCls}">${iconOrNumber}</div>
                  <div class="flex-1"></div>
                </div>
                <p class="text-[10px] text-center mt-1.5 leading-tight ${idx === currentIndex ? "font-semibold text-emerald-600" : "text-gray-400"}">${escapeHtml(step.label)}</p>
              </div>
              ${connector ? `<div class="flex-1" style="max-width:24px;">${connector}</div>` : ""}
            `;
          })
          .join("");
      }

      function renderOrder(order) {
        document.getElementById("orderIdLabel").textContent = order.id;
        document.getElementById("orderDateLabel").textContent = order.date;
        document.getElementById("orderStallLabel").textContent = order.stall;

        const meta = STATUS_META[order.status];
        const badge = document.getElementById("orderStatusBadge");
        badge.textContent = getStatusLabel(order.status, order.orderType);
        badge.className = `status-badge rounded-[3px] shrink-0 ${meta.cls}`;

        renderProgress(order);

        const fulfillmentTitle = document.getElementById("fulfillmentTitle");
        const fulfillmentLabel = document.getElementById("fulfillmentLabel");
        const fulfillmentValue = document.getElementById("fulfillmentValue");
        const contactRow = document.getElementById("contactRow");

        if (order.orderType === "pickup") {
          fulfillmentTitle.textContent = "Pickup Info";
          fulfillmentLabel.textContent = "Pickup at";
          fulfillmentValue.textContent = order.stall;
          if (order.stallOwner) {
            contactRow.classList.remove("hidden");
            document.getElementById("contactRoleLabel").textContent = "Stall Contact";
            document.getElementById("contactNameLabel").textContent = order.stallOwner.name;
            document.getElementById("callContactBtn").href = `tel:${order.stallOwner.phone.replace(/\s+/g, "")}`;
          } else {
            contactRow.classList.add("hidden");
          }
        } else {
          fulfillmentTitle.textContent = "Delivery Info";
          fulfillmentLabel.textContent = "Deliver to";
          fulfillmentValue.textContent = order.location;
          if (order.deliveryStaff) {
            contactRow.classList.remove("hidden");
            document.getElementById("contactRoleLabel").textContent = "Delivery Rider";
            document.getElementById("contactNameLabel").textContent = order.deliveryStaff.name;
            document.getElementById("callContactBtn").href = `tel:${order.deliveryStaff.phone.replace(/\s+/g, "")}`;
          } else {
            contactRow.classList.add("hidden");
          }
        }

        const itemsList = document.getElementById("itemsList");
        itemsList.innerHTML = order.items
          .map(
            (item) => `
          <div class="flex items-center gap-3">
            <img src="${item.img}" alt="${escapeHtml(item.name)}" class="w-10 h-10 object-cover bg-gray-100 shrink-0 rounded-[3px]" />
            <div class="flex-1 flex items-center justify-between">
              <div class="flex flex-col">
                <span class="text-xs text-gray-600">${escapeHtml(item.name)} x${item.qty}</span>
                <span class="text-[10px] text-gray-400">₱${item.price.toFixed(2)} each</span>
              </div>
              <span class="text-xs font-medium text-gray-700">₱${(item.price * item.qty).toFixed(2)}</span>
            </div>
          </div>
        `,
          )
          .join("");
        document.getElementById("itemsCountLabel").textContent = `${order.items.length} item${order.items.length > 1 ? "s" : ""}`;

        const noteCard = document.getElementById("noteCard");
        if (order.note && order.note.trim() !== "") {
          noteCard.classList.remove("hidden");
          document.getElementById("noteValue").textContent = order.note;
        } else {
          noteCard.classList.add("hidden");
        }

        document.getElementById("paymentValue").textContent = order.payment;

        const subtotal = calcSubtotal(order);
        const total = calcTotal(order);
        document.getElementById("subtotalValue").textContent = `₱${subtotal.toFixed(2)}`;
        document.getElementById("feeLabel").textContent =
          order.orderType === "pickup" ? "Pickup fee" : "Delivery fee";
        if (order.orderType === "pickup") {
          document.getElementById("feeValue").innerHTML =
            '<span class="text-emerald-600 font-semibold">Free</span>';
        } else {
          document.getElementById("feeValue").textContent = `₱${order.deliveryFee.toFixed(2)}`;
        }
        document.getElementById("totalValue").textContent = `₱${total.toFixed(2)}`;

        const actionRow = document.getElementById("actionRow");
        const cancelBtn = document.getElementById("cancelOrderBtn");
        const reorderBtn = document.getElementById("reorderBtn");
        cancelBtn.classList.add("hidden");
        reorderBtn.classList.add("hidden");
        actionRow.classList.add("hidden");

        if (order.status === "pending") {
          cancelBtn.classList.remove("hidden");
          actionRow.classList.remove("hidden");
          actionRow.classList.add("flex");
        } else if (order.status === "completed") {
          reorderBtn.classList.remove("hidden");
          actionRow.classList.remove("hidden");
          actionRow.classList.add("flex");
        }
      }

      function openCancelModal() {
        if (!currentOrder) return;
        document.getElementById("cancelOrderIdLabel").textContent = currentOrder.id;
        document
          .querySelectorAll("input[name='cancelReason']")
          .forEach((r) => (r.checked = false));
        document.getElementById("otherReasonText").value = "";
        document.getElementById("otherReasonWrapper").classList.add("hidden");
        document.getElementById("cancelModalConfirmBtn").disabled = true;
        document.getElementById("cancelModal").classList.remove("hidden");
        document.body.style.overflow = "hidden";
      }

      function closeCancelModal() {
        document.getElementById("cancelModal").classList.add("hidden");
        document.body.style.overflow = "";
      }

      window.addEventListener("load", function () {
        const orderId = getParam("id");
        currentOrder = ALL_ORDERS.find((o) => o.id === orderId) || null;

        document.getElementById("loadingSkeleton").classList.add("hidden");

        if (!currentOrder) {
          document.getElementById("notFoundView").classList.remove("hidden");
        } else {
          document.getElementById("detailsView").classList.remove("hidden");
          renderOrder(currentOrder);
        }

        document.getElementById("copyIdBtn").addEventListener("click", () => {
          if (currentOrder) copyToClipboard(currentOrder.id);
        });

        document.getElementById("backButton").addEventListener("click", () => {
          if (document.referrer) {
            window.history.back();
          } else {
            window.location.href = "./order.php";
          }
        });

        document.getElementById("cancelOrderBtn").addEventListener("click", openCancelModal);
        document.getElementById("closeCancelModalBtn").addEventListener("click", closeCancelModal);
        document.getElementById("cancelModalOverlay").addEventListener("click", closeCancelModal);
        document.getElementById("cancelModalKeepBtn").addEventListener("click", closeCancelModal);

        document.getElementById("reorderBtn").addEventListener("click", () => {
          if (currentOrder) alert(`Items from ${currentOrder.id} added to cart!`);
        });

        document
          .querySelectorAll("input[name='cancelReason']")
          .forEach((radio) => {
            radio.addEventListener("change", () => {
              const isOther = radio.value === "other";
              document
                .getElementById("otherReasonWrapper")
                .classList.toggle("hidden", !isOther);
              document.getElementById("cancelModalConfirmBtn").disabled = false;
            });
          });

        document.getElementById("otherReasonText").addEventListener("input", (e) => {
          const otherSelected =
            document.querySelector("input[name='cancelReason']:checked")?.value === "other";
          document.getElementById("cancelModalConfirmBtn").disabled =
            otherSelected && e.target.value.trim() === "";
        });

        document.getElementById("cancelModalConfirmBtn").addEventListener("click", () => {
          const selected = document.querySelector("input[name='cancelReason']:checked");
          if (!selected || !currentOrder) return;
          let reason = selected.value;
          if (reason === "other") {
            const text = document.getElementById("otherReasonText").value.trim();
            if (!text) return;
            reason = text;
          }
          currentOrder.status = "cancelled";
          currentOrder.cancelReason = reason;
          closeCancelModal();
          renderOrder(currentOrder);
        });
      });
    </script>
  </body>
</html>