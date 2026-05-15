<?php
// /api/categories (admin for POST/PUT/DELETE, login for GET)

$db = db();

if ($method === 'GET' && $id === null) {
  $rows = $db->query('SELECT id, name, sort_order FROM categories ORDER BY sort_order ASC, id ASC')->fetchAll();
  respond(array_map(fn($r) => [
    'id' => (int)$r['id'],
    'name' => $r['name'],
    'sortOrder' => (int)$r['sort_order'],
  ], $rows));
}

if ($method === 'POST' && $id === null) {
  $b = body();
  $name = trim((string)($b['name'] ?? ''));
  $sortOrder = (int)($b['sortOrder'] ?? 100);
  if ($name === '') fail('name required', 400);
  if (mb_strlen($name) > 50) fail('name too long (max 50)', 400);
  try {
    $stmt = $db->prepare('INSERT INTO categories (name, sort_order) VALUES (?, ?)');
    $stmt->execute([$name, $sortOrder]);
    respond(['id' => (int)$db->lastInsertId()]);
  } catch (PDOException $e) {
    if (($e->errorInfo[1] ?? 0) === 1062) fail('category name already exists', 409);
    throw $e;
  }
}

if ($method === 'PUT' && $id !== null) {
  $b = body();
  $fields = []; $vals = [];
  if (array_key_exists('name', $b)) {
    $newName = trim((string)$b['name']);
    if ($newName === '') fail('name required', 400);
    if (mb_strlen($newName) > 50) fail('name too long', 400);
    // Find the old name first — if it changes, cascade into orders + suppliers
    $stmt = $db->prepare('SELECT name FROM categories WHERE id=?');
    $stmt->execute([(int)$id]);
    $row = $stmt->fetch();
    if (!$row) fail('not found', 404);
    $oldName = $row['name'];
    if ($oldName !== $newName) {
      $db->beginTransaction();
      try {
        $u1 = $db->prepare('UPDATE orders    SET category=?         WHERE category=?');
        $u1->execute([$newName, $oldName]);
        $u2 = $db->prepare('UPDATE suppliers SET primary_category=? WHERE primary_category=?');
        $u2->execute([$newName, $oldName]);
        $u3 = $db->prepare('UPDATE categories SET name=? WHERE id=?');
        $u3->execute([$newName, (int)$id]);
        $db->commit();
      } catch (Throwable $e) { $db->rollBack(); throw $e; }
    }
  }
  if (array_key_exists('sortOrder', $b)) {
    $stmt = $db->prepare('UPDATE categories SET sort_order=? WHERE id=?');
    $stmt->execute([(int)$b['sortOrder'], (int)$id]);
  }
  respond(['ok' => true]);
}

if ($method === 'DELETE' && $id !== null) {
  $stmt = $db->prepare('SELECT name FROM categories WHERE id=?');
  $stmt->execute([(int)$id]);
  $row = $stmt->fetch();
  if (!$row) fail('not found', 404);
  $name = $row['name'];
  // Refuse if any orders or suppliers still reference this category
  $stmt = $db->prepare('SELECT COUNT(*) AS n FROM orders WHERE category=?');
  $stmt->execute([$name]);
  $orderCount = (int)$stmt->fetch()['n'];
  $stmt = $db->prepare('SELECT COUNT(*) AS n FROM suppliers WHERE primary_category=?');
  $stmt->execute([$name]);
  $supCount = (int)$stmt->fetch()['n'];
  if ($orderCount > 0 || $supCount > 0) {
    fail("category in use: $orderCount orders, $supCount suppliers", 409);
  }
  $stmt = $db->prepare('DELETE FROM categories WHERE id=?');
  $stmt->execute([(int)$id]);
  respond(['ok' => true]);
}

fail('not found', 404);
