<?php
// Local dev router for PHP built-in server.
// Run: php -S localhost:8080 router.php
//
// Simulates the .htaccess rewriting that Apache does on Plesk.

$uri  = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$root = __DIR__;

// API routes → api/index.php?route=…
if (str_starts_with($uri, '/api/') || $uri === '/api') {
  $_GET['route'] = trim(substr($uri, 4), '/');
  require __DIR__ . '/api/index.php';
  return true;
}

// /uploads/xxx.jpg → serve from disk
if (str_starts_with($uri, '/uploads/')) {
  $file = $root . $uri;
  if (is_file($file)) return false; // let the built-in server serve the static file
  http_response_code(404);
  return true;
}

// Frontend at /  → serve frontend/index.html
if ($uri === '/' || $uri === '/index.html') {
  readfile($root . '/../frontend/index.html');
  return true;
}
if ($uri === '/i18n.js') {
  header('Content-Type: application/javascript');
  readfile($root . '/../frontend/i18n.js');
  return true;
}

http_response_code(404);
echo '404';
return true;
