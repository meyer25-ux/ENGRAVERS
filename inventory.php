<?php
session_start();
require_once __DIR__ . '/config.php';

// --- AUTH ---
if (isset($_POST['logout'])) { session_destroy(); header('Location: inventory.php'); exit; }
if (isset($_POST['password'])) {
    if (password_verify($_POST['password'], ADMIN_PASSWORD_HASH)) $_SESSION['admin'] = true;
    else $loginError = 'Wrong password.';
}
$loggedIn = !empty($_SESSION['admin']);

$conn = null;
$message = '';
$messageType = '';

if ($loggedIn) {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($conn->connect_error) die('DB error: ' . $conn->connect_error);
    $conn->set_charset('utf8mb4');

    // --- ADD PHONE CASE PRODUCT ---
    if (isset($_POST['action']) && $_POST['action'] === 'add') {
        $pname  = trim($_POST['product_name'] ?? '');
        $brand  = trim($_POST['brand'] ?? '');
        $stock  = intval($_POST['stock'] ?? 0);
        $bps    = intval($_POST['buy_price_single'] ?? 0);
        $sps    = intval($_POST['sell_price_single'] ?? 0);
        $bpd    = intval($_POST['buy_price_double'] ?? 0);
        $spd    = intval($_POST['sell_price_double'] ?? 0);
        $allowedBrands = ['iphone', 'samsung', 'pixel'];
        if ($pname && in_array($brand, $allowedBrands)) {
            $stmt = $conn->prepare("INSERT INTO inventory (product_name, brand, stock, buy_price_single, sell_price_single, buy_price_double, sell_price_double) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param('ssiiiii', $pname, $brand, $stock, $bps, $sps, $bpd, $spd);
            if ($stmt->execute()) { $message = "\"$pname\" added successfully."; $messageType = 'success'; }
            else { $message = 'Failed to add product.'; $messageType = 'error'; }
            $stmt->close();
        } else { $message = 'Please fill in all fields correctly.'; $messageType = 'error'; }
    }

    // --- UPDATE PRICES ---
    if (isset($_POST['action']) && $_POST['action'] === 'update_prices') {
        $id  = intval($_POST['product_id'] ?? 0);
        $bps = intval($_POST['buy_price_single'] ?? 0);
        $sps = intval($_POST['sell_price_single'] ?? 0);
        $bpd = intval($_POST['buy_price_double'] ?? 0);
        $spd = intval($_POST['sell_price_double'] ?? 0);
        if ($id > 0) {
            $stmt = $conn->prepare("UPDATE inventory SET buy_price_single=?, sell_price_single=?, buy_price_double=?, sell_price_double=? WHERE id=?");
            $stmt->bind_param('iiiii', $bps, $sps, $bpd, $spd, $id);
            if ($stmt->execute()) { $message = 'Prices updated.'; $messageType = 'success'; }
            else { $message = 'Failed to update prices.'; $messageType = 'error'; }
            $stmt->close();
        }
    }

    // --- BULK UPDATE PRICES ---
    if (isset($_POST['action']) && $_POST['action'] === 'bulk_update_prices') {
        $bulkBrand = trim($_POST['bulk_brand'] ?? 'All');
        $bulkType  = trim($_POST['bulk_type'] ?? 'Both'); // Single, Double, Both

        $setParts = [];
        $bindTypes = '';
        $bindVals = [];

        // Only update the fields the user actually filled in (non-empty)
        if ($bulkType === 'Single' || $bulkType === 'Both') {
            if (($_POST['bulk_buy_single'] ?? '') !== '')  { $setParts[] = 'buy_price_single = ?';  $bindTypes .= 'i'; $bindVals[] = intval($_POST['bulk_buy_single']); }
            if (($_POST['bulk_sell_single'] ?? '') !== '') { $setParts[] = 'sell_price_single = ?'; $bindTypes .= 'i'; $bindVals[] = intval($_POST['bulk_sell_single']); }
        }
        if ($bulkType === 'Double' || $bulkType === 'Both') {
            if (($_POST['bulk_buy_double'] ?? '') !== '')  { $setParts[] = 'buy_price_double = ?';  $bindTypes .= 'i'; $bindVals[] = intval($_POST['bulk_buy_double']); }
            if (($_POST['bulk_sell_double'] ?? '') !== '') { $setParts[] = 'sell_price_double = ?'; $bindTypes .= 'i'; $bindVals[] = intval($_POST['bulk_sell_double']); }
        }

        if (empty($setParts)) {
            $message = 'Enter at least one price to bulk update.';
            $messageType = 'error';
        } else {
            $sql = "UPDATE inventory SET " . implode(', ', $setParts);
            if ($bulkBrand !== 'All') {
                $sql .= " WHERE brand = ?";
                $bindTypes .= 's';
                $bindVals[] = $bulkBrand;
            }
            $stmt = $conn->prepare($sql);
            $stmt->bind_param($bindTypes, ...$bindVals);
            if ($stmt->execute()) {
                $affected = $stmt->affected_rows;
                $message = "Bulk update applied to $affected product(s).";
                $messageType = 'success';
            } else {
                $message = 'Bulk update failed.';
                $messageType = 'error';
            }
            $stmt->close();
        }
    }

    // --- UPDATE STOCK (cases) ---
    if (isset($_POST['action']) && $_POST['action'] === 'stock') {
        $id     = intval($_POST['product_id'] ?? 0);
        $op     = $_POST['operation'] ?? '';
        $amount = intval($_POST['amount'] ?? 0);
        if ($id > 0 && $amount > 0 && in_array($op, ['set', 'increase', 'decrease'])) {
            if ($op === 'set')          $sql = "UPDATE inventory SET stock = ? WHERE id = ?";
            elseif ($op === 'increase') $sql = "UPDATE inventory SET stock = stock + ? WHERE id = ?";
            else                        $sql = "UPDATE inventory SET stock = GREATEST(0, stock - ?) WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('ii', $amount, $id);
            if ($stmt->execute()) { $message = 'Stock updated.'; $messageType = 'success'; }
            else { $message = 'Failed to update stock.'; $messageType = 'error'; }
            $stmt->close();
        } else { $message = 'Invalid input.'; $messageType = 'error'; }
    }

    // --- DELETE PRODUCT ---
    if (isset($_POST['action']) && $_POST['action'] === 'delete') {
        $id = intval($_POST['product_id'] ?? 0);
        if ($id > 0) {
            $stmt = $conn->prepare("DELETE FROM inventory WHERE id = ?");
            $stmt->bind_param('i', $id);
            if ($stmt->execute()) { $message = 'Product removed.'; $messageType = 'success'; }
            else { $message = 'Failed to remove.'; $messageType = 'error'; }
            $stmt->close();
        }
    }

    // --- ADD ACCESSORY ---
    if (isset($_POST['action']) && $_POST['action'] === 'add_acc') {
        $aname = trim($_POST['acc_name'] ?? '');
        $astock = intval($_POST['acc_stock'] ?? 0);
        $abuy   = intval($_POST['acc_buy_price'] ?? 0);
        $asell  = intval($_POST['acc_sell_price'] ?? 0);
        if ($aname) {
            $stmt = $conn->prepare("INSERT INTO accessories (name, stock, buy_price, sell_price) VALUES (?, ?, ?, ?)");
            $stmt->bind_param('siii', $aname, $astock, $abuy, $asell);
            if ($stmt->execute()) { $message = "\"$aname\" added to accessories."; $messageType = 'success'; }
            else { $message = 'Failed to add accessory.'; $messageType = 'error'; }
            $stmt->close();
        } else { $message = 'Please enter an accessory name.'; $messageType = 'error'; }
    }

    // --- UPDATE ACCESSORY STOCK ---
    if (isset($_POST['action']) && $_POST['action'] === 'acc_stock') {
        $id     = intval($_POST['acc_id'] ?? 0);
        $op     = $_POST['operation'] ?? '';
        $amount = intval($_POST['amount'] ?? 0);
        if ($id > 0 && $amount > 0 && in_array($op, ['set', 'increase', 'decrease'])) {
            if ($op === 'set')          $sql = "UPDATE accessories SET stock = ? WHERE id = ?";
            elseif ($op === 'increase') $sql = "UPDATE accessories SET stock = stock + ? WHERE id = ?";
            else                        $sql = "UPDATE accessories SET stock = GREATEST(0, stock - ?) WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('ii', $amount, $id);
            if ($stmt->execute()) { $message = 'Accessory stock updated.'; $messageType = 'success'; }
            else { $message = 'Failed to update.'; $messageType = 'error'; }
            $stmt->close();
        }
    }

    // --- UPDATE ACCESSORY PRICES ---
    if (isset($_POST['action']) && $_POST['action'] === 'update_acc_prices') {
        $id    = intval($_POST['acc_id'] ?? 0);
        $abuy  = intval($_POST['acc_buy_price'] ?? 0);
        $asell = intval($_POST['acc_sell_price'] ?? 0);
        if ($id > 0) {
            $stmt = $conn->prepare("UPDATE accessories SET buy_price=?, sell_price=? WHERE id=?");
            $stmt->bind_param('iii', $abuy, $asell, $id);
            if ($stmt->execute()) { $message = 'Accessory prices updated.'; $messageType = 'success'; }
            else { $message = 'Failed to update prices.'; $messageType = 'error'; }
            $stmt->close();
        }
    }

    // --- DELETE ACCESSORY ---
    if (isset($_POST['action']) && $_POST['action'] === 'delete_acc') {
        $id = intval($_POST['acc_id'] ?? 0);
        if ($id > 0) {
            $stmt = $conn->prepare("DELETE FROM accessories WHERE id = ?");
            $stmt->bind_param('i', $id);
            if ($stmt->execute()) { $message = 'Accessory removed.'; $messageType = 'success'; }
            else { $message = 'Failed to remove.'; $messageType = 'error'; }
            $stmt->close();
        }
    }

    // --- FETCH CASES ---
    $filterBrand = $_GET['brand'] ?? 'All';
    $search = trim($_GET['search'] ?? '');
    $where = []; $params = []; $types = '';
    if ($filterBrand !== 'All') { $where[] = 'brand = ?'; $params[] = $filterBrand; $types .= 's'; }
    if ($search !== '') { $where[] = 'product_name LIKE ?'; $params[] = '%'.$search.'%'; $types .= 's'; }
    $sql = "SELECT * FROM inventory" . ($where ? ' WHERE '.implode(' AND ', $where) : '') . " ORDER BY brand, product_name ASC";
    $stmt = $conn->prepare($sql);
    if ($params) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $products = [];
    while ($row = $result->fetch_assoc()) $products[] = $row;
    $stmt->close();

    // --- FETCH ACCESSORIES ---
    $accessories = [];
    $accResult = $conn->query("SELECT * FROM accessories ORDER BY name ASC");
    while ($row = $accResult->fetch_assoc()) $accessories[] = $row;

    // Stats
    $totalItems  = count($products);
    $outOfStock  = count(array_filter($products, fn($p) => $p['stock'] <= 0));
    $lowStock    = count(array_filter($products, fn($p) => $p['stock'] > 0 && $p['stock'] <= 3));
    $totalUnits  = array_sum(array_column($products, 'stock'));
}

$brandLabels = ['iphone' => 'iPhone', 'samsung' => 'Samsung', 'pixel' => 'Google Pixel'];
$brandColors = ['iphone' => '#3b82f6', 'samsung' => '#f59e0b', 'pixel' => '#8b5cf6'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Engravers — Inventory</title>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Syne:wght@700;800&family=DM+Sans:wght@400;500;600&display=swap">
<link rel="icon" type="image/svg+xml" href="favicon.svg">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'DM Sans', sans-serif; background: #f0f6eb; color: #111; min-height: 100vh; }

.login-wrap { min-height: 100vh; display: flex; align-items: center; justify-content: center; }
.login-box { background: #fff; border-radius: 20px; padding: 48px 40px; width: 100%; max-width: 380px; box-shadow: 0 8px 32px rgba(0,0,0,.08); text-align: center; }
.login-box h1 { font-family: 'Syne', sans-serif; font-size: 28px; color: #2d5a1b; margin-bottom: 6px; }
.login-box p { font-size: 13px; color: #6b7280; margin-bottom: 28px; }
.login-box input[type=password] { width: 100%; padding: 12px 16px; border: 1.5px solid #c5e0b0; border-radius: 10px; font-size: 14px; font-family: 'DM Sans', sans-serif; outline: none; margin-bottom: 14px; }
.login-box input[type=password]:focus { border-color: #2d5a1b; }
.login-box button { width: 100%; padding: 13px; background: #2d5a1b; color: #deecd6; border: none; border-radius: 100px; font-family: 'Syne', sans-serif; font-size: 15px; font-weight: 700; cursor: pointer; }
.login-box button:hover { background: #1a3d08; }
.error-msg { color: #ef4444; font-size: 13px; margin-top: 10px; }

.topbar { background: #2d5a1b; padding: 16px 32px; display: flex; align-items: center; justify-content: space-between; gap: 16px; }
.topbar-left { display: flex; align-items: center; gap: 20px; }
.topbar h1 { font-family: 'Syne', sans-serif; font-size: 22px; color: #deecd6; }
.nav-link { color: #a8d490; font-size: 13px; font-weight: 500; text-decoration: none; padding: 6px 14px; border-radius: 100px; border: 1px solid rgba(255,255,255,.2); transition: background .2s; }
.nav-link:hover { background: rgba(255,255,255,.15); color: #deecd6; }
.nav-link.active { background: rgba(255,255,255,.2); color: #deecd6; }
.logout-btn { background: rgba(255,255,255,.15); color: #deecd6; border: none; border-radius: 100px; padding: 8px 20px; font-family: 'DM Sans', sans-serif; font-size: 13px; cursor: pointer; }
.logout-btn:hover { background: rgba(255,255,255,.25); }

.main { padding: 32px; max-width: 1400px; margin: 0 auto; }

.flash { padding: 14px 18px; border-radius: 12px; margin-bottom: 24px; font-size: 14px; font-weight: 500; }
.flash.success { background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; }
.flash.error   { background: #fee2e2; color: #b91c1c; border: 1px solid #fecaca; }

.stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 16px; margin-bottom: 32px; }
.stat-card { background: #fff; border-radius: 16px; padding: 20px 24px; box-shadow: 0 2px 8px rgba(0,0,0,.05); }
.stat-card .label { font-size: 12px; color: #6b7280; text-transform: uppercase; letter-spacing: .5px; margin-bottom: 6px; }
.stat-card .value { font-family: 'Syne', sans-serif; font-size: 28px; font-weight: 800; color: #2d5a1b; }
.stat-card .value.warn { color: #f59e0b; }
.stat-card .value.danger { color: #ef4444; }

.section-title { font-family: 'Syne', sans-serif; font-size: 20px; color: #2d5a1b; margin-bottom: 16px; margin-top: 40px; padding-bottom: 10px; border-bottom: 2px solid #c5e0b0; }
.section-title:first-of-type { margin-top: 0; }

.add-card { background: #fff; border-radius: 20px; padding: 28px; box-shadow: 0 2px 12px rgba(0,0,0,.06); margin-bottom: 28px; }
.add-card h2 { font-family: 'Syne', sans-serif; font-size: 16px; color: #2d5a1b; margin-bottom: 6px; }
.add-card .hint { font-size: 12px; color: #9ca3af; margin-bottom: 18px; }
.add-form { display: flex; gap: 12px; flex-wrap: wrap; align-items: flex-end; }
.add-form .field { display: flex; flex-direction: column; gap: 6px; }
.add-form label { font-size: 11px; font-weight: 600; color: #4a7c35; text-transform: uppercase; letter-spacing: .4px; }
.add-form input, .add-form select { padding: 10px 14px; border: 1.5px solid #c5e0b0; border-radius: 10px; font-size: 14px; font-family: 'DM Sans', sans-serif; outline: none; background: #f9fcf7; }
.add-form input:focus, .add-form select:focus { border-color: #2d5a1b; }
.add-form .field-name input { min-width: 200px; }
.add-form .field-sm input, .add-form .field-sm select { width: 110px; }
.add-form .field-xs input { width: 80px; }
.btn-add { padding: 11px 24px; background: #2d5a1b; color: #deecd6; border: none; border-radius: 100px; font-family: 'Syne', sans-serif; font-size: 14px; font-weight: 700; cursor: pointer; white-space: nowrap; align-self: flex-end; }
.btn-add:hover { background: #1a3d08; }

.price-group { display: flex; gap: 12px; flex-wrap: wrap; align-items: flex-end; }
.price-divider { font-size: 11px; color: #9ca3af; font-weight: 600; text-transform: uppercase; letter-spacing: .5px; align-self: center; padding: 0 4px; }

.bulk-card { border: 2px solid #fde68a; background: #fffdf5; }
.bulk-card h2 { color: #92400e; }
.btn-bulk { background: #d97706; }
.btn-bulk:hover { background: #b45309; }

.toolbar { display: flex; gap: 12px; flex-wrap: wrap; align-items: center; margin-bottom: 20px; }
.filter-tabs { display: flex; gap: 8px; flex-wrap: wrap; }
.filter-tabs a { padding: 8px 18px; border-radius: 100px; font-size: 13px; font-weight: 500; text-decoration: none; background: #fff; color: #374151; border: 1.5px solid #e5e7eb; transition: all .2s; }
.filter-tabs a.active, .filter-tabs a:hover { background: #2d5a1b; color: #fff; border-color: #2d5a1b; }
.search-wrap { margin-left: auto; }
.search-wrap input { padding: 9px 16px; border: 1.5px solid #d1d5db; border-radius: 100px; font-size: 13px; font-family: 'DM Sans', sans-serif; outline: none; width: 220px; }
.search-wrap input:focus { border-color: #2d5a1b; }

.table-wrap { background: #fff; border-radius: 20px; box-shadow: 0 2px 12px rgba(0,0,0,.06); overflow: hidden; margin-bottom: 16px; }
table { width: 100%; border-collapse: collapse; font-size: 13px; }
thead { background: #f9fafb; }
th { padding: 12px 14px; text-align: left; font-weight: 600; color: #374151; font-size: 11px; text-transform: uppercase; letter-spacing: .4px; border-bottom: 1px solid #e5e7eb; }
td { padding: 12px 14px; border-bottom: 1px solid #f3f4f6; vertical-align: middle; }
tr:last-child td { border-bottom: none; }
tr:hover td { background: #fafff8; }

.brand-badge { display: inline-block; padding: 3px 10px; border-radius: 100px; font-size: 11px; font-weight: 600; }
.stock-num { font-family: 'Syne', sans-serif; font-size: 18px; font-weight: 800; color: #2d5a1b; }
.stock-num.low { color: #f59e0b; }
.stock-num.out { color: #ef4444; }
.stock-label { font-size: 10px; color: #9ca3af; display: block; }

.stock-controls { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }
.stock-controls input[type=number] { width: 60px; padding: 6px 8px; border: 1.5px solid #d1d5db; border-radius: 8px; font-size: 13px; font-family: 'DM Sans', sans-serif; outline: none; text-align: center; }
.stock-controls input[type=number]:focus { border-color: #2d5a1b; }
.btn-sm { padding: 6px 11px; border: none; border-radius: 8px; font-size: 12px; font-weight: 600; cursor: pointer; font-family: 'DM Sans', sans-serif; transition: opacity .2s; white-space: nowrap; }
.btn-sm:hover { opacity: .85; }
.btn-increase { background: #dcfce7; color: #15803d; }
.btn-decrease { background: #fee2e2; color: #b91c1c; }
.btn-set      { background: #e0e7ff; color: #4338ca; }
.btn-delete   { background: #f3f4f6; color: #6b7280; }
.btn-prices   { background: #fef9c3; color: #854d0e; }

/* Inline price edit row */
.price-edit-row { display: none; }
.price-edit-row td { background: #fffbeb; border-top: 1px dashed #fde68a; }
.price-edit-form { display: flex; gap: 10px; flex-wrap: wrap; align-items: flex-end; padding: 4px 0; }
.price-edit-form .pf { display: flex; flex-direction: column; gap: 4px; }
.price-edit-form label { font-size: 10px; font-weight: 600; color: #92400e; text-transform: uppercase; letter-spacing: .3px; }
.price-edit-form input { width: 90px; padding: 7px 10px; border: 1.5px solid #fde68a; border-radius: 8px; font-size: 13px; font-family: 'DM Sans', sans-serif; outline: none; background: #fff; }
.price-edit-form input:focus { border-color: #d97706; }
.price-section-label { font-size: 10px; font-weight: 700; color: #6b7280; text-transform: uppercase; letter-spacing: .5px; align-self: center; }
.btn-save-prices { padding: 8px 16px; background: #2d5a1b; color: #deecd6; border: none; border-radius: 8px; font-size: 12px; font-weight: 700; cursor: pointer; font-family: 'DM Sans', sans-serif; }
.btn-cancel-prices { padding: 8px 14px; background: #f3f4f6; color: #6b7280; border: none; border-radius: 8px; font-size: 12px; font-weight: 600; cursor: pointer; font-family: 'DM Sans', sans-serif; }

.price-display { font-size: 12px; color: #374151; line-height: 1.7; }
.price-display .buy { color: #9ca3af; }
.price-display .sell { color: #2d5a1b; font-weight: 600; }
.price-display .unset { color: #d1d5db; font-style: italic; }

.empty { text-align: center; padding: 48px; color: #9ca3af; }

/* MODAL */
.modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.5); z-index: 100; align-items: center; justify-content: center; }
.modal-overlay.open { display: flex; }
.modal { background: #fff; border-radius: 20px; padding: 32px; width: 100%; max-width: 360px; box-shadow: 0 20px 60px rgba(0,0,0,.2); }
.modal h3 { font-family: 'Syne', sans-serif; color: #2d5a1b; margin-bottom: 8px; }
.modal p { font-size: 14px; color: #6b7280; margin-bottom: 20px; }
.modal-btns { display: flex; gap: 10px; }
.modal-btns button { flex: 1; padding: 12px; border-radius: 100px; border: none; font-family: 'Syne', sans-serif; font-size: 14px; font-weight: 700; cursor: pointer; }
.btn-confirm-delete { background: #ef4444; color: #fff; }
.btn-cancel-modal { background: #f3f4f6; color: #374151; }
</style>
</head>
<body>

<?php if (!$loggedIn): ?>
<div class="login-wrap">
  <div class="login-box">
    <h1>ENGRAVERS</h1>
    <p>Admin Panel — Enter your password</p>
    <form method="POST">
      <input type="password" name="password" placeholder="Password" autofocus required />
      <button type="submit">Enter →</button>
    </form>
    <?php if (!empty($loginError)): ?>
      <p class="error-msg"><?= htmlspecialchars($loginError) ?></p>
    <?php endif; ?>
  </div>
</div>

<?php else: ?>

<div class="topbar">
  <div class="topbar-left">
    <h1>* ENGRAVERS Admin</h1>
    <a href="admin.php" class="nav-link">Orders</a>
    <a href="inventory.php" class="nav-link active">Inventory</a>
  </div>
  <form method="POST">
    <button class="logout-btn" name="logout" value="1">Log Out</button>
  </form>
</div>

<div class="main">

  <?php if ($message): ?>
    <div class="flash <?= $messageType ?>"><?= htmlspecialchars($message) ?></div>
  <?php endif; ?>

  <!-- STATS -->
  <div class="stats-grid">
    <div class="stat-card"><div class="label">Case Models</div><div class="value"><?= $totalItems ?></div></div>
    <div class="stat-card"><div class="label">Total Case Units</div><div class="value"><?= $totalUnits ?></div></div>
    <div class="stat-card"><div class="label">Low Stock (≤3)</div><div class="value warn"><?= $lowStock ?></div></div>
    <div class="stat-card"><div class="label">Out of Stock</div><div class="value danger"><?= $outOfStock ?></div></div>
    <div class="stat-card"><div class="label">Accessories</div><div class="value"><?= count($accessories) ?></div></div>
  </div>

  <!-- ============ PHONE CASES SECTION ============ -->
  <div class="section-title">📱 Phone Cases</div>

  <!-- ADD CASE FORM -->
  <div class="add-card">
    <h2>+ Create New Phone Case Model</h2>
    <p class="hint">Only for models not already in the list. To update stock or prices on an existing model, use the buttons in the table below.</p>
    <form class="add-form" method="POST">
      <input type="hidden" name="action" value="add" />
      <div class="field field-name">
        <label>Product Name</label>
        <input type="text" name="product_name" placeholder="e.g. Pixel 9 Pro" required />
      </div>
      <div class="field field-sm">
        <label>Brand</label>
        <select name="brand" required>
          <option value="">-- Brand --</option>
          <option value="iphone">iPhone</option>
          <option value="samsung">Samsung</option>
          <option value="pixel">Google Pixel</option>
        </select>
      </div>
      <div class="field field-xs">
        <label>Stock</label>
        <input type="number" name="stock" min="0" value="0" />
      </div>
      <div class="price-group">
        <span class="price-divider">Single</span>
        <div class="field field-xs">
          <label>Buy (K)</label>
          <input type="number" name="buy_price_single" min="0" value="0" placeholder="0" />
        </div>
        <div class="field field-xs">
          <label>Sell (K)</label>
          <input type="number" name="sell_price_single" min="0" value="120" placeholder="120" />
        </div>
        <span class="price-divider">Double</span>
        <div class="field field-xs">
          <label>Buy (K)</label>
          <input type="number" name="buy_price_double" min="0" value="0" placeholder="0" />
        </div>
        <div class="field field-xs">
          <label>Sell (K)</label>
          <input type="number" name="sell_price_double" min="0" value="150" placeholder="150" />
        </div>
      </div>
      <button type="submit" class="btn-add">Add Model</button>
    </form>
  </div>

  <!-- BULK PRICE UPDATE -->
  <div class="add-card bulk-card">
    <h2>⚡ Bulk Price Update</h2>
    <p class="hint">Update prices for many models at once — e.g. the dollar moved, so raise all Samsung Single prices to K130. Leave a field blank to leave that price untouched.</p>
    <form class="add-form" method="POST" onsubmit="return confirmBulk()">
      <input type="hidden" name="action" value="bulk_update_prices" />
      <div class="field field-sm">
        <label>Apply to Brand</label>
        <select name="bulk_brand">
          <option value="All">All Brands</option>
          <option value="iphone">iPhone only</option>
          <option value="samsung">Samsung only</option>
          <option value="pixel">Google Pixel only</option>
        </select>
      </div>
      <div class="field field-sm">
        <label>Apply to Case Type</label>
        <select name="bulk_type" id="bulkType" onchange="toggleBulkFields()">
          <option value="Both">Single + Double</option>
          <option value="Single">Single only</option>
          <option value="Double">Double only</option>
        </select>
      </div>
      <div class="price-group" id="bulkSingleFields">
        <span class="price-divider">Single</span>
        <div class="field field-xs">
          <label>New Buy (K)</label>
          <input type="number" name="bulk_buy_single" min="0" placeholder="leave blank" />
        </div>
        <div class="field field-xs">
          <label>New Sell (K)</label>
          <input type="number" name="bulk_sell_single" min="0" placeholder="leave blank" />
        </div>
      </div>
      <div class="price-group" id="bulkDoubleFields">
        <span class="price-divider">Double</span>
        <div class="field field-xs">
          <label>New Buy (K)</label>
          <input type="number" name="bulk_buy_double" min="0" placeholder="leave blank" />
        </div>
        <div class="field field-xs">
          <label>New Sell (K)</label>
          <input type="number" name="bulk_sell_double" min="0" placeholder="leave blank" />
        </div>
      </div>
      <button type="submit" class="btn-add btn-bulk">Apply Bulk Update</button>
    </form>
  </div>

  <!-- FILTERS -->
  <div class="toolbar">
    <div class="filter-tabs">
      <?php
        $brands = ['All', 'iphone', 'samsung', 'pixel'];
        $brandDisplay = ['All' => 'All', 'iphone' => 'iPhone', 'samsung' => 'Samsung', 'pixel' => 'Google Pixel'];
        foreach ($brands as $b):
          $active = ($filterBrand === $b) ? 'active' : '';
          $qs = http_build_query(['brand' => $b, 'search' => $search]);
      ?>
        <a href="inventory.php?<?= $qs ?>" class="<?= $active ?>"><?= $brandDisplay[$b] ?></a>
      <?php endforeach; ?>
    </div>
    <div class="search-wrap">
      <form method="GET">
        <input type="hidden" name="brand" value="<?= htmlspecialchars($filterBrand) ?>">
        <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search models...">
      </form>
    </div>
  </div>

  <!-- CASES TABLE -->
  <div class="table-wrap">
    <?php if (empty($products)): ?>
      <div class="empty">No products found.</div>
    <?php else: ?>
    <table>
      <thead>
        <tr>
          <th>#</th>
          <th>Model</th>
          <th>Brand</th>
          <th>Stock</th>
          <th>Prices (Buy / Sell)</th>
          <th>Adjust Stock</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($products as $p):
          $stock = intval($p['stock']);
          $stockClass = $stock <= 0 ? 'out' : ($stock <= 3 ? 'low' : '');
          $bColor = $brandColors[$p['brand']] ?? '#6b7280';
          $bLabel = $brandLabels[$p['brand']] ?? $p['brand'];
          $bps = intval($p['buy_price_single']); $sps = intval($p['sell_price_single']);
          $bpd = intval($p['buy_price_double']); $spd = intval($p['sell_price_double']);
        ?>
        <tr id="row_<?= $p['id'] ?>">
          <td style="color:#9ca3af;font-size:11px"><?= $p['id'] ?></td>
          <td><strong><?= htmlspecialchars($p['product_name']) ?></strong></td>
          <td><span class="brand-badge" style="background:<?= $bColor ?>22;color:<?= $bColor ?>"><?= $bLabel ?></span></td>
          <td>
            <span class="stock-num <?= $stockClass ?>"><?= $stock ?></span>
            <span class="stock-label"><?= $stock <= 0 ? 'Out of stock' : ($stock <= 3 ? 'Low stock' : 'In stock') ?></span>
          </td>
          <td>
            <div class="price-display">
              <div><span class="buy">Single buy:</span> <?= $bps > 0 ? 'K'.$bps : '<span class="unset">not set</span>' ?> &nbsp; <span class="sell">Sell: <?= $sps > 0 ? 'K'.$sps : '—' ?></span></div>
              <div><span class="buy">Double buy:</span> <?= $bpd > 0 ? 'K'.$bpd : '<span class="unset">not set</span>' ?> &nbsp; <span class="sell">Sell: <?= $spd > 0 ? 'K'.$spd : '—' ?></span></div>
            </div>
          </td>
          <td>
            <div class="stock-controls">
              <input type="number" id="amt_<?= $p['id'] ?>" min="1" value="1" />
              <form method="POST" style="display:inline">
                <input type="hidden" name="action" value="stock">
                <input type="hidden" name="product_id" value="<?= $p['id'] ?>">
                <input type="hidden" name="operation" value="increase">
                <input type="hidden" name="amount" id="inc_<?= $p['id'] ?>">
                <button type="submit" class="btn-sm btn-increase" onclick="syncAmt(<?= $p['id'] ?>,'inc')">+ Add</button>
              </form>
              <form method="POST" style="display:inline">
                <input type="hidden" name="action" value="stock">
                <input type="hidden" name="product_id" value="<?= $p['id'] ?>">
                <input type="hidden" name="operation" value="decrease">
                <input type="hidden" name="amount" id="dec_<?= $p['id'] ?>">
                <button type="submit" class="btn-sm btn-decrease" onclick="syncAmt(<?= $p['id'] ?>,'dec')">− Reduce</button>
              </form>
              <form method="POST" style="display:inline">
                <input type="hidden" name="action" value="stock">
                <input type="hidden" name="product_id" value="<?= $p['id'] ?>">
                <input type="hidden" name="operation" value="set">
                <input type="hidden" name="amount" id="set_<?= $p['id'] ?>">
                <button type="submit" class="btn-sm btn-set" onclick="syncAmt(<?= $p['id'] ?>,'set')">Set</button>
              </form>
            </div>
          </td>
          <td style="white-space:nowrap">
            <button class="btn-sm btn-prices" onclick="togglePrices(<?= $p['id'] ?>)">Edit Prices</button>
            &nbsp;
            <button class="btn-sm btn-delete" onclick="confirmDelete(<?= $p['id'] ?>, '<?= htmlspecialchars(addslashes($p['product_name'])) ?>', 'case')">Delete</button>
          </td>
        </tr>
        <!-- Inline price edit row -->
        <tr class="price-edit-row" id="prices_<?= $p['id'] ?>">
          <td colspan="7">
            <form class="price-edit-form" method="POST">
              <input type="hidden" name="action" value="update_prices">
              <input type="hidden" name="product_id" value="<?= $p['id'] ?>">
              <span class="price-section-label">Single Case</span>
              <div class="pf"><label>Buy Price (K)</label><input type="number" name="buy_price_single" value="<?= $bps ?>" min="0" /></div>
              <div class="pf"><label>Sell Price (K)</label><input type="number" name="sell_price_single" value="<?= $sps ?>" min="0" /></div>
              <span class="price-section-label" style="margin-left:12px">Double Case</span>
              <div class="pf"><label>Buy Price (K)</label><input type="number" name="buy_price_double" value="<?= $bpd ?>" min="0" /></div>
              <div class="pf"><label>Sell Price (K)</label><input type="number" name="sell_price_double" value="<?= $spd ?>" min="0" /></div>
              <button type="submit" class="btn-save-prices">Save Prices</button>
              <button type="button" class="btn-cancel-prices" onclick="togglePrices(<?= $p['id'] ?>)">Cancel</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

  <!-- ============ ACCESSORIES SECTION ============ -->
  <div class="section-title" style="margin-top:48px">🔧 Accessories</div>

  <!-- ADD ACCESSORY FORM -->
  <div class="add-card">
    <h2>+ Add New Accessory</h2>
    <p class="hint">Silicon suction pads, screen protectors, or anything else you sell separately.</p>
    <form class="add-form" method="POST">
      <input type="hidden" name="action" value="add_acc" />
      <div class="field field-name">
        <label>Accessory Name</label>
        <input type="text" name="acc_name" placeholder="e.g. Silicon Suction Pad" required />
      </div>
      <div class="field field-xs">
        <label>Stock</label>
        <input type="number" name="acc_stock" min="0" value="0" />
      </div>
      <div class="field field-xs">
        <label>Buy Price (K)</label>
        <input type="number" name="acc_buy_price" min="0" value="0" placeholder="0" />
      </div>
      <div class="field field-xs">
        <label>Sell Price (K)</label>
        <input type="number" name="acc_sell_price" min="0" value="0" placeholder="0" />
      </div>
      <button type="submit" class="btn-add">Add Accessory</button>
    </form>
  </div>

  <!-- ACCESSORIES TABLE -->
  <div class="table-wrap">
    <?php if (empty($accessories)): ?>
      <div class="empty">No accessories yet. Add one above.</div>
    <?php else: ?>
    <table>
      <thead>
        <tr>
          <th>#</th>
          <th>Name</th>
          <th>Stock</th>
          <th>Buy Price</th>
          <th>Sell Price</th>
          <th>Profit / Unit</th>
          <th>Adjust Stock</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($accessories as $a):
          $astock = intval($a['stock']);
          $astockClass = $astock <= 0 ? 'out' : ($astock <= 3 ? 'low' : '');
          $profit = intval($a['sell_price']) - intval($a['buy_price']);
        ?>
        <tr id="accrow_<?= $a['id'] ?>">
          <td style="color:#9ca3af;font-size:11px"><?= $a['id'] ?></td>
          <td><strong><?= htmlspecialchars($a['name']) ?></strong></td>
          <td>
            <span class="stock-num <?= $astockClass ?>"><?= $astock ?></span>
            <span class="stock-label"><?= $astock <= 0 ? 'Out of stock' : ($astock <= 3 ? 'Low stock' : 'In stock') ?></span>
          </td>
          <td><?= $a['buy_price'] > 0 ? 'K'.$a['buy_price'] : '<span style="color:#d1d5db">—</span>' ?></td>
          <td><?= $a['sell_price'] > 0 ? '<strong>K'.$a['sell_price'].'</strong>' : '<span style="color:#d1d5db">—</span>' ?></td>
          <td>
            <?php if ($profit > 0): ?>
              <span style="color:#15803d;font-weight:600">+K<?= $profit ?></span>
            <?php elseif ($profit < 0): ?>
              <span style="color:#ef4444">K<?= $profit ?></span>
            <?php else: ?>
              <span style="color:#9ca3af">—</span>
            <?php endif; ?>
          </td>
          <td>
            <div class="stock-controls">
              <input type="number" id="aamt_<?= $a['id'] ?>" min="1" value="1" />
              <form method="POST" style="display:inline">
                <input type="hidden" name="action" value="acc_stock">
                <input type="hidden" name="acc_id" value="<?= $a['id'] ?>">
                <input type="hidden" name="operation" value="increase">
                <input type="hidden" name="amount" id="ainc_<?= $a['id'] ?>">
                <button type="submit" class="btn-sm btn-increase" onclick="syncAccAmt(<?= $a['id'] ?>,'ainc')">+ Add</button>
              </form>
              <form method="POST" style="display:inline">
                <input type="hidden" name="action" value="acc_stock">
                <input type="hidden" name="acc_id" value="<?= $a['id'] ?>">
                <input type="hidden" name="operation" value="decrease">
                <input type="hidden" name="amount" id="adec_<?= $a['id'] ?>">
                <button type="submit" class="btn-sm btn-decrease" onclick="syncAccAmt(<?= $a['id'] ?>,'adec')">− Reduce</button>
              </form>
              <form method="POST" style="display:inline">
                <input type="hidden" name="action" value="acc_stock">
                <input type="hidden" name="acc_id" value="<?= $a['id'] ?>">
                <input type="hidden" name="operation" value="set">
                <input type="hidden" name="amount" id="aset_<?= $a['id'] ?>">
                <button type="submit" class="btn-sm btn-set" onclick="syncAccAmt(<?= $a['id'] ?>,'aset')">Set</button>
              </form>
            </div>
          </td>
          <td style="white-space:nowrap">
            <button class="btn-sm btn-prices" onclick="toggleAccPrices(<?= $a['id'] ?>)">Edit Prices</button>
            &nbsp;
            <button class="btn-sm btn-delete" onclick="confirmDelete(<?= $a['id'] ?>, '<?= htmlspecialchars(addslashes($a['name'])) ?>', 'acc')">Delete</button>
          </td>
        </tr>
        <!-- Inline price edit row for accessory -->
        <tr class="price-edit-row" id="accprices_<?= $a['id'] ?>">
          <td colspan="8">
            <form class="price-edit-form" method="POST">
              <input type="hidden" name="action" value="update_acc_prices">
              <input type="hidden" name="acc_id" value="<?= $a['id'] ?>">
              <div class="pf"><label>Buy Price (K)</label><input type="number" name="acc_buy_price" value="<?= $a['buy_price'] ?>" min="0" /></div>
              <div class="pf"><label>Sell Price (K)</label><input type="number" name="acc_sell_price" value="<?= $a['sell_price'] ?>" min="0" /></div>
              <button type="submit" class="btn-save-prices">Save Prices</button>
              <button type="button" class="btn-cancel-prices" onclick="toggleAccPrices(<?= $a['id'] ?>)">Cancel</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

</div>

<!-- DELETE MODAL -->
<div class="modal-overlay" id="deleteModal">
  <div class="modal">
    <h3>Remove Item?</h3>
    <p id="deleteModalText">This will permanently remove the item.</p>
    <form method="POST" id="deleteForm">
      <input type="hidden" name="action" id="deleteAction" value="delete">
      <input type="hidden" name="product_id" id="deleteProductId" value="">
      <input type="hidden" name="acc_id" id="deleteAccId" value="">
      <div class="modal-btns">
        <button type="button" class="btn-cancel-modal" onclick="closeModal()">Cancel</button>
        <button type="submit" class="btn-confirm-delete">Yes, Remove</button>
      </div>
    </form>
  </div>
</div>

<script>
function toggleBulkFields() {
  const type = document.getElementById('bulkType').value;
  document.getElementById('bulkSingleFields').style.display = (type === 'Double') ? 'none' : 'flex';
  document.getElementById('bulkDoubleFields').style.display = (type === 'Single') ? 'none' : 'flex';
}
function confirmBulk() {
  return confirm('This will update prices for multiple products at once. Continue?');
}
function syncAmt(id, type) {
  document.getElementById(type + '_' + id).value = document.getElementById('amt_' + id).value;
}
function syncAccAmt(id, type) {
  document.getElementById(type + '_' + id).value = document.getElementById('aamt_' + id).value;
}
function togglePrices(id) {
  const row = document.getElementById('prices_' + id);
  row.style.display = row.style.display === 'table-row' ? 'none' : 'table-row';
}
function toggleAccPrices(id) {
  const row = document.getElementById('accprices_' + id);
  row.style.display = row.style.display === 'table-row' ? 'none' : 'table-row';
}
function confirmDelete(id, name, type) {
  document.getElementById('deleteModalText').textContent = 'Remove "' + name + '"? This cannot be undone.';
  document.getElementById('deleteProductId').value = type === 'case' ? id : '';
  document.getElementById('deleteAccId').value    = type === 'acc'  ? id : '';
  document.getElementById('deleteAction').value   = type === 'acc'  ? 'delete_acc' : 'delete';
  document.getElementById('deleteModal').classList.add('open');
}
function closeModal() { document.getElementById('deleteModal').classList.remove('open'); }
document.getElementById('deleteModal').addEventListener('click', function(e) { if (e.target === this) closeModal(); });
document.addEventListener('keydown', function(e) { if (e.key === 'Escape') closeModal(); });
</script>

<?php endif; ?>
<?php if ($conn) $conn->close(); ?>
</body>
</html>
