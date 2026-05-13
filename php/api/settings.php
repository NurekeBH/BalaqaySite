<?php
// /api/settings (GET all), /api/settings/:key (PUT)

$db = db();

if ($method === 'GET' && $id === null) {
  $rows = $db->query('SELECT k, v FROM settings')->fetchAll();
  $out = [];
  foreach ($rows as $r) $out[$r['k']] = $r['v'];
  respond($out);
}

if ($method === 'PUT' && $id !== null) {
  $b = body();
  $value = (string)($b['value'] ?? '');
  $stmt = $db->prepare('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v=VALUES(v)');
  $stmt->execute([$id, $value]);
  respond(['ok' => true]);
}

fail('not found', 404);
