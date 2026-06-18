<?php
header('Content-Type: application/json');
require_once __DIR__ . '/db.php';

// ---------------------------------------------------------------------------
// Sanitize & validate inputs
// ---------------------------------------------------------------------------
$name       = trim($_POST['name']       ?? '');
$phone      = preg_replace('/[^\d+\s\-()]/', '', trim($_POST['phone'] ?? ''));
$brand      = trim($_POST['brand']      ?? '');
$product_id = intval($_POST['product_id'] ?? 0);
$finish     = trim($_POST['finish']     ?? '');
$caseType   = trim($_POST['caseType']   ?? '');
$silicon    = trim($_POST['silicon']    ?? '');
$delivery   = trim($_POST['delivery']   ?? '');
$location   = trim($_POST['location']   ?? '');
$price      = intval($_POST['total']    ?? 0);

// Whitelist checks
$allowedFinish    = ['Matte', 'Glossy'];
$allowedCaseType  = ['Single', 'Double'];
$allowedSilicon   = ['Yes', 'No'];
$allowedDelivery  = ['Pickup', 'Delivery'];

$missing = [];
if (!$name) $missing[] = 'name';
if (!$phone) $missing[] = 'phone';
if (!$brand) $missing[] = 'brand';
if (!$product_id) $missing[] = 'product_id';
if (!$finish) $missing[] = 'finish';
if (!$caseType) $missing[] = 'caseType';
if (!$silicon) $missing[] = 'silicon';
if (!$delivery) $missing[] = 'delivery';
if ($price <= 0) $missing[] = 'total';

if (!empty($missing)) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields: ' . implode(', ', $missing)]);
    exit;
}
if (!in_array($finish,   $allowedFinish,   true)) { echo json_encode(['success' => false, 'message' => 'Invalid finish.']);    exit; }
if (!in_array($caseType, $allowedCaseType, true)) { echo json_encode(['success' => false, 'message' => 'Invalid case type.']); exit; }
if (!in_array($silicon,  $allowedSilicon,  true)) { echo json_encode(['success' => false, 'message' => 'Invalid silicon option.']); exit; }
if (!in_array($delivery, $allowedDelivery, true)) { echo json_encode(['success' => false, 'message' => 'Invalid delivery option.']); exit; }
if ($delivery === 'Delivery' && !$location) {
    echo json_encode(['success' => false, 'message' => 'Location is required for delivery.']);
    exit;
}

// ---------------------------------------------------------------------------
// File uploads
// ---------------------------------------------------------------------------
$uploadDir = __DIR__ . '/uploads/';
if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

$maxFileSize = 5 * 1024 * 1024; // 5MB in bytes

// Check file sizes before doing anything else
foreach (['design', 'payment'] as $fileKey) {
    if (isset($_FILES[$fileKey]) && $_FILES[$fileKey]['error'] === UPLOAD_ERR_OK) {
        if ($_FILES[$fileKey]['size'] > $maxFileSize) {
            echo json_encode(['success' => false, 'message' => 'File "' . $fileKey . '" exceeds the 5MB limit. Please compress your image and try again.']);
            exit;
        }
    }
}

function saveFile($fileKey, $uploadDir) {
    if (!isset($_FILES[$fileKey]) || $_FILES[$fileKey]['error'] !== UPLOAD_ERR_OK) return null;
    $ext     = strtolower(pathinfo($_FILES[$fileKey]['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    if (!in_array($ext, $allowed, true)) return null;
    // Verify it is actually an image
    if (!getimagesize($_FILES[$fileKey]['tmp_name'])) return null;
    $filename = bin2hex(random_bytes(16)) . '.' . $ext;
    $dest     = $uploadDir . $filename;
    return move_uploaded_file($_FILES[$fileKey]['tmp_name'], $dest) ? 'uploads/' . $filename : null;
}

$designFile  = saveFile('design',  $uploadDir);
$paymentFile = saveFile('payment', $uploadDir);

if (!$designFile)  { echo json_encode(['success' => false, 'message' => 'Design image upload failed.']);         exit; }
if (!$paymentFile) { echo json_encode(['success' => false, 'message' => 'Payment screenshot upload failed.']);   exit; }

// ---------------------------------------------------------------------------
// Stock check + insert + stock decrement — all inside a transaction
// This prevents race conditions (two people ordering the last item at once).
// ---------------------------------------------------------------------------
$conn->begin_transaction();

try {
    // Lock the inventory row for this transaction
    $checkStmt = $conn->prepare("SELECT stock, product_name FROM inventory WHERE id = ? FOR UPDATE");
    $checkStmt->bind_param('i', $product_id);
    $checkStmt->execute();
    $product = $checkStmt->get_result()->fetch_assoc();
    $checkStmt->close();

    if (!$product) {
        throw new Exception('Invalid product selected.');
    }
    if ($product['stock'] <= 0) {
        throw new Exception('This product is out of stock.');
    }

    // Insert order
    $insertStmt = $conn->prepare("
        INSERT INTO orders
            (name, phone, brand, model, finish, case_type, silicon_pad, delivery, location, total_price, design_file, payment_file)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $insertStmt->bind_param(
        'sssssssssiss',
        $name, $phone, $brand, $product['product_name'],
        $finish, $caseType, $silicon, $delivery, $location,
        $price, $designFile, $paymentFile
    );
    $insertStmt->execute();
    $newOrderId = $conn->insert_id;
    $insertStmt->close();

    // Decrement stock
    $stockStmt = $conn->prepare("UPDATE inventory SET stock = stock - 1 WHERE id = ? AND stock > 0");
    $stockStmt->bind_param('i', $product_id);
    $stockStmt->execute();
    if ($stockStmt->affected_rows === 0) {
        // Someone else grabbed the last unit between our SELECT and UPDATE
        throw new Exception('This product is out of stock.');
    }
    $stockStmt->close();

    $conn->commit();
    echo json_encode(['success' => true, 'message' => 'Order placed successfully!', 'order_id' => $newOrderId]);

} catch (Exception $e) {
    $conn->rollback();
    // Clean up uploaded files since the order didn't go through
    if ($designFile  && file_exists(__DIR__ . '/' . $designFile))  unlink(__DIR__ . '/' . $designFile);
    if ($paymentFile && file_exists(__DIR__ . '/' . $paymentFile)) unlink(__DIR__ . '/' . $paymentFile);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

$conn->close();
