<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>About — NWSSU Food Court</title>
  <link rel="icon" href="assets/images/nwssu-logo.png" type="image/png" />
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    @import url("https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap");
    * {
      font-family: "Poppins", sans-serif;
    }
    body {
      background: #fafafa;
      min-height: 100vh;
      margin: 0;
    }
    body::-webkit-scrollbar {
      width: 6px;
    }
    body::-webkit-scrollbar-track {
      background: #f1f5f9;
    }
    body::-webkit-scrollbar-thumb {
      background: #059669;
      border-radius: 3px;
    }
    .hero-glow {
      background: radial-gradient(circle at 30% 20%, rgba(5,150,105,0.10), transparent 45%),
                  radial-gradient(circle at 80% 60%, rgba(5,150,105,0.08), transparent 40%);
    }
    .fade-up {
      animation: fadeUp 0.6s ease both;
    }
    @keyframes fadeUp {
      from { opacity: 0; transform: translateY(14px); }
      to { opacity: 1; transform: translateY(0); }
    }
    .chat-panel {
      transform: translateY(16px) scale(0.97);
      opacity: 0;
      pointer-events: none;
      transition: all 0.22s ease;
    }
    .chat-panel.open {
      transform: translateY(0) scale(1);
      opacity: 1;
      pointer-events: all;
    }
    .star-btn {
      transition: transform 0.15s ease;
    }
    .star-btn:hover {
      transform: scale(1.15);
    }
  </style>
</head>
<body class="bg-[#fafafa]">

  <div class="hero-glow fixed inset-0 -z-10"></div>

  <header class="sticky top-0 z-30 bg-white/80 backdrop-blur-md border-b border-gray-100">
    <div class="max-w-5xl mx-auto px-5 py-3 flex items-center justify-between">
      <a href="./auth/login.php" class="flex items-center gap-2">
        <img src="assets/images/nwssu-logo.png" alt="NWSSU Food Court" class="w-8 h-8 rounded-full object-cover" />
        <span class="text-sm font-bold text-gray-800">NWSSU Food Court</span>
      </a>
      <nav class="hidden sm:flex items-center gap-6 text-xs font-semibold text-gray-500">
        <a href="#about" class="hover:text-emerald-600 transition-colors">About</a>
        <a href="#stack" class="hover:text-emerald-600 transition-colors">Tech Stack</a>
        <a href="#team" class="hover:text-emerald-600 transition-colors">Team</a>
        <a href="#adviser" class="hover:text-emerald-600 transition-colors">Adviser</a>
        <a href="#feedback" class="hover:text-emerald-600 transition-colors">Feedback</a>
      </nav>
      <a href="./auth/login.php" class="text-[11px] font-semibold px-3 py-1.5 border border-gray-200 rounded-full text-gray-600 hover:border-emerald-500 hover:text-emerald-600 transition-all">
        Back to App
      </a>
    </div>
  </header>

  <section class="max-w-5xl mx-auto px-5 pt-16 pb-14 text-center fade-up">
    <span class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-emerald-50 border border-emerald-100 text-emerald-700 text-[11px] font-semibold rounded-full">
      <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-3.5 h-3.5">
        <path stroke-linecap="round" stroke-linejoin="round" d="M4.26 10.147a60.436 60.436 0 0 0-.491 6.347A48.627 48.627 0 0 1 12 20.904a48.627 48.627 0 0 1 8.232-4.41 60.46 60.46 0 0 0-.491-6.347m-15.482 0a50.57 50.57 0 0 0-2.658-.813A59.905 59.905 0 0 1 12 3.493a59.902 59.902 0 0 1 10.399 5.84c-.896.248-1.783.52-2.658.814m-15.482 0A50.697 50.697 0 0 1 12 13.489a50.702 50.702 0 0 1 7.74-3.342M6.75 15a.75.75 0 1 0 0-1.5.75.75 0 0 0 0 1.5Zm0 0v-3.675A55.378 55.378 0 0 1 12 8.443" />
      </svg>
      Capstone Thesis Project
    </span>
    <h1 class="text-2xl sm:text-4xl font-extrabold text-gray-900 mt-5 leading-tight max-w-3xl mx-auto">
      A Web-Based Food Court Ordering System for Northwest Samar State University
    </h1>
    <p class="text-sm text-gray-500 mt-4 max-w-xl mx-auto leading-relaxed">
      A campus-wide ordering platform built to streamline pickup and delivery
      across every food stall in NwSSU Main — fast, mobile-first, and
      installable right on your phone.
    </p>
    <div class="flex items-center justify-center gap-2 mt-6">
      <span class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-white border border-gray-200 text-gray-600 text-[11px] font-semibold rounded-full shadow-sm">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-3.5 h-3.5 text-emerald-600">
          <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 1.5H8.25A2.25 2.25 0 0 0 6 3.75v16.5a2.25 2.25 0 0 0 2.25 2.25h7.5A2.25 2.25 0 0 0 18 20.25V3.75a2.25 2.25 0 0 0-2.25-2.25H13.5m-3 0V3h3V1.5m-3 0h3m-3 18.75h3" />
        </svg>
        Progressive Web App
      </span>
      <span class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-white border border-gray-200 text-gray-600 text-[11px] font-semibold rounded-full shadow-sm">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-3.5 h-3.5 text-emerald-600">
          <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 10.5V6a3.75 3.75 0 1 0-7.5 0v4.5m11.356-1.993 1.263 12c.07.665-.45 1.243-1.119 1.243H4.25a1.125 1.125 0 0 1-1.12-1.243l1.264-12A1.125 1.125 0 0 1 5.513 7.5h12.974c.576 0 1.059.435 1.119 1.007Z" />
        </svg>
        Secure Ordering
      </span>
    </div>
  </section>

  <section id="about" class="max-w-4xl mx-auto px-5 py-10">
    <div class="bg-white border border-gray-100 shadow-sm rounded-2xl p-6 sm:p-8">
      <p class="text-[11px] font-bold text-emerald-600 uppercase tracking-widest">About the System</p>
      <h2 class="text-lg font-bold text-gray-900 mt-1.5">What this project solves</h2>
      <p class="text-sm text-gray-500 mt-3 leading-relaxed">
        NWSSU Food Court digitizes the entire campus food-ordering experience —
        from menu browsing and cart checkout to real-time order tracking for
        pickup and delivery. Stall owners manage their own orders and menus,
        delivery staff coordinate handoffs, and admins oversee the whole
        ecosystem, all from one connected platform.
      </p>
      <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mt-6">
        <div class="text-center p-3 bg-gray-50 rounded-xl">
          <p class="text-xl font-extrabold text-emerald-600">4</p>
          <p class="text-[10px] font-semibold text-gray-500 mt-0.5">User Roles</p>
        </div>
        <div class="text-center p-3 bg-gray-50 rounded-xl">
          <p class="text-xl font-extrabold text-emerald-600">2</p>
          <p class="text-[10px] font-semibold text-gray-500 mt-0.5">Order Modes</p>
        </div>
        <div class="text-center p-3 bg-gray-50 rounded-xl">
          <p class="text-xl font-extrabold text-emerald-600">1</p>
          <p class="text-[10px] font-semibold text-gray-500 mt-0.5">Unified Platform</p>
        </div>
        <div class="text-center p-3 bg-gray-50 rounded-xl">
          <p class="text-xl font-extrabold text-emerald-600">24/7</p>
          <p class="text-[10px] font-semibold text-gray-500 mt-0.5">Availability</p>
        </div>
      </div>
    </div>
  </section>

  <section id="stack" class="max-w-4xl mx-auto px-5 py-10">
    <div class="text-center mb-8">
      <p class="text-[11px] font-bold text-emerald-600 uppercase tracking-widest">Built With</p>
      <h2 class="text-lg font-bold text-gray-900 mt-1.5">Technology Stack</h2>
    </div>
    <div class="grid grid-cols-2 sm:grid-cols-5 gap-3">
      <div class="bg-white border border-gray-100 shadow-sm rounded-xl p-4 text-center hover:-translate-y-1 hover:shadow-md transition-all">
        <div class="w-10 h-10 bg-orange-50 flex items-center justify-center mx-auto rounded-xl">
          <span class="text-orange-500 font-extrabold text-xs">HTML</span>
        </div>
        <p class="text-[11px] font-semibold text-gray-700 mt-2">HTML5</p>
      </div>
      <div class="bg-white border border-gray-100 shadow-sm rounded-xl p-4 text-center hover:-translate-y-1 hover:shadow-md transition-all">
        <div class="w-10 h-10 bg-cyan-50 flex items-center justify-center mx-auto rounded-xl">
          <span class="text-cyan-500 font-extrabold text-xs">TW</span>
        </div>
        <p class="text-[11px] font-semibold text-gray-700 mt-2">Tailwind CSS</p>
      </div>
      <div class="bg-white border border-gray-100 shadow-sm rounded-xl p-4 text-center hover:-translate-y-1 hover:shadow-md transition-all">
        <div class="w-10 h-10 bg-amber-50 flex items-center justify-center mx-auto rounded-xl">
          <span class="text-amber-500 font-extrabold text-xs">JS</span>
        </div>
        <p class="text-[11px] font-semibold text-gray-700 mt-2">JavaScript</p>
      </div>
      <div class="bg-white border border-gray-100 shadow-sm rounded-xl p-4 text-center hover:-translate-y-1 hover:shadow-md transition-all">
        <div class="w-10 h-10 bg-indigo-50 flex items-center justify-center mx-auto rounded-xl">
          <span class="text-indigo-500 font-extrabold text-xs">PHP</span>
        </div>
        <p class="text-[11px] font-semibold text-gray-700 mt-2">PHP</p>
      </div>
      <div class="bg-white border border-gray-100 shadow-sm rounded-xl p-4 text-center hover:-translate-y-1 hover:shadow-md transition-all">
        <div class="w-10 h-10 bg-blue-50 flex items-center justify-center mx-auto rounded-xl">
          <span class="text-blue-500 font-extrabold text-xs">SQL</span>
        </div>
        <p class="text-[11px] font-semibold text-gray-700 mt-2">MySQL</p>
      </div>
    </div>
  </section>

  <section id="team" class="max-w-4xl mx-auto px-5 py-10">
    <div class="text-center mb-8">
      <p class="text-[11px] font-bold text-emerald-600 uppercase tracking-widest">The People Behind It</p>
      <h2 class="text-lg font-bold text-gray-900 mt-1.5">Meet the Team</h2>
    </div>
    <div class="grid sm:grid-cols-3 gap-4">
      <div class="bg-white border border-gray-100 shadow-sm rounded-2xl p-5 text-center hover:shadow-md transition-all">
        <div class="w-16 h-16 bg-gradient-to-br from-emerald-500 to-emerald-700 flex items-center justify-center text-white font-bold text-lg mx-auto rounded-full">
          ND
        </div>
        <p class="text-sm font-bold text-gray-800 mt-3">Nathaniel Dagamac</p>
        <p class="text-[10px] text-gray-400 font-medium">BSIT</p>
        <p class="text-[11px] text-emerald-600 font-semibold mt-1">Team Leader &middot; Lead Developer &amp; Programmer</p>
      </div>
      <div class="bg-white border border-gray-100 shadow-sm rounded-2xl p-5 text-center hover:shadow-md transition-all">
        <div class="w-16 h-16 bg-gradient-to-br from-blue-500 to-blue-700 flex items-center justify-center text-white font-bold text-lg mx-auto rounded-full">
          SC
        </div>
        <p class="text-sm font-bold text-gray-800 mt-3">Sheryl Caibog</p>
        <p class="text-[10px] text-gray-400 font-medium">BSIS</p>
        <p class="text-[11px] text-blue-600 font-semibold mt-1">Documentation &amp; Research</p>
      </div>
      <div class="bg-white border border-gray-100 shadow-sm rounded-2xl p-5 text-center hover:shadow-md transition-all">
        <div class="w-16 h-16 bg-gradient-to-br from-purple-500 to-purple-700 flex items-center justify-center text-white font-bold text-lg mx-auto rounded-full">
          JC
        </div>
        <p class="text-sm font-bold text-gray-800 mt-3">Jenuel Castillo</p>
        <p class="text-[10px] text-gray-400 font-medium">BSIT</p>
        <p class="text-[11px] text-purple-600 font-semibold mt-1">Quality Assurance &amp; Testing</p>
      </div>
      <div class="bg-white border border-gray-100 shadow-sm rounded-2xl p-5 text-center hover:shadow-md transition-all">
        <div class="w-16 h-16 bg-gradient-to-br from-rose-500 to-rose-700 flex items-center justify-center text-white font-bold text-lg mx-auto rounded-full">
          RB
        </div>
        <p class="text-sm font-bold text-gray-800 mt-3">Rina Baga</p>
        <p class="text-[10px] text-gray-400 font-medium">BSIT</p>
        <p class="text-[11px] text-rose-600 font-semibold mt-1">System Analyst</p>
      </div>
      <div class="bg-white border border-gray-100 shadow-sm rounded-2xl p-5 text-center hover:shadow-md transition-all">
        <div class="w-16 h-16 bg-gradient-to-br from-amber-500 to-amber-700 flex items-center justify-center text-white font-bold text-lg mx-auto rounded-full">
          EP
        </div>
        <p class="text-sm font-bold text-gray-800 mt-3">Erica Piamonte</p>
        <p class="text-[10px] text-gray-400 font-medium">BSIS</p>
        <p class="text-[11px] text-amber-600 font-semibold mt-1">UI/UX Designer</p>
      </div>
      <div class="bg-white border border-gray-100 shadow-sm rounded-2xl p-5 text-center hover:shadow-md transition-all">
        <div class="w-16 h-16 bg-gradient-to-br from-teal-500 to-teal-700 flex items-center justify-center text-white font-bold text-lg mx-auto rounded-full">
          JC
        </div>
        <p class="text-sm font-bold text-gray-800 mt-3">Jio Canaman</p>
        <p class="text-[10px] text-gray-400 font-medium">BSIS</p>
        <p class="text-[11px] text-teal-600 font-semibold mt-1">Project Coordinator</p>
      </div>
    </div>
  </section>

  <section id="adviser" class="max-w-4xl mx-auto px-5 py-10">
    <div class="text-center mb-8">
      <p class="text-[11px] font-bold text-emerald-600 uppercase tracking-widest">Guidance &amp; Mentorship</p>
      <h2 class="text-lg font-bold text-gray-900 mt-1.5">Capstone Adviser</h2>
    </div>
    <div class="bg-white border border-gray-100 shadow-sm rounded-2xl p-6 max-w-sm mx-auto text-center">
      <div class="w-16 h-16 bg-gradient-to-br from-gray-600 to-gray-800 flex items-center justify-center text-white font-bold text-lg mx-auto rounded-full">
        AH
      </div>
      <p class="text-sm font-bold text-gray-800 mt-3">Arnelyn Heleran</p>
      <p class="text-[11px] text-gray-500 font-semibold mt-0.5">Thesis Adviser</p>
    </div>
  </section>

  <section class="max-w-2xl mx-auto px-5 py-10">
    <div class="text-center">
      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-8 h-8 text-emerald-200 mx-auto">
        <path d="M6.75 3A3.75 3.75 0 0 0 3 6.75v3a3.75 3.75 0 0 0 3.75 3.75H8.25v.75A3.75 3.75 0 0 1 4.5 18H4a.75.75 0 0 0 0 1.5h.5a5.25 5.25 0 0 0 5.25-5.25v-7.5A3.75 3.75 0 0 0 6.75 3ZM17.25 3A3.75 3.75 0 0 0 13.5 6.75v3a3.75 3.75 0 0 0 3.75 3.75h1.5v.75A3.75 3.75 0 0 1 15 18h-.5a.75.75 0 0 0 0 1.5h.5a5.25 5.25 0 0 0 5.25-5.25v-7.5A3.75 3.75 0 0 0 17.25 3Z" />
      </svg>
      <p class="text-sm sm:text-base font-semibold text-gray-700 italic mt-3 leading-relaxed">
        "Commit to the Lord whatever you do, and he will establish your plans."
      </p>
      <p class="text-[11px] font-bold text-emerald-600 uppercase tracking-widest mt-2">Proverbs 16:3</p>
    </div>
  </section>

  <section id="feedback" class="max-w-3xl mx-auto px-5 py-10">
    <div class="text-center mb-8">
      <p class="text-[11px] font-bold text-emerald-600 uppercase tracking-widest">We'd Love to Hear From You</p>
      <h2 class="text-lg font-bold text-gray-900 mt-1.5">Send Feedback</h2>
      <p class="text-xs text-gray-500 mt-2">Found a bug or have a suggestion? Let us know below.</p>
    </div>
    <div class="bg-white border border-gray-100 shadow-sm rounded-2xl p-6 sm:p-8">
      <div class="flex items-center justify-center gap-1.5 mb-5" id="feedbackStars">
        <button type="button" class="star-btn" data-star="1">
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="w-7 h-7 text-gray-300">
            <path stroke-linecap="round" stroke-linejoin="round" d="M11.48 3.499a.562.562 0 0 1 1.04 0l2.125 5.111a.563.563 0 0 0 .475.345l5.518.442c.499.04.701.663.321.988l-4.204 3.602a.563.563 0 0 0-.182.557l1.285 5.385a.562.562 0 0 1-.84.61l-4.725-2.885a.562.562 0 0 0-.586 0L6.982 20.54a.562.562 0 0 1-.84-.61l1.285-5.386a.562.562 0 0 0-.182-.557l-4.204-3.602a.562.562 0 0 1 .321-.988l5.518-.442a.563.563 0 0 0 .475-.345L11.48 3.5Z" />
          </svg>
        </button>
        <button type="button" class="star-btn" data-star="2">
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="w-7 h-7 text-gray-300">
            <path stroke-linecap="round" stroke-linejoin="round" d="M11.48 3.499a.562.562 0 0 1 1.04 0l2.125 5.111a.563.563 0 0 0 .475.345l5.518.442c.499.04.701.663.321.988l-4.204 3.602a.563.563 0 0 0-.182.557l1.285 5.385a.562.562 0 0 1-.84.61l-4.725-2.885a.562.562 0 0 0-.586 0L6.982 20.54a.562.562 0 0 1-.84-.61l1.285-5.386a.562.562 0 0 0-.182-.557l-4.204-3.602a.562.562 0 0 1 .321-.988l5.518-.442a.563.563 0 0 0 .475-.345L11.48 3.5Z" />
          </svg>
        </button>
        <button type="button" class="star-btn" data-star="3">
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="w-7 h-7 text-gray-300">
            <path stroke-linecap="round" stroke-linejoin="round" d="M11.48 3.499a.562.562 0 0 1 1.04 0l2.125 5.111a.563.563 0 0 0 .475.345l5.518.442c.499.04.701.663.321.988l-4.204 3.602a.563.563 0 0 0-.182.557l1.285 5.385a.562.562 0 0 1-.84.61l-4.725-2.885a.562.562 0 0 0-.586 0L6.982 20.54a.562.562 0 0 1-.84-.61l1.285-5.386a.562.562 0 0 0-.182-.557l-4.204-3.602a.562.562 0 0 1 .321-.988l5.518-.442a.563.563 0 0 0 .475-.345L11.48 3.5Z" />
          </svg>
        </button>
        <button type="button" class="star-btn" data-star="4">
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="w-7 h-7 text-gray-300">
            <path stroke-linecap="round" stroke-linejoin="round" d="M11.48 3.499a.562.562 0 0 1 1.04 0l2.125 5.111a.563.563 0 0 0 .475.345l5.518.442c.499.04.701.663.321.988l-4.204 3.602a.563.563 0 0 0-.182.557l1.285 5.385a.562.562 0 0 1-.84.61l-4.725-2.885a.562.562 0 0 0-.586 0L6.982 20.54a.562.562 0 0 1-.84-.61l1.285-5.386a.562.562 0 0 0-.182-.557l-4.204-3.602a.562.562 0 0 1 .321-.988l5.518-.442a.563.563 0 0 0 .475-.345L11.48 3.5Z" />
          </svg>
        </button>
        <button type="button" class="star-btn" data-star="5">
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="w-7 h-7 text-gray-300">
            <path stroke-linecap="round" stroke-linejoin="round" d="M11.48 3.499a.562.562 0 0 1 1.04 0l2.125 5.111a.563.563 0 0 0 .475.345l5.518.442c.499.04.701.663.321.988l-4.204 3.602a.563.563 0 0 0-.182.557l1.285 5.385a.562.562 0 0 1-.84.61l-4.725-2.885a.562.562 0 0 0-.586 0L6.982 20.54a.562.562 0 0 1-.84-.61l1.285-5.386a.562.562 0 0 0-.182-.557l-4.204-3.602a.562.562 0 0 1 .321-.988l5.518-.442a.563.563 0 0 0 .475-.345L11.48 3.5Z" />
          </svg>
        </button>
      </div>
      <div class="grid sm:grid-cols-2 gap-3">
        <input type="text" placeholder="Your name" class="w-full px-3 py-2.5 bg-white border border-gray-200 text-xs text-gray-900 placeholder-gray-400 focus:outline-none focus:border-emerald-600 rounded-[8px]" />
        <input type="email" placeholder="Your email" class="w-full px-3 py-2.5 bg-white border border-gray-200 text-xs text-gray-900 placeholder-gray-400 focus:outline-none focus:border-emerald-600 rounded-[8px]" />
      </div>
      <textarea rows="4" placeholder="Tell us what you think..." class="w-full mt-3 px-3 py-2.5 bg-white border border-gray-200 text-xs text-gray-900 placeholder-gray-400 focus:outline-none focus:border-emerald-600 rounded-[8px] resize-none"></textarea>
      <button type="button" class="w-full mt-4 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold transition-colors rounded-[8px]">
        Submit Feedback
      </button>
    </div>
  </section>

  <footer class="border-t border-gray-100 mt-10">
    <div class="max-w-5xl mx-auto px-5 py-8 flex flex-col sm:flex-row items-center justify-between gap-3">
      <div class="flex items-center gap-2">
        <img src="assets/images/nwssu-logo.png" alt="NWSSU Food Court" class="w-7 h-7 rounded-full object-cover" />
        <span class="text-xs font-semibold text-gray-600">NWSSU Food Court &copy; 2026</span>
      </div>
      <p class="text-[11px] text-gray-400">A Progressive Web App Capstone Project</p>
      <a href="./auth/login.php" class="text-[11px] font-semibold text-emerald-600 hover:text-emerald-700">
        Back to App
      </a>
    </div>
  </footer>

  <button id="chatFab" class="fixed bottom-5 right-5 z-40 w-14 h-14 bg-emerald-600 hover:bg-emerald-700 text-white shadow-xl rounded-full flex items-center justify-center transition-all hover:scale-105">
    <svg id="chatFabIconOpen" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6">
      <path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H8.25m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H12m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 0 1-2.555-.337A5.972 5.972 0 0 1 5.41 20.97a5.969 5.969 0 0 1-.474-.065 4.48 4.48 0 0 0 .978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25Z" />
    </svg>
    <svg id="chatFabIconClose" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6 hidden">
      <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
    </svg>
  </button>

  <div id="chatPanel" class="chat-panel fixed bottom-24 right-5 z-40 w-[92vw] max-w-sm bg-white shadow-2xl border border-gray-100 rounded-2xl overflow-hidden">
    <div class="bg-emerald-600 px-4 py-3 flex items-center gap-2.5">
      <div class="w-9 h-9 bg-white/15 flex items-center justify-center rounded-full">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="white" class="w-5 h-5">
          <path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09Z" />
        </svg>
      </div>
      <div>
        <p class="text-white text-xs font-bold">Food Court Assistant</p>
        <p class="text-emerald-100 text-[10px]">Ask me anything</p>
      </div>
    </div>
    <div class="p-4 h-64 overflow-y-auto bg-gray-50 space-y-3">
      <div class="flex items-start gap-2">
        <div class="w-7 h-7 bg-emerald-600 flex items-center justify-center text-white rounded-full shrink-0">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09Z" />
          </svg>
        </div>
        <div class="bg-white border border-gray-100 shadow-sm rounded-2xl rounded-tl-sm px-3.5 py-2.5 max-w-[75%]">
          <p class="text-xs text-gray-700">Hi! How can I help you today?</p>
        </div>
      </div>
    </div>
    <div class="p-3 border-t border-gray-100 flex items-center gap-2">
      <input type="text" placeholder="Type a message..." class="flex-1 px-3 py-2.5 bg-gray-50 border border-gray-200 text-xs text-gray-900 placeholder-gray-400 focus:outline-none focus:border-emerald-600 rounded-full" />
      <button type="button" id="chatSendBtn" class="w-9 h-9 bg-emerald-600 hover:bg-emerald-700 text-white flex items-center justify-center shrink-0 rounded-full transition-colors">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4">
          <path stroke-linecap="round" stroke-linejoin="round" d="M6 12 3.269 3.126A59.768 59.768 0 0 1 21.485 12 59.77 59.77 0 0 1 3.27 20.876L5.999 12Zm0 0h7.5" />
        </svg>
      </button>
    </div>
  </div>

  <script>
    const chatFab = document.getElementById("chatFab");
    const chatPanel = document.getElementById("chatPanel");
    const chatFabIconOpen = document.getElementById("chatFabIconOpen");
    const chatFabIconClose = document.getElementById("chatFabIconClose");

    chatFab.addEventListener("click", () => {
      const isOpen = chatPanel.classList.contains("open");
      chatPanel.classList.toggle("open", !isOpen);
      chatFabIconOpen.classList.toggle("hidden", !isOpen);
      chatFabIconClose.classList.toggle("hidden", isOpen);
    });

    const starButtons = document.querySelectorAll(".star-btn");
    let selectedStar = 0;

    starButtons.forEach((btn) => {
      btn.addEventListener("click", () => {
        selectedStar = parseInt(btn.getAttribute("data-star"));
        starButtons.forEach((b) => {
          const svg = b.querySelector("svg");
          const val = parseInt(b.getAttribute("data-star"));
          if (val <= selectedStar) {
            svg.setAttribute("fill", "currentColor");
            svg.classList.remove("text-gray-300");
            svg.classList.add("text-amber-400");
          } else {
            svg.setAttribute("fill", "none");
            svg.classList.remove("text-amber-400");
            svg.classList.add("text-gray-300");
          }
        });
      });
    });
  </script>
</body>
</html>