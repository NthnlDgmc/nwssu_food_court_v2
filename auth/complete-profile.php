<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'customer' || !isset($_SESSION['id_number'])) {
  header('Location: ./login.php');
  exit;
}

$idNumber = $_SESSION['id_number'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  header('Content-Type: application/json');

  $contact = trim($_POST['contact_number'] ?? '');
  $email = trim($_POST['email'] ?? '');
  $password = trim($_POST['password'] ?? '');
  $confirmPassword = trim($_POST['confirm_password'] ?? '');

  if ($contact === '' || strlen($contact) !== 10 || $contact[0] !== '9') {
    echo json_encode(['success' => false, 'message' => 'Please enter a valid 10-digit contact number starting with 9.']);
    exit;
  }
  if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Please enter a valid email address.']);
    exit;
  }
  if ($password === '' || strlen($password) < 8) {
    echo json_encode(['success' => false, 'message' => 'Password must be at least 8 characters.']);
    exit;
  }
  if ($password !== $confirmPassword) {
    echo json_encode(['success' => false, 'message' => 'Passwords do not match.']);
    exit;
  }

  $fullContact = '+63' . $contact;

  $stmt = $conn->prepare("UPDATE customers SET contact_number = ?, email = ?, password = ? WHERE id_number = ?");
  $stmt->bind_param("ssss", $fullContact, $email, $password, $idNumber);

  if ($stmt->execute()) {
    $stmt->close();
    $conn->close();
    echo json_encode(['success' => true, 'redirect' => '../customer/home.php']);
    exit;
  } else {
    $stmt->close();
    $conn->close();
    echo json_encode(['success' => false, 'message' => 'Something went wrong. Please try again.']);
    exit;
  }
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
              placeholder="At least 8 characters"
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
          class="w-full py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold transition-colors flex items-center justify-center gap-1.5 rounded-[3px]">
          <svg
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
          <span id="submitBtnText">Complete Profile &amp; Continue</span>
        </button>

        <p class="text-center text-[10px] text-gray-400">
          Wrong account?
          <a href="./login.php" class="text-emerald-600 font-semibold hover:text-emerald-700">Sign out</a>
        </p>
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
    const formError = document.getElementById("formError");
    const formErrorMsg = document.getElementById("formErrorMsg");
    const matchMsg = document.getElementById("matchMsg");

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
      if (pw.length < 8) {
        showError("Password must be at least 8 characters.");
        markError(passwordInput);
        return;
      }
      if (pw !== cpw) {
        showError("Passwords do not match.");
        markError(confirmPassword);
        return;
      }

      submitBtn.disabled = true;
      submitBtnText.textContent = "Saving...";

      try {
        const formData = new FormData();
        formData.append("contact_number", tel);
        formData.append("email", em);
        formData.append("password", pw);
        formData.append("confirm_password", cpw);

        const response = await fetch(window.location.href, {
          method: "POST",
          body: formData,
        });

        const data = await response.json();

        if (data.success) {
          window.location.href = data.redirect;
        } else {
          showError(data.message || "Something went wrong. Please try again.");
          submitBtn.disabled = false;
          submitBtnText.textContent = "Complete Profile & Continue";
        }
      } catch (err) {
        showError("Something went wrong. Please try again.");
        submitBtn.disabled = false;
        submitBtnText.textContent = "Complete Profile & Continue";
      }
    }

    submitBtn.addEventListener("click", handleSubmit);

    window.addEventListener("load", loadRegistrarRecord);
  </script>
</body>

</html>