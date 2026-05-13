<?php
// /api/suppliers, /api/suppliers/:id, /api/suppliers/:id/payments

const AGG_SQL = "
  SELECT
    s.id, s.name, s.primary_category, s.tag, s.pinned, s.note, s.created_at,
    COALESCE(o.order_count, 0)      AS order_count,
    COALESCE(o.total_ordered, 0)    AS total_ordered,
    COALESCE(o.total_ordered_tg, 0) AS total_ordered_tg,
    COALESCE(p.total_paid, 0)       AS total_paid,
    COALESCE(p.total_paid_tg, 0)    AS total_paid_tg,
    COALESCE(a.total_allocated, 0)  AS total_allocated,
    COALESCE(o.total_ordered, 0)    - COALESCE(a.total_allocated, 0) AS owed,
    COALESCE(p.total_paid, 0)       - COALESCE(a.total_allocated, 0) AS account_balance,
    (SELECT MAX(order_date) FROM orders WHERE supplier_id = s.id) AS last_order_date
  FROM suppliers s
  LEFT JOIN (
    SELECT supplier_id, COUNT(*) AS order_count, SUM(total) AS total_ordered,
           SUM(total * COALESCE(rate, 65.5)) AS total_ordered_tg
    FROM orders GROUP BY supplier_id
  ) o ON o.supplier_id = s.id
  LEFT JOIN (
    SELECT supplier_id, SUM(amount) AS total_paid,
           SUM(amount * COALESCE(rate, 65.5)) AS total_paid_tg
    FROM payments GROUP BY supplier_id
  ) p ON p.supplier_id = s.id
  LEFT JOIN (
    SELECT p.supplier_id, SUM(a.amount) AS total_allocated
    FROM payment_allocations a JOIN payments p ON p.id = a.payment_id
    GROUP BY p.supplier_id
  ) a ON a.supplier_id = s.id
";

function shapeSupplier(array $r): array {
  $orderedTg = (float)$r['total_ordered_tg'];
  $paidTg    = (float)$r['total_paid_tg'];
  return [
    'id' => (int)$r['id'],
    'name' => $r['name'],
    'primaryCategory' => $r['primary_category'],
    'tag' => $r['tag'],
    'pinned' => (bool)$r['pinned'],
    'note' => $r['note'] ?? '',
    'orderCount' => (int)$r['order_count'],
    'totalOrdered' => (float)$r['total_ordered'],
    'totalOrderedTg' => $orderedTg,
    'totalPaid' => (float)$r['total_paid'],
    'totalPaidTg' => $paidTg,
    'totalAllocated' => (float)$r['total_allocated'],
    'owed' => (float)$r['owed'],
    'owedTg' => $orderedTg - $paidTg,
    'accountBalance' => (float)$r['account_balance'],
    'lastOrderDate' => $r['last_order_date'],
  ];
}

$db = db();

if ($method === 'GET' && $id === null) {
  $stmt = $db->query(AGG_SQL . ' ORDER BY s.pinned DESC, s.name ASC');
  respond(array_map('shapeSupplier', $stmt->fetchAll()));
}

if ($method === 'GET' && $id !== null && $sub === null) {
  $stmt = $db->prepare(AGG_SQL . ' WHERE s.id = ?');
  $stmt->execute([(int)$id]);
  $row = $stmt->fetch();
  if (!$row) fail('not found', 404);
  respond(shapeSupplier($row));
}

if ($method === 'POST' && $id === null) {
  $b = body();
  $name = trim((string)($b['name'] ?? ''));
  if ($name === '') throw new Exception('name required');
  try {
    $stmt = $db->prepare('INSERT INTO suppliers (name, primary_category, tag, note) VALUES (?, ?, ?, ?)');
    $stmt->execute([
      $name,
      $b['primaryCategory'] ?? '其他',
      $b['tag'] ?? 'flexible',
      $b['note'] ?? '',
    ]);
    respond(['id' => (int)$db->lastInsertId()]);
  } catch (PDOException $e) {
    if ($e->errorInfo[1] ?? 0 === 1062) fail('supplier name already exists', 409);
    throw $e;
  }
}

if ($method === 'PUT' && $id !== null && $sub === null) {
  $b = body();
  $map = [
    'name' => 'name', 'primaryCategory' => 'primary_category',
    'tag' => 'tag', 'pinned' => 'pinned', 'note' => 'note',
  ];
  $fields = []; $vals = [];
  foreach ($map as $k => $col) {
    if (array_key_exists($k, $b)) {
      $v = $k === 'pinned' ? ($b[$k] ? 1 : 0) : $b[$k];
      $fields[] = "$col=?"; $vals[] = $v;
    }
  }
  if ($fields) {
    $vals[] = (int)$id;
    $stmt = $db->prepare('UPDATE suppliers SET ' . implode(', ', $fields) . ' WHERE id=?');
    $stmt->execute($vals);
  }
  respond(['ok' => true]);
}

if ($method === 'DELETE' && $id !== null && $sub === null) {
  $stmt = $db->prepare('SELECT COUNT(*) AS n FROM orders WHERE supplier_id=?');
  $stmt->execute([(int)$id]);
  if ((int)$stmt->fetch()['n'] > 0) fail('supplier has orders', 409);
  $stmt = $db->prepare('DELETE FROM suppliers WHERE id=?');
  $stmt->execute([(int)$id]);
  respond(['ok' => true]);
}

if ($method === 'GET' && $id !== null && $sub === 'payments') {
  $stmt = $db->prepare('SELECT * FROM payments WHERE supplier_id=? ORDER BY pay_date DESC, id DESC');
  $stmt->execute([(int)$id]);
  $payments = $stmt->fetchAll();
  if (!$payments) respond([]);

  $ids = array_map('intval', array_column($payments, 'id'));
  $ph  = implode(',', array_fill(0, count($ids), '?'));
  $stmt = $db->prepare("SELECT a.*, o.name AS order_name FROM payment_allocations a
                          LEFT JOIN orders o ON o.id = a.order_id
                         WHERE a.payment_id IN ($ph)");
  $stmt->execute($ids);
  $allocs = $stmt->fetchAll();
  $byPay = [];
  foreach ($allocs as $a) {
    $byPay[(int)$a['payment_id']][] = [
      'id' => (int)$a['id'],
      'orderId' => (int)$a['order_id'],
      'amount' => (float)$a['amount'],
      'isAuto' => (bool)$a['is_auto'],
    ];
  }

  respond(array_map(fn($p) => [
    'id' => (int)$p['id'],
    'date' => $p['pay_date'],
    'amount' => (float)$p['amount'],
    'rate' => $p['rate'] !== null ? (float)$p['rate'] : null,
    'kind' => $p['kind'],
    'note' => $p['note'] ?? '',
    'photo' => $p['photo_path'] ?? '',
    'allocations' => $byPay[(int)$p['id']] ?? [],
  ], $payments));
}

fail('not found', 404);
