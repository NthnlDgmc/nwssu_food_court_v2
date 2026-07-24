<?php
session_start();
require_once '../config/database.php';
require_once '../config/mail.php';
require_once '../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

define('RESEND_COOLDOWN_SECONDS', 30);
define('MAX_VERIFY_ATTEMPTS', 5);

function isStrongPassword($password)
{
  if (strlen($password) < 8) return false;
  if (!preg_match('/[A-Z]/', $password)) return false;
  if (!preg_match('/[0-9]/', $password)) return false;
  if (!preg_match('/[^A-Za-z0-9]/', $password)) return false;
  return true;
}

function findUserByEmail($conn, $email)
{
  $stmt = $conn->prepare("SELECT admin_id FROM admin WHERE email = ? LIMIT 1");
  $stmt->bind_param("s", $email);
  $stmt->execute();
  if ($stmt->get_result()->fetch_assoc()) {
    $stmt->close();
    return 'admin';
  }
  $stmt->close();

  $stmt = $conn->prepare("SELECT owner_id FROM stall_owners WHERE email = ? LIMIT 1");
  $stmt->bind_param("s", $email);
  $stmt->execute();
  if ($stmt->get_result()->fetch_assoc()) {
    $stmt->close();
    return 'stall_owner';
  }
  $stmt->close();

  $stmt = $conn->prepare("SELECT staff_id FROM delivery_staff WHERE email = ? LIMIT 1");
  $stmt->bind_param("s", $email);
  $stmt->execute();
  if ($stmt->get_result()->fetch_assoc()) {
    $stmt->close();
    return 'delivery_staff';
  }
  $stmt->close();

  $stmt = $conn->prepare("SELECT customer_id FROM customers WHERE email = ? LIMIT 1");
  $stmt->bind_param("s", $email);
  $stmt->execute();
  if ($stmt->get_result()->fetch_assoc()) {
    $stmt->close();
    return 'customer';
  }
  $stmt->close();

  return null;
}

function updatePasswordByType($conn, $userType, $email, $newPassword)
{
  $tableMap = [
    'admin' => 'admin',
    'stall_owner' => 'stall_owners',
    'delivery_staff' => 'delivery_staff',
    'customer' => 'customers',
  ];

  if (!isset($tableMap[$userType])) {
    return false;
  }

  $table = $tableMap[$userType];
  $stmt = $conn->prepare("UPDATE {$table} SET password = ? WHERE email = ?");
  $stmt->bind_param("ss", $newPassword, $email);
  $ok = $stmt->execute();
  $stmt->close();
  return $ok;
}

function sendResetCodeEmail($toEmail, $code)
{
  $mail = new PHPMailer(true);
  try {
    $mail->CharSet = PHPMailer::CHARSET_UTF8;
    $mail->isSMTP();
    $mail->Host = 'smtp.gmail.com';
    $mail->SMTPAuth = true;
    $mail->Username = SMTP_USERNAME;
    $mail->Password = SMTP_PASSWORD;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = 587;

    $mail->setFrom(SMTP_USERNAME, SMTP_FROM_NAME);
    $mail->addAddress($toEmail);

    $mail->isHTML(true);
    $mail->Subject = 'Your Password Reset Code';
    $mail->Body = '<p>We received a request to reset your NWSSU Food Court password.</p>'
      . '<p style="font-size:28px;font-weight:bold;letter-spacing:6px;">' . htmlspecialchars($code) . '</p>'
      . '<p>This code will expire in 15 minutes. If you did not request this, you can safely ignore this email.</p>';
    $mail->AltBody = 'Your password reset code is: ' . $code . '. This code expires in 15 minutes.';

    $mail->send();
    return true;
  } catch (Exception $e) {
    return false;
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
  header('Content-Type: application/json');
  $action = $_POST['action'];

  if ($action === 'send_code') {
    $email = strtolower(preg_replace('/\s+/', '', $_POST['email'] ?? ''));

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
      echo json_encode(['success' => false, 'message' => 'Please enter a valid email address.']);
      $conn->close();
      exit;
    }

    $lastSentAt = $_SESSION['reset_last_sent_at'] ?? 0;
    $secondsSinceLastSend = time() - $lastSentAt;

    if ($secondsSinceLastSend < RESEND_COOLDOWN_SECONDS) {
      $waitSeconds = RESEND_COOLDOWN_SECONDS - $secondsSinceLastSend;
      echo json_encode([
        'success' => false,
        'message' => 'Please wait ' . $waitSeconds . ' seconds before requesting a new code.',
        'wait_seconds' => $waitSeconds,
      ]);
      $conn->close();
      exit;
    }

    $userType = findUserByEmail($conn, $email);

    if ($userType) {
      $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
      $expiresAt = date('Y-m-d H:i:s', time() + 900);

      $stmt = $conn->prepare("UPDATE password_resets SET used = 1 WHERE email = ? AND used = 0");
      $stmt->bind_param("s", $email);
      $stmt->execute();
      $stmt->close();

      $stmt = $conn->prepare("INSERT INTO password_resets (email, user_type, code, expires_at) VALUES (?, ?, ?, ?)");
      $stmt->bind_param("ssss", $email, $userType, $code, $expiresAt);
      $stmt->execute();
      $stmt->close();

      sendResetCodeEmail($email, $code);
    }

    $_SESSION['reset_last_sent_at'] = time();
    $_SESSION['reset_verify_attempts'] = 0;

    echo json_encode(['success' => true, 'message' => 'If that email exists in our system, a reset code has been sent.', 'wait_seconds' => RESEND_COOLDOWN_SECONDS]);
    $conn->close();
    exit;
  }

  if ($action === 'verify_code') {
    $email = strtolower(preg_replace('/\s+/', '', $_POST['email'] ?? ''));
    $code = trim($_POST['code'] ?? '');

    if ($email === '' || $code === '') {
      echo json_encode(['success' => false, 'message' => 'Please enter the code sent to your email.']);
      $conn->close();
      exit;
    }

    $attempts = $_SESSION['reset_verify_attempts'] ?? 0;

    if ($attempts >= MAX_VERIFY_ATTEMPTS) {
      echo json_encode([
        'success' => false,
        'message' => 'Too many incorrect attempts. Please request a new code.',
        'locked' => true,
      ]);
      $conn->close();
      exit;
    }

    $stmt = $conn->prepare("SELECT reset_id, expires_at FROM password_resets WHERE email = ? AND code = ? AND used = 0 LIMIT 1");
    $stmt->bind_param("ss", $email, $code);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row || strtotime($row['expires_at']) < time()) {
      $attempts++;
      $_SESSION['reset_verify_attempts'] = $attempts;
      $attemptsLeft = MAX_VERIFY_ATTEMPTS - $attempts;

      if ($attemptsLeft <= 0) {
        echo json_encode([
          'success' => false,
          'message' => 'Too many incorrect attempts. Please request a new code.',
          'locked' => true,
        ]);
      } else {
        echo json_encode([
          'success' => false,
          'message' => 'Invalid or expired code. Please try again.',
          'attempts_left' => $attemptsLeft,
        ]);
      }
      $conn->close();
      exit;
    }

    $_SESSION['reset_verify_attempts'] = 0;

    echo json_encode(['success' => true]);
    $conn->close();
    exit;
  }

  if ($action === 'reset_password') {
    $email = strtolower(preg_replace('/\s+/', '', $_POST['email'] ?? ''));
    $code = trim($_POST['code'] ?? '');
    $newPassword = $_POST['new_password'] ?? '';

    if (!isStrongPassword($newPassword)) {
      echo json_encode(['success' => false, 'message' => 'Password must be at least 8 characters and include an uppercase letter, a number, and a symbol.']);
      $conn->close();
      exit;
    }

    $stmt = $conn->prepare("SELECT reset_id, user_type, expires_at FROM password_resets WHERE email = ? AND code = ? AND used = 0 LIMIT 1");
    $stmt->bind_param("ss", $email, $code);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row || strtotime($row['expires_at']) < time()) {
      echo json_encode(['success' => false, 'message' => 'Invalid or expired code. Please restart the process.']);
      $conn->close();
      exit;
    }

    $ok = updatePasswordByType($conn, $row['user_type'], $email, $newPassword);

    if ($ok) {
      $stmt = $conn->prepare("UPDATE password_resets SET used = 1 WHERE reset_id = ?");
      $stmt->bind_param("i", $row['reset_id']);
      $stmt->execute();
      $stmt->close();

      unset($_SESSION['reset_last_sent_at']);
      unset($_SESSION['reset_verify_attempts']);
    }

    echo json_encode($ok
      ? ['success' => true]
      : ['success' => false, 'message' => 'Failed to reset password. Please try again.']);
    $conn->close();
    exit;
  }

  echo json_encode(['success' => false, 'message' => 'Unknown action.']);
  $conn->close();
  exit;
}

$conn->close();
?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>NWSSU Food Court — Forgot Password</title>
    <link rel="icon" href="../assets/images/nwssu-logo.png" type="image/png" />
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
      .modal-overlay {
        background-color: rgba(0, 0, 0, 0.5);
      }
      .code-box.error {
        border-color: #f87171;
      }
      .strength-bar {
        transition: background-color 0.2s ease, flex-grow 0.2s ease;
      }
    </style>
  </head>
  <body class="bg-white">
    <a
      href="./login.php"
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
      <span>Back</span>
    </a>

    <div class="min-h-screen flex items-center justify-center px-4 py-10">
      <div class="w-full max-w-sm">
        <div class="rounded-md bg-white border border-gray-200 shadow-sm p-4 space-y-4">
          <div class="text-center">
            <div class="w-14 h-14 bg-emerald-50 flex items-center justify-center mx-auto mb-3 rounded-full">
              <svg
                xmlns="http://www.w3.org/2000/svg"
                fill="none"
                viewBox="0 0 24 24"
                stroke-width="1.5"
                stroke="currentColor"
                class="w-7 h-7 text-emerald-600"
              >
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  d="M15.75 5.25a3 3 0 0 1 3 3m3 0a6 6 0 0 1-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1 1 21.75 8.25Z"
                />
              </svg>
            </div>
            <h1 class="text-base font-semibold text-emerald-600">
              Forgot Password?
            </h1>
            <p class="text-xs text-gray-500 mt-1 max-w-xs mx-auto">
              Enter your email and we'll send you a code to reset your
              password.
            </p>
          </div>

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

          <div
            id="successBanner"
            role="status"
            aria-live="polite"
            class="hidden items-center gap-2 p-3 bg-emerald-50 border border-emerald-200 rounded-[3px]"
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
                d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z"
              />
            </svg>
            <p class="text-[10px] text-emerald-700 font-medium leading-none" id="successText"></p>
          </div>

          <div>
            <label
              for="emailInput"
              class="block text-[11px] font-semibold text-gray-500 uppercase tracking-wide mb-1.5"
            >
              Email Address
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
                  d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75"
                />
              </svg>
              <input
                type="email"
                id="emailInput"
                autocomplete="email"
                placeholder="Enter your email address"
                class="w-full pl-10 pr-3 py-2.5 bg-white border border-gray-200 text-xs text-gray-900 placeholder-gray-400 focus:outline-none focus:border-emerald-600 rounded-[3px]"
              />
            </div>
            <p class="text-[10px] text-gray-400 mt-1.5">
              We'll send a 6-digit code to this email to verify it's you.
            </p>
          </div>

          <button
            type="button"
            id="sendResetBtn"
            class="w-full py-2.5 bg-emerald-600 hover:bg-emerald-700 disabled:opacity-70 disabled:cursor-not-allowed text-white text-xs font-semibold transition-colors flex items-center justify-center gap-1.5 rounded-[3px]"
          >
            <svg
              id="sendResetDefaultIcon"
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
                d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75"
              />
            </svg>
            <svg
              id="sendResetSpinnerIcon"
              class="hidden w-4 h-4 animate-spin"
              xmlns="http://www.w3.org/2000/svg"
              fill="none"
              viewBox="0 0 24 24"
            >
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
              <path class="opacity-90" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.373 0 0 5.373 0 12h4Z"></path>
            </svg>
            <span id="sendResetBtnText">Send Reset Code</span>
          </button>

          <div class="flex items-center gap-3">
            <div class="flex-1 h-px bg-gray-100"></div>
            <span class="text-xs text-gray-400 font-medium">or</span>
            <div class="flex-1 h-px bg-gray-100"></div>
          </div>

          <p class="text-center text-[11px] text-gray-500">
            Remember your password?
            <a
              href="./login.php"
              class="font-semibold text-emerald-600 hover:text-emerald-700 transition-colors"
            >
              Sign in
            </a>
          </p>
        </div>
      </div>
    </div>

    <div
      id="codeModal"
      class="fixed inset-0 z-50 hidden flex items-center justify-center px-4"
    >
      <div class="modal-overlay absolute inset-0" id="closeCodeOverlay"></div>
      <div class="bg-white w-full max-w-sm relative z-10 shadow-2xl rounded-md">
        <div class="p-4 border-b border-gray-100 flex items-center justify-between">
          <h2 class="font-bold text-gray-800 text-sm">Enter Reset Code</h2>
          <button id="closeCodeModalBtn" class="p-1 hover:bg-gray-100 rounded-[3px]">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 text-gray-500">
              <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
            </svg>
          </button>
        </div>
        <div class="p-4 space-y-3">
          <p class="text-xs text-gray-500">
            We sent a 6-digit code to <span id="codeModalEmail" class="font-semibold text-gray-700"></span>. Enter it below to continue.
          </p>
          <div
            id="codeError"
            class="hidden items-center gap-2 p-3 bg-red-50 border border-red-200 rounded-[3px]"
          >
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 text-red-500 shrink-0">
              <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
            </svg>
            <p class="text-[10px] text-red-600 font-medium leading-none" id="codeErrorText"></p>
          </div>
          <div class="flex gap-2 justify-center" id="codeBoxWrapper">
            <input type="text" maxlength="1" inputmode="numeric" data-index="0" class="code-box w-10 h-11 text-center text-base font-bold bg-white border border-gray-200 text-gray-900 focus:outline-none focus:border-emerald-600 rounded-[3px]" />
            <input type="text" maxlength="1" inputmode="numeric" data-index="1" class="code-box w-10 h-11 text-center text-base font-bold bg-white border border-gray-200 text-gray-900 focus:outline-none focus:border-emerald-600 rounded-[3px]" />
            <input type="text" maxlength="1" inputmode="numeric" data-index="2" class="code-box w-10 h-11 text-center text-base font-bold bg-white border border-gray-200 text-gray-900 focus:outline-none focus:border-emerald-600 rounded-[3px]" />
            <input type="text" maxlength="1" inputmode="numeric" data-index="3" class="code-box w-10 h-11 text-center text-base font-bold bg-white border border-gray-200 text-gray-900 focus:outline-none focus:border-emerald-600 rounded-[3px]" />
            <input type="text" maxlength="1" inputmode="numeric" data-index="4" class="code-box w-10 h-11 text-center text-base font-bold bg-white border border-gray-200 text-gray-900 focus:outline-none focus:border-emerald-600 rounded-[3px]" />
            <input type="text" maxlength="1" inputmode="numeric" data-index="5" class="code-box w-10 h-11 text-center text-base font-bold bg-white border border-gray-200 text-gray-900 focus:outline-none focus:border-emerald-600 rounded-[3px]" />
          </div>
          <p class="text-center text-[11px] text-gray-500">
            Didn't get the code?
            <button
              type="button"
              id="resendCodeBtn"
              class="font-semibold text-emerald-600 hover:text-emerald-700 transition-colors"
            >
              Resend it
            </button>
          </p>
          <div id="resendConfirmText" class="hidden items-center gap-2 p-2.5 bg-emerald-50 border border-emerald-200 rounded-[3px]">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 text-emerald-600 shrink-0">
              <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z" />
            </svg>
            <p class="text-[10px] text-emerald-700 font-medium leading-none">A new code has been sent to your email.</p>
          </div>
        </div>
        <div class="px-4 pb-4 flex gap-2">
          <button id="codeCancelBtn" class="flex-1 py-2.5 border border-gray-200 text-gray-700 text-xs font-semibold hover:bg-gray-50 transition-colors rounded-[3px]">
            Cancel
          </button>
          <button id="codeVerifyBtn" class="flex-1 py-2.5 bg-emerald-600 hover:bg-emerald-700 disabled:opacity-70 disabled:cursor-not-allowed text-white text-xs font-semibold transition-colors flex items-center justify-center gap-1.5 rounded-[3px]">
            <svg
              id="codeVerifySpinnerIcon"
              class="hidden w-4 h-4 animate-spin"
              xmlns="http://www.w3.org/2000/svg"
              fill="none"
              viewBox="0 0 24 24"
            >
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
              <path class="opacity-90" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.373 0 0 5.373 0 12h4Z"></path>
            </svg>
            <span id="codeVerifyBtnText">Verify Code</span>
          </button>
        </div>
      </div>
    </div>

    <div
      id="newPasswordModal"
      class="fixed inset-0 z-50 hidden flex items-center justify-center px-4"
    >
      <div class="modal-overlay absolute inset-0" id="closeNewPasswordOverlay"></div>
      <div class="bg-white w-full max-w-sm relative z-10 shadow-2xl rounded-md">
        <div class="p-4 border-b border-gray-100 flex items-center justify-between">
          <h2 class="font-bold text-gray-800 text-sm">Set New Password</h2>
        </div>
        <div class="p-4 space-y-3">
          <div
            id="newPasswordError"
            class="hidden items-center gap-2 p-3 bg-red-50 border border-red-200 rounded-[3px]"
          >
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 text-red-500 shrink-0">
              <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
            </svg>
            <p class="text-[10px] text-red-600 font-medium leading-none" id="newPasswordErrorText"></p>
          </div>
          <div>
            <label class="block text-[11px] font-semibold text-gray-500 uppercase tracking-wide mb-1.5">New Password</label>
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
                id="newPasswordInput"
                placeholder="Enter your new password"
                class="w-full pl-10 pr-9 py-2.5 bg-white border border-gray-200 text-xs text-gray-900 placeholder-gray-400 focus:outline-none focus:border-emerald-600 rounded-[3px]"
              />
              <button
                type="button"
                id="newPwToggleBtn"
                aria-label="Toggle password visibility"
                class="absolute right-2.5 top-1/2 -translate-y-1/2 text-gray-400 hover:text-emerald-600 transition-colors"
              >
                <svg
                  id="newPwEyeIcon"
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
            <p class="text-[10px] text-gray-400 mt-1.5">
              At least 8 characters, with an uppercase letter, a number, and a symbol.
            </p>
            <div class="mt-2">
              <div class="flex items-center gap-1.5" id="strengthBarWrapper">
                <div class="flex-1 h-1 bg-gray-100 rounded-full overflow-hidden">
                  <div id="strengthBar1" class="strength-bar h-full w-0 bg-gray-200"></div>
                </div>
                <div class="flex-1 h-1 bg-gray-100 rounded-full overflow-hidden">
                  <div id="strengthBar2" class="strength-bar h-full w-0 bg-gray-200"></div>
                </div>
                <div class="flex-1 h-1 bg-gray-100 rounded-full overflow-hidden">
                  <div id="strengthBar3" class="strength-bar h-full w-0 bg-gray-200"></div>
                </div>
                <div class="flex-1 h-1 bg-gray-100 rounded-full overflow-hidden">
                  <div id="strengthBar4" class="strength-bar h-full w-0 bg-gray-200"></div>
                </div>
              </div>
              <p class="text-[10px] mt-1" id="strengthLabel"></p>
            </div>
          </div>
          <div>
            <label class="block text-[11px] font-semibold text-gray-500 uppercase tracking-wide mb-1.5">Confirm New Password</label>
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
                id="confirmNewPasswordInput"
                placeholder="Repeat new password"
                class="w-full pl-10 pr-9 py-2.5 bg-white border border-gray-200 text-xs text-gray-900 placeholder-gray-400 focus:outline-none focus:border-emerald-600 rounded-[3px]"
              />
              <button
                type="button"
                id="confirmPwToggleBtn"
                aria-label="Toggle password visibility"
                class="absolute right-2.5 top-1/2 -translate-y-1/2 text-gray-400 hover:text-emerald-600 transition-colors"
              >
                <svg
                  id="confirmPwEyeIcon"
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
            <p id="passwordMatchText" class="text-[10px] font-medium mt-1.5 hidden"></p>
          </div>
        </div>
        <div class="px-4 pb-4">
          <button id="resetPasswordBtn" class="w-full py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold transition-colors rounded-[3px]">
            Reset Password
          </button>
        </div>
      </div>
    </div>

    <div
      id="doneModal"
      class="fixed inset-0 z-50 hidden flex items-center justify-center px-4"
    >
      <div class="modal-overlay absolute inset-0"></div>
      <div class="bg-white w-full max-w-sm relative z-10 shadow-2xl p-5 space-y-4 text-center rounded-md">
        <div class="w-12 h-12 bg-emerald-50 flex items-center justify-center mx-auto rounded-full">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6 text-emerald-600">
            <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
          </svg>
        </div>
        <div>
          <p class="text-sm font-bold text-gray-800">Password Reset</p>
          <p class="text-xs text-gray-500 mt-1">Your password has been updated. You can now sign in with your new password.</p>
        </div>
        <a href="./login.php" class="block w-full py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold transition-colors rounded-[3px]">
          Go to Login
        </a>
      </div>
    </div>

    <script>
      const emailInput = document.getElementById("emailInput");
      const sendResetBtn = document.getElementById("sendResetBtn");
      const sendResetBtnText = document.getElementById("sendResetBtnText");
      const sendResetDefaultIcon = document.getElementById("sendResetDefaultIcon");
      const sendResetSpinnerIcon = document.getElementById("sendResetSpinnerIcon");
      const errorBanner = document.getElementById("errorBanner");
      const errorText = document.getElementById("errorText");
      const successBanner = document.getElementById("successBanner");
      const successText = document.getElementById("successText");

      let verifiedEmail = "";
      let verifiedCode = "";

      function setSendResetLoading(isLoading) {
        sendResetBtn.disabled = isLoading;
        sendResetBtnText.textContent = isLoading ? "Sending..." : "Send Reset Code";
        sendResetDefaultIcon.classList.toggle("hidden", isLoading);
        sendResetSpinnerIcon.classList.toggle("hidden", !isLoading);
      }

      async function postAction(action, data = {}) {
        const formData = new FormData();
        formData.append("action", action);
        for (const key in data) {
          formData.append(key, data[key] === null || data[key] === undefined ? "" : data[key]);
        }
        try {
          const response = await fetch(window.location.href, {
            method: "POST",
            body: formData,
          });
          return await response.json();
        } catch (err) {
          return { success: false, message: "Something went wrong. Please try again." };
        }
      }

      function showFormError(msg) {
        successBanner.classList.add("hidden");
        successBanner.classList.remove("flex");
        errorText.textContent = msg;
        errorBanner.classList.remove("hidden");
        errorBanner.classList.add("flex");
        emailInput.classList.add("error");
      }

      function hideFormBanners() {
        errorBanner.classList.add("hidden");
        errorBanner.classList.remove("flex");
        successBanner.classList.add("hidden");
        successBanner.classList.remove("flex");
        emailInput.classList.remove("error");
      }

      function isValidEmail(value) {
        return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value);
      }

      function isStrongPassword(val) {
        return (
          val.length >= 8 &&
          /[A-Z]/.test(val) &&
          /[0-9]/.test(val) &&
          /[^A-Za-z0-9]/.test(val)
        );
      }

      async function requestCode() {
        const email = emailInput.value.trim();

        if (!email || !isValidEmail(email)) {
          showFormError("Please enter a valid email address.");
          return;
        }

        hideFormBanners();
        setSendResetLoading(true);

        const res = await postAction("send_code", { email });

        setSendResetLoading(false);

        if (!res.success) {
          showFormError(res.message || "Something went wrong. Please try again.");
          return;
        }

        successText.textContent = res.message;
        successBanner.classList.remove("hidden");
        successBanner.classList.add("flex");
        openCodeModal(email);
      }

      sendResetBtn.addEventListener("click", requestCode);
      emailInput.addEventListener("input", hideFormBanners);
      emailInput.addEventListener("keydown", (e) => {
        if (e.key === "Enter") requestCode();
      });

      const codeModal = document.getElementById("codeModal");
      const codeModalEmail = document.getElementById("codeModalEmail");
      const codeBoxes = Array.from(document.querySelectorAll(".code-box"));
      const codeError = document.getElementById("codeError");
      const codeErrorText = document.getElementById("codeErrorText");
      const codeVerifyBtn = document.getElementById("codeVerifyBtn");
      const resendCodeBtn = document.getElementById("resendCodeBtn");
      const resendConfirmText = document.getElementById("resendConfirmText");

      function getCodeValue() {
        return codeBoxes.map((box) => box.value).join("");
      }

      function clearCodeBoxes() {
        codeBoxes.forEach((box) => {
          box.value = "";
          box.classList.remove("error");
        });
      }

      const RESEND_COOLDOWN_SECONDS = 30;
      const RESEND_BTN_DEFAULT_TEXT = "Resend it";
      let resendCooldownInterval = null;

      function startResendCooldown(seconds) {
        let remaining = seconds;
        resendCodeBtn.disabled = true;
        resendCodeBtn.textContent = `Resend in ${remaining}s`;

        if (resendCooldownInterval) clearInterval(resendCooldownInterval);

        resendCooldownInterval = setInterval(() => {
          remaining--;
          if (remaining <= 0) {
            clearInterval(resendCooldownInterval);
            resendCooldownInterval = null;
            resendCodeBtn.disabled = false;
            resendCodeBtn.textContent = RESEND_BTN_DEFAULT_TEXT;
          } else {
            resendCodeBtn.textContent = `Resend in ${remaining}s`;
          }
        }, 1000);
      }

      function openCodeModal(email) {
        verifiedEmail = email;
        codeModalEmail.textContent = email;
        clearCodeBoxes();
        codeError.classList.add("hidden");
        codeError.classList.remove("flex");
        resendConfirmText.classList.add("hidden");
        resendConfirmText.classList.remove("flex");
        codeModal.classList.remove("hidden");
        document.body.style.overflow = "hidden";
        setTimeout(() => codeBoxes[0].focus(), 100);
        startResendCooldown(RESEND_COOLDOWN_SECONDS);
      }

      function closeCodeModal() {
        codeModal.classList.add("hidden");
        document.body.style.overflow = "";
      }

      function showCodeError(msg) {
        codeErrorText.textContent = msg;
        codeError.classList.remove("hidden");
        codeError.classList.add("flex");
        codeBoxes.forEach((box) => box.classList.add("error"));
      }

      document.getElementById("closeCodeModalBtn").addEventListener("click", closeCodeModal);
      document.getElementById("closeCodeOverlay").addEventListener("click", closeCodeModal);
      document.getElementById("codeCancelBtn").addEventListener("click", closeCodeModal);

      codeBoxes.forEach((box, index) => {
        box.addEventListener("input", () => {
          box.value = box.value.replace(/\D/g, "").slice(0, 1);
          box.classList.remove("error");
          codeError.classList.add("hidden");
          codeError.classList.remove("flex");
          if (box.value && index < codeBoxes.length - 1) {
            codeBoxes[index + 1].focus();
          }
        });

        box.addEventListener("keydown", (e) => {
          if (e.key === "Backspace" && !box.value && index > 0) {
            codeBoxes[index - 1].focus();
          }
          if (e.key === "Enter") {
            codeVerifyBtn.click();
          }
        });

        box.addEventListener("paste", (e) => {
          e.preventDefault();
          const pasted = (e.clipboardData || window.clipboardData).getData("text").replace(/\D/g, "").slice(0, 6);
          pasted.split("").forEach((digit, i) => {
            if (codeBoxes[i]) codeBoxes[i].value = digit;
          });
          const nextIndex = Math.min(pasted.length, codeBoxes.length - 1);
          codeBoxes[nextIndex].focus();
        });
      });

      const codeVerifyBtnText = document.getElementById("codeVerifyBtnText");
      const codeVerifySpinnerIcon = document.getElementById("codeVerifySpinnerIcon");

      function setVerifyLoading(isLoading) {
        codeVerifyBtn.disabled = isLoading;
        codeVerifyBtnText.textContent = isLoading ? "Verifying..." : "Verify Code";
        codeVerifySpinnerIcon.classList.toggle("hidden", !isLoading);
      }

      codeVerifyBtn.addEventListener("click", async () => {
        const code = getCodeValue();

        if (code.length !== 6) {
          showCodeError("Please enter the 6-digit code.");
          return;
        }

        setVerifyLoading(true);
        const res = await postAction("verify_code", { email: verifiedEmail, code });
        setVerifyLoading(false);

        if (!res.success) {
          if (res.locked) {
            showCodeError(res.message || "Too many incorrect attempts. Please request a new code.");
            codeVerifyBtn.disabled = true;
            codeBoxes.forEach((box) => (box.disabled = true));
          } else if (res.attempts_left !== undefined) {
            const attemptWord = res.attempts_left === 1 ? "attempt" : "attempts";
            showCodeError((res.message || "Invalid or expired code.") + " (" + res.attempts_left + " " + attemptWord + " left)");
          } else {
            showCodeError(res.message || "Invalid or expired code. Please try again.");
          }
          return;
        }

        verifiedCode = code;
        closeCodeModal();
        openNewPasswordModal();
      });

      resendCodeBtn.addEventListener("click", async () => {
        resendCodeBtn.disabled = true;
        const res = await postAction("send_code", { email: verifiedEmail });

        if (!res.success) {
          showCodeError(res.message || "Please wait before requesting a new code.");
          startResendCooldown(res.wait_seconds || RESEND_COOLDOWN_SECONDS);
          return;
        }

        clearCodeBoxes();
        codeError.classList.add("hidden");
        codeError.classList.remove("flex");
        codeVerifyBtn.disabled = false;
        codeBoxes.forEach((box) => (box.disabled = false));
        resendConfirmText.classList.remove("hidden");
        resendConfirmText.classList.add("flex");
        codeBoxes[0].focus();
        startResendCooldown(res.wait_seconds || RESEND_COOLDOWN_SECONDS);
      });

      const newPasswordModal = document.getElementById("newPasswordModal");
      const newPasswordInput = document.getElementById("newPasswordInput");
      const confirmNewPasswordInput = document.getElementById("confirmNewPasswordInput");
      const newPasswordError = document.getElementById("newPasswordError");
      const newPasswordErrorText = document.getElementById("newPasswordErrorText");
      const resetPasswordBtn = document.getElementById("resetPasswordBtn");
      const strengthBar1 = document.getElementById("strengthBar1");
      const strengthBar2 = document.getElementById("strengthBar2");
      const strengthBar3 = document.getElementById("strengthBar3");
      const strengthBar4 = document.getElementById("strengthBar4");
      const strengthLabel = document.getElementById("strengthLabel");

      function calculatePasswordStrength(password) {
        if (!password) return { level: 0, label: "", color: "bg-gray-200", labelColor: "text-gray-400" };

        let score = 0;
        if (password.length >= 8) score++;
        if (/[A-Z]/.test(password)) score++;
        if (/[0-9]/.test(password)) score++;
        if (/[^A-Za-z0-9]/.test(password)) score++;

        if (score <= 1) return { level: 1, label: "Weak", color: "bg-red-400", labelColor: "text-red-500" };
        if (score === 2) return { level: 2, label: "Fair", color: "bg-amber-400", labelColor: "text-amber-500" };
        if (score === 3) return { level: 3, label: "Good", color: "bg-emerald-500", labelColor: "text-emerald-600" };
        return { level: 4, label: "Strong", color: "bg-emerald-700", labelColor: "text-emerald-700" };
      }

      function updateStrengthBar() {
        const result = calculatePasswordStrength(newPasswordInput.value);
        const bars = [strengthBar1, strengthBar2, strengthBar3, strengthBar4];

        bars.forEach((bar, i) => {
          bar.className = i < result.level
            ? "strength-bar h-full w-full " + result.color
            : "strength-bar h-full w-0 bg-gray-200";
        });

        strengthLabel.textContent = result.label;
        strengthLabel.className = "text-[10px] mt-1 font-semibold " + result.labelColor;
      }

      newPasswordInput.addEventListener("input", updateStrengthBar);

      const passwordMatchText = document.getElementById("passwordMatchText");

      function updatePasswordMatch() {
        const password = newPasswordInput.value;
        const confirmPassword = confirmNewPasswordInput.value;

        if (!confirmPassword) {
          passwordMatchText.classList.add("hidden");
          return;
        }

        passwordMatchText.classList.remove("hidden");

        if (password === confirmPassword) {
          passwordMatchText.textContent = "Passwords match";
          passwordMatchText.className = "text-[10px] font-medium mt-1.5 text-emerald-600";
        } else {
          passwordMatchText.textContent = "Passwords do not match";
          passwordMatchText.className = "text-[10px] font-medium mt-1.5 text-red-500";
        }
      }

      newPasswordInput.addEventListener("input", updatePasswordMatch);
      confirmNewPasswordInput.addEventListener("input", updatePasswordMatch);

      function setupPasswordToggle(toggleBtnId, eyeIconId, inputEl) {
        const toggleBtn = document.getElementById(toggleBtnId);
        const eyeIcon = document.getElementById(eyeIconId);
        toggleBtn.addEventListener("click", () => {
          const isPassword = inputEl.type === "password";
          inputEl.type = isPassword ? "text" : "password";
          eyeIcon.innerHTML = isPassword
            ? `<path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 0 0 1.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.451 10.451 0 0 1 12 4.5c4.756 0 8.773 3.162 10.065 7.498a10.522 10.522 0 0 1-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65m0 0a3 3 0 1 0-4.243-4.243m4.242 4.242L9.88 9.88"/>`
            : `<path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/>`;
        });
      }

      setupPasswordToggle("newPwToggleBtn", "newPwEyeIcon", newPasswordInput);
      setupPasswordToggle("confirmPwToggleBtn", "confirmPwEyeIcon", confirmNewPasswordInput);

      function openNewPasswordModal() {
        newPasswordInput.value = "";
        confirmNewPasswordInput.value = "";
        newPasswordInput.type = "password";
        confirmNewPasswordInput.type = "password";
        newPasswordError.classList.add("hidden");
        newPasswordError.classList.remove("flex");
        newPasswordModal.classList.remove("hidden");
        document.body.style.overflow = "hidden";
        updateStrengthBar();
        updatePasswordMatch();
      }

      function closeNewPasswordModal() {
        newPasswordModal.classList.add("hidden");
        document.body.style.overflow = "";
      }

      document.getElementById("closeNewPasswordOverlay").addEventListener("click", closeNewPasswordModal);

      function showNewPasswordError(msg) {
        newPasswordErrorText.textContent = msg;
        newPasswordError.classList.remove("hidden");
        newPasswordError.classList.add("flex");
      }

      resetPasswordBtn.addEventListener("click", async () => {
        const newPassword = newPasswordInput.value;
        const confirmPassword = confirmNewPasswordInput.value;

        newPasswordError.classList.add("hidden");
        newPasswordError.classList.remove("flex");

        if (!isStrongPassword(newPassword)) {
          showNewPasswordError("Password must be at least 8 characters and include an uppercase letter, a number, and a symbol.");
          return;
        }

        if (newPassword !== confirmPassword) {
          showNewPasswordError("Passwords do not match.");
          return;
        }

        resetPasswordBtn.disabled = true;
        const res = await postAction("reset_password", {
          email: verifiedEmail,
          code: verifiedCode,
          new_password: newPassword,
        });
        resetPasswordBtn.disabled = false;

        if (!res.success) {
          showNewPasswordError(res.message || "Something went wrong. Please try again.");
          return;
        }

        closeNewPasswordModal();
        document.getElementById("doneModal").classList.remove("hidden");
        document.body.style.overflow = "hidden";
      });
    </script>
  </body>
</html>