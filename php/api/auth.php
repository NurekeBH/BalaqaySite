<?php
// /api/auth/login, /api/auth/logout, /api/auth/me

$db = db();

if ($method === 'GET' && $id === 'me') {
  $u = current_user();
  if (!$u) respond(['authenticated' => false]);
  respond(['authenticated' => true, 'user' => $u]);
}

if ($method === 'POST' && $id === 'login') {
  $b = body();
  $username = trim((string)($b['username'] ?? ''));
  $password = (string)($b['password'] ?? '');
  if ($username === '' || $password === '') fail('username + password required', 400);

  $stmt = $db->prepare('SELECT * FROM users WHERE username=?');
  $stmt->execute([$username]);
  $u = $stmt->fetch();
  if (!$u || !password_verify($password, $u['password_hash'])) {
    fail('invalid credentials', 401);
  }
  start_session();
  $_SESSION['user_id'] = (int)$u['id'];
  respond(['authenticated' => true, 'user' => [
    'id' => (int)$u['id'],
    'username' => $u['username'],
    'role' => $u['role'],
    'display_name' => $u['display_name'],
  ]]);
}

if ($method === 'POST' && $id === 'logout') {
  start_session();
  $_SESSION = [];
  if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
  }
  session_destroy();
  respond(['ok' => true]);
}

fail('not found', 404);
