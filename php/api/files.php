<?php
// /api/files/upload    multipart POST, field "file"   → { url, name, size, mime }
// /api/files/order/:orderId   POST { url, name, size, mime }  /  DELETE { url }

$db = db();
$uploadsDir = __DIR__ . '/../uploads';
if (!is_dir($uploadsDir)) @mkdir($uploadsDir, 0755, true);

const ALLOWED_EXT = ['pdf','xls','xlsx','doc','docx','csv','txt','ppt','pptx','rtf','zip'];

if ($method === 'POST' && $id === 'upload') {
  if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    fail('no file', 400);
  }
  $f = $_FILES['file'];
  if ($f['size'] > 20 * 1024 * 1024) fail('file too large (max 20 MB)', 400);

  $origName = $f['name'];
  $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
  if (!in_array($ext, ALLOWED_EXT, true)) {
    fail('file type not allowed: .' . $ext, 400);
  }

  $safeExt = preg_replace('/[^a-z0-9]/', '', $ext);
  $stored = sprintf('%s-%s.%s', date('YmdHis'), bin2hex(random_bytes(3)), $safeExt);
  $dest   = $uploadsDir . '/' . $stored;
  if (!move_uploaded_file($f['tmp_name'], $dest)) {
    fail('failed to save file', 500);
  }
  respond([
    'url'  => '/uploads/' . $stored,
    'name' => $origName,
    'size' => (int)$f['size'],
    'mime' => $f['type'] ?? '',
  ]);
}

if ($id === 'order' && $sub !== null) {
  $orderId = (int)$sub;
  $b = body();
  if ($method === 'POST') {
    $url  = (string)($b['url']  ?? '');
    $name = (string)($b['name'] ?? '');
    $size = (int)($b['size']    ?? 0);
    $mime = (string)($b['mime'] ?? '');
    if ($url === '' || $name === '') fail('url + name required', 400);
    $stmt = $db->prepare('INSERT INTO order_files (order_id, file_path, file_name, file_size, mime_type) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$orderId, $url, $name, $size, $mime]);
    respond(['id' => (int)$db->lastInsertId()]);
  }
  if ($method === 'DELETE') {
    $url = (string)($b['url'] ?? '');
    $stmt = $db->prepare('DELETE FROM order_files WHERE order_id=? AND file_path=?');
    $stmt->execute([$orderId, $url]);
    respond(['ok' => true]);
  }
}

fail('not found', 404);
