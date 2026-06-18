<?php
require_once 'db.php';
$res = $conn->query("SELECT * FROM orders ORDER BY id DESC LIMIT 5");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        print_r($row);
    }
} else {
    echo "Query failed: " . $conn->error;
}
