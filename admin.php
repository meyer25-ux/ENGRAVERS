<?php
// Harden session cookie before session_start()
ini_set('session.cookie_httponly', '1');   // Blocks JS from reading the cookie
ini_set('session.cookie_secure',   '1');   // HTTPS only — set to '0' for local HTTP testing
ini_set('session.cookie_samesite', 'Strict'); // Blocks cross-site requests carrying the cookie
ini_set('session.use_strict_mode', '1');   // Reject unrecognised session IDs
ini_set('session.gc_maxlifetime',  '1800'); // Expire idle sessions after 30 min
session_start();
require_once __DIR__ . '/config.php';

if (isset($_POST['logout'])) { session_destroy(); header('Location: admin.php'); exit; }

// Validate CSRF token on every POST (logout, login, status update)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/db.php'; // loads csrf_validate_token()
    // Skip CSRF check only for the initial login form — token doesn't exist yet for anonymous users.
    // For all authenticated POSTs the token must be present and valid.
    if (!isset($_POST['password'])) {
        csrf_validate_token();
    }
}

// --- Brute-force protection ---
$ip = $_SERVER['REMOTE_ADDR'];
$maxAttempts = 5;
$lockoutMinutes = 5;

$authConn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($authConn->connect_error) {
    error_log('DB connection failed: ' . $authConn->connect_error);
    die('Something went wrong. Please try again later.');
}

$stmt = $authConn->prepare("SELECT attempts, last_attempt FROM login_attempts WHERE ip = ?");
$stmt->bind_param('s', $ip);
$stmt->execute();
$attemptRow = $stmt->get_result()->fetch_assoc();
$stmt->close();

$isLockedOut = false;
if ($attemptRow && $attemptRow['attempts'] >= $maxAttempts) {
    $secondsSince = time() - strtotime($attemptRow['last_attempt']);
    if ($secondsSince < $lockoutMinutes * 60) {
        $isLockedOut = true;
        $minutesLeft = ceil(($lockoutMinutes * 60 - $secondsSince) / 60);
        $loginError = "Too many failed attempts. Try again in $minutesLeft minute(s).";
    }
}

if (isset($_POST['password']) && !$isLockedOut) {
    if (password_verify($_POST['password'], ADMIN_PASSWORD_HASH)) {
        $_SESSION['admin'] = true;
        $reset = $authConn->prepare("DELETE FROM login_attempts WHERE ip = ?");
        $reset->bind_param('s', $ip);
        $reset->execute();
        $reset->close();
    } else {
        $loginError = 'Wrong password. Try again.';
        $upsert = $authConn->prepare("
            INSERT INTO login_attempts (ip, attempts, last_attempt)
            VALUES (?, 1, NOW())
            ON DUPLICATE KEY UPDATE attempts = attempts + 1, last_attempt = NOW()
        ");
        $upsert->bind_param('s', $ip);
        $upsert->execute();
        $upsert->close();
    }
}

$authConn->close();
$loggedIn = !empty($_SESSION['admin']);

$conn = null;
$orders = [];
$stats  = [];

if ($loggedIn) {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
   if ($conn->connect_error) {
    error_log('DB error: ' . $conn->connect_error);
    die('Something went wrong. Please try again later.');
}

    // Status update
    if (isset($_POST['update_status']) && isset($_POST['order_id'])) {
        $newStatus = trim($_POST['status']);
        $orderId   = intval($_POST['order_id']);
        $allowed   = ['Pending', 'In Progress', 'Ready', 'Delivered', 'Cancelled'];
        if (in_array($newStatus, $allowed)) {
            $stmt = $conn->prepare("UPDATE orders SET status = ? WHERE id = ?");
            $stmt->bind_param('si', $newStatus, $orderId);
            $stmt->execute();
            $stmt->close();
        }
        header('Location: admin.php');
        exit;
    }

    // Fetch orders
    $filter = $_GET['status'] ?? 'All';
    $search = trim($_GET['search'] ?? '');
    $where = []; $params = []; $types = '';
    if ($filter !== 'All') { $where[] = 'status = ?'; $params[] = $filter; $types .= 's'; }
    if ($search !== '') {
        $where[] = '(name LIKE ? OR phone LIKE ? OR brand LIKE ? OR model LIKE ?)';
        $like = '%' . $search . '%';
        $params = array_merge($params, [$like, $like, $like, $like]);
        $types .= 'ssss';
    }
    $sql = "SELECT * FROM orders" . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . " ORDER BY created_at DESC";
    $stmt = $conn->prepare($sql);
    if ($params) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) $orders[] = $row;
    $stmt->close();

    // Stats
    $statsRes = $conn->query("SELECT status, COUNT(*) as cnt, SUM(total_price) as revenue FROM orders GROUP BY status");
    while ($row = $statsRes->fetch_assoc()) $stats[$row['status']] = $row;
    $totalRevenue = $conn->query("SELECT SUM(total_price) as t FROM orders")->fetch_assoc()['t'] ?? 0;
    $totalOrders  = $conn->query("SELECT COUNT(*) as c FROM orders")->fetch_assoc()['c'] ?? 0;

    // Load inventory prices (cases) keyed by product_name + case_type
    $invPrices = [];
    $invRes = $conn->query("SELECT product_name, buy_price_single, buy_price_double FROM inventory");
    while ($row = $invRes->fetch_assoc()) {
        $invPrices[$row['product_name']] = [
            'Single' => intval($row['buy_price_single']),
            'Double' => intval($row['buy_price_double']),
        ];
    }

    // Load accessories buy price (silicon pad)
    $padBuyPrice = 0;
    $padRes = $conn->query("SELECT buy_price FROM accessories LIMIT 1");
    if ($padRes && $padRow = $padRes->fetch_assoc()) {
        $padBuyPrice = intval($padRow['buy_price']);
    }

    // Calculate per-order profit and totals
    $totalCost   = 0;
    $totalProfit = 0;
    foreach ($orders as &$o) {
        $caseBuy = $invPrices[$o['model']][$o['case_type']] ?? 0;
        $padCost = ($o['silicon_pad'] === 'Yes') ? $padBuyPrice : 0;
        $cost    = $caseBuy + $padCost;
        $profit  = intval($o['total_price']) - $cost;
        $o['_cost']   = $cost;
        $o['_profit'] = $profit;
        $totalCost   += $cost;
        $totalProfit += $profit;
    }
    unset($o);
}

$statusColors = [
    'Pending'     => '#f59e0b',
    'In Progress' => '#3b82f6',
    'Ready'       => '#8b5cf6',
    'Delivered'   => '#22c55e',
    'Cancelled'   => '#ef4444',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Engravers - Admin</title>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Syne:wght@700;800&family=DM+Sans:wght@400;500;600&display=swap">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'DM Sans', sans-serif; background: #f0f6eb; color: #111; min-height: 100vh; }

.login-wrap { min-height: 100vh; display: flex; align-items: center; justify-content: center; }
.login-box { background: #fff; border-radius: 20px; padding: 48px 40px; width: 100%; max-width: 380px; box-shadow: 0 8px 32px rgba(0,0,0,.08); text-align: center; }
.login-box h1 { font-family: 'Syne', sans-serif; font-size: 28px; color: #2d5a1b; margin-bottom: 6px; }
.login-box p  { font-size: 13px; color: #6b7280; margin-bottom: 28px; }
.login-box input[type=password] { width: 100%; padding: 12px 16px; border: 1.5px solid #c5e0b0; border-radius: 10px; font-size: 14px; font-family: 'DM Sans', sans-serif; outline: none; margin-bottom: 14px; }
.login-box input[type=password]:focus { border-color: #2d5a1b; }
.login-box button { width: 100%; padding: 13px; background: #2d5a1b; color: #deecd6; border: none; border-radius: 100px; font-family: 'Syne', sans-serif; font-size: 15px; font-weight: 700; cursor: pointer; }
.login-box button:hover { background: #1a3d08; }
.error { color: #ef4444; font-size: 13px; margin-top: 10px; }

.topbar { background: #2d5a1b; padding: 16px 32px; display: flex; align-items: center; justify-content: space-between; }
.topbar-left { display: flex; align-items: center; gap: 20px; }
.topbar h1 { font-family: 'Syne', sans-serif; font-size: 22px; color: #deecd6; }
.nav-link { color: #a8d490; font-size: 13px; font-weight: 500; text-decoration: none; padding: 6px 14px; border-radius: 100px; border: 1px solid rgba(255,255,255,.2); transition: background .2s; }
.nav-link:hover { background: rgba(255,255,255,.15); color: #deecd6; }
.nav-link.active { background: rgba(255,255,255,.2); color: #deecd6; }
.topbar .logout-btn { background: rgba(255,255,255,.15); color: #deecd6; border: none; border-radius: 100px; padding: 8px 20px; font-family: 'DM Sans', sans-serif; font-size: 13px; cursor: pointer; }
.topbar .logout-btn:hover { background: rgba(255,255,255,.25); }

.main { padding: 32px; max-width: 1400px; margin: 0 auto; }

/* STATS */
.stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px; margin-bottom: 32px; }
.stat-card { background: #fff; border-radius: 16px; padding: 20px 24px; box-shadow: 0 2px 8px rgba(0,0,0,.05); }
.stat-card .label { font-size: 12px; color: #6b7280; text-transform: uppercase; letter-spacing: .5px; margin-bottom: 6px; }
.stat-card .value { font-family: 'Syne', sans-serif; font-size: 26px; font-weight: 800; color: #2d5a1b; }
.stat-card .sub   { font-size: 12px; color: #9ca3af; margin-top: 2px; }
.stat-card.profit-card { border: 2px solid #bbf7d0; background: #f0fff4; }
.stat-card.profit-card .value { color: #15803d; }
.stat-card.cost-card .value { color: #ef4444; }

/* PROFIT NOTE */
.profit-note { font-size: 12px; color: #9ca3af; margin-bottom: 24px; background: #fff; border-radius: 10px; padding: 10px 16px; border-left: 3px solid #c5e0b0; }
.profit-note strong { color: #4a7c35; }

/* FILTERS */
.toolbar { display: flex; gap: 12px; flex-wrap: wrap; align-items: center; margin-bottom: 24px; }
.filter-tabs { display: flex; gap: 8px; flex-wrap: wrap; }
.filter-tabs a { padding: 8px 18px; border-radius: 100px; font-size: 13px; font-weight: 500; text-decoration: none; background: #fff; color: #374151; border: 1.5px solid #e5e7eb; transition: all .2s; }
.filter-tabs a.active, .filter-tabs a:hover { background: #2d5a1b; color: #fff; border-color: #2d5a1b; }
.search-wrap { margin-left: auto; }
.search-wrap input { padding: 9px 16px; border: 1.5px solid #d1d5db; border-radius: 100px; font-size: 13px; font-family: 'DM Sans', sans-serif; outline: none; width: 220px; }
.search-wrap input:focus { border-color: #2d5a1b; }

/* TABLE */
.table-wrap { background: #fff; border-radius: 20px; box-shadow: 0 2px 12px rgba(0,0,0,.06); overflow: hidden; }
table { width: 100%; border-collapse: collapse; font-size: 13.5px; }
thead { background: #f9fafb; }
th { padding: 14px 16px; text-align: left; font-weight: 600; color: #374151; font-size: 12px; text-transform: uppercase; letter-spacing: .4px; border-bottom: 1px solid #e5e7eb; }
td { padding: 14px 16px; border-bottom: 1px solid #f3f4f6; vertical-align: middle; }
tr:last-child td { border-bottom: none; }
tr:hover td { background: #fafff8; }

.badge { display: inline-block; padding: 4px 12px; border-radius: 100px; font-size: 12px; font-weight: 600; }
.order-id { font-family: 'Syne', sans-serif; font-weight: 700; color: #2d5a1b; }
.thumb { width: 48px; height: 48px; object-fit: cover; border-radius: 8px; border: 1px solid #e5e7eb; cursor: pointer; }
.thumb:hover { transform: scale(1.05); }

.status-form { display: flex; gap: 6px; align-items: center; }
.status-form select { padding: 6px 10px; border: 1.5px solid #d1d5db; border-radius: 8px; font-size: 12px; font-family: 'DM Sans', sans-serif; outline: none; cursor: pointer; }
.status-form button { padding: 6px 14px; background: #2d5a1b; color: #fff; border: none; border-radius: 8px; font-size: 12px; cursor: pointer; font-family: 'DM Sans', sans-serif; font-weight: 600; }
.status-form button:hover { background: #1a3d08; }

/* PROFIT CELL */
.profit-val { font-family: 'Syne', sans-serif; font-size: 15px; font-weight: 800; }
.profit-val.positive { color: #15803d; }
.profit-val.zero { color: #9ca3af; }
.profit-val.negative { color: #ef4444; }
.profit-breakdown { font-size: 11px; color: #9ca3af; margin-top: 2px; }

.empty { text-align: center; padding: 60px; color: #9ca3af; }

.lightbox { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.8); z-index: 999; align-items: center; justify-content: center; }
.lightbox.open { display: flex; }
.lightbox img { max-width: 90vw; max-height: 90vh; border-radius: 12px; }
.lightbox-close { position: fixed; top: 20px; right: 24px; color: #fff; font-size: 32px; cursor: pointer; line-height: 1; }
</style>
</head>
<body>

<?php if (!$loggedIn): ?>
<div class="login-wrap">
  <div class="login-box">
    <h1>ENGRAVERS</h1>
    <p>Admin Panel - Enter your password</p>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_generate_token()) ?>">
      <input type="password" name="password" placeholder="Password" autofocus required />
      <button type="submit">Enter →</button>
    </form>
    <?php if (!empty($loginError)): ?>
      <p class="error"><?= htmlspecialchars($loginError) ?></p>
    <?php endif; ?>
  </div>
</div>

<?php else: ?>
<div class="topbar">
  <div class="topbar-left">
    <h1>* ENGRAVERS Admin</h1>
    <a href="admin.php" class="nav-link active">Orders</a>
    <a href="inventory.php" class="nav-link">Inventory</a>
  </div>
  <form method="POST">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_generate_token()) ?>">
    <button class="logout-btn" name="logout" value="1">Log Out</button>
  </form>
</div>

<div class="main">

  <!-- STATS -->
  <div class="stats-grid">
    <div class="stat-card">
      <div class="label">Total Orders</div>
      <div class="value"><?= $totalOrders ?></div>
    </div>
    <div class="stat-card">
      <div class="label">Total Revenue</div>
      <div class="value">K<?= number_format($totalRevenue) ?></div>
    </div>
    <div class="stat-card cost-card">
      <div class="label">Total Cost</div>
      <div class="value">K<?= number_format($totalCost) ?></div>
      <div class="sub">Cases + pads bought</div>
    </div>
    <div class="stat-card profit-card">
      <div class="label">Net Profit</div>
      <div class="value">K<?= number_format($totalProfit) ?></div>
      <div class="sub">Revenue − Cost</div>
    </div>
    <?php foreach (['Pending','In Progress','Ready','Delivered','Cancelled'] as $s): ?>
    <div class="stat-card">
      <div class="label"><?= $s ?></div>
      <div class="value" style="color:<?= $statusColors[$s] ?>"><?= $stats[$s]['cnt'] ?? 0 ?></div>
      <div class="sub">K<?= number_format($stats[$s]['revenue'] ?? 0) ?></div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- PROFIT NOTE -->
  <div class="profit-note">
    💡 Profit is calculated as <strong>selling price − buying price of the blank case</strong> (+ silicon pad cost if ordered).
    Make sure buy prices are set in the <a href="inventory.php" style="color:#2d5a1b">Inventory page</a> for accurate figures.
    <?php if ($totalCost === 0): ?>
      <strong style="color:#f59e0b"> — Buy prices are not set yet, so profit currently equals revenue.</strong>
    <?php endif; ?>
  </div>

  <!-- TOOLBAR -->
  <div class="toolbar">
    <div class="filter-tabs">
      <?php foreach (['All','Pending','In Progress','Ready','Delivered','Cancelled'] as $s):
        $active = ($filter === $s) ? 'active' : '';
        $qs = http_build_query(['status' => $s, 'search' => $search]);
      ?>
        <a href="admin.php?<?= $qs ?>" class="<?= $active ?>"><?= $s ?></a>
      <?php endforeach; ?>
    </div>
    <div class="search-wrap">
      <form method="GET">
        <input type="hidden" name="status" value="<?= htmlspecialchars($filter) ?>">
        <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search name, phone, model...">
      </form>
    </div>
  </div>

  <!-- TABLE -->
  <div class="table-wrap">
    <?php if (empty($orders)): ?>
      <div class="empty">No orders found.</div>
    <?php else: ?>
    <table>
      <thead>
        <tr>
          <th>#</th>
          <th>Customer</th>
          <th>Phone</th>
          <th>Device</th>
          <th>Case</th>
          <th>Delivery</th>
          <th>Total Paid</th>
          <th>Profit</th>
          <th>Design</th>
          <th>Payment</th>
          <th>Date</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($orders as $o):
          $color   = $statusColors[$o['status']] ?? '#6b7280';
          $profit  = $o['_profit'];
          $pClass  = $profit > 0 ? 'positive' : ($profit < 0 ? 'negative' : 'zero');
        ?>
        <tr>
          <td><span class="order-id">#<?= $o['id'] ?></span></td>
          <td><strong><?= htmlspecialchars($o['name']) ?></strong></td>
          <td><?= htmlspecialchars($o['phone']) ?></td>
          <td>
            <?= htmlspecialchars($o['brand']) ?><br>
            <small style="color:#6b7280"><?= htmlspecialchars($o['model']) ?></small>
          </td>
          <td>
            <?= htmlspecialchars($o['finish']) ?> - <?= htmlspecialchars($o['case_type']) ?><br>
            <small style="color:#6b7280">Pad: <?= htmlspecialchars($o['silicon_pad']) ?></small>
          </td>
          <td>
            <?= htmlspecialchars($o['delivery']) ?>
            <?php if ($o['location']): ?>
              <br><small style="color:#6b7280"><?= htmlspecialchars($o['location']) ?></small>
            <?php endif; ?>
          </td>
          <td><strong>K<?= number_format($o['total_price']) ?></strong></td>
          <td>
            <span class="profit-val <?= $pClass ?>">
              <?= $profit >= 0 ? '+' : '' ?>K<?= number_format($profit) ?>
            </span>
            <?php if ($o['_cost'] > 0): ?>
              <div class="profit-breakdown">Cost: K<?= number_format($o['_cost']) ?></div>
            <?php else: ?>
              <div class="profit-breakdown" style="color:#f59e0b">Set buy price</div>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($o['design_file']): ?>
              <img class="thumb" src="<?= htmlspecialchars($o['design_file']) ?>" onclick="openLightbox(this.src)" />
            <?php else: ?><span style="color:#9ca3af">-</span><?php endif; ?>
          </td>
          <td>
            <?php if ($o['payment_file']): ?>
              <img class="thumb" src="<?= htmlspecialchars($o['payment_file']) ?>" onclick="openLightbox(this.src)" />
            <?php else: ?><span style="color:#9ca3af">-</span><?php endif; ?>
          </td>
          <td style="white-space:nowrap;font-size:12px;color:#6b7280">
            <?= date('M j, Y', strtotime($o['created_at'])) ?><br>
            <?= date('g:ia', strtotime($o['created_at'])) ?>
          </td>
          <td>
            <form class="status-form" method="POST">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_generate_token()) ?>">
              <input type="hidden" name="order_id" value="<?= $o['id'] ?>">
              <select name="status">
                <?php foreach (['Pending','In Progress','Ready','Delivered','Cancelled'] as $s): ?>
                  <option value="<?= $s ?>" <?= $o['status'] === $s ? 'selected' : '' ?>><?= $s ?></option>
                <?php endforeach; ?>
              </select>
              <button type="submit" name="update_status" value="1">Save</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

</div>

<div class="lightbox" id="lightbox" onclick="closeLightbox()">
  <span class="lightbox-close" onclick="closeLightbox()">x</span>
  <img id="lightboxImg" src="" alt="Preview">
</div>

<script>
function openLightbox(src) {
  document.getElementById('lightboxImg').src = src;
  document.getElementById('lightbox').classList.add('open');
}
function closeLightbox() {
  document.getElementById('lightbox').classList.remove('open');
}
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeLightbox(); });
</script>

<?php endif; ?>
<?php if ($conn) $conn->close(); ?>
</body>
</html>
