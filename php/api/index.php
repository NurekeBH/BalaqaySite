<?php
// Front controller for /api/* — all requests come here via .htaccess rewrite.
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');
// Same-origin in production; permissive for local dev.
header('Access-Control-Allow-Origin: ' . ($_SERVER['HTTP_ORIGIN'] ?? '*'));
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(204);
  exit;
}

start_session();

$method = $_SERVER['REQUEST_METHOD'];
$path   = trim($_GET['route'] ?? '', '/');
$parts  = $path === '' ? [] : explode('/', $path);

$resource = $parts[0] ?? '';
$id       = isset($parts[1]) && $parts[1] !== '' ? $parts[1] : null;
$sub      = $parts[2] ?? null;

// ── Auth gate ──────────────────────────────────────────
// Public: health + auth endpoints. Everything else requires a login.
// Write operations (POST/PUT/DELETE) outside /auth + /users require admin.
$isAuthEndpoint = ($resource === 'auth');
$isPublic       = ($resource === 'health') || $isAuthEndpoint;

if (!$isPublic) {
  $me = current_user();
  if (!$me) fail('not authenticated', 401);
  // Write-protect everything except users (which handles its own admin check)
  if ($resource !== 'users' && $method !== 'GET' && $me['role'] !== 'admin') {
    fail('admin only', 403);
  }
}

try {
  switch ($resource) {
    case 'health':
      respond(['ok' => true]);
    case 'auth':
      require __DIR__ . '/auth.php'; break;
    case 'users':
      require __DIR__ . '/users.php'; break;
    case 'orders':
      require __DIR__ . '/orders.php'; break;
    case 'payments':
      require __DIR__ . '/payments.php'; break;
    case 'photos':
      require __DIR__ . '/photos.php'; break;
    case 'files':
      require __DIR__ . '/files.php'; break;
    case 'categories':
      require __DIR__ . '/categories.php'; break;
    case 'settings':
      require __DIR__ . '/settings.php'; break;
    case 'suppliers':
      require __DIR__ . '/suppliers.php'; break;
    default:
      fail('not found', 404);
  }
} catch (Throwable $e) {
  fail($e->getMessage(), 500);
}
