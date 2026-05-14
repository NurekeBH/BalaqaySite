<?php
// /api/users (admin only)

require_admin();
$db = db();

if ($method === 'GET' && $id === null) {
  $rows = $db->query('SELECT id, username, role, display_name, created_at FROM users ORDER BY id ASC')->fetchAll();
  respond(array_map(fn($r) => [
    'id' => (int)$r['id'],
    'username' => $r['username'],
    'role' => $r['role'],
    'displayName' => $r['display_name'] ?? '',
    'createdAt' => $r['created_at'],
  ], $rows));
}

if ($method === 'POST' && $id === null) {
  $b = body();
  $username = trim((string)($b['username'] ?? ''));
  $password = (string)($b['password'] ?? '');
  $role     = $b['role'] ?? 'user';
  $display  = trim((string)($b['displayName'] ?? ''));
  if ($username === '' || $password === '') fail('username + password required', 400);
  if (!in_array($role, ['admin', 'user'], true)) fail('invalid role', 400);
  if (strlen($password) < 6) fail('password must be at least 6 chars', 400);

  try {
    $stmt = $db->prepare('INSERT INTO users (username, password_hash, role, display_name) VALUES (?, ?, ?, ?)');
    $stmt->execute([$username, password_hash($password, PASSWORD_BCRYPT), $role, $display]);
    respond(['id' => (int)$db->lastInsertId()]);
  } catch (PDOException $e) {
    if (($e->errorInfo[1] ?? 0) === 1062) fail('username already exists', 409);
    throw $e;
  }
}

if ($method === 'PUT' && $id !== null) {
  $b = body();
  $fields = []; $vals = [];
  if (array_key_exists('role', $b)) {
    if (!in_array($b['role'], ['admin', 'user'], true)) fail('invalid role', 400);
    $fields[] = 'role=?'; $vals[] = $b['role'];
  }
  if (array_key_exists('displayName', $b)) {
    $fields[] = 'display_name=?'; $vals[] = (string)$b['displayName'];
  }
  if (array_key_exists('password', $b) && $b['password'] !== '') {
    if (strlen($b['password']) < 6) fail('password must be at least 6 chars', 400);
    $fields[] = 'password_hash=?'; $vals[] = password_hash($b['password'], PASSWORD_BCRYPT);
  }
  if ($fields) {
    $vals[] = (int)$id;
    $stmt = $db->prepare('UPDATE users SET ' . implode(', ', $fields) . ' WHERE id=?');
    $stmt->execute($vals);
  }
  respond(['ok' => true]);
}

if ($method === 'DELETE' && $id !== null) {
  $me = current_user();
  if ((int)$me['id'] === (int)$id) fail('cannot delete yourself', 400);
  // Don't allow deleting the last admin
  $stmt = $db->prepare("SELECT COUNT(*) AS n FROM users WHERE role='admin' AND id != ?");
  $stmt->execute([(int)$id]);
  if ((int)$stmt->fetch()['n'] < 1) {
    $stmt = $db->prepare('SELECT role FROM users WHERE id=?');
    $stmt->execute([(int)$id]);
    $r = $stmt->fetch();
    if ($r && $r['role'] === 'admin') fail('cannot delete the last admin', 400);
  }
  $stmt = $db->prepare('DELETE FROM users WHERE id=?');
  $stmt->execute([(int)$id]);
  respond(['ok' => true]);
}

fail('not found', 404);
