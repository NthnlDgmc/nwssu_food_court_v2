<?php
session_start();
require_once '../config/database.php';

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

        .ring {
            animation: ring-in .8s cubic-bezier(.23, 1, .32, 1) both;
        }

        .fade-up {
            animation: fade-up .45s cubic-bezier(.23, 1, .32, 1) both;
        }

        .count {
            font-variant-numeric: tabular-nums;
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

        @keyframes ring-in {
            from {
                stroke-dashoffset: 440;
            }

            to {
                stroke-dashoffset: var(--dash);
            }
        }

        @keyframes fade-up {
            from {
                opacity: 0;
                transform: translateY(8px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @media (prefers-reduced-motion: reduce) {

            *,
            *::before,
            *::after {
                animation-duration: .01ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: .01ms !important;
            }
        }
    </style>
</head>

<body class="bg-white">
    <div class="flex flex-col h-screen">
        <div class="bg-white flex-shrink-0 fixed top-0 left-0 right-0 z-20">
            <div class="max-w-5xl mx-auto px-4 py-2 grid grid-cols-3 items-center">
                <button id="backButton" class="p-1.5 bg-white border border-gray-200 hover:border-emerald-500 hover:bg-slate-50 transition-all justify-self-start flex items-center justify-center shrink-0" style="width:34px;height:34px;border-radius:6px" title="Go back">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 text-gray-600">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" />
                    </svg>
                </button>
                <h1 class="text-base font-semibold text-emerald-600 text-center">Analytics</h1>
                <div class="justify-self-end" style="width:34px"></div>
            </div>
        </div>

        <div class="flex-1 overflow-y-auto mt-12 mb-0" id="mainContent">
            <div class="max-w-5xl mx-auto px-4 pt-3 pb-4">
                <div class="rounded-md bg-white border border-gray-200 shadow-sm p-3 mb-3 fade-up">
                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                        <div class="min-w-0">
                            <p class="text-[11px] font-bold text-gray-500 uppercase tracking-wide">Stall Analytics</p>
                            <p class="text-[10px] text-gray-400 mt-0.5">School canteen performance for August 15, 2026</p>
                        </div>
                        <div class="flex items-center gap-1.5 shrink-0">
                            <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
                            <span class="text-[10px] text-gray-400">Static preview</span>
                        </div>
                    </div>
                    <div class="mt-3 flex flex-wrap items-center gap-1.5">
                        <button data-range="today" class="range-btn px-3 py-1.5 bg-emerald-600 text-white text-[11px] font-semibold hover:bg-emerald-700 transition-colors rounded-[3px]">Today</button>
                        <button data-range="yesterday" class="range-btn px-3 py-1.5 bg-white border border-gray-200 text-gray-600 text-[11px] font-semibold hover:border-emerald-500 hover:text-emerald-600 transition-colors rounded-[3px]">Yesterday</button>
                        <button data-range="week" class="range-btn px-3 py-1.5 bg-white border border-gray-200 text-gray-600 text-[11px] font-semibold hover:border-emerald-500 hover:text-emerald-600 transition-colors rounded-[3px]">This Week</button>
                        <button data-range="month" class="range-btn px-3 py-1.5 bg-white border border-gray-200 text-gray-600 text-[11px] font-semibold hover:border-emerald-500 hover:text-emerald-600 transition-colors rounded-[3px]">This Month</button>
                        <label class="relative ml-0 sm:ml-auto">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-3.5 h-3.5 text-gray-400 absolute left-2.5 top-1/2 -translate-y-1/2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 8.25h18M4.5 5.25h15a1.5 1.5 0 0 1 1.5 1.5v13.5a1.5 1.5 0 0 1-1.5 1.5h-15a1.5 1.5 0 0 1-1.5-1.5V6.75a1.5 1.5 0 0 1 1.5-1.5Z" />
                            </svg>
                            <input id="dateInput" type="date" value="2026-08-15" class="pl-8 pr-2 py-1.5 bg-white border border-gray-200 text-[11px] text-gray-600 focus:outline-none focus:border-emerald-600 rounded-[3px]" />
                        </label>
                    </div>
                </div>

                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-3">
                    <div class="rounded-md bg-white border border-gray-200 shadow-sm p-3 fade-up" style="animation-delay:.04s">
                        <div class="flex items-start justify-between gap-2">
                            <p class="text-[10px] text-gray-400">Total Orders</p><span class="w-7 h-7 bg-emerald-50 flex items-center justify-center rounded-[3px]"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 text-emerald-600">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 0 0 2.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 0 0-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 0 0 .75-.75 2.25 2.25 0 0 0-.1-.664m-5.8 0A2.251 2.251 0 0 1 13.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25Z" />
                                </svg></span>
                        </div>
                        <p id="kpiOrders" class="count text-xl font-bold text-gray-800 mt-4">0</p>
                        <p class="text-[10px] text-emerald-600 mt-1 font-semibold">+12.4% vs last week</p>
                    </div>
                    <div class="rounded-md bg-white border border-gray-200 shadow-sm p-3 fade-up" style="animation-delay:.08s">
                        <div class="flex items-start justify-between gap-2">
                            <p class="text-[10px] text-gray-400">Gross Sales</p><span class="w-7 h-7 bg-amber-50 flex items-center justify-center rounded-[3px]"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 text-amber-500">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v12m3-9.75c0-1.243-1.343-2.25-3-2.25s-3 1.007-3 2.25 1.343 2.25 3 2.25 3 1.007 3 2.25S13.657 15 12 15s-3-1.007-3-2.25M12 3.75v1.5m0 13.5v1.5" />
                                </svg></span>
                        </div>
                        <p id="kpiSales" class="count text-xl font-bold text-gray-800 mt-4">₱0</p>
                        <p class="text-[10px] text-emerald-600 mt-1 font-semibold">+8.7% vs last week</p>
                    </div>
                    <div class="rounded-md bg-white border border-gray-200 shadow-sm p-3 fade-up" style="animation-delay:.12s">
                        <div class="flex items-start justify-between gap-2">
                            <p class="text-[10px] text-gray-400">Avg. Order</p><span class="w-7 h-7 bg-sky-50 flex items-center justify-center rounded-[3px]"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 text-sky-500">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 12h16.5m-16.5 3.75h16.5m-16.5-7.5h16.5" />
                                </svg></span>
                        </div>
                        <p id="kpiAverage" class="count text-xl font-bold text-gray-800 mt-4">₱0</p>
                        <p class="text-[10px] text-gray-400 mt-1">per completed order</p>
                    </div>
                    <div class="rounded-md bg-white border border-gray-200 shadow-sm p-3 fade-up" style="animation-delay:.16s">
                        <div class="flex items-start justify-between gap-2">
                            <p class="text-[10px] text-gray-400">Top Item</p><span class="w-7 h-7 bg-violet-50 flex items-center justify-center rounded-[3px]"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 text-violet-500">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m16.5 7.5 4.5 3-9 5.25L3 10.5l4.5-3m9 0-9 5.25m9-5.25L12 4.5 7.5 7.5m9 0v5.25m-9-5.25v5.25m13.5-2.25v5.25L12 20.25 3 15.75V10.5" />
                                </svg></span>
                        </div>
                        <p id="kpiTopItem" class="text-sm font-bold text-gray-800 mt-4 truncate">Chicken Rice</p>
                        <p id="kpiTopItemCount" class="text-[10px] text-gray-400 mt-1">0 orders</p>
                    </div>
                </div>

                <div class="rounded-md bg-emerald-700 border border-emerald-700 shadow-sm p-4 mb-3 fade-up" style="animation-delay:.2s">
                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                        <div>
                            <p class="text-xs font-bold text-white">Lunch rush is your best selling window</p>
                            <p class="text-[10px] text-emerald-100 mt-1">Students order most between 11:30 AM and 1:00 PM.</p>
                        </div>
                        <div class="flex items-center gap-2"><span class="text-[10px] text-emerald-100">Today</span><span class="px-2 py-1 bg-white/10 border border-white/20 text-[10px] font-semibold text-white rounded-[3px]">₱4,860 lunch sales</span></div>
                    </div>
                </div>

                <div class="rounded-md bg-white border border-gray-200 shadow-sm overflow-hidden mb-3 fade-up" style="animation-delay:.24s">
                    <div class="p-4 border-b border-gray-100 flex items-center justify-between gap-3">
                        <div>
                            <p class="text-[11px] font-bold text-gray-500 uppercase tracking-wide">Order Channel</p>
                            <p class="text-[10px] text-gray-400 mt-0.5">How students receive their meals</p>
                        </div><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 text-gray-300">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 12h16.5m-16.5 3.75h16.5m-16.5-7.5h16.5" />
                        </svg>
                    </div>
                    <div class="p-4 grid grid-cols-2 gap-3">
                        <div class="border border-gray-200 p-3 rounded-[6px]">
                            <div class="flex items-center gap-2"><span class="w-7 h-7 bg-emerald-50 flex items-center justify-center rounded-[3px]"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 text-emerald-600">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 18.75a1.5 1.5 0 1 1-3 0 1.5 1.5 0 0 1 3 0ZM18.75 18.75a1.5 1.5 0 1 1-3 0 1.5 1.5 0 0 1 3 0ZM3 4.5h1.5l1.5 10.5h11.25l2.25-7.5H6" />
                                    </svg></span><span class="text-[11px] font-semibold text-gray-700">Pickup</span></div>
                            <p id="pickupOrders" class="count text-lg font-bold text-gray-800 mt-3">0</p>
                            <p id="pickupShare" class="text-[10px] text-gray-400 mt-0.5">0% of orders</p>
                            <div class="h-1 bg-gray-100 mt-3 rounded-full overflow-hidden">
                                <div id="pickupBar" class="h-full bg-emerald-500 rounded-full transition-all duration-700" style="width:0%"></div>
                            </div>
                        </div>
                        <div class="border border-gray-200 p-3 rounded-[6px]">
                            <div class="flex items-center gap-2"><span class="w-7 h-7 bg-sky-50 flex items-center justify-center rounded-[3px]"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 text-sky-500">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 13.5h2.25l1.5-3 2.25 6 2.25-4.5h3.75l1.5-3h2.25" />
                                    </svg></span><span class="text-[11px] font-semibold text-gray-700">Delivery</span></div>
                            <p id="deliveryOrders" class="count text-lg font-bold text-gray-800 mt-3">0</p>
                            <p id="deliveryShare" class="text-[10px] text-gray-400 mt-0.5">0% of orders</p>
                            <div class="h-1 bg-gray-100 mt-3 rounded-full overflow-hidden">
                                <div id="deliveryBar" class="h-full bg-sky-500 rounded-full transition-all duration-700" style="width:0%"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="rounded-md bg-white border border-gray-200 shadow-sm overflow-hidden mb-3 fade-up" style="animation-delay:.26s">
                    <div class="p-4 border-b border-gray-100 flex items-center justify-between gap-3">
                        <div>
                            <p class="text-[11px] font-bold text-gray-500 uppercase tracking-wide">Customer Roles</p>
                            <p class="text-[10px] text-gray-400 mt-0.5">Who is ordering from your school stall</p>
                        </div><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 text-gray-300">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.125a6.75 6.75 0 0 0-13.5 0m13.5 0v-.75a6.75 6.75 0 0 0-13.5 0v.75m13.5 0h3.75a2.25 2.25 0 0 0 2.25-2.25v-.75a6 6 0 0 0-6-6 6 6 0 0 0-5.15 2.92M12 5.25a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0Z" />
                        </svg>
                    </div>
                    <div id="roleBreakdown" class="divide-y divide-gray-100"></div>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-2 gap-3 mb-3">
                    <div class="rounded-md bg-white border border-gray-200 shadow-sm overflow-hidden fade-up" style="animation-delay:.28s">
                        <div class="p-4 border-b border-gray-100 flex items-center justify-between gap-3">
                            <div>
                                <p class="text-[11px] font-bold text-gray-500 uppercase tracking-wide">Orders by Hour</p>
                                <p class="text-[10px] text-gray-400 mt-0.5">Today’s student order volume</p>
                            </div><span class="text-[10px] font-semibold text-emerald-600">Peak 12 PM</span>
                        </div>
                        <div class="p-4">
                            <div class="h-40 flex items-end gap-2" id="hourChart"></div>
                            <div class="flex justify-between mt-2 text-[9px] text-gray-400"><span>7 AM</span><span>9 AM</span><span>11 AM</span><span>1 PM</span><span>3 PM</span><span>5 PM</span></div>
                        </div>
                    </div>
                    <div class="rounded-md bg-white border border-gray-200 shadow-sm overflow-hidden fade-up" style="animation-delay:.32s">
                        <div class="p-4 border-b border-gray-100 flex items-center justify-between gap-3">
                            <div>
                                <p class="text-[11px] font-bold text-gray-500 uppercase tracking-wide">Sales Trend</p>
                                <p class="text-[10px] text-gray-400 mt-0.5">This week in Philippine pesos</p>
                            </div><span class="text-[10px] font-semibold text-emerald-600">+8.7%</span>
                        </div>
                        <div class="p-4">
                            <div class="h-40 relative"><svg viewBox="0 0 500 150" preserveAspectRatio="none" class="w-full h-full overflow-visible">
                                    <path d="M0 128 C55 120 70 105 110 110 S165 92 210 98 S260 65 305 78 S360 42 400 62 S455 20 500 34 L500 150 L0 150 Z" fill="#d1fae5" opacity=".65"></path>
                                    <path d="M0 128 C55 120 70 105 110 110 S165 92 210 98 S260 65 305 78 S360 42 400 62 S455 20 500 34" fill="none" stroke="#059669" stroke-width="3" stroke-linecap="round" class="line-draw"></path>
                                </svg>
                                <div class="absolute inset-x-0 bottom-0 border-t border-gray-100"></div>
                            </div>
                            <div class="flex justify-between mt-2 text-[9px] text-gray-400"><span>Mon</span><span>Tue</span><span>Wed</span><span>Thu</span><span>Fri</span><span>Sat</span></div>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-2 gap-3 mb-3">
                    <div class="rounded-md bg-white border border-gray-200 shadow-sm overflow-hidden fade-up" style="animation-delay:.36s">
                        <div class="p-4 border-b border-gray-100">
                            <p class="text-[11px] font-bold text-gray-500 uppercase tracking-wide">Best Selling Items</p>
                            <p class="text-[10px] text-gray-400 mt-0.5">Student favorites this week</p>
                        </div>
                        <div id="topItems" class="divide-y divide-gray-100"></div>
                    </div>
                    <div class="rounded-md bg-white border border-gray-200 shadow-sm overflow-hidden fade-up" style="animation-delay:.4s">
                        <div class="p-4 border-b border-gray-100">
                            <p class="text-[11px] font-bold text-gray-500 uppercase tracking-wide">Busiest Hours</p>
                            <p class="text-[10px] text-gray-400 mt-0.5">When to prepare extra servings</p>
                        </div>
                        <div id="busyHours" class="p-4 space-y-3"></div>
                    </div>
                </div>

                <div class="rounded-md bg-white border border-gray-200 shadow-sm overflow-hidden mb-3 fade-up" style="animation-delay:.44s">
                    <div class="p-4 border-b border-gray-100 flex items-center justify-between gap-3">
                        <div>
                            <p class="text-[11px] font-bold text-gray-500 uppercase tracking-wide">Recent Order Pulse</p>
                            <p class="text-[10px] text-gray-400 mt-0.5">Latest static activity from your stall</p>
                        </div><a href="./orders.php" class="text-[11px] font-semibold text-emerald-600 shrink-0">View Orders</a>
                    </div>
                    <div id="recentOrders" class="divide-y divide-gray-100"></div>
                </div>
            </div>
        </div>

    </div>

    <script>
        const datasets = {
            today: {
                orders: 186,
                sales: 28460,
                pickup: 112,
                delivery: 74,
                average: 153,
                topItem: "Chicken Rice",
                topCount: 64,
                hours: [8, 14, 28, 20, 16, 31, 18, 12],
                items: [{
                    name: "Chicken Rice",
                    count: 64,
                    share: 34,
                    color: "bg-emerald-500"
                }, {
                    name: "Iced Gulaman",
                    count: 42,
                    share: 22,
                    color: "bg-sky-500"
                }, {
                    name: "Classic Burger",
                    count: 38,
                    share: 20,
                    color: "bg-amber-500"
                }, {
                    name: "Fries with Cheese",
                    count: 25,
                    share: 13,
                    color: "bg-violet-500"
                }, {
                    name: "Lumpia Shanghai",
                    count: 17,
                    share: 9,
                    color: "bg-rose-400"
                }],
                busy: [{
                    label: "11:30 AM - 12:30 PM",
                    count: 42,
                    share: 100
                }, {
                    label: "12:30 PM - 1:30 PM",
                    count: 36,
                    share: 86
                }, {
                    label: "10:30 AM - 11:30 AM",
                    count: 29,
                    share: 69
                }],
                recent: [{
                    id: "ORD-2026-00186",
                    type: "Pickup",
                    item: "Chicken Rice + Iced Gulaman",
                    total: 185,
                    time: "12:42 PM",
                    status: "Preparing"
                }, {
                    id: "ORD-2026-00185",
                    type: "Delivery",
                    item: "2 Classic Burgers + Fries",
                    total: 310,
                    time: "12:35 PM",
                    status: "Ready"
                }, {
                    id: "ORD-2026-00184",
                    type: "Pickup",
                    item: "Chicken Rice Bowl",
                    total: 120,
                    time: "12:21 PM",
                    status: "Completed"
                }]
            },
            yesterday: {
                orders: 164,
                sales: 24980,
                pickup: 96,
                delivery: 68,
                average: 152,
                topItem: "Chicken Rice",
                topCount: 56,
                hours: [7, 12, 24, 18, 15, 26, 17, 11],
                items: [{
                    name: "Chicken Rice",
                    count: 56,
                    share: 34,
                    color: "bg-emerald-500"
                }, {
                    name: "Classic Burger",
                    count: 37,
                    share: 23,
                    color: "bg-amber-500"
                }, {
                    name: "Iced Gulaman",
                    count: 31,
                    share: 19,
                    color: "bg-sky-500"
                }, {
                    name: "Fries with Cheese",
                    count: 23,
                    share: 14,
                    color: "bg-violet-500"
                }, {
                    name: "Lumpia Shanghai",
                    count: 17,
                    share: 10,
                    color: "bg-rose-400"
                }],
                busy: [{
                    label: "11:30 AM - 12:30 PM",
                    count: 37,
                    share: 100
                }, {
                    label: "12:30 PM - 1:30 PM",
                    count: 31,
                    share: 84
                }, {
                    label: "10:30 AM - 11:30 AM",
                    count: 25,
                    share: 68
                }],
                recent: [{
                    id: "ORD-2026-00164",
                    type: "Delivery",
                    item: "Chicken Rice + Fries",
                    total: 210,
                    time: "12:48 PM",
                    status: "Completed"
                }, {
                    id: "ORD-2026-00163",
                    type: "Pickup",
                    item: "Classic Burger Meal",
                    total: 175,
                    time: "12:32 PM",
                    status: "Completed"
                }, {
                    id: "ORD-2026-00162",
                    type: "Pickup",
                    item: "Iced Gulaman",
                    total: 45,
                    time: "12:14 PM",
                    status: "Completed"
                }]
            },
            week: {
                orders: 1024,
                sales: 154620,
                pickup: 607,
                delivery: 417,
                average: 151,
                topItem: "Chicken Rice",
                topCount: 342,
                hours: [42, 68, 112, 96, 88, 126, 74, 51],
                items: [{
                    name: "Chicken Rice",
                    count: 342,
                    share: 33,
                    color: "bg-emerald-500"
                }, {
                    name: "Classic Burger",
                    count: 227,
                    share: 22,
                    color: "bg-amber-500"
                }, {
                    name: "Iced Gulaman",
                    count: 188,
                    share: 18,
                    color: "bg-sky-500"
                }, {
                    name: "Fries with Cheese",
                    count: 151,
                    share: 15,
                    color: "bg-violet-500"
                }, {
                    name: "Lumpia Shanghai",
                    count: 116,
                    share: 12,
                    color: "bg-rose-400"
                }],
                busy: [{
                    label: "11:30 AM - 12:30 PM",
                    count: 238,
                    share: 100
                }, {
                    label: "12:30 PM - 1:30 PM",
                    count: 204,
                    share: 86
                }, {
                    label: "10:30 AM - 11:30 AM",
                    count: 173,
                    share: 73
                }],
                recent: [{
                    id: "ORD-2026-01024",
                    type: "Pickup",
                    item: "Chicken Rice + Iced Gulaman",
                    total: 185,
                    time: "Today, 12:42 PM",
                    status: "Preparing"
                }, {
                    id: "ORD-2026-01023",
                    type: "Delivery",
                    item: "2 Classic Burgers + Fries",
                    total: 310,
                    time: "Today, 12:35 PM",
                    status: "Ready"
                }, {
                    id: "ORD-2026-01022",
                    type: "Pickup",
                    item: "Chicken Rice Bowl",
                    total: 120,
                    time: "Today, 12:21 PM",
                    status: "Completed"
                }]
            },
            month: {
                orders: 4228,
                sales: 638490,
                pickup: 2506,
                delivery: 1722,
                average: 151,
                topItem: "Chicken Rice",
                topCount: 1394,
                hours: [174, 286, 438, 371, 328, 492, 301, 226],
                items: [{
                    name: "Chicken Rice",
                    count: 1394,
                    share: 33,
                    color: "bg-emerald-500"
                }, {
                    name: "Classic Burger",
                    count: 934,
                    share: 22,
                    color: "bg-amber-500"
                }, {
                    name: "Iced Gulaman",
                    count: 768,
                    share: 18,
                    color: "bg-sky-500"
                }, {
                    name: "Fries with Cheese",
                    count: 634,
                    share: 15,
                    color: "bg-violet-500"
                }, {
                    name: "Lumpia Shanghai",
                    count: 498,
                    share: 12,
                    color: "bg-rose-400"
                }],
                busy: [{
                    label: "11:30 AM - 12:30 PM",
                    count: 962,
                    share: 100
                }, {
                    label: "12:30 PM - 1:30 PM",
                    count: 812,
                    share: 84
                }, {
                    label: "10:30 AM - 11:30 AM",
                    count: 694,
                    share: 72
                }],
                recent: [{
                    id: "ORD-2026-04228",
                    type: "Pickup",
                    item: "Chicken Rice + Iced Gulaman",
                    total: 185,
                    time: "Today, 12:42 PM",
                    status: "Preparing"
                }, {
                    id: "ORD-2026-04227",
                    type: "Delivery",
                    item: "2 Classic Burgers + Fries",
                    total: 310,
                    time: "Today, 12:35 PM",
                    status: "Ready"
                }, {
                    id: "ORD-2026-04226",
                    type: "Pickup",
                    item: "Chicken Rice Bowl",
                    total: 120,
                    time: "Today, 12:21 PM",
                    status: "Completed"
                }]
            }
        };

        const roleData = [{
                name: "Students",
                detail: "Learners",
                orders: 124,
                sales: 18840,
                share: 67,
                color: "bg-emerald-500",
                icon: "M12 14.25a4.5 4.5 0 1 0 0-9 4.5 4.5 0 0 0 0 9Zm-7.5 6a7.5 7.5 0 0 1 15 0"
            },
            {
                name: "Faculty",
                detail: "Teaching staff",
                orders: 24,
                sales: 4370,
                share: 13,
                color: "bg-sky-500",
                icon: "M12 6.75V3m0 3.75a3 3 0 1 0 0 6 3 3 0 0 0 0-6ZM5.25 21a6.75 6.75 0 0 1 13.5 0"
            },
            {
                name: "Staff",
                detail: "School personnel",
                orders: 29,
                sales: 4280,
                share: 16,
                color: "bg-amber-500",
                icon: "M12 6.75V3m0 3.75a3 3 0 1 0 0 6 3 3 0 0 0 0-6ZM5.25 21a6.75 6.75 0 0 1 13.5 0"
            },
            {
                name: "Guests",
                detail: "Visitors and others",
                orders: 9,
                sales: 970,
                share: 4,
                color: "bg-violet-500",
                icon: "M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0"
            }
        ];

        let activeRange = "today";
        const money = value => "₱" + Number(value).toLocaleString("en-PH");
        const animateNumber = (element, end, prefix = "") => {
            const start = Number(element.dataset.value || 0);
            const duration = 650;
            const started = performance.now();
            const tick = now => {
                const progress = Math.min((now - started) / duration, 1);
                const eased = 1 - Math.pow(1 - progress, 3);
                const current = Math.round(start + (end - start) * eased);
                element.textContent = prefix + current.toLocaleString("en-PH");
                if (progress < 1) requestAnimationFrame(tick);
                else element.dataset.value = end;
            };
            requestAnimationFrame(tick);
        };

        function renderBars(data) {
            const max = Math.max(...data.hours);
            document.getElementById("hourChart").innerHTML = data.hours.map((value, index) => `<div class="flex-1 h-full flex items-end"><div class="bar w-full ${index === 5 ? "bg-emerald-700" : "bg-emerald-500"} rounded-t-[3px]" style="height:${Math.max(10, value / max * 100)}%;animation-delay:${index * 45}ms" title="${value} orders"></div></div>`).join("");
        }

        function renderItems(items) {
            document.getElementById("topItems").innerHTML = items.map((item, index) => `<div class="flex items-center gap-3 px-4 py-3"><span class="w-5 text-[10px] text-gray-400">0${index + 1}</span><span class="w-7 h-7 ${item.color} bg-opacity-10 flex items-center justify-center rounded-[3px]"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 text-gray-500"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.75v10.5m-3.75-3.75h7.5M4.5 6.75h15v10.5h-15z" /></svg></span><span class="flex-1 min-w-0 text-[11px] font-medium text-gray-700 truncate">${item.name}</span><span class="text-[11px] font-semibold text-gray-800">${item.count}</span><span class="w-12 h-1 bg-gray-100 rounded-full overflow-hidden"><span class="block h-full ${item.color} rounded-full" style="width:${item.share * 2.2}%"></span></span></div>`).join("");
        }

        function renderRoles() {
            document.getElementById("roleBreakdown").innerHTML = roleData.map(role => `<div class="px-4 py-3 flex items-center gap-3"><span class="w-8 h-8 ${role.color} bg-opacity-10 flex items-center justify-center rounded-[3px]"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 text-gray-500"><path stroke-linecap="round" stroke-linejoin="round" d="${role.icon}" /></svg></span><div class="flex-1 min-w-0"><p class="text-[11px] font-semibold text-gray-700">${role.name}</p><p class="text-[10px] text-gray-400 mt-0.5">${role.detail} · ${role.orders} orders</p></div><div class="w-28 sm:w-40"><div class="h-1.5 bg-gray-100 rounded-full overflow-hidden"><div class="h-full ${role.color} rounded-full transition-all duration-700" style="width:${role.share}%"></div></div></div><div class="text-right w-16 shrink-0"><p class="text-[11px] font-semibold text-gray-800">${money(role.sales)}</p><p class="text-[10px] text-gray-400 mt-0.5">${role.share}% share</p></div></div>`).join("");
        }

        function renderBusy(items) {
            document.getElementById("busyHours").innerHTML = items.map((item, index) => `<div><div class="flex items-center justify-between gap-2 mb-1"><span class="text-[11px] font-medium text-gray-700">${item.label}</span><span class="text-[10px] font-semibold text-gray-500">${item.count} orders</span></div><div class="h-1.5 bg-gray-100 rounded-full overflow-hidden"><div class="h-full ${index === 0 ? "bg-emerald-600" : "bg-emerald-400"} rounded-full transition-all duration-700" style="width:${item.share}%"></div></div></div>`).join("");
        }

        function renderRecent(items) {
            document.getElementById("recentOrders").innerHTML = items.map(item => `<div class="px-4 py-3 flex items-center gap-3"><span class="w-8 h-8 ${item.type === "Pickup" ? "bg-emerald-50 text-emerald-600" : "bg-sky-50 text-sky-500"} flex items-center justify-center rounded-[3px]"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="${item.type === "Pickup" ? "M8.25 18.75a1.5 1.5 0 1 1-3 0 1.5 1.5 0 0 1 3 0ZM18.75 18.75a1.5 1.5 0 1 1-3 0 1.5 1.5 0 0 1 3 0ZM3 4.5h1.5l1.5 10.5h11.25l2.25-7.5H6" : "M8.25 18.75a1.5 1.5 0 1 1-3 0 1.5 1.5 0 0 1 3 0ZM18.75 18.75a1.5 1.5 0 1 1-3 0 1.5 1.5 0 0 1 3 0ZM3 4.5h2.25l1.5 9h10.5l2.25-6H7.5"}" /></svg></span><div class="flex-1 min-w-0"><p class="text-[11px] font-semibold text-gray-700 truncate">${item.item}</p><p class="text-[10px] text-gray-400 mt-0.5">${item.id} · ${item.type} · ${item.time}</p></div><div class="text-right shrink-0"><p class="text-[11px] font-semibold text-gray-800">${money(item.total)}</p><p class="text-[10px] ${item.status === "Completed" ? "text-emerald-600" : "text-amber-500"} mt-0.5">${item.status}</p></div></div>`).join("");
        }

        function render(data) {
            animateNumber(document.getElementById("kpiOrders"), data.orders);
            animateNumber(document.getElementById("kpiSales"), data.sales, "₱");
            animateNumber(document.getElementById("kpiAverage"), data.average, "₱");
            document.getElementById("kpiTopItem").textContent = data.topItem;
            document.getElementById("kpiTopItemCount").textContent = data.topCount.toLocaleString("en-PH") + " orders";
            const pickupShare = Math.round(data.pickup / data.orders * 100);
            const deliveryShare = 100 - pickupShare;
            animateNumber(document.getElementById("pickupOrders"), data.pickup);
            animateNumber(document.getElementById("deliveryOrders"), data.delivery);
            document.getElementById("pickupShare").textContent = pickupShare + "% of orders";
            document.getElementById("deliveryShare").textContent = deliveryShare + "% of orders";
            document.getElementById("pickupBar").style.width = pickupShare + "%";
            document.getElementById("deliveryBar").style.width = deliveryShare + "%";
            renderBars(data);
            renderItems(data.items);
            renderBusy(data.busy);
            renderRecent(data.recent);
            renderRoles();
        }

        document.querySelectorAll(".range-btn").forEach(button => button.addEventListener("click", () => {
            activeRange = button.dataset.range;
            document.querySelectorAll(".range-btn").forEach(item => item.className = "range-btn px-3 py-1.5 bg-white border border-gray-200 text-gray-600 text-[11px] font-semibold hover:border-emerald-500 hover:text-emerald-600 transition-colors rounded-[3px]");
            button.className = "range-btn px-3 py-1.5 bg-emerald-600 text-white text-[11px] font-semibold hover:bg-emerald-700 transition-colors rounded-[3px]";
            render(datasets[activeRange]);
        }));

        document.getElementById("dateInput").addEventListener("change", () => {
            document.querySelectorAll(".range-btn").forEach(item => item.className = "range-btn px-3 py-1.5 bg-white border border-gray-200 text-gray-600 text-[11px] font-semibold hover:border-emerald-500 hover:text-emerald-600 transition-colors rounded-[3px]");
            render(datasets.today);
        });

        document.getElementById("backButton").addEventListener("click", () => {
            window.location.href = "./dashboard.php";
        });
        render(datasets.today);
    </script>
</body>

</html>