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

function isEmailRegisteredAnywhere($conn, $email, $excludeIdNumber = '')
{
  if ($email === '' || $email === null) return false;

  $stmt = $conn->prepare("SELECT admin_id FROM admin WHERE email = ? LIMIT 1");
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

  $stmt = $conn->prepare("SELECT customer_id FROM customers WHERE email = ? AND id_number != ? LIMIT 1");
  $stmt->bind_param("ss", $email, $excludeIdNumber);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  if ($row) return true;

  return false;
}

if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'customer' || !isset($_SESSION['id_number'])) {
  header('Location: ./login.php');
  exit;
}

$idNumber = $_SESSION['id_number'];

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
    $mail->Subject = 'Confirm Your Email — NWSSU Food Court';
    $mail->Body = '<p>Use the code below to confirm your email address and complete your profile.</p>'
      . '<p style="font-size:28px;font-weight:bold;letter-spacing:6px;">' . htmlspecialchars($code) . '</p>'
      . '<p>This code will expire in 15 minutes. If you did not request this, you can safely ignore this email.</p>';
    $mail->AltBody = 'Your email confirmation code is: ' . $code . '. This code expires in 15 minutes.';

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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
  header('Content-Type: application/json');
  $action = $_POST['action'];

  if ($action === 'send_code') {
    $contact = trim($_POST['contact_number'] ?? '');
    $email = strtolower(preg_replace('/\s+/', '', $_POST['email'] ?? ''));
    $password = trim($_POST['password'] ?? '');
    $confirmPassword = trim($_POST['confirm_password'] ?? '');

    if ($contact === '' || strlen($contact) !== 10 || $contact[0] !== '9') {
      echo json_encode(['success' => false, 'message' => 'Please enter a valid 10-digit contact number starting with 9.']);
      $conn->close();
      exit;
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
      echo json_encode(['success' => false, 'message' => 'Please enter a valid email address.']);
      $conn->close();
      exit;
    }
    if (!isStrongPassword($password)) {
      echo json_encode(['success' => false, 'message' => 'Password must be at least 8 characters and include an uppercase letter, a number, and a symbol.']);
      $conn->close();
      exit;
    }
    if ($password !== $confirmPassword) {
      echo json_encode(['success' => false, 'message' => 'Passwords do not match.']);
      $conn->close();
      exit;
    }

    if (isEmailRegisteredAnywhere($conn, $email, $idNumber)) {
      echo json_encode(['success' => false, 'message' => 'This email address is already registered.']);
      $conn->close();
      exit;
    }

    $lastSentAt = $_SESSION['profile_last_sent_at'] ?? 0;
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

    $_SESSION['pending_profile'] = [
      'contact_number' => '+63' . $contact,
      'email' => $email,
      'password' => $password,
      'last_sent_at' => time(),
      'verify_attempts' => 0,
    ];

    echo json_encode(['success' => true, 'wait_seconds' => RESEND_COOLDOWN_SECONDS]);
    $conn->close();
    exit;
  }

  if ($action === 'resend_code') {
    $email = $_SESSION['pending_profile']['email'] ?? '';

    if ($email === '') {
      echo json_encode(['success' => false, 'message' => 'Your session has expired. Please start over.']);
      $conn->close();
      exit;
    }

    $lastSentAt = $_SESSION['pending_profile']['last_sent_at'] ?? 0;
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
    $_SESSION['pending_profile']['last_sent_at'] = time();
    $_SESSION['pending_profile']['verify_attempts'] = 0;

    echo json_encode(['success' => true, 'wait_seconds' => RESEND_COOLDOWN_SECONDS]);
    $conn->close();
    exit;
  }

  if ($action === 'verify_code') {
    $code = trim($_POST['code'] ?? '');
    $pending = $_SESSION['pending_profile'] ?? null;

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

    $attempts = $_SESSION['pending_profile']['verify_attempts'] ?? 0;

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
      $_SESSION['pending_profile']['verify_attempts'] = $attempts;
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

    $stmt = $conn->prepare("UPDATE customers SET contact_number = ?, email = ?, password = ? WHERE id_number = ?");
    $stmt->bind_param("ssss", $pending['contact_number'], $pending['email'], $pending['password'], $idNumber);
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

    unset($_SESSION['pending_profile']);

    echo json_encode(['success' => true, 'redirect' => '../customer/home.php']);
    $conn->close();
    exit;
  }

  echo json_encode(['success' => false, 'message' => 'Unknown action.']);
  $conn->close();
  exit;
}

$stmt = $conn->prepare("SELECT id_number, first_name, last_name, customer_type FROM customers WHERE id_number = ? LIMIT 1");
$stmt->bind_param("s", $idNumber);
$stmt->execute();
$result = $stmt->get_result();
$customer = $result->fetch_assoc();
$stmt->close();
$conn->close();

if (!$customer) {
  header('Location: ./login.php');
  exit;
}
?>
<!doctype html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta
    name="viewport"
    content="width=device-width, initial-scale=1.0, user-scalable=yes" />
  <title>NWSSU Food Court — Complete Profile</title>
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

    .locked-input {
      background: #f3f4f6;
      color: #6b7280;
      cursor: not-allowed;
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
  </style>
</head>

<body class="bg-white">
  <div class="min-h-screen flex items-center justify-center px-4 py-10">
    <div class="w-full max-w-sm">
      <div class="text-center mb-5">
        <h1 class="text-base font-semibold text-emerald-600">
          Complete Your Profile
        </h1>
        <p class="text-xs text-gray-500 mt-1">
          Just a few more details before you start ordering
        </p>
      </div>

      <div class="rounded-md bg-white border border-gray-200 shadow-sm p-4 space-y-4">
        <div
          id="formError"
          class="hidden flex items-start gap-2 p-3 bg-red-50 border border-red-200 rounded-[3px]">
          <svg
            xmlns="http://www.w3.org/2000/svg"
            fill="none"
            viewBox="0 0 24 24"
            stroke-width="1.5"
            stroke="currentColor"
            class="w-4 h-4 text-red-500 shrink-0 mt-0.5">
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
          </svg>
          <p class="text-[10px] text-red-600 font-medium" id="formErrorMsg"></p>
        </div>

        <div class="bg-gray-50 border border-gray-200 p-3 space-y-3 rounded-[3px]">
          <div class="flex items-center justify-between">
            <p class="text-[11px] font-semibold text-gray-500 uppercase tracking-wide">
              Verified by Registrar
            </p>
            <span
              id="accountTypeBadge"
              class="text-[10px] font-semibold px-2 py-0.5 border capitalize rounded-[3px]"></span>
          </div>

          <div>
            <label class="block text-[11px] font-semibold text-gray-500 uppercase tracking-wide mb-1.5">ID Number</label>
            <input
              type="text"
              id="idNumberInput"
              disabled
              class="locked-input w-full px-3 py-2.5 border border-gray-200 text-xs font-medium rounded-[3px]" />
          </div>
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="block text-[11px] font-semibold text-gray-500 uppercase tracking-wide mb-1.5">First Name</label>
              <input
                type="text"
                id="firstNameInput"
                disabled
                class="locked-input w-full px-3 py-2.5 border border-gray-200 text-xs font-medium rounded-[3px]" />
            </div>
            <div>
              <label class="block text-[11px] font-semibold text-gray-500 uppercase tracking-wide mb-1.5">Last Name</label>
              <input
                type="text"
                id="lastNameInput"
                disabled
                class="locked-input w-full px-3 py-2.5 border border-gray-200 text-xs font-medium rounded-[3px]" />
            </div>
          </div>
          <p class="text-[10px] text-gray-400 leading-relaxed">
            This information was provided by the Registrar's Office and
            cannot be edited here. If anything is incorrect, please visit
            the Registrar.
          </p>
        </div>

        <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest pt-1">
          Contact Details
        </p>

        <div>
          <label class="block text-[11px] font-semibold text-gray-500 uppercase tracking-wide mb-1.5">Contact Number</label>
          <div class="relative">
            <div
              class="absolute left-3 top-1/2 -translate-y-1/2 text-xs text-gray-400 font-medium pointer-events-none">
              +63
            </div>
            <input
              type="tel"
              id="contactNumber"
              maxlength="10"
              placeholder="9XX XXX XXXX"
              class="w-full pl-10 pr-3 py-2.5 bg-white border border-gray-200 text-xs text-gray-900 placeholder-gray-400 focus:outline-none focus:border-emerald-600 rounded-[3px]" />
          </div>
        </div>

        <div>
          <label class="block text-[11px] font-semibold text-gray-500 uppercase tracking-wide mb-1.5">Email Address</label>
          <input
            type="email"
            id="emailInput"
            placeholder="example@email.com"
            class="w-full px-3 py-2.5 bg-white border border-gray-200 text-xs text-gray-900 placeholder-gray-400 focus:outline-none focus:border-emerald-600 rounded-[3px]" />
        </div>

        <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest pt-1">
          Set Your Password
        </p>

        <div>
          <label class="block text-[11px] font-semibold text-gray-500 uppercase tracking-wide mb-1.5">Password</label>
          <div class="relative">
            <input
              type="password"
              id="passwordInput"
              placeholder="Enter your password"
              class="w-full px-3 py-2.5 pr-9 bg-white border border-gray-200 text-xs text-gray-900 placeholder-gray-400 focus:outline-none focus:border-emerald-600 rounded-[3px]" />
            <button
              type="button"
              id="pwToggle1"
              class="absolute right-2.5 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 transition-colors">
              <svg
                xmlns="http://www.w3.org/2000/svg"
                fill="none"
                viewBox="0 0 24 24"
                stroke-width="1.5"
                stroke="currentColor"
                class="w-4 h-4"
                id="pwIcon1">
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" />
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
              </svg>
            </button>
          </div>
          <p class="text-[10px] text-gray-400 mt-1.5">
            At least 8 characters, with an uppercase letter, a number, and a symbol.
          </p>
          <div class="mt-2">
            <div class="flex gap-1">
              <div class="h-1 flex-1 bg-gray-200 transition-colors rounded-full" id="pwBar1"></div>
              <div class="h-1 flex-1 bg-gray-200 transition-colors rounded-full" id="pwBar2"></div>
              <div class="h-1 flex-1 bg-gray-200 transition-colors rounded-full" id="pwBar3"></div>
              <div class="h-1 flex-1 bg-gray-200 transition-colors rounded-full" id="pwBar4"></div>
            </div>
            <p class="text-[10px] mt-1" id="pwStrengthLabel"></p>
          </div>
        </div>

        <div>
          <label class="block text-[11px] font-semibold text-gray-500 uppercase tracking-wide mb-1.5">Re-enter Password</label>
          <div class="relative">
            <input
              type="password"
              id="confirmPassword"
              placeholder="Repeat your password"
              class="w-full px-3 py-2.5 pr-9 bg-white border border-gray-200 text-xs text-gray-900 placeholder-gray-400 focus:outline-none focus:border-emerald-600 rounded-[3px]" />
            <button
              type="button"
              id="pwToggle2"
              class="absolute right-2.5 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 transition-colors">
              <svg
                xmlns="http://www.w3.org/2000/svg"
                fill="none"
                viewBox="0 0 24 24"
                stroke-width="1.5"
                stroke="currentColor"
                class="w-4 h-4"
                id="pwIcon2">
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" />
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
              </svg>
            </button>
          </div>
          <p class="text-[10px] mt-1.5 hidden" id="matchMsg"></p>
        </div>

        <button
          id="submitBtn"
          class="w-full py-2.5 bg-emerald-600 hover:bg-emerald-700 disabled:opacity-70 disabled:cursor-not-allowed text-white text-xs font-semibold transition-colors flex items-center justify-center gap-1.5 rounded-[3px]">
          <svg
            id="submitDefaultIcon"
            xmlns="http://www.w3.org/2000/svg"
            fill="none"
            viewBox="0 0 24 24"
            stroke-width="1.5"
            stroke="currentColor"
            class="w-4 h-4">
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
          </svg>
          <svg
            id="submitSpinnerIcon"
            class="hidden w-4 h-4 animate-spin"
            xmlns="http://www.w3.org/2000/svg"
            fill="none"
            viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-90" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.373 0 0 5.373 0 12h4Z"></path>
          </svg>
          <span id="submitBtnText">Complete Profile &amp; Continue</span>
        </button>

        <p class="text-center text-[10px] text-gray-400">
          Wrong account?
          <a href="./login.php" class="text-emerald-600 font-semibold hover:text-emerald-700">Sign out</a>
        </p>
      </div>
    </div>
  </div>

  <div id="codeModal" class="fixed inset-0 z-50 hidden flex items-center justify-center px-4">
    <div class="modal-overlay absolute inset-0" id="closeCodeOverlay"></div>
    <div class="bg-white w-full max-w-sm relative z-10 shadow-2xl rounded-md">
      <div class="p-4 border-b border-gray-100 flex items-center justify-between">
        <h2 class="font-bold text-gray-800 text-sm">Confirm Your Email</h2>
        <button id="closeCodeModalBtn" class="p-1 hover:bg-gray-100 rounded-[3px]">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 text-gray-500">
            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
          </svg>
        </button>
      </div>
      <div class="p-4 space-y-3">
        <p class="text-xs text-gray-500">
          We sent a 6-digit code to <span id="codeModalEmail" class="font-semibold text-gray-700"></span>. Enter it below to confirm your email and finish completing your profile.
        </p>
        <div id="codeError" class="hidden items-center gap-2 p-3 bg-red-50 border border-red-200 rounded-[3px]">
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
          <button type="button" id="resendCodeBtn" class="font-semibold text-emerald-600 hover:text-emerald-700 transition-colors">
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
            viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-90" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.373 0 0 5.373 0 12h4Z"></path>
          </svg>
          <span id="codeVerifyBtnText">Verify &amp; Complete Profile</span>
        </button>
      </div>
    </div>
  </div>

  <script>
    const REGISTRAR_RECORD = {
      idNumber: <?php echo json_encode($customer['id_number']); ?>,
      firstName: <?php echo json_encode($customer['first_name']); ?>,
      lastName: <?php echo json_encode($customer['last_name']); ?>,
      accountType: <?php echo json_encode($customer['customer_type']); ?>,
    };

    const TYPE_COLORS = {
      student: "bg-emerald-50 text-emerald-700 border-emerald-200",
      faculty: "bg-blue-50 text-blue-700 border-blue-200",
      staff: "bg-purple-50 text-purple-700 border-purple-200",
      outsider: "bg-gray-100 text-gray-600 border-gray-200",
    };

    const idNumberInput = document.getElementById("idNumberInput");
    const firstNameInput = document.getElementById("firstNameInput");
    const lastNameInput = document.getElementById("lastNameInput");
    const accountTypeBadge = document.getElementById("accountTypeBadge");

    const contactNumber = document.getElementById("contactNumber");
    const emailInput = document.getElementById("emailInput");
    const passwordInput = document.getElementById("passwordInput");
    const confirmPassword = document.getElementById("confirmPassword");
    const submitBtn = document.getElementById("submitBtn");
    const submitBtnText = document.getElementById("submitBtnText");
    const submitDefaultIcon = document.getElementById("submitDefaultIcon");
    const submitSpinnerIcon = document.getElementById("submitSpinnerIcon");
    const formError = document.getElementById("formError");
    const formErrorMsg = document.getElementById("formErrorMsg");
    const matchMsg = document.getElementById("matchMsg");

    function setSubmitLoading(isLoading) {
      submitBtn.disabled = isLoading;
      submitBtnText.textContent = isLoading ? "Sending code..." : "Complete Profile & Continue";
      submitDefaultIcon.classList.toggle("hidden", isLoading);
      submitSpinnerIcon.classList.toggle("hidden", !isLoading);
    }

    function loadRegistrarRecord() {
      idNumberInput.value = REGISTRAR_RECORD.idNumber;
      firstNameInput.value = REGISTRAR_RECORD.firstName;
      lastNameInput.value = REGISTRAR_RECORD.lastName;
      const cls = TYPE_COLORS[REGISTRAR_RECORD.accountType] || TYPE_COLORS.student;
      accountTypeBadge.textContent = REGISTRAR_RECORD.accountType;
      accountTypeBadge.className =
        "text-[10px] font-semibold px-2 py-0.5 border capitalize rounded-[3px] " + cls;
    }

    function showError(msg) {
      formErrorMsg.textContent = msg;
      formError.classList.remove("hidden");
      formError.classList.add("flex");
    }

    function hideError() {
      formError.classList.add("hidden");
      formError.classList.remove("flex");
    }

    function markError(el) {
      el.classList.add("error");
      el.focus();
    }

    function clearErrors() {
      [contactNumber, emailInput, passwordInput, confirmPassword].forEach((el) =>
        el.classList.remove("error"),
      );
    }

    function makeToggle(btnId, inputEl, iconId) {
      const btn = document.getElementById(btnId);
      const icon = document.getElementById(iconId);
      btn.addEventListener("click", () => {
        const show = inputEl.type === "password";
        inputEl.type = show ? "text" : "password";
        icon.innerHTML = show ?
          `<path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 0 0 1.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.451 10.451 0 0 1 12 4.5c4.756 0 8.773 3.162 10.065 7.498a10.522 10.522 0 0 1-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65m0 0a3 3 0 1 0-4.243-4.243m4.242 4.242L9.88 9.88"/>` :
          `<path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/>`;
      });
    }
    makeToggle("pwToggle1", passwordInput, "pwIcon1");
    makeToggle("pwToggle2", confirmPassword, "pwIcon2");

    contactNumber.addEventListener("input", () => {
      contactNumber.value = contactNumber.value.replace(/\D/g, "").slice(0, 10);
      hideError();
    });

    const pwLevels = [{
        label: "",
        color: "bg-gray-200"
      },
      {
        label: "Weak",
        color: "bg-red-400",
        textCls: "text-red-500"
      },
      {
        label: "Fair",
        color: "bg-amber-400",
        textCls: "text-amber-500"
      },
      {
        label: "Good",
        color: "bg-emerald-500",
        textCls: "text-emerald-600"
      },
      {
        label: "Strong",
        color: "bg-emerald-700",
        textCls: "text-emerald-700"
      },
    ];

    function getPwStrength(pw) {
      let s = 0;
      if (pw.length >= 8) s++;
      if (/[A-Z]/.test(pw)) s++;
      if (/[0-9]/.test(pw)) s++;
      if (/[^A-Za-z0-9]/.test(pw)) s++;
      return s;
    }
    passwordInput.addEventListener("input", () => {
      const pw = passwordInput.value;
      const score = pw.length === 0 ? 0 : Math.max(1, getPwStrength(pw));
      const level = pwLevels[score];
      [1, 2, 3, 4].forEach((n, i) => {
        const b = document.getElementById("pwBar" + n);
        b.className = `h-1 flex-1 transition-colors rounded-full ${i < score ? level.color : "bg-gray-200"}`;
      });
      const lbl = document.getElementById("pwStrengthLabel");
      lbl.textContent = pw.length > 0 ? level.label : "";
      lbl.className = `text-[10px] mt-1 ${score > 0 ? level.textCls : "text-gray-400"}`;
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

    emailInput.addEventListener("input", hideError);

    function isValidEmail(val) {
      return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(val);
    }

    function isStrongPassword(val) {
      return (
        val.length >= 8 &&
        /[A-Z]/.test(val) &&
        /[0-9]/.test(val) &&
        /[^A-Za-z0-9]/.test(val)
      );
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

    async function handleSubmit() {
      hideError();
      clearErrors();

      const tel = contactNumber.value.trim();
      const em = emailInput.value.trim();
      const pw = passwordInput.value;
      const cpw = confirmPassword.value;

      if (!tel) {
        showError("Please enter your contact number.");
        markError(contactNumber);
        return;
      }
      if (tel.length < 10) {
        showError("Contact number must be 10 digits.");
        markError(contactNumber);
        return;
      }
      if (!tel.startsWith("9")) {
        showError("Contact number must start with 9.");
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
      codeVerifyBtnText.textContent = isLoading ? "Verifying..." : "Verify & Complete Profile";
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

    window.addEventListener("load", loadRegistrarRecord);
  </script>
</body>

</html>