
<!doctype html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>404 - Not Found</title>
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
              d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z"
            />
          </svg>
        </div>
        <p class="text-4xl font-bold text-emerald-600">404</p>
        <p class="text-sm font-bold text-gray-800 mt-2">Page Not Found</p>
        <p class="text-xs text-gray-500 mt-1.5 leading-relaxed">
          The page you're looking for doesn't exist or may have been moved.
        </p>
        <a
          href="javascript:history.back()"
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
              d="M15.75 19.5 8.25 12l7.5-7.5"
            />
          </svg>
          Go Back
        </a>
      </div>
    </div>
  </body>
</html>