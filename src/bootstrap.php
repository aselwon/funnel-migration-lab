<?php
declare(strict_types=1);
session_start(['cookie_httponly' => true, 'cookie_samesite' => 'Lax', 'cookie_secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off']);
$_SESSION['owner'] ??= bin2hex(random_bytes(24));
$_SESSION['csrf'] ??= bin2hex(random_bytes(24));
function db(): PDO {
    static $pdo;
    return $pdo ??= new PDO('mysql:host='.(getenv('DB_HOST') ?: '127.0.0.1').';dbname='.(getenv('DB_NAME') ?: 'sellerboost').';charset=utf8mb4', getenv('DB_USER') ?: 'sellerboost', getenv('DB_PASSWORD') ?: 'local-demo-password', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
}
function query(string $sql, array $args = []): PDOStatement { $s = db()->prepare($sql); $s->execute($args); return $s; }
function e(mixed $value): string { return htmlspecialchars(is_scalar($value) ? (string)$value : '', ENT_QUOTES, 'UTF-8'); }
function migrated(): bool { return getenv('MIGRATE_CHECKOUT') === '1'; }
function csrf(): void {
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['csrf'], $token)) { throw new DomainException('Invalid CSRF token', 403); }
}
function fields(array $data): array {
    foreach (['name', 'email', 'plan'] as $key) if (!isset($data[$key]) || !is_string($data[$key])) throw new DomainException('Name, email and plan are required.', 422);
    $name = trim($data['name']); $email = trim($data['email']); $plan = $data['plan'];
    if ($name === '' || strlen($name) > 100 || strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL) || !in_array($plan, ['free', 'paid'], true)) throw new DomainException('Enter a name, valid email and free or paid plan.', 422);
    return [$name, $email, $plan];
}
function lead(int $id): array {
    $row = query('SELECT id,name,email,plan,status,created_at FROM leads WHERE id=? AND owner=?', [$id, $_SESSION['owner']])->fetch();
    if (!$row) throw new DomainException('Lead not found.', 404);
    return $row;
}
function create_lead(array $data): array {
    [$name, $email, $plan] = fields($data);
    query('INSERT INTO leads (owner,name,email,plan,status) VALUES (?,?,?,?,?)', [$_SESSION['owner'], $name, $email, $plan, $plan === 'free' ? 'free' : 'pending']);
    return lead((int)db()->lastInsertId());
}
function checkout_url(int $id): string { return (migrated() ? '/app/checkout/' : '/legacy/payment.php?id=').$id; }
function checkout_intent(int $id): array {
    $row = lead($id);
    if ($row['plan'] !== 'paid') throw new DomainException('Free leads do not require payment.', 409);
    query('UPDATE leads SET intent=COALESCE(intent,?) WHERE id=? AND owner=?', [bin2hex(random_bytes(24)), $id, $_SESSION['owner']]);
    return ['intent' => query('SELECT intent FROM leads WHERE id=? AND owner=?', [$id, $_SESSION['owner']])->fetchColumn(), 'amount' => 2900, 'currency' => 'USD', 'status' => $row['status']];
}
function pay(int $id, mixed $intent): array {
    lead($id);
    $saved = query('SELECT intent FROM leads WHERE id=? AND owner=?', [$id, $_SESSION['owner']])->fetchColumn();
    if (!is_string($intent) || !$saved || !hash_equals($saved, $intent)) throw new DomainException('Invalid checkout intent.', 409);
    query("UPDATE leads SET status='paid' WHERE id=? AND owner=? AND plan='paid'", [$id, $_SESSION['owner']]);
    return lead($id);
}
function redirect(string $url): never { header('Location: '.$url, true, 303); exit; }
function legacy_header(string $title): void {
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>'.e($title).' | SellerBoost legacy</title><body bgcolor="#eeeeee"><main style="max-width:760px;margin:35px auto;font:18px Georgia;padding:20px;background:white"><nav><a href="/legacy/">Legacy home</a> | <a href="/legacy/admin.php">Admin</a> | <a href="/app/">React app</a></nav><hr><h1>'.e($title).'</h1>';
}
function legacy_footer(): void { echo '<hr><small>SellerBoost · Legacy PHP · Demo payments only</small></main></body></html>'; }
