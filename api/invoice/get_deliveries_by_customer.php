<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/auth.php';
header('Content-Type: application/json');
requireLogin();
requireRole('director', 'accountant', 'manager');

$pdo        = getDBConnection();
$customerId = (int) ($_GET['customer_id'] ?? 0);
if (!$customerId) { echo json_encode(['ok' => false, 'msg' => 'Thiếu khách hàng']); exit; }

try {
    $customerStmt = $pdo->prepare("
        SELECT id, customer_name, COALESCE(vat_rate, 8) AS vat_rate
        FROM customers
        WHERE id = ?
        LIMIT 1
    ");
    $customerStmt->execute([$customerId]);
    $customer = $customerStmt->fetch(PDO::FETCH_ASSOC);
    if (!$customer) {
        echo json_encode(['ok' => false, 'msg' => 'Không tìm thấy khách hàng']);
        exit;
    }

    // 1. Lấy danh sách biên bản đã xác nhận, chưa xuất HĐ của khách
    $deliveries = $pdo->prepare("
        SELECT d.id, d.delivery_no, d.delivery_date,
               COUNT(di.id) AS item_count,
               COALESCE(SUM(di.quantity), 0) AS total_qty
        FROM deliveries d
        LEFT JOIN delivery_items di ON di.delivery_id = d.id
        WHERE d.customer_id = ?
          AND d.status = 'confirmed'
        GROUP BY d.id
        ORDER BY d.delivery_date DESC, d.id DESC
    ");
    $deliveries->execute([$customerId]);
    $deliveries = $deliveries->fetchAll(PDO::FETCH_ASSOC);

    // 2. Lấy tất cả items của những biên bản đó, kèm đơn giá từ customer_prices
    if (empty($deliveries)) {
        echo json_encode([
            'ok' => true,
            'customer' => $customer,
            'vat_rate' => (int)$customer['vat_rate'],
            'deliveries' => [],
            'items_by_delivery' => []
        ]);
        exit;
    }

    $deliveryIds  = array_column($deliveries, 'id');
    $placeholders = implode(',', array_fill(0, count($deliveryIds), '?'));

    $itemsStmt = $pdo->prepare("
        SELECT di.delivery_id,
               di.product_code_id,
               pc.product_code,
               pc.description,
               pc.unit,
               di.quantity,
               COALESCE(
                   (SELECT cp.unit_price
                    FROM customer_prices cp
                    WHERE cp.customer_id   = ?
                      AND cp.product_code_id = di.product_code_id
                      AND cp.is_active = 1
                    ORDER BY cp.id DESC
                    LIMIT 1),
                   di.unit_price,
                   0
               ) AS unit_price
        FROM delivery_items di
        JOIN product_codes pc ON pc.id = di.product_code_id
        WHERE di.delivery_id IN ($placeholders)
        ORDER BY di.delivery_id, di.id
    ");
    $params = array_merge([$customerId], $deliveryIds);
    $itemsStmt->execute($params);
    $allItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

    // Nhóm items theo delivery_id
    $itemsByDelivery = [];
    foreach ($allItems as $item) {
        $item['total_price'] = round((float) $item['quantity'] * (float) $item['unit_price']);
        $itemsByDelivery[$item['delivery_id']][] = $item;
    }

    echo json_encode([
        'ok'               => true,
        'customer'         => $customer,
        'vat_rate'         => (int)$customer['vat_rate'],
        'deliveries'       => $deliveries,
        'items_by_delivery' => $itemsByDelivery,
    ]);
} catch (Throwable $e) {
    error_log('get_deliveries_by_customer error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'msg' => 'Lỗi truy vấn dữ liệu']);
}
