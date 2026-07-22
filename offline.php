<!doctype html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>You're Offline</title>
    <link rel="icon" href="assets/images/nwssu-logo.png" type="image/png" />
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
    </style>
  </head>
  <body class="bg-white">
    <div class="min-h-screen flex items-center justify-center px-4">
      <div class="w-full max-w-sm text-center">
        <div
          class="w-16 h-16 bg-emerald-50 border border-emerald-100 flex items-center justify-center mx-auto mb-4 rounded-full"
        >
          <svg
            xmlns="http://www.w3.org/2000/svg"
            fill="none"
            viewBox="0 0 24 24"
            stroke-width="1.5"
            stroke="currentColor"
            class="w-8 h-8 text-emerald-600"
          >
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              d="M8.288 15.038a5.25 5.25 0 0 1 7.424 0M5.106 11.856c3.807-3.808 9.98-3.808 13.788 0M1.924 8.674c5.565-5.565 14.587-5.565 20.152 0M12.53 18.22l-.53.53-.53-.53a.75.75 0 0 1 1.06 0Z"
            />
          </svg>
        </div>
        <p class="text-sm font-bold text-gray-800 mt-2">You're Offline</p>
        <p class="text-xs text-gray-500 mt-1.5 leading-relaxed">
          Please check your internet connection and try again.
        </p>
        <button
          type="button"
          onclick="window.location.reload()"
          class="inline-flex items-center justify-center gap-1.5 mt-5 px-4 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold transition-colors rounded-[3px]"
        >
          <svg
            xmlns="http://www.w3.org/2000/svg"
            fill="none"
            viewBox="0 0 24 24"
            stroke-width="1.5"
            stroke="currentColor"
            class="w-4 h-4"
          >
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99"
            />
          </svg>
          Try Again
        </button>
      </div>
    </div>
  </body>
</html>