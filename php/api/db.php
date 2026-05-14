<?php

function db(): PDO {
  static $pdo = null;
  if ($pdo === null) {
    $pdo = new PDO(
      sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME),
      DB_USER, DB_PASSWORD,
      [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
      ]
    );
  }
  return $pdo;
}

// Parse JSON request body once and cache it.
function body(): array {
  static $cached = null;
  if ($cached === null) {
    $raw = file_get_contents('php://input');
    $cached = $raw ? (json_decode($raw, true) ?? []) : [];
  }
  return $cached;
}

function respond($data, int $code = 200): void {
  http_response_code($code);
  echo json_encode($data, JSON_UNESCAPED_UNICODE);
  exit;
}

function fail(string $msg, int $code = 400): void {
  respond(['error' => $msg], $code);
}

// ── Session / auth helpers ──────────────────────────────
function start_session(): void {
  if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
      'lifetime' => 60 * 60 * 24 * 14, // 14 days
      'path'     => '/',
      'secure'   => !empty($_SERVER['HTTPS']),
      'httponly' => true,
      'samesite' => 'Lax',
    ]);
    session_start();
  }
}

function current_user(): ?array {
  start_session();
  if (empty($_SESSION['user_id'])) return null;
  static $u = null;
  if ($u !== null) return $u;
  $stmt = db()->prepare('SELECT id, username, role, display_name FROM users WHERE id=?');
  $stmt->execute([(int)$_SESSION['user_id']]);
  $u = $stmt->fetch() ?: null;
  return $u;
}

function require_login(): array {
  $u = current_user();
  if (!$u) fail('not authenticated', 401);
  return $u;
}

function require_admin(): array {
  $u = require_login();
  if ($u['role'] !== 'admin') fail('admin only', 403);
  return $u;
}
