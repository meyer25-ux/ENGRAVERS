<?php
header('Content-Type: application/json');
require_once __DIR__ . '/db.php';

$brand = $_GET['brand'] ?? '';

if ($brand) {
    $stmt = $conn->prepare("SELECT id, product_name, stock, buy_price_single, sell_price_single, buy_price_double, sell_price_double FROM inventory WHERE brand = ? ORDER BY product_name ASC");
    $stmt->bind_param("s", $brand);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $result = $conn->query("SELECT id, product_name, stock, buy_price_single, sell_price_single, buy_price_double, sell_price_double FROM inventory ORDER BY product_name ASC");
}

if (!$result) {
    echo json_encode(['success' => false, 'message' => 'Query failed: ' . $conn->error]);
    $conn->close();
    exit;
}

$data = [];
while ($row = $result->fetch_assoc()) {
    // Cast numeric fields so JS receives numbers, not strings
    $row['stock']             = intval($row['stock']);
    $row['sell_price_single'] = intval($row['sell_price_single']);
    $row['sell_price_double'] = intval($row['sell_price_double']);
    $row['buy_price_single']  = intval($row['buy_price_single']);
    $row['buy_price_double']  = intval($row['buy_price_double']);
    $data[] = $row;
}

$conn->close();
echo json_encode($data);