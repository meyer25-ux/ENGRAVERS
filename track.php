<?php
require_once __DIR__ . '/config.php';

$order = null;
$message = '';
$submitted = $_SERVER['REQUEST_METHOD'] === 'POST' || isset($_GET['order_id']);

function e($value) {
  return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function digitsOnly($value) {
  return preg_replace('/\D+/', '', (string) $value);
}

function statusClass($status) {
  $key = strtolower(str_replace(' ', '-', $status ?: 'Pending'));
  return in_array($key, ['pending', 'in-progress', 'ready', 'delivered', 'cancelled'], true) ? $key : 'pending';
}

$orderIdInput = trim($_POST['order_id'] ?? $_GET['order_id'] ?? '');
$phoneInput = trim($_POST['phone'] ?? $_GET['phone'] ?? '');
$orderId = (int) ltrim($orderIdInput, '#');
$phoneDigits = digitsOnly($phoneInput);

if ($submitted) {
  if ($orderId <= 0 || $phoneDigits === '') {
    $message = 'Enter your order ID and phone number.';
  } else {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

    if ($conn->connect_error) {
      $message = 'We could not connect to the order system. Please try again later.';
    } else {
      $conn->set_charset('utf8mb4');

      $hasStatus = false;
      $columnCheck = $conn->query("SHOW COLUMNS FROM orders LIKE 'status'");
      if ($columnCheck && $columnCheck->num_rows > 0) {
        $hasStatus = true;
      }

      $statusSelect = $hasStatus ? 'status' : "'Pending' AS status";
      $stmt = $conn->prepare("
        SELECT id, name, phone, brand, model, finish, case_type, silicon_pad, delivery, location, total_price, created_at, $statusSelect
        FROM orders
        WHERE id = ?
        LIMIT 1
      ");
      $stmt->bind_param('i', $orderId);
      $stmt->execute();
      $result = $stmt->get_result();
      $foundOrder = $result ? $result->fetch_assoc() : null;
      $stmt->close();
      $conn->close();

      if ($foundOrder && digitsOnly($foundOrder['phone']) === $phoneDigits) {
        $order = $foundOrder;
      } else {
        $message = 'No order matched that ID and phone number.';
      }
    }
  }
}

$status = $order['status'] ?? '';
$steps = ['Pending', 'In Progress', 'Ready', 'Delivered'];
$currentIndex = array_search($status, $steps, true);
if ($currentIndex === false) $currentIndex = 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Track Order - ENGRAVERS</title>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Syne:wght@700;800&family=DM+Sans:wght@400;500;700&display=swap">
  <style>
    *, *::before, *::after { box-sizing: border-box; }
    body {
      margin: 0;
      min-height: 100vh;
      font-family: 'DM Sans', Arial, sans-serif;
      background: #deecd6;
      color: #10200a;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 28px 16px;
    }
    main { width: min(100%, 760px); }
    .panel {
      background: #ffffff;
      border: 1px solid #c5e0b0;
      border-radius: 8px;
      box-shadow: 0 18px 50px rgba(45, 90, 27, 0.12);
      padding: 28px;
    }
    .top-link {
      display: inline-block;
      margin-bottom: 14px;
      color: #2d5a1b;
      font-weight: 700;
      text-decoration: none;
    }
    h1 {
      margin: 0 0 8px;
      font-family: 'Syne', Arial, sans-serif;
      font-size: clamp(28px, 5vw, 42px);
      color: #2d5a1b;
      letter-spacing: 0;
    }
    p { margin: 0; color: #4a7c35; line-height: 1.5; }
    form {
      display: grid;
      grid-template-columns: 1fr 1fr auto;
      gap: 10px;
      margin-top: 24px;
    }
    label { display: block; font-size: 13px; color: #3b6d11; font-weight: 700; margin-bottom: 6px; }
    input {
      width: 100%;
      min-height: 46px;
      border: 1.5px solid #c5e0b0;
      border-radius: 8px;
      padding: 10px 12px;
      font: inherit;
      outline: none;
    }
    input:focus { border-color: #2d5a1b; box-shadow: 0 0 0 3px rgba(45, 90, 27, 0.12); }
    button {
      align-self: end;
      min-height: 46px;
      border: 0;
      border-radius: 8px;
      padding: 0 22px;
      background: #2d5a1b;
      color: #deecd6;
      font-family: 'Syne', Arial, sans-serif;
      font-weight: 800;
      cursor: pointer;
    }
    .message {
      margin-top: 18px;
      padding: 12px 14px;
      border-radius: 8px;
      background: #fff7ed;
      color: #9a3412;
      border: 1px solid #fed7aa;
    }
    .result {
      margin-top: 24px;
      border-top: 1px solid #e5f0dc;
      padding-top: 24px;
    }
    .status-row {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      margin-bottom: 20px;
    }
    .status-badge {
      display: inline-flex;
      align-items: center;
      min-height: 32px;
      padding: 6px 12px;
      border-radius: 999px;
      font-weight: 800;
      font-size: 13px;
    }
    .pending { background: #fef3c7; color: #92400e; }
    .in-progress { background: #dbeafe; color: #1d4ed8; }
    .ready { background: #ede9fe; color: #6d28d9; }
    .delivered { background: #dcfce7; color: #15803d; }
    .cancelled { background: #fee2e2; color: #b91c1c; }
    .timeline {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: 8px;
      margin-bottom: 22px;
    }
    .step {
      min-height: 70px;
      border-radius: 8px;
      border: 1px solid #d8e9cd;
      padding: 12px;
      color: #6b8061;
      background: #f8fbf5;
      font-size: 13px;
      font-weight: 700;
    }
    .step.active {
      background: #2d5a1b;
      border-color: #2d5a1b;
      color: #deecd6;
    }
    .details {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 12px;
    }
    .detail {
      border: 1px solid #e5f0dc;
      border-radius: 8px;
      padding: 13px;
      background: #fbfdf9;
    }
    .detail span { display: block; color: #6b8061; font-size: 12px; margin-bottom: 4px; }
    .detail strong { color: #10200a; }
    @media (max-width: 700px) {
      form { grid-template-columns: 1fr; }
      button { width: 100%; }
      .status-row { align-items: flex-start; flex-direction: column; }
      .timeline, .details { grid-template-columns: 1fr; }
      .panel { padding: 22px; }
    }
  </style>
</head>
<body>
  <main>
    <a class="top-link" href="index.html">Back to home</a>
    <section class="panel">
      <h1>Track Your Order</h1>
      <p>Enter your order ID and the same phone number you used when placing the order.</p>

      <form method="POST">
        <div>
          <label for="order_id">Order ID</label>
          <input id="order_id" name="order_id" value="<?= e($orderIdInput) ?>" placeholder="Example: 1" inputmode="numeric" required>
        </div>
        <div>
          <label for="phone">Phone Number</label>
          <input id="phone" name="phone" value="<?= e($phoneInput) ?>" placeholder="Example: 0971234567" inputmode="tel" required>
        </div>
        <button type="submit">Track</button>
      </form>

      <?php if ($message): ?>
        <div class="message"><?= e($message) ?></div>
      <?php endif; ?>

      <?php if ($order): ?>
        <section class="result">
          <div class="status-row">
            <div>
              <p>Order #<?= e($order['id']) ?></p>
              <h1><?= e($order['name']) ?></h1>
            </div>
            <span class="status-badge <?= e(statusClass($status)) ?>"><?= e($status ?: 'Pending') ?></span>
          </div>

          <?php if ($status === 'Cancelled'): ?>
            <div class="message">This order has been cancelled. Contact ENGRAVERS if you need help.</div>
          <?php else: ?>
            <div class="timeline">
              <?php foreach ($steps as $index => $step): ?>
                <div class="step <?= $index <= $currentIndex ? 'active' : '' ?>"><?= e($step) ?></div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

          <div class="details">
            <div class="detail"><span>Phone model</span><strong><?= e($order['brand']) ?> <?= e($order['model']) ?></strong></div>
            <div class="detail"><span>Case</span><strong><?= e($order['finish']) ?> - <?= e($order['case_type']) ?></strong></div>
            <div class="detail"><span>Silicon pad</span><strong><?= e($order['silicon_pad']) ?></strong></div>
            <div class="detail"><span>Delivery</span><strong><?= e($order['delivery']) ?></strong></div>
            <?php if (!empty($order['location'])): ?>
              <div class="detail"><span>Location</span><strong><?= e($order['location']) ?></strong></div>
            <?php endif; ?>
            <div class="detail"><span>Total</span><strong>K<?= e(number_format((float) $order['total_price'], 0)) ?></strong></div>
            <div class="detail"><span>Placed</span><strong><?= e(date('M j, Y g:ia', strtotime($order['created_at']))) ?></strong></div>
          </div>
        </section>
      <?php endif; ?>
    </section>
  </main>
</body>
</html>
