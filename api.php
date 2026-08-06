<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';

$pdo = db();
$action = $_GET['action'] ?? 'bootstrap';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$input = json_input();

try {
    if ($action === 'login' && $method === 'POST') {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE username = ?');
        $stmt->execute([trim((string)($input['username'] ?? ''))]);
        $user = $stmt->fetch();
        if (!$user || !password_verify((string)($input['password'] ?? ''), $user['password_hash'])) {
            respond(['error' => 'Invalid username or password.'], 422);
        }
        session_regenerate_id(true);
        $_SESSION['user'] = ['id' => (int)$user['id'], 'name' => $user['display_name'], 'role' => $user['role']];
        respond(['ok' => true, 'csrf' => csrf_token(), 'user' => $_SESSION['user']]);
    }
    if ($action === 'logout' && $method === 'POST') {
        verify_csrf(); session_destroy(); respond(['ok' => true]);
    }
    if ($action === 'session') {
        respond(['authenticated' => !empty($_SESSION['user']), 'user' => $_SESSION['user'] ?? null, 'csrf' => csrf_token()]);
    }

    $user = require_user();
    if ($method !== 'GET') verify_csrf();

    if ($action === 'bootstrap') {
        $products = $pdo->query('SELECT id, name, category, sku, price, stock, sold FROM products WHERE active = 1 ORDER BY name')->fetchAll();
        $sales = $pdo->query("SELECT s.*, u.display_name cashier, COALESCE(SUM(si.quantity),0) item_count FROM sales s JOIN users u ON u.id=s.cashier_id LEFT JOIN sale_items si ON si.sale_id=s.id GROUP BY s.id ORDER BY s.id DESC LIMIT 250")->fetchAll();
        $today = $pdo->query("SELECT COALESCE(SUM(total),0) FROM sales WHERE date(created_at)=date('now','localtime')")->fetchColumn();
        respond(['user' => $user, 'csrf' => csrf_token(), 'products' => $products, 'sales' => $sales, 'settings' => settings($pdo), 'todayRevenue' => (float)$today]);
    }
    if ($action === 'product' && $method === 'POST') {
        $id = (int)($input['id'] ?? 0); $name = trim((string)($input['name'] ?? ''));
        $category = trim((string)($input['category'] ?? '')); $sku = strtoupper(trim((string)($input['sku'] ?? '')));
        $price = filter_var($input['price'] ?? null, FILTER_VALIDATE_FLOAT); $stock = filter_var($input['stock'] ?? null, FILTER_VALIDATE_INT);
        if ($name === '' || $category === '' || $sku === '' || $price === false || $price < 0 || $stock === false || $stock < 0) respond(['error' => 'Enter valid product details.'], 422);
        if ($id) {
            $stmt = $pdo->prepare('UPDATE products SET name=?, category=?, sku=?, price=?, stock=?, updated_at=CURRENT_TIMESTAMP WHERE id=? AND active=1');
            $stmt->execute([$name, $category, $sku, $price, $stock, $id]);
        } else {
            $stmt = $pdo->prepare('INSERT INTO products (name, category, sku, price, stock) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$name, $category, $sku, $price, $stock]); $id = (int)$pdo->lastInsertId();
        }
        respond(['ok' => true, 'id' => $id]);
    }
    if ($action === 'product' && $method === 'DELETE') {
        $id = (int)($_GET['id'] ?? 0);
        $stmt = $pdo->prepare('UPDATE products SET active=0, updated_at=CURRENT_TIMESTAMP WHERE id=?'); $stmt->execute([$id]);
        respond(['ok' => true]);
    }
    if ($action === 'checkout' && $method === 'POST') {
        $items = $input['items'] ?? []; $payment = trim((string)($input['paymentMethod'] ?? 'Cash'));
        if (!is_array($items) || !$items) respond(['error' => 'The cart is empty.'], 422);
        if (!in_array($payment, ['Cash', 'Card', 'Transfer'], true)) respond(['error' => 'Invalid payment method.'], 422);
        $pdo->beginTransaction();
        $subtotal = 0.0; $saleItems = [];
        $find = $pdo->prepare('SELECT id, name, price, stock FROM products WHERE id=? AND active=1');
        foreach ($items as $item) {
            $id = (int)($item['productId'] ?? 0); $qty = (int)($item['quantity'] ?? 0); $find->execute([$id]); $product = $find->fetch();
            if (!$product || $qty < 1 || $qty > (int)$product['stock']) throw new DomainException(($product['name'] ?? 'A product') . ' does not have enough stock.');
            $subtotal += (float)$product['price'] * $qty; $saleItems[] = [$product, $qty];
        }
        $config = settings($pdo); $tax = round($subtotal * ((float)$config['tax_rate'] / 100), 2); $total = $subtotal + $tax;
        $receipt = 'PM-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
        $stmt = $pdo->prepare('INSERT INTO sales (receipt_no, subtotal, tax, total, payment_method, cashier_id) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$receipt, $subtotal, $tax, $total, $payment, $user['id']]); $saleId = (int)$pdo->lastInsertId();
        $add = $pdo->prepare('INSERT INTO sale_items (sale_id, product_id, product_name, price, quantity) VALUES (?, ?, ?, ?, ?)');
        $reduce = $pdo->prepare('UPDATE products SET stock=stock-?, sold=sold+?, updated_at=CURRENT_TIMESTAMP WHERE id=? AND stock>=?');
        foreach ($saleItems as [$product, $qty]) { $add->execute([$saleId, $product['id'], $product['name'], $product['price'], $qty]); $reduce->execute([$qty, $qty, $product['id'], $qty]); if ($reduce->rowCount() !== 1) throw new DomainException('Stock changed during checkout.'); }
        $pdo->commit();
        respond(['ok' => true, 'sale' => ['id'=>$saleId, 'receipt_no'=>$receipt, 'subtotal'=>$subtotal, 'tax'=>$tax, 'total'=>$total, 'payment_method'=>$payment, 'created_at'=>date('Y-m-d H:i:s'), 'items'=>array_map(fn($row)=>['name'=>$row[0]['name'],'price'=>(float)$row[0]['price'],'quantity'=>$row[1]], $saleItems)]]);
    }
    if ($action === 'sale') {
        $id = (int)($_GET['id'] ?? 0); $stmt = $pdo->prepare('SELECT s.*, u.display_name cashier FROM sales s JOIN users u ON u.id=s.cashier_id WHERE s.id=?'); $stmt->execute([$id]); $sale = $stmt->fetch();
        if (!$sale) respond(['error' => 'Sale not found.'], 404);
        $stmt = $pdo->prepare('SELECT product_name name, price, quantity FROM sale_items WHERE sale_id=?'); $stmt->execute([$id]); $sale['items'] = $stmt->fetchAll(); respond(['sale'=>$sale]);
    }
    if ($action === 'settings' && $method === 'POST') {
        $allowed = ['store_name','store_address','store_phone','currency','tax_rate','receipt_footer','low_stock_level'];
        if ((float)($input['tax_rate'] ?? 0) < 0 || (float)($input['tax_rate'] ?? 0) > 100) respond(['error'=>'Tax rate must be between 0 and 100.'], 422);
        $stmt = $pdo->prepare('INSERT INTO settings (key,value) VALUES (?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value');
        foreach ($allowed as $key) if (array_key_exists($key, $input)) $stmt->execute([$key, trim((string)$input[$key])]);
        respond(['ok'=>true, 'settings'=>settings($pdo)]);
    }
    if ($action === 'password' && $method === 'POST') {
        if (strlen((string)($input['newPassword'] ?? '')) < 8) respond(['error'=>'New password must have at least 8 characters.'], 422);
        $stmt=$pdo->prepare('SELECT password_hash FROM users WHERE id=?'); $stmt->execute([$user['id']]);
        if (!password_verify((string)($input['currentPassword'] ?? ''), (string)$stmt->fetchColumn())) respond(['error'=>'Current password is incorrect.'], 422);
        $stmt=$pdo->prepare('UPDATE users SET password_hash=? WHERE id=?'); $stmt->execute([password_hash((string)$input['newPassword'], PASSWORD_DEFAULT),$user['id']]); respond(['ok'=>true]);
    }
    if ($action === 'export') {
        $payload=['exported_at'=>date(DATE_ATOM),'products'=>$pdo->query('SELECT * FROM products')->fetchAll(),'sales'=>$pdo->query('SELECT * FROM sales')->fetchAll(),'sale_items'=>$pdo->query('SELECT * FROM sale_items')->fetchAll(),'settings'=>settings($pdo)];
        header('Content-Disposition: attachment; filename="philomart-backup-'.date('Y-m-d').'.json"'); respond($payload);
    }
    respond(['error' => 'Endpoint not found.'], 404);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    respond(['error' => str_contains($e->getMessage(), 'UNIQUE') ? 'That SKU is already in use.' : 'A database error occurred.'], 422);
} catch (DomainException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack(); respond(['error'=>$e->getMessage()], 409);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack(); respond(['error'=>'Unexpected server error.'], 500);
}
