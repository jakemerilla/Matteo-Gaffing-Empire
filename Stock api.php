<?php
// Public endpoint used by the store: reduces stock after an order is placed.
require_once __DIR__ . '/includes/stock.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false]);
    exit;
}

$body = json_decode((string) file_get_contents('php://input'), true);
$items = is_array($body['items'] ?? null) ? array_slice($body['items'], 0, 50) : [];

$ok = mge_stock_update(function (array $stock) use ($items) {
    foreach ($items as $item) {
        $key = (string) ($item['productKey'] ?? '') . '||' . (string) ($item['variant'] ?? '');
        $qty = max(1, min(99, (int) ($item['quantity'] ?? 0)));
        // Only products the admin has stocked are tracked here.
        if (array_key_exists($key, $stock)) {
            $stock[$key] = max(0, (int) $stock[$key] - $qty);
        }
    }
    return $stock;
});

echo json_encode(['ok' => $ok]);
