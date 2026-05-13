<?php
// /api/photos/upload  (multipart POST, field name "photo") → { url: '/uploads/...' }
// /api/photos/order/:orderId   POST { url }, DELETE { url }

$db = db();
$uploadsDir = __DIR__ . '/../uploads'; // httpdocs/uploads
if (!is_dir($uploadsDir)) @mkdir($uploadsDir, 0755, true);

if ($method === 'POST' && $id === 'upload') {
  if (empty($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
    fail('no file', 400);
  }
  $f = $_FILES['photo'];
  if ($f['size'] > 10 * 1024 * 1024) fail('file too large (max 10 MB)', 400);

  $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION)) ?: 'jpg';
  $ext = preg_replace('/[^a-z0-9]/', '', $ext) ?: 'jpg';
  $name = sprintf('%s-%s.%s', date('YmdHis'), bin2hex(random_bytes(3)), $ext);
  $dest = $uploadsDir . '/' . $name;

  if (!move_uploaded_file($f['tmp_name'], $dest)) {
    fail('failed to save file', 500);
  }
  respond(['url' => '/uploads/' . $name]);
}

if ($id === 'order' && $sub !== null) {
  $orderId = (int)$sub;
  $b = body();
  if ($method === 'POST') {
    $url = (string)($b['url'] ?? '');
    if ($url === '') fail('url required', 400);
    $stmt = $db->prepare('INSERT INTO order_photos (order_id, photo_path) VALUES (?, ?)');
    $stmt->execute([$orderId, $url]);
    respond(['id' => (int)$db->lastInsertId()]);
  }
  if ($method === 'DELETE') {
    $url = (string)($b['url'] ?? '');
    $stmt = $db->prepare('DELETE FROM order_photos WHERE order_id=? AND photo_path=?');
    $stmt->execute([$orderId, $url]);
    respond(['ok' => true]);
  }
}

fail('not found', 404);
