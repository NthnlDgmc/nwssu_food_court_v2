<?php
session_start();
require_once '../config/database.php';
require_once '../config/mail.php';
require_once '../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

define('RESEND_COOLDOWN_SECONDS', 30);
define('MAX_VERIFY_ATTEMPTS', 5);

function sendVerificationEmail($toEmail, $code)
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
    $mail->Subject = 'Verify Your Email — NWSSU Food Court';
    $mail->Body = '<p>Thanks for signing up for NWSSU Food Court! Use the code below to verify your email address.</p>'
      . '<p style="font-size:28px;font-weight:bold;letter-spacing:6px;">' . htmlspecialchars($code) . '</p>'
      . '<p>This code will expire in 15 minutes. If you did not create this account, you can safely ignore this email.</p>';
    $mail->AltBody = 'Your email verification code is: ' . $code . '. This code expires in 15 minutes.';

    $mail->send();
    return true;
  } catch (Exception $e) {
    return false;
  }
}

function generateAndSendCode($conn, $email)
{
  $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
  $expiresAt = date('Y-m-d H:i:s', time() + 900);

  $stmt = $conn->prepare("UPDATE email_verifications SET used = 1 WHERE email = ? AND used = 0");
  $stmt->bind_param("s", $email);
  $stmt->execute();
  $stmt->close();

  $stmt = $conn->prepare("INSERT INTO email_verifications (email, code, expires_at) VALUES (?, ?, ?)");
  $stmt->bind_param("sss", $email, $code, $expiresAt);
  $stmt->execute();
  $stmt->close();

  sendVerificationEmail($email, $code);
}

function toTitleCase($str)
{
  $str = preg_replace('/\s+/', ' ', trim($str));
  if ($str === '') {
    return '';
  }
  $str = mb_strtolower($str, 'UTF-8');
  return preg_replace_callback(
    "/(^|[\s'\-])(\p{L})/u",
    function ($m) {
      return $m[1] . mb_strtoupper($m[2], 'UTF-8');
    },
    $str
  );
}

function isEmailRegisteredAnywhere($conn, $email)
{
  if ($email === '' || $email === null) return false;

  $stmt = $conn->prepare("SELECT admin_id FROM admins WHERE email = ? LIMIT 1");
  $stmt->bind_param("s", $email);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  if ($row) return true;

  $stmt = $conn->prepare("SELECT owner_id FROM stall_owners WHERE email = ? LIMIT 1");
  $stmt->bind_param("s", $email);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  if ($row) return true;

  $stmt = $conn->prepare("SELECT staff_id FROM delivery_staff WHERE email = ? LIMIT 1");
  $stmt->bind_param("s", $email);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  if ($row) return true;

  $stmt = $conn->prepare("SELECT customer_id FROM customers WHERE email = ? LIMIT 1");
  $stmt->bind_param("s", $email);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  if ($row) return true;

  return false;
}

function isStrongPassword($password)
{
  if (strlen($password) < 8) return false;
  if (!preg_match('/[A-Z]/', $password)) return false;
  if (!preg_match('/[0-9]/', $password)) return false;
  if (!preg_match('/[^A-Za-z0-9]/', $password)) return false;
  return true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
  header('Content-Type: application/json');
  $action = $_POST['action'];

  if ($action === 'send_code') {
    $firstName = toTitleCase(trim($_POST['first_name'] ?? ''));
    $lastName = toTitleCase(trim($_POST['last_name'] ?? ''));
    $contact = trim($_POST['contact_number'] ?? '');
    $email = strtolower(preg_replace('/\s+/', '', $_POST['email'] ?? ''));
    $password = trim($_POST['password'] ?? '');
    $confirmPassword = trim($_POST['confirm_password'] ?? '');

    if ($firstName === '' && $lastName === '' && $contact === '' && $email === '' && $password === '') {
      echo json_encode(['success' => false, 'message' => 'Please fill in all required fields.']);
      exit;
    }

    if ($firstName === '') {
      echo json_encode(['success' => false, 'message' => 'Please enter your first name.']);
      exit;
    }
    if (!preg_match("/^[\p{L}\s'\-]+$/u", $firstName)) {
      echo json_encode(['success' => false, 'message' => 'First name can only contain letters.']);
      exit;
    }
    if ($lastName === '') {
      echo json_encode(['success' => false, 'message' => 'Please enter your last name.']);
      exit;
    }
    if (!preg_match("/^[\p{L}\s'\-]+$/u", $lastName)) {
      echo json_encode(['success' => false, 'message' => 'Last name can only contain letters.']);
      exit;
    }
    if ($contact === '') {
      echo json_encode(['success' => false, 'message' => 'Please enter your contact number.']);
      exit;
    }
    if ($email === '') {
      echo json_encode(['success' => false, 'message' => 'Please enter your email address.']);
      exit;
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
      echo json_encode(['success' => false, 'message' => 'Please enter a valid email address.']);
      exit;
    }
    if ($password === '') {
      echo json_encode(['success' => false, 'message' => 'Please enter a password.']);
      exit;
    }
    if (!isStrongPassword($password)) {
      echo json_encode(['success' => false, 'message' => 'Password must be at least 8 characters and include an uppercase letter, a number, and a symbol.']);
      exit;
    }
    if ($password !== $confirmPassword) {
      echo json_encode(['success' => false, 'message' => 'Passwords do not match.']);
      exit;
    }

    $password = password_hash($password, PASSWORD_DEFAULT);

    if (isEmailRegisteredAnywhere($conn, $email)) {
      echo json_encode(['success' => false, 'message' => 'This email address is already registered.']);
      $conn->close();
      exit;
    }

    generateAndSendCode($conn, $email);

    $_SESSION['pending_signup'] = [
      'first_name' => $firstName,
      'last_name' => $lastName,
      'contact_number' => $contact,
      'email' => $email,
      'password' => $password,
      'last_sent_at' => time(),
      'verify_attempts' => 0,
    ];

    echo json_encode(['success' => true, 'message' => 'A verification code has been sent to your email.']);
    $conn->close();
    exit;
  }

  if ($action === 'resend_code') {
    $email = $_SESSION['pending_signup']['email'] ?? '';

    if ($email === '') {
      echo json_encode(['success' => false, 'message' => 'Your session has expired. Please start over.']);
      $conn->close();
      exit;
    }

    $lastSentAt = $_SESSION['pending_signup']['last_sent_at'] ?? 0;
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

    generateAndSendCode($conn, $email);
    $_SESSION['pending_signup']['last_sent_at'] = time();
    $_SESSION['pending_signup']['verify_attempts'] = 0;

    echo json_encode(['success' => true, 'wait_seconds' => RESEND_COOLDOWN_SECONDS]);
    $conn->close();
    exit;
  }

  if ($action === 'verify_code') {
    $code = trim($_POST['code'] ?? '');
    $pending = $_SESSION['pending_signup'] ?? null;

    if (!$pending) {
      echo json_encode(['success' => false, 'message' => 'Your session has expired. Please start over.']);
      $conn->close();
      exit;
    }

    if ($code === '') {
      echo json_encode(['success' => false, 'message' => 'Please enter the code sent to your email.']);
      $conn->close();
      exit;
    }

    $attempts = $_SESSION['pending_signup']['verify_attempts'] ?? 0;

    if ($attempts >= MAX_VERIFY_ATTEMPTS) {
      echo json_encode([
        'success' => false,
        'message' => 'Too many incorrect attempts. Please request a new code.',
        'locked' => true,
      ]);
      $conn->close();
      exit;
    }

    $email = $pending['email'];

    $stmt = $conn->prepare("SELECT verification_id, expires_at FROM email_verifications WHERE email = ? AND code = ? AND used = 0 LIMIT 1");
    $stmt->bind_param("ss", $email, $code);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row || strtotime($row['expires_at']) < time()) {
      $attempts++;
      $_SESSION['pending_signup']['verify_attempts'] = $attempts;
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

    if (isEmailRegisteredAnywhere($conn, $email)) {
      echo json_encode(['success' => false, 'message' => 'This email address is already registered.']);
      $conn->close();
      exit;
    }

    $stmt = $conn->prepare("INSERT INTO customers (customer_type, id_number, first_name, last_name, contact_number, email, password, status) VALUES ('guest', NULL, ?, ?, ?, ?, ?, 'active')");
    $stmt->bind_param(
      "sssss",
      $pending['first_name'],
      $pending['last_name'],
      $pending['contact_number'],
      $pending['email'],
      $pending['password']
    );
    $ok = $stmt->execute();
    $stmt->close();

    if (!$ok) {
      echo json_encode(['success' => false, 'message' => 'Something went wrong. Please try again.']);
      $conn->close();
      exit;
    }

    $stmt = $conn->prepare("UPDATE email_verifications SET used = 1 WHERE verification_id = ?");
    $stmt->bind_param("i", $row['verification_id']);
    $stmt->execute();
    $stmt->close();

    unset($_SESSION['pending_signup']);

    echo json_encode(['success' => true, 'redirect' => './login.php']);
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
    <title>NWSSU Food Court — Create Account</title>
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
    </style>
  </head>
  <body class="bg-white">
    <div class="min-h-screen flex items-center justify-center px-4 py-10">
      <div class="w-full max-w-sm">
        <div class="text-center mb-5">
          <h1 class="text-base font-semibold text-emerald-600">
            Create Account
          </h1>
          <p class="text-xs text-gray-500 mt-1">
            NwSSU Food Court sign-up &middot; Guest customers only
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

          <div class="grid grid-cols-2 gap-3">
            <div>
              <label
                for="firstName"
                class="block text-[11px] font-semibold text-gray-500 uppercase tracking-wide mb-1.5"
              >
                First Name <span class="text-red-400">*</span>
              </label>
              <input
                type="text"
                id="firstName"
                autocomplete="given-name"
                placeholder="Juan"
                class="w-full px-3 py-2.5 bg-white border border-gray-200 text-xs text-gray-900 placeholder-gray-400 focus:outline-none focus:border-emerald-600 rounded-[3px]"
              />
            </div>
            <div>
              <label
                for="lastName"
                class="block text-[11px] font-semibold text-gray-500 uppercase tracking-wide mb-1.5"
              >
                Last Name <span class="text-red-400">*</span>
              </label>
              <input
                type="text"
                id="lastName"
                autocomplete="family-name"
                placeholder="Dela Cruz"
                class="w-full px-3 py-2.5 bg-white border border-gray-200 text-xs text-gray-900 placeholder-gray-400 focus:outline-none focus:border-emerald-600 rounded-[3px]"
              />
            </div>
          </div>

          <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest pt-1">
            Contact Details
          </p>

          <div>
            <label
              for="contactNumber"
              class="block text-[11px] font-semibold text-gray-500 uppercase tracking-wide mb-1.5"
            >
              Contact Number <span class="text-red-400">*</span>
            </label>
            <input
              type="tel"
              id="contactNumber"
              autocomplete="tel"
              placeholder="0917 123 4567"
              class="w-full px-3 py-2.5 bg-white border border-gray-200 text-xs text-gray-900 placeholder-gray-400 focus:outline-none focus:border-emerald-600 rounded-[3px]"
            />
          </div>

          <div>
            <label
              for="emailInput"
              class="block text-[11px] font-semibold text-gray-500 uppercase tracking-wide mb-1.5"
            >
              Email Address <span class="text-red-400">*</span>
            </label>
            <input
              type="email"
              id="emailInput"
              autocomplete="email"
              placeholder="example@email.com"
              class="w-full px-3 py-2.5 bg-white border border-gray-200 text-xs text-gray-900 placeholder-gray-400 focus:outline-none focus:border-emerald-600 rounded-[3px]"
            />
          </div>

          <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest pt-1">
            Set Your Password
          </p>

          <div>
            <label
              for="passwordInput"
              class="block text-[11px] font-semibold text-gray-500 uppercase tracking-wide mb-1.5"
            >
              Password <span class="text-red-400">*</span>
            </label>
            <div class="relative">
              <input
                type="password"
                id="passwordInput"
                autocomplete="new-password"
                placeholder="Enter your password"
                class="w-full px-3 py-2.5 pr-9 bg-white border border-gray-200 text-xs text-gray-900 placeholder-gray-400 focus:outline-none focus:border-emerald-600 rounded-[3px]"
              />
              <button
                type="button"
                id="pwToggle1"
                aria-label="Toggle password visibility"
                class="absolute right-2.5 top-1/2 -translate-y-1/2 text-gray-400 hover:text-emerald-600 transition-colors"
              >
                <svg
                  id="pwIcon1"
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
              <div class="flex gap-1">
                <div class="h-1 flex-1 bg-gray-200 transition-colors rounded-full" id="bar1"></div>
                <div class="h-1 flex-1 bg-gray-200 transition-colors rounded-full" id="bar2"></div>
                <div class="h-1 flex-1 bg-gray-200 transition-colors rounded-full" id="bar3"></div>
                <div class="h-1 flex-1 bg-gray-200 transition-colors rounded-full" id="bar4"></div>
              </div>
              <p class="text-[10px] mt-1" id="strengthLabel"></p>
            </div>
          </div>

          <div>
            <label
              for="confirmPassword"
              class="block text-[11px] font-semibold text-gray-500 uppercase tracking-wide mb-1.5"
            >
              Re-enter Password <span class="text-red-400">*</span>
            </label>
            <div class="relative">
              <input
                type="password"
                id="confirmPassword"
                autocomplete="new-password"
                placeholder="Repeat your password"
                class="w-full px-3 py-2.5 pr-9 bg-white border border-gray-200 text-xs text-gray-900 placeholder-gray-400 focus:outline-none focus:border-emerald-600 rounded-[3px]"
              />
              <button
                type="button"
                id="pwToggle2"
                aria-label="Toggle confirm password visibility"
                class="absolute right-2.5 top-1/2 -translate-y-1/2 text-gray-400 hover:text-emerald-600 transition-colors"
              >
                <svg
                  id="pwIcon2"
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
            <p class="text-[10px] mt-1.5 hidden" id="matchMsg"></p>
          </div>

          <button
            type="button"
            id="submitBtn"
            class="w-full py-2.5 bg-emerald-600 hover:bg-emerald-700 disabled:opacity-70 disabled:cursor-not-allowed text-white text-xs font-semibold transition-colors flex items-center justify-center gap-1.5 rounded-[3px]"
          >
            <svg
              id="submitDefaultIcon"
              class="w-4 h-4 shrink-0"
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
            <svg
              id="submitSpinnerIcon"
              class="hidden w-4 h-4 animate-spin shrink-0"
              xmlns="http://www.w3.org/2000/svg"
              fill="none"
              viewBox="0 0 24 24"
            >
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
              <path class="opacity-90" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.373 0 0 5.373 0 12h4Z"></path>
            </svg>
            <span id="submitBtnText">Create Account</span>
          </button>

          <div class="flex items-center gap-3">
            <div class="flex-1 h-px bg-gray-100"></div>
            <span class="text-xs text-gray-400 font-medium">or</span>
            <div class="flex-1 h-px bg-gray-100"></div>
          </div>

          <a
            href="login.php"
            id="backBtn"
            class="w-full py-2.5 bg-white border border-gray-200 hover:border-emerald-500 text-gray-700 text-xs font-semibold transition-colors flex items-center justify-center gap-1.5 group rounded-[3px]"
          >
            <svg
              class="w-4 h-4 text-emerald-600 shrink-0 group-hover:-translate-x-0.5 transition-transform"
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
            <span>Back to Sign In</span>
          </a>

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
            Your information is kept secure
          </div>
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
          <h2 class="font-bold text-gray-800 text-sm">Verify Your Email</h2>
          <button id="closeCodeModalBtn" class="p-1 hover:bg-gray-100 rounded-[3px]">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 text-gray-500">
              <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
            </svg>
          </button>
        </div>
        <div class="p-4 space-y-3">
          <p class="text-xs text-gray-500">
            We sent a 6-digit code to <span id="codeModalEmail" class="font-semibold text-gray-700"></span>. Enter it below to verify your email and finish creating your account.
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
        <div class="px-4 pb-4">
          <button id="codeVerifyBtn" class="w-full py-2.5 bg-emerald-600 hover:bg-emerald-700 disabled:opacity-70 disabled:cursor-not-allowed text-white text-xs font-semibold transition-colors flex items-center justify-center gap-1.5 rounded-[3px]">
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
            <span id="codeVerifyBtnText">Verify &amp; Create Account</span>
          </button>
        </div>
      </div>
    </div>

    <script>
      const firstName = document.getElementById("firstName");
      const lastName = document.getElementById("lastName");
      const contactNumber = document.getElementById("contactNumber");
      const emailInput = document.getElementById("emailInput");
      const passwordInput = document.getElementById("passwordInput");
      const confirmPassword = document.getElementById("confirmPassword");
      const submitBtn = document.getElementById("submitBtn");
      const submitBtnText = document.getElementById("submitBtnText");
      const submitDefaultIcon = document.getElementById("submitDefaultIcon");
      const submitSpinnerIcon = document.getElementById("submitSpinnerIcon");
      const errorBanner = document.getElementById("errorBanner");
      const errorText = document.getElementById("errorText");
      const matchMsg = document.getElementById("matchMsg");
      const bars = [1, 2, 3, 4].map((n) => document.getElementById("bar" + n));
      const strengthLabel = document.getElementById("strengthLabel");

      function setSubmitLoading(isLoading) {
        submitBtn.disabled = isLoading;
        submitBtnText.textContent = isLoading ? "Sending code..." : "Create Account";
        submitDefaultIcon.classList.toggle("hidden", isLoading);
        submitSpinnerIcon.classList.toggle("hidden", !isLoading);
      }

      function toTitleCase(str) {
        return str
          .trim()
          .replace(/\s+/g, " ")
          .toLowerCase()
          .replace(/(^|[\s'-])\p{L}/gu, (c) => c.toUpperCase());
      }

      [firstName, lastName].forEach((el) => {
        el.addEventListener("input", () => {
          const cursorPos = el.selectionStart;
          const cleaned = el.value.replace(/[^\p{L}\s'-]/gu, "");
          if (cleaned !== el.value) {
            const removedCount = el.value.length - cleaned.length;
            el.value = cleaned;
            const newPos = Math.max(0, cursorPos - removedCount);
            el.setSelectionRange(newPos, newPos);
          }
        });

        el.addEventListener("blur", () => {
          if (el.value.trim()) {
            el.value = toTitleCase(el.value);
          }
        });
      });

      emailInput.addEventListener("input", () => {
        const cursorPos = emailInput.selectionStart;
        const cleaned = emailInput.value.replace(/\s/g, "");
        if (cleaned !== emailInput.value) {
          const removedCount = emailInput.value.length - cleaned.length;
          emailInput.value = cleaned;
          const newPos = Math.max(0, cursorPos - removedCount);
          emailInput.setSelectionRange(newPos, newPos);
        }
      });

      emailInput.addEventListener("blur", () => {
        if (emailInput.value.trim()) {
          emailInput.value = emailInput.value.trim().toLowerCase();
        }
      });

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

      function showError(msg) {
        errorText.textContent = msg;
        errorBanner.classList.remove("hidden");
        errorBanner.classList.add("flex");
        errorBanner.scrollIntoView({ behavior: "smooth", block: "nearest" });
      }

      function hideError() {
        errorBanner.classList.add("hidden");
        errorBanner.classList.remove("flex");
      }

      function makeToggle(btnId, inputEl, iconId) {
        const btn = document.getElementById(btnId);
        const icon = document.getElementById(iconId);
        btn.addEventListener("click", () => {
          const show = inputEl.type === "password";
          inputEl.type = show ? "text" : "password";
          icon.innerHTML = show
            ? `<path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 0 0 1.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.451 10.451 0 0 1 12 4.5c4.756 0 8.773 3.162 10.065 7.498a10.522 10.522 0 0 1-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65m0 0a3 3 0 1 0-4.243-4.243m4.242 4.242L9.88 9.88"/>`
            : `<path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/>`;
        });
      }
      makeToggle("pwToggle1", passwordInput, "pwIcon1");
      makeToggle("pwToggle2", confirmPassword, "pwIcon2");

      contactNumber.addEventListener("input", () => {
        hideError();
      });

      const strengthLevels = [
        { label: "", color: "bg-gray-200" },
        { label: "Weak", color: "bg-red-400", textCls: "text-red-500" },
        { label: "Fair", color: "bg-amber-400", textCls: "text-amber-500" },
        { label: "Good", color: "bg-emerald-500", textCls: "text-emerald-600" },
        { label: "Strong", color: "bg-emerald-700", textCls: "text-emerald-700" },
      ];

      function getStrength(pw) {
        let score = 0;
        if (pw.length >= 8) score++;
        if (/[A-Z]/.test(pw)) score++;
        if (/[0-9]/.test(pw)) score++;
        if (/[^A-Za-z0-9]/.test(pw)) score++;
        return score;
      }

      passwordInput.addEventListener("input", () => {
        const pw = passwordInput.value;
        const score = pw.length === 0 ? 0 : Math.max(1, getStrength(pw));
        const level = strengthLevels[score];

        bars.forEach((bar, i) => {
          bar.className = `h-1 flex-1 transition-colors rounded-full ${i < score ? level.color : "bg-gray-200"}`;
        });
        strengthLabel.textContent = pw.length > 0 ? level.label : "";
        strengthLabel.className = `text-[10px] mt-1 ${score > 0 ? level.textCls : "text-gray-400"}`;

        checkMatch();
        hideError();
      });

      function checkMatch() {
        const pw = passwordInput.value;
        const cpw = confirmPassword.value;
        if (!cpw) {
          matchMsg.classList.add("hidden");
          return;
        }
        matchMsg.classList.remove("hidden");
        if (pw === cpw) {
          matchMsg.textContent = "Passwords match";
          matchMsg.className = "text-[10px] mt-1.5 text-emerald-600";
          confirmPassword.classList.remove("error");
        } else {
          matchMsg.textContent = "Passwords do not match";
          matchMsg.className = "text-[10px] mt-1.5 text-red-500";
          confirmPassword.classList.add("error");
        }
      }

      confirmPassword.addEventListener("input", () => {
        checkMatch();
        hideError();
      });

      [firstName, lastName, emailInput].forEach((el) => {
        el.addEventListener("input", hideError);
      });

      function isValidEmail(val) {
        return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(val);
      }

      function isValidName(val) {
        return /^[\p{L}\s'-]+$/u.test(val);
      }

      function isStrongPassword(val) {
        return (
          val.length >= 8 &&
          /[A-Z]/.test(val) &&
          /[0-9]/.test(val) &&
          /[^A-Za-z0-9]/.test(val)
        );
      }

      function markError(el) {
        el.classList.add("error");
        el.focus();
      }

      function clearErrors() {
        [firstName, lastName, contactNumber, emailInput, passwordInput, confirmPassword].forEach(
          (el) => el.classList.remove("error"),
        );
      }

      async function handleSubmit() {
        hideError();
        clearErrors();

        const fn = firstName.value.trim();
        const ln = lastName.value.trim();
        const tel = contactNumber.value.trim();
        const em = emailInput.value.trim();
        const pw = passwordInput.value;
        const cpw = confirmPassword.value;

        if (!fn && !ln && !tel && !em && !pw) {
          showError("Please fill in all required fields.");
          return;
        }

        if (!fn) {
          showError("Please enter your first name.");
          markError(firstName);
          return;
        }
        if (!isValidName(fn)) {
          showError("First name can only contain letters.");
          markError(firstName);
          return;
        }
        if (!ln) {
          showError("Please enter your last name.");
          markError(lastName);
          return;
        }
        if (!isValidName(ln)) {
          showError("Last name can only contain letters.");
          markError(lastName);
          return;
        }
        if (!tel) {
          showError("Please enter your contact number.");
          markError(contactNumber);
          return;
        }
        if (!em) {
          showError("Please enter your email address.");
          markError(emailInput);
          return;
        }
        if (!isValidEmail(em)) {
          showError("Please enter a valid email address.");
          markError(emailInput);
          return;
        }
        if (!pw) {
          showError("Please enter a password.");
          markError(passwordInput);
          return;
        }
        if (!isStrongPassword(pw)) {
          showError("Password must be at least 8 characters and include an uppercase letter, a number, and a symbol.");
          markError(passwordInput);
          return;
        }
        if (pw !== cpw) {
          showError("Passwords do not match.");
          markError(confirmPassword);
          return;
        }

        setSubmitLoading(true);

        const res = await postAction("send_code", {
          first_name: fn,
          last_name: ln,
          contact_number: tel,
          email: em,
          password: pw,
          confirm_password: cpw,
        });

        setSubmitLoading(false);

        if (!res.success) {
          showError(res.message || "Something went wrong. Please try again.");
          return;
        }

        openCodeModal(em.toLowerCase());
      }

      submitBtn.addEventListener("click", handleSubmit);

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
        codeModalEmail.textContent = email;
        clearCodeBoxes();
        codeError.classList.add("hidden");
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
        codeVerifyBtnText.textContent = isLoading ? "Verifying..." : "Verify & Create Account";
        codeVerifySpinnerIcon.classList.toggle("hidden", !isLoading);
      }

      codeVerifyBtn.addEventListener("click", async () => {
        const code = getCodeValue();

        if (code.length !== 6) {
          showCodeError("Please enter the 6-digit code.");
          return;
        }

        setVerifyLoading(true);

        const res = await postAction("verify_code", { code });

        if (!res.success) {
          setVerifyLoading(false);
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

        window.location.href = res.redirect;
      });

      resendCodeBtn.addEventListener("click", async () => {
        resendCodeBtn.disabled = true;
        const res = await postAction("resend_code");

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
    </script>
  </body>
</html>