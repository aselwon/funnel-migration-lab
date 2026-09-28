<?php
require __DIR__.'/../../src/bootstrap.php';
$user = getenv('ADMIN_USER') ?: 'admin'; $password = getenv('ADMIN_PASSWORD') ?: 'local-admin-password';
if (!hash_equals($user, $_SERVER['PHP_AUTH_USER'] ?? '') || !hash_equals($password, $_SERVER['PHP_AUTH_PW'] ?? '')) {
    header('WWW-Authenticate: Basic realm="SellerBoost demo admin"'); http_response_code(401); echo 'Admin authentication required.'; exit;
}
legacy_header('All leads — shared database');
echo '<table border="1" cellpadding="8"><tr><th>ID</th><th>Name</th><th>Email</th><th>Plan</th><th>Status</th></tr>';
foreach (query('SELECT id,name,email,plan,status FROM leads ORDER BY id DESC LIMIT 200') as $row) { echo '<tr>'; foreach ($row as $value) echo '<td>'.e($value).'</td>'; echo '</tr>'; }
echo '</table><p>Latest 200 registrations from both interfaces.</p>'; legacy_footer();
