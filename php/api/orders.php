<?php
// /api/orders, /api/orders/:id

$db = db();

if ($method === 'GET' && $id === null) {
  $supplierId = isset($_GET['supplierId']) ? (int)$_GET['supplierId'] : null;
  $sql = "SELECT o.*, s.name AS supplier_name, s.tag AS supplier_tag
            FROM orders o JOIN suppliers s ON s.id = o.supplier_id";
  $params = [];
  if ($supplierId) { $sql .= ' WHERE o.supplier_id = ?'; $params[] = $supplierId; }
  $sql .= ' ORDER BY o.order_date DESC, o.id DESC';
  $stmt = $db->prepare($sql);
  $stmt->execute($params);
  $orders = $stmt->fetchAll();
  if (!$orders) { respond([]); }

  $ids = array_map('intval', array_column($orders, 'id'));
  $ph  = implode(',', array_fill(0, count($ids), '?'));

  $stmt = $db->prepare("SELECT a.*, p.pay_date, p.note AS pay_note, p.kind, p.rate AS pay_rate
                          FROM payment_allocations a
                          JOIN payments p ON p.id = a.payment_id
                         WHERE a.order_id IN ($ph)
                         ORDER BY p.pay_date ASC, a.id ASC");
  $stmt->execute($ids);
  $allocs = $stmt->fetchAll();

  $stmt = $db->prepare("SELECT * FROM order_photos WHERE order_id IN ($ph) ORDER BY id ASC");
  $stmt->execute($ids);
  $photos = $stmt->fetchAll();

  $allocByOrder = [];
  foreach ($allocs as $a) { $allocByOrder[(int)$a['order_id']][] = $a; }
  $photoByOrder = [];
  foreach ($photos as $p) { $photoByOrder[(int)$p['order_id']][] = $p; }

  $result = [];
  foreach ($orders as $o) {
    $oid = (int)$o['id'];
    $oAllocs = $allocByOrder[$oid] ?? [];
    $oPhotos = $photoByOrder[$oid] ?? [];
    $paid = 0.0;
    foreach ($oAllocs as $a) $paid += (float)$a['amount'];
    $result[] = [
      'id' => $oid,
      'supplierId' => (int)$o['supplier_id'],
      'supplierName' => $o['supplier_name'],
      'supplierTag' => $o['supplier_tag'],
      'date' => $o['order_date'],
      'name' => $o['supplier_name'],
      'cat' => $o['category'],
      'total' => (float)$o['total'],
      'rate' => $o['rate'] !== null ? (float)$o['rate'] : null,
      'note' => $o['note'] ?? '',
      'ship' => $o['ship_status'],
      'shipDate' => $o['ship_date'] ?? '',
      'etaDate' => $o['eta_date'] ?? '',
      'paid' => $paid,
      'debt' => max(0, (float)$o['total'] - $paid),
      'allocations' => array_map(fn($a) => [
        'id' => (int)$a['id'],
        'paymentId' => (int)$a['payment_id'],
        'paymentDate' => $a['pay_date'],
        'paymentNote' => $a['pay_note'],
        'paymentKind' => $a['kind'],
        'paymentRate' => $a['pay_rate'] !== null ? (float)$a['pay_rate'] : null,
        'amount' => (float)$a['amount'],
        'isAuto' => (bool)$a['is_auto'],
      ], $oAllocs),
      'photos' => array_map(fn($p) => $p['photo_path'], $oPhotos),
    ];
  }
  respond($result);
}

if ($method === 'POST' && $id === null) {
  $b = body();
  $db->beginTransaction();
  try {
    // Resolve supplier — either supplierId given, or supplierName (create-if-missing)
    $supplierId = isset($b['supplierId']) ? (int)$b['supplierId'] : null;
    if (!$supplierId) {
      $name = trim((string)($b['supplierName'] ?? $b['name'] ?? ''));
      if ($name === '') throw new Exception('supplierId or supplierName required');
      $stmt = $db->prepare('SELECT id FROM suppliers WHERE name = ?');
      $stmt->execute([$name]);
      $row = $stmt->fetch();
      if ($row) {
        $supplierId = (int)$row['id'];
      } else {
        $stmt = $db->prepare('INSERT INTO suppliers (name, primary_category) VALUES (?, ?)');
        $stmt->execute([$name, $b['cat'] ?? '其他']);
        $supplierId = (int)$db->lastInsertId();
      }
    }
    if (empty($b['total'])) throw new Exception('total required');

    $today = date('Y-m-d');
    $stmt = $db->prepare("INSERT INTO orders
      (supplier_id, order_date, name, category, total, rate, note, ship_status, ship_date, eta_date)
      VALUES (?, ?, (SELECT name FROM suppliers WHERE id=?), ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
      $supplierId,
      $b['date'] ?? $today,
      $supplierId,
      $b['cat'] ?? '其他',
      $b['total'],
      $b['rate'] ?? null,
      $b['note'] ?? '',
      $b['ship'] ?? 'ordered',
      !empty($b['shipDate']) ? $b['shipDate'] : null,
      !empty($b['etaDate'])  ? $b['etaDate']  : null,
    ]);
    $orderId = (int)$db->lastInsertId();

    // Optional first payment recorded with the order
    $initialPaid = (float)($b['initialPaid'] ?? 0);
    if ($initialPaid > 0) {
      $stmt = $db->prepare("INSERT INTO payments
        (supplier_id, order_id, pay_date, amount, rate, kind, note)
        VALUES (?, ?, ?, ?, ?, 'order', ?)");
      $stmt->execute([
        $supplierId, $orderId,
        $b['date'] ?? $today,
        $initialPaid,
        $b['rate'] ?? null,
        $b['initialPayNote'] ?? '首次付款',
      ]);
      $payId = (int)$db->lastInsertId();
      $stmt = $db->prepare('INSERT INTO payment_allocations (payment_id, order_id, amount, is_auto) VALUES (?, ?, ?, 0)');
      $stmt->execute([$payId, $orderId, $initialPaid]);
    }

    // FIFO-consume any unallocated account/prepaid balance on this supplier
    $stmt = $db->prepare("SELECT p.id, p.amount - COALESCE((SELECT SUM(amount) FROM payment_allocations WHERE payment_id=p.id),0) AS leftover
                            FROM payments p
                           WHERE p.supplier_id=? AND p.kind IN ('account','prepaid')
                          HAVING leftover > 0.001
                           ORDER BY p.pay_date ASC, p.id ASC");
    $stmt->execute([$supplierId]);
    $unalloc = $stmt->fetchAll();
    $needed = (float)$b['total'];
    foreach ($unalloc as $p) {
      if ($needed <= 0.001) break;
      $take = min((float)$p['leftover'], $needed);
      $stmt2 = $db->prepare('INSERT INTO payment_allocations (payment_id, order_id, amount, is_auto) VALUES (?, ?, ?, 1)');
      $stmt2->execute([$p['id'], $orderId, $take]);
      $needed -= $take;
    }

    foreach ($b['photos'] ?? [] as $url) {
      $stmt = $db->prepare('INSERT INTO order_photos (order_id, photo_path) VALUES (?, ?)');
      $stmt->execute([$orderId, $url]);
    }

    $db->commit();
    respond(['id' => $orderId, 'supplierId' => $supplierId]);
  } catch (Throwable $e) {
    $db->rollBack();
    throw $e;
  }
}

if ($method === 'PUT' && $id !== null) {
  $b = body();
  $map = [
    'date' => 'order_date', 'cat' => 'category', 'total' => 'total', 'rate' => 'rate',
    'note' => 'note', 'ship' => 'ship_status', 'shipDate' => 'ship_date', 'etaDate' => 'eta_date',
  ];
  $fields = []; $vals = [];
  foreach ($map as $k => $col) {
    if (array_key_exists($k, $b)) {
      $v = $b[$k];
      if (($col === 'ship_date' || $col === 'eta_date') && empty($v)) $v = null;
      $fields[] = "$col=?"; $vals[] = $v;
    }
  }
  if ($fields) {
    $vals[] = (int)$id;
    $stmt = $db->prepare('UPDATE orders SET ' . implode(', ', $fields) . ' WHERE id=?');
    $stmt->execute($vals);
  }
  respond(['ok' => true]);
}

if ($method === 'DELETE' && $id !== null) {
  $stmt = $db->prepare('DELETE FROM orders WHERE id=?');
  $stmt->execute([(int)$id]);
  respond(['ok' => true]);
}

fail('not found', 404);
