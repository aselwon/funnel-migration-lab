<?php
declare(strict_types=1);
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (str_starts_with($path, '/api/')) { require __DIR__.'/../src/api.php'; return true; }
if ($path === '/') { header('Location: /legacy/'); return true; }
if ($path === '/app' || str_starts_with($path, '/app/')) {
    if (str_starts_with($path, '/app/assets/') && is_file(__DIR__.$path) && realpath(__DIR__.$path) && str_starts_with(realpath(__DIR__.$path), __DIR__.'/app/assets/')) return false;
    if (!is_file(__DIR__.'/app/index.html')) { http_response_code(503); echo 'React build unavailable. Use hybrid build or npm run build in frontend.'; return true; }
    header('Content-Type: text/html; charset=utf-8'); readfile(__DIR__.'/app/index.html'); return true;
}
if ($path === '/legacy/') { require __DIR__.'/legacy/index.php'; return true; }
if (in_array($path, ['/legacy/index.php','/legacy/signup.php','/legacy/payment.php','/legacy/admin.php'], true)) { require __DIR__.$path; return true; }
http_response_code(404); echo 'Not found'; return true;
