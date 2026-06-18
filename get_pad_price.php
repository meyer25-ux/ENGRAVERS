<?php
header('Content-Type: application/json');
require_once __DIR__ . '/db.php';

// Looks for an accessory named like "silicon" or "suction" to get its current sell price.
// If you rename the accessory, this still finds it as long as the name contains "silicon" or "pad".
$result = $conn->query("SELECT sell_price FROM accessories WHERE name LIKE '%silicon%' OR name LIKE '%pad%' LIMIT 1");

if ($result && $row = $result->fetch_assoc()) {
    echo json_encode(['sell_price' => intval($row['sell_price'])]);
} else {
    // Fallback if no accessory matched
    echo json_encode(['sell_price' => 30]);
}

$conn->close();
