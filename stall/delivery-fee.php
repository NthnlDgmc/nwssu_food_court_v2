<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['owner_id'])) {
  header('Location: ../auth/login.php');
  exit;
}

$ownerId = $_SESSION['owner_id'];

$stallId = null;
$stallName = '';

$deliveryFee = 0.00;

$stmt = $conn->prepare("SELECT delivery_fee FROM stall_owners WHERE owner_id = ? LIMIT 1");
$stmt->bind_param("i", $ownerId);
$stmt->execute();
$ownerResult = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($ownerResult) {
  $deliveryFee = (float) $ownerResult['delivery_fee'];
}

$stmt = $conn->prepare("SELECT stall_id, stall_name FROM stalls WHERE owner_id = ? LIMIT 1");
$stmt->bind_param("i", $ownerId);
$stmt->execute();
$stallResult = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($stallResult) {
  $stallId = (int) $stallResult['stall_id'];
  $stallName = $stallResult['stall_name'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
  header('Content-Type: application/json');
  $action = $_POST['action'];

  if ($action === 'update_fee') {
    $feeRaw = $_POST['delivery_fee'] ?? '';

    if ($feeRaw === '' || !is_numeric($feeRaw) || (float) $feeRaw < 0) {
      echo json_encode(['success' => false, 'message' => 'Please enter a valid delivery fee.']);
      $conn->close();
      exit;
    }

    $fee = round((float) $feeRaw, 2);

    $stmt = $conn->prepare("UPDATE stall_owners SET delivery_fee = ? WHERE owner_id = ?");
    $stmt->bind_param("di", $fee, $ownerId);
    $ok = $stmt->execute();
    $stmt->close();

    if (!$ok) {
      echo json_encode(['success' => false, 'message' => 'Failed to update delivery fee.']);
      $conn->close();
      exit;
    }

    echo json_encode(['success' => true, 'delivery_fee' => $fee]);
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
  <meta
    name="viewport"
    content="width=device-width, initial-scale=1.0, user-scalable=yes" />
  <title>Stall - Delivery Fee</title>
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
  </style>
</head>

<body class="bg-white">
  <div class="flex flex-col h-screen">
    <div class="bg-white flex-shrink-0 fixed top-0 left-0 right-0 z-20">
      <div class="max-w-5xl mx-auto px-4 py-2 grid grid-cols-3 items-center">
        <button
          id="backButton"
          class="p-1.5 bg-white border border-gray-200 hover:border-emerald-500 hover:bg-slate-50 transition-all justify-self-start flex items-center justify-center shrink-0"
          style="width: 34px; height: 34px; border-radius: 6px"
          title="Go back">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 text-gray-600">
            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" />
          </svg>
        </button>
        <h1 class="text-base font-semibold text-emerald-600 text-center">
          Delivery Fee
        </h1>
        <div class="justify-self-end" style="width: 34px"></div>
      </div>
    </div>

    <div class="flex-1 overflow-y-auto mt-12" id="mainContent">
      <div class="max-w-5xl mx-auto px-4 pt-3 pb-4 space-y-3">

        <?php if (!$stallId): ?>
          <div class="rounded-md bg-emerald-50 border border-emerald-100 shadow-sm p-3">
            <p class="text-[11px] text-emerald-700 font-medium">You don't have a stall assigned yet, but you can still set your delivery fee now — it will apply automatically once the admin assigns you to a stall.</p>
          </div>
        <?php endif; ?>

        <div class="rounded-md bg-white border border-gray-200 shadow-sm overflow-hidden">
          <div class="p-4 border-b border-gray-100 flex items-center gap-3">
            <div class="w-9 h-9 bg-emerald-600 flex items-center justify-center shrink-0" style="border-radius:6px">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 text-white">
                <path stroke-linecap="round" stroke-linejoin="round" d="m20.25 7.5-.625 10.632a2.25 2.25 0 0 1-2.247 2.118H6.622a2.25 2.25 0 0 1-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125Z" />
              </svg>
            </div>
            <div class="min-w-0">
              <p class="text-xs font-bold text-gray-700 truncate"><?php echo $stallId ? htmlspecialchars($stallName) : 'Your Delivery Fee'; ?></p>
              <p class="text-[10px] text-gray-400 mt-0.5">Set the delivery fee charged to customers</p>
            </div>
          </div>

          <div class="p-4 space-y-3">
            <div>
              <label class="block text-[11px] font-semibold text-gray-500 uppercase tracking-wide mb-1.5">Delivery Fee (&#8369;)</label>
              <input
                type="number"
                id="deliveryFeeInput"
                value="<?php echo htmlspecialchars(number_format($deliveryFee, 2, '.', '')); ?>"
                placeholder="0.00"
                min="0"
                step="0.01"
                class="w-full px-3 py-2.5 bg-white border border-gray-200 text-xs text-gray-900 placeholder-gray-400 focus:outline-none focus:border-emerald-600"
                style="border-radius: 3px" />
              <p class="text-[10px] text-gray-400 mt-1.5">Set to 0 to offer free delivery.</p>
            </div>

            <div
              id="feeFormError"
              class="hidden flex items-center gap-2 p-3 bg-red-50 border border-red-200"
              style="border-radius: 3px">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 text-red-500 shrink-0">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
              </svg>
              <p class="text-[10px] text-red-600 font-medium leading-none" id="feeFormErrorMsg"></p>
            </div>

            <div
              id="feeFormSuccess"
              class="hidden flex items-center gap-2 p-3 bg-emerald-50 border border-emerald-200"
              style="border-radius: 3px">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 text-emerald-600 shrink-0">
                <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
              </svg>
              <p class="text-[10px] text-emerald-700 font-medium leading-none">Delivery fee updated successfully.</p>
            </div>

            <button
              id="saveFeeBtn"
              class="w-full py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold transition-colors"
              style="border-radius: 3px">
              Save Delivery Fee
            </button>
          </div>
        </div>

      </div>
    </div>
  </div>

  <script>
    async function postAction(action, data = {}) {
      const formData = new FormData();
      formData.append("action", action);
      for (const key in data) {
        const val = data[key];
        formData.append(key, val === null || val === undefined ? "" : val);
      }
      try {
        const response = await fetch(window.location.href, {
          method: "POST",
          body: formData,
        });
        return await response.json();
      } catch (err) {
        return {
          success: false,
          message: "Something went wrong. Please try again."
        };
      }
    }

    window.addEventListener("load", function() {
      document
        .getElementById("backButton")
        .addEventListener("click", () => window.history.back());

      const saveFeeBtn = document.getElementById("saveFeeBtn");
      if (saveFeeBtn) {
        saveFeeBtn.addEventListener("click", async () => {
          const input = document.getElementById("deliveryFeeInput");
          const errEl = document.getElementById("feeFormError");
          const errMsg = document.getElementById("feeFormErrorMsg");
          const successEl = document.getElementById("feeFormSuccess");

          errEl.classList.add("hidden");
          successEl.classList.add("hidden");

          const feeRaw = input.value;
          const fee = parseFloat(feeRaw);

          if (feeRaw === "" || isNaN(fee) || fee < 0) {
            errMsg.textContent = "Please enter a valid delivery fee.";
            errEl.classList.remove("hidden");
            return;
          }

          saveFeeBtn.disabled = true;
          const res = await postAction("update_fee", {
            delivery_fee: fee
          });
          saveFeeBtn.disabled = false;

          if (!res.success) {
            errMsg.textContent = res.message || "Something went wrong. Please try again.";
            errEl.classList.remove("hidden");
            return;
          }

          input.value = Number(res.delivery_fee).toFixed(2);
          successEl.classList.remove("hidden");
          setTimeout(() => successEl.classList.add("hidden"), 2500);
        });
      }
    });
  </script>
</body>

</html>