<?php
// /api/payments, /api/payments/:id, /api/payments/:id/reallocate

function allocateFIFO(PDO $db, int $paymentId, int $supplierId, float $amount): array {
  $stmt = $db->prepare("SELECT o.id, o.total,
      COALESCE((SELECT SUM(amount) FROM payment_allocations WHERE order_id=o.id), 0) AS allocated
    FROM orders o
    WHERE o.supplier_id = ?
    ORDER BY o.order_date ASC, o.id ASC");
  $stmt->execute([$supplierId]);
  $orders = $stmt->fetchAll();

  $remaining = $amount;
  $rows = [];
  foreach ($orders as $o) {
    if ($remaining <= 0.0001) break;
    $debt = (float)$o['total'] - (float)$o['allocated'];
    if ($debt <= 0.0001) continue;
    $take = min($debt, $remaining);
    $ins = $db->prepare('INSERT INTO payment_allocations (payment_id, order_id, amount, is_auto) VALUES (?, ?, ?, 1)');
    $ins->execute([$paymentId, (int)$o['id'], $take]);
    $rows[] = ['orderId' => (int)$o['id'], 'amount' => $take];
    $remaining -= $take;
  }
  return ['allocated' => $amount - $remaining, 'leftover' => $remaining, 'rows' => $rows];
}

$db = db();

if ($method === 'POST' && $id === null) {
  $b = body();
  $db->beginTransaction();
  try {
    $supplierId = (int)($b['supplierId'] ?? 0);
    $date       = $b['date'] ?? null;
    $amount     = (float)($b['amount'] ?? 0);
    $kind       = $b['kind'] ?? 'order';
    $note       = $b['note'] ?? '';
    $photo      = $b['photo'] ?? '';
    $orderId    = isset($b['orderId']) ? (int)$b['orderId'] : null;
    $rate       = isset($b['rate']) ? (float)$b['rate'] : null;

    if (!$supplierId) throw new Exception('supplierId required');
    if (!$date) throw new Exception('date required');
    if ($amount <= 0) throw new Exception('amount must be > 0');

    if ($kind === 'order') {
      if (!$orderId) throw new Exception('orderId required for kind=order');
      $stmt = $db->prepare('SELECT supplier_id FROM orders WHERE id=?');
      $stmt->execute([$orderId]);
      $row = $stmt->fetch();
      if (!$row) throw new Exception('order not found');
      if ((int)$row['supplier_id'] !== $supplierId) throw new Exception('order does not belong to supplier');
    } else {
      $orderId = null;
    }

    $stmt = $db->prepare('INSERT INTO payments (supplier_id, order_id, pay_date, amount, rate, kind, note, photo_path) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$supplierId, $orderId, $date, $amount, $rate, $kind, $note, $photo]);
    $paymentId = (int)$db->lastInsertId();

    $alloc = null;
    if ($kind === 'order') {
      $stmt = $db->prepare('INSERT INTO payment_allocations (payment_id, order_id, amount, is_auto) VALUES (?, ?, ?, 0)');
      $stmt->execute([$paymentId, $orderId, $amount]);
    } elseif ($kind === 'account') {
      $alloc = allocateFIFO($db, $paymentId, $supplierId, $amount);
    }
    // prepaid → no allocation

    $db->commit();
    respond(['id' => $paymentId, 'allocation' => $alloc]);
  } catch (Throwable $e) {
    $db->rollBack();
    throw $e;
  }
}

if ($method === 'PUT' && $id !== null && $sub === null) {
  $b = body();
  $map = ['date' => 'pay_date', 'note' => 'note', 'photo' => 'photo_path', 'rate' => 'rate'];
  $fields = []; $vals = [];
  foreach ($map as $k => $col) {
    if (array_key_exists($k, $b)) { $fields[] = "$col=?"; $vals[] = $b[$k]; }
  }
  if ($fields) {
    $vals[] = (int)$id;
    $stmt = $db->prepare('UPDATE payments SET ' . implode(', ', $fields) . ' WHERE id=?');
    $stmt->execute($vals);
  }
  respond(['ok' => true]);
}

if ($method === 'DELETE' && $id !== null && $sub === null) {
  $stmt = $db->prepare('DELETE FROM payments WHERE id=?');
  $stmt->execute([(int)$id]);
  respond(['ok' => true]);
}

if ($method === 'POST' && $id !== null && $sub === 'reallocate') {
  $b = body();
  $allocations = $b['allocations'] ?? null;
  if (!is_array($allocations)) throw new Exception('allocations array required');
  $db->beginTransaction();
  try {
    $stmt = $db->prepare('SELECT amount FROM payments WHERE id=?');
    $stmt->execute([(int)$id]);
    $p = $stmt->fetch();
    if (!$p) throw new Exception('payment not found');
    $sum = 0.0;
    foreach ($allocations as $a) $sum += (float)$a['amount'];
    if ($sum > (float)$p['amount'] + 0.001) throw new Exception('allocation sum exceeds payment amount');

    $del = $db->prepare('DELETE FROM payment_allocations WHERE payment_id=?');
    $del->execute([(int)$id]);
    $ins = $db->prepare('INSERT INTO payment_allocations (payment_id, order_id, amount, is_auto) VALUES (?, ?, ?, 0)');
    foreach ($allocations as $a) {
      $ins->execute([(int)$id, (int)$a['orderId'], (float)$a['amount']]);
    }
    $db->commit();
    respond(['ok' => true]);
  } catch (Throwable $e) {
    $db->rollBack();
    throw $e;
  }
}

fail('not found', 404);
