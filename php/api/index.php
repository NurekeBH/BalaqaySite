<?php
// Front controller for /api/* — all requests come here via .htaccess rewrite.
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(204);
  exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$path   = trim($_GET['route'] ?? '', '/');
$parts  = $path === '' ? [] : explode('/', $path);

$resource = $parts[0] ?? '';
$id       = isset($parts[1]) && $parts[1] !== '' ? $parts[1] : null;
$sub      = $parts[2] ?? null;

try {
  switch ($resource) {
    case 'health':
      respond(['ok' => true]);
    case 'orders':
      require __DIR__ . '/orders.php'; break;
    case 'payments':
      require __DIR__ . '/payments.php'; break;
    case 'photos':
      require __DIR__ . '/photos.php'; break;
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
