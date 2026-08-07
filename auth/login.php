<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['remember_me'] ?? '') === '1') {
    session_set_cookie_params([
        'lifetime' => 30 * 24 * 60 * 60,
        'path' => '/',
    ]);
}
session_start();
require_once '../config/database.php';

define('MAX_LOGIN_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_SECONDS', 60);

$pageLoadLockoutRemaining = 0;
$existingLockoutUntil = $_SESSION['login_lockout_until'] ?? 0;
if ($existingLockoutUntil > time()) {
    $pageLoadLockoutRemaining = $existingLockoutUntil - time();
}

$showDeactivatedMessage = isset($_GET['deactivated']);
$showDeletedMessage = isset($_GET['deleted']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    $lockoutUntil = $_SESSION['login_lockout_until'] ?? 0;
    if ($lockoutUntil > time()) {
        $waitSeconds = $lockoutUntil - time();
        echo json_encode([
            'success' => false,
            'message' => 'Too many failed attempts. Please wait ' . $waitSeconds . ' seconds before trying again.',
            'locked' => true,
            'wait_seconds' => $waitSeconds,
        ]);
        exit;
    }

    $username = strtolower(preg_replace('/\s+/', '', $_POST['username'] ?? ''));
    $password = trim($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        echo json_encode(['success' => false, 'message' => 'Please enter both your ID number/email and password.']);
        exit;
    }

    function registerFailedLoginAttempt()
    {
        $_SESSION['login_attempts'] = ($_SESSION['login_attempts'] ?? 0) + 1;
        if ($_SESSION['login_attempts'] >= MAX_LOGIN_ATTEMPTS) {
            $_SESSION['login_lockout_until'] = time() + LOGIN_LOCKOUT_SECONDS;
            $_SESSION['login_attempts'] = 0;
        }
    }

    $stmt = $conn->prepare("SELECT admin_id, password, first_name, last_name FROM admins WHERE email = ? LIMIT 1");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        $stmt->close();
        if (password_verify($password, $row['password'])) {
            session_regenerate_id(true);
            $_SESSION = [];

            $_SESSION['user_type'] = 'admin';
            $_SESSION['admin_id'] = $row['admin_id'];
            $_SESSION['name'] = $row['first_name'] . ' ' . $row['last_name'];
            echo json_encode(['success' => true, 'redirect' => '../admin/dashboard.php']);
            exit;
        } else {
            registerFailedLoginAttempt();
            echo json_encode(['success' => false, 'message' => 'Invalid ID number or password. Please try again.']);
            exit;
        }
    }
    $stmt->close();

    $stmt = $conn->prepare("SELECT owner_id, password, status, first_name, last_name FROM stall_owners WHERE email = ? LIMIT 1");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        $stmt->close();
        if (password_verify($password, $row['password'])) {
            session_regenerate_id(true);
            $_SESSION = [];

            $_SESSION['user_type'] = 'stall_owner';
            $_SESSION['owner_id'] = $row['owner_id'];
            $_SESSION['name'] = $row['first_name'] . ' ' . $row['last_name'];
            echo json_encode(['success' => true, 'redirect' => '../stall/dashboard.php']);
            exit;
        } else {
            registerFailedLoginAttempt();
            echo json_encode(['success' => false, 'message' => 'Invalid ID number or password. Please try again.']);
            exit;
        }
    }
    $stmt->close();

    $stmt = $conn->prepare("SELECT staff_id, password, status, first_name, last_name FROM delivery_staff WHERE email = ? LIMIT 1");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        $stmt->close();
        if (password_verify($password, $row['password'])) {
            session_regenerate_id(true);
            $_SESSION = [];

            $_SESSION['user_type'] = 'delivery_staff';
            $_SESSION['staff_id'] = $row['staff_id'];
            $_SESSION['name'] = $row['first_name'] . ' ' . $row['last_name'];
            echo json_encode(['success' => true, 'redirect' => '../delivery/dashboard.php']);
            exit;
        } else {
            registerFailedLoginAttempt();
            echo json_encode(['success' => false, 'message' => 'Invalid ID number or password. Please try again.']);
            exit;
        }
    }
    $stmt->close();

    $stmt = $conn->prepare("SELECT customer_id, id_number, contact_number, email, password, customer_type, status FROM customers WHERE id_number = ? OR email = ? LIMIT 1");
    $stmt->bind_param("ss", $username, $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        $stmt->close();
        if (password_verify($password, $row['password'])) {
            if ($row['status'] === 'inactive') {
                echo json_encode(['success' => false, 'message' => 'Your account has been deactivated. Please contact the admin for assistance.']);
                exit;
            }

            session_regenerate_id(true);
            $_SESSION = [];

            $_SESSION['user_type'] = 'customer';
            $_SESSION['customer_id'] = $row['customer_id'];
            $_SESSION['id_number'] = $row['id_number'];
            $_SESSION['customer_type'] = $row['customer_type'];

            $contactEmpty = empty($row['contact_number']);
            $emailEmpty = empty($row['email']);

            if ($contactEmpty || $emailEmpty) {
                echo json_encode(['success' => true, 'redirect' => './complete-profile.php']);
            } else {
                echo json_encode(['success' => true, 'redirect' => '../customer/home.php']);
            }
            exit;
        } else {
            registerFailedLoginAttempt();
            echo json_encode(['success' => false, 'message' => 'Invalid ID number or password. Please try again.']);
            exit;
        }
    }
    $stmt->close();

    registerFailedLoginAttempt();
    echo json_encode(['success' => false, 'message' => 'Invalid ID number or password. Please try again.']);

    $conn->close();
    exit;
}
?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>NWSSU Food Court — Sign In</title>
    <link rel="icon" href="assets/images/nwssu-logo.svg" type="image/svg+xml" />
    <link rel="manifest" href="../manifest.json" />
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
      body::-webkit-scrollbar {
        width: 5px;
      }
      body::-webkit-scrollbar-track {
        background: #e2e8f0;
        border-radius: 3px;
      }
      body::-webkit-scrollbar-thumb {
        background: #059669;
        border-radius: 3px;
      }
      input:focus {
        outline: none;
        border-color: #059669;
      }
      input.error {
        border-color: #f87171;
      }
    </style>
  </head>
  <body class="bg-white">
    <a
      href="../index.php"
      class="rounded-[3px] fixed top-4 left-4 flex items-center gap-2 px-3 py-2 bg-white border border-gray-200 hover:border-emerald-500 hover:bg-gray-50 text-xs font-medium text-gray-600 hover:text-emerald-600 transition-all shadow-sm group"
    >
      <svg
        class="w-4 h-4 shrink-0 group-hover:-translate-x-0.5 transition-transform"
        xmlns="http://www.w3.org/2000/svg"
        fill="none"
        viewBox="0 0 24 24"
        stroke-width="1.5"
        stroke="currentColor"
      >
        <path
          stroke-linecap="round"
          stroke-linejoin="round"
          d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18"
        />
      </svg>
      <span>Browse</span>
    </a>

    <div class="min-h-screen flex items-center justify-center px-4 py-10">
      <div class="w-full max-w-sm">
        <div class="text-center mb-5">
          <img
            src="../assets/images/nwssu-logo.svg"
            alt="NWSSU Logo"
            class="w-14 h-14 object-contain mx-auto mb-3"
            onerror="
              this.onerror = null;
              this.src = 'https://placehold.co/56x56/059669/ffffff?text=N';
            "
          />
          <h1 class="text-base font-semibold text-emerald-600">
            Welcome
          </h1>
          <p class="text-xs text-gray-500 mt-1">
            Sign in to continue
          </p>
        </div>

        <div class="rounded-md bg-white border border-gray-200 shadow-sm p-4 space-y-4">
          <div
            id="errorBanner"
            role="alert"
            aria-live="polite"
            class="hidden items-center gap-2 p-3 bg-red-50 border border-red-200 rounded-[3px]"
          >
            <svg
              class="w-4 h-4 text-red-500 shrink-0"
              xmlns="http://www.w3.org/2000/svg"
              fill="none"
              viewBox="0 0 24 24"
              stroke-width="1.5"
              stroke="currentColor"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z"
              />
            </svg>
            <p class="text-[10px] text-red-600 font-medium leading-none" id="errorText"></p>
          </div>

          <div>
            <label
              for="usernameInput"
              class="block text-[11px] font-semibold text-gray-500 uppercase tracking-wide mb-1.5"
            >
              ID Number or Email
            </label>
            <div class="relative">
              <svg
                class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400 pointer-events-none"
                xmlns="http://www.w3.org/2000/svg"
                fill="none"
                viewBox="0 0 24 24"
                stroke-width="1.5"
                stroke="currentColor"
              >
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6a2.25 2.25 0 0 0-2.25 2.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15m3 0 3-3m0 0-3-3m3 3H9"
                />
              </svg>
              <input
                type="text"
                id="usernameInput"
                autocomplete="username"
                placeholder="Enter your ID number or email"
                class="w-full pl-10 pr-3 py-2.5 bg-white border border-gray-200 text-xs text-gray-900 placeholder-gray-400 focus:outline-none focus:border-emerald-600 rounded-[3px]"
              />
            </div>
            <p class="text-[10px] text-gray-400 mt-1.5">
              Campus users: ID number &nbsp;&middot;&nbsp; Guest: email
              address
            </p>
          </div>

          <div>
            <label
              for="passwordInput"
              class="block text-[11px] font-semibold text-gray-500 uppercase tracking-wide mb-1.5"
            >
              Password
            </label>
            <div class="relative">
              <svg
                class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400 pointer-events-none"
                xmlns="http://www.w3.org/2000/svg"
                fill="none"
                viewBox="0 0 24 24"
                stroke-width="1.5"
                stroke="currentColor"
              >
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z"
                />
              </svg>
              <input
                type="password"
                id="passwordInput"
                autocomplete="current-password"
                placeholder="Enter your password"
                class="w-full pl-10 pr-9 py-2.5 bg-white border border-gray-200 text-xs text-gray-900 placeholder-gray-400 focus:outline-none focus:border-emerald-600 rounded-[3px]"
              />
              <button
                type="button"
                id="pwToggleBtn"
                aria-label="Toggle password visibility"
                class="absolute right-2.5 top-1/2 -translate-y-1/2 text-gray-400 hover:text-emerald-600 transition-colors"
              >
                <svg
                  id="pwEyeIcon"
                  class="w-4 h-4"
                  xmlns="http://www.w3.org/2000/svg"
                  fill="none"
                  viewBox="0 0 24 24"
                  stroke-width="1.5"
                  stroke="currentColor"
                >
                  <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z"
                  />
                  <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"
                  />
                </svg>
              </button>
            </div>
          </div>

          <div class="flex items-center justify-between">
            <label class="flex items-center gap-2 cursor-pointer">
              <input
                type="checkbox"
                id="rememberMe"
                class="w-3.5 h-3.5 accent-emerald-600 cursor-pointer"
              />
              <span class="text-[11px] font-medium text-gray-600">Remember me</span>
            </label>
            <a
              href="./forgot-password.php"
              class="text-[11px] font-semibold text-emerald-600 hover:text-emerald-700 transition-colors"
            >
              Forgot password?
            </a>
          </div>

          <button
            type="button"
            id="signInBtn"
            class="w-full py-2.5 bg-emerald-600 hover:bg-emerald-700 disabled:opacity-70 disabled:cursor-not-allowed text-white text-xs font-semibold transition-colors flex items-center justify-center gap-1.5 rounded-[3px]"
          >
            <svg
              id="signInArrowIcon"
              class="w-4 h-4"
              xmlns="http://www.w3.org/2000/svg"
              fill="none"
              viewBox="0 0 24 24"
              stroke-width="1.5"
              stroke="currentColor"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6a2.25 2.25 0 0 0-2.25 2.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15m3 0 3-3m0 0-3-3m3 3H9"
              />
            </svg>
            <svg
              id="signInSpinnerIcon"
              class="hidden w-4 h-4 animate-spin"
              xmlns="http://www.w3.org/2000/svg"
              fill="none"
              viewBox="0 0 24 24"
            >
              <circle
                class="opacity-25"
                cx="12"
                cy="12"
                r="10"
                stroke="currentColor"
                stroke-width="4"
              ></circle>
              <path
                class="opacity-90"
                fill="currentColor"
                d="M4 12a8 8 0 0 1 8-8V0C5.373 0 0 5.373 0 12h4Z"
              ></path>
            </svg>
            <span id="signInBtnText">Sign In</span>
          </button>

          <div class="flex items-center gap-3">
            <div class="flex-1 h-px bg-gray-100"></div>
            <span class="text-xs text-gray-400 font-medium">or</span>
            <div class="flex-1 h-px bg-gray-100"></div>
          </div>

          <a
            href="guest-signup.php"
            id="createAccountBtn"
            class="w-full py-2.5 bg-white border border-gray-200 hover:border-emerald-500 text-gray-700 text-xs font-semibold transition-colors flex items-center justify-center gap-1.5 group rounded-[3px]"
          >
            <svg
              class="w-4 h-4 text-emerald-600 shrink-0"
              xmlns="http://www.w3.org/2000/svg"
              fill="none"
              viewBox="0 0 24 24"
              stroke-width="1.5"
              stroke="currentColor"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                d="M18 7.5v3m0 0v3m0-3h3m-3 0h-3m-2.25-4.125a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0ZM3 19.235v-.11a6.375 6.375 0 0 1 12.75 0v.109A12.318 12.318 0 0 1 9.374 21c-2.331 0-4.512-.645-6.374-1.766Z"
              />
            </svg>
            <span>Create account</span>
            <svg
              class="w-3.5 h-3.5 text-emerald-600 shrink-0 group-hover:translate-x-0.5 transition-transform"
              xmlns="http://www.w3.org/2000/svg"
              fill="none"
              viewBox="0 0 24 24"
              stroke-width="1.5"
              stroke="currentColor"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3"
              />
            </svg>
          </a>
          <p class="text-center text-[10px] text-gray-400">
            This sign-up is for guest customers only
          </p>

          <div
            class="flex items-center justify-center gap-1.5 pt-3 border-t border-gray-100 text-[10px] text-gray-400"
          >
            <svg
              class="w-3.5 h-3.5 shrink-0"
              xmlns="http://www.w3.org/2000/svg"
              fill="none"
              viewBox="0 0 24 24"
              stroke-width="1.5"
              stroke="currentColor"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z"
              />
            </svg>
            Secure checkout for campus &amp; guest users
          </div>
        </div>

        <p class="text-center text-[10px] text-gray-400 mt-4">
          Developed by: Nathaniel Dagamac
        </p>
      </div>
    </div>

    <script>
      const usernameInput = document.getElementById("usernameInput");
      const passwordInput = document.getElementById("passwordInput");
      const signInBtn = document.getElementById("signInBtn");
      const signInBtnText = document.getElementById("signInBtnText");
      const signInArrowIcon = document.getElementById("signInArrowIcon");
      const signInSpinnerIcon = document.getElementById("signInSpinnerIcon");
      const errorBanner = document.getElementById("errorBanner");
      const errorText = document.getElementById("errorText");
      const pwToggleBtn = document.getElementById("pwToggleBtn");
      const pwEyeIcon = document.getElementById("pwEyeIcon");

      function setSignInLoading(isLoading) {
        signInBtn.disabled = isLoading;
        signInBtnText.textContent = isLoading ? "Signing in..." : "Sign In";
        signInArrowIcon.classList.toggle("hidden", isLoading);
        signInSpinnerIcon.classList.toggle("hidden", !isLoading);
      }

      function showError(msg) {
        errorText.textContent = msg;
        errorBanner.classList.remove("hidden");
        errorBanner.classList.add("flex");
        usernameInput.classList.add("error");
        passwordInput.classList.add("error");
      }

      function hideError() {
        errorBanner.classList.add("hidden");
        errorBanner.classList.remove("flex");
        usernameInput.classList.remove("error");
        passwordInput.classList.remove("error");
      }

      pwToggleBtn.addEventListener("click", () => {
        const isPassword = passwordInput.type === "password";
        passwordInput.type = isPassword ? "text" : "password";
        pwEyeIcon.innerHTML = isPassword
          ? `<path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 0 0 1.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.451 10.451 0 0 1 12 4.5c4.756 0 8.773 3.162 10.065 7.498a10.522 10.522 0 0 1-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65m0 0a3 3 0 1 0-4.243-4.243m4.242 4.242L9.88 9.88"/>`
          : `<path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/>`;
      });

      let lockoutInterval = null;

      function startLoginLockout(seconds) {
        let remaining = seconds;
        signInBtn.disabled = true;
        signInBtnText.textContent = `Try again in ${remaining}s`;

        if (lockoutInterval) clearInterval(lockoutInterval);

        lockoutInterval = setInterval(() => {
          remaining--;
          if (remaining <= 0) {
            clearInterval(lockoutInterval);
            lockoutInterval = null;
            signInBtn.disabled = false;
            signInBtnText.textContent = "Sign In";
            signInArrowIcon.classList.remove("hidden");
            signInSpinnerIcon.classList.add("hidden");
          } else {
            signInBtnText.textContent = `Try again in ${remaining}s`;
          }
        }, 1000);
      }

      async function handleSignIn() {
        const username = usernameInput.value.trim();
        const password = passwordInput.value;

        if (!username || !password) {
          showError("Please enter both your ID number and password.");
          return;
        }

        hideError();
        setSignInLoading(true);

        try {
          const formData = new FormData();
          formData.append("username", username);
          formData.append("password", password);
          formData.append("remember_me", document.getElementById("rememberMe").checked ? "1" : "0");

          const response = await fetch(window.location.href, {
            method: "POST",
            body: formData,
          });

          const data = await response.json();

          if (data.success) {
            window.location.href = data.redirect;
          } else {
            showError(data.message || "Invalid ID number or password. Please try again.");
            setSignInLoading(false);
            if (data.locked && data.wait_seconds) {
              startLoginLockout(data.wait_seconds);
            }
          }
        } catch (err) {
          showError("Something went wrong. Please try again.");
          setSignInLoading(false);
        }
      }

      signInBtn.addEventListener("click", handleSignIn);
      usernameInput.addEventListener("keydown", (e) => {
        if (e.key === "Enter") handleSignIn();
      });
      passwordInput.addEventListener("keydown", (e) => {
        if (e.key === "Enter") handleSignIn();
      });
      usernameInput.addEventListener("input", hideError);
      passwordInput.addEventListener("input", hideError);

      const pageLoadLockoutRemaining = <?php echo (int) $pageLoadLockoutRemaining; ?>;
      if (pageLoadLockoutRemaining > 0) {
        showError("Too many failed attempts. Please wait " + pageLoadLockoutRemaining + " seconds before trying again.");
        startLoginLockout(pageLoadLockoutRemaining);
      }

      const showDeactivatedMessage = <?php echo $showDeactivatedMessage ? 'true' : 'false'; ?>;
      if (showDeactivatedMessage) {
        showError("Your account has been deactivated. Please contact support.");
      }

      const showDeletedMessage = <?php echo $showDeletedMessage ? 'true' : 'false'; ?>;
      if (showDeletedMessage) {
        showError("Your account has been deleted. Thank you for using NWSSU Food Court.");
      }

      if ("serviceWorker" in navigator) {
        navigator.serviceWorker.register("../service-worker.js");
      }
    </script>
  </body>
</html>