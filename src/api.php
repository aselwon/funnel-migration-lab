<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function json_response(mixed $data, int $code = 200): never { http_response_code($code); echo json_encode($data, JSON_THROW_ON_ERROR); exit; }
try {
    $method = $_SERVER['REQUEST_METHOD']; $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if ($method === 'GET' && $path === '/api/session') json_response(['csrf' => $_SESSION['csrf'], 'migrateCheckout' => migrated()]);
    $data = [];
    if (!in_array($method, ['GET', 'HEAD'], true)) {
        csrf();
        $raw = file_get_contents('php://input');
        $data = $raw === '' ? [] : json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($data)) throw new DomainException('JSON object required.', 422);
    }
    if ($path === '/api/leads') {
        if ($method === 'GET') json_response(query('SELECT id,name,email,plan,status,created_at FROM leads WHERE owner=? ORDER BY id DESC', [$_SESSION['owner']])->fetchAll());
        if ($method === 'POST') { $row = create_lead($data); json_response($row + ['checkoutUrl' => checkout_url((int)$row['id'])], 201); }
    }
    if (preg_match('#^/api/leads/([1-9][0-9]*)(?:/(intent|pay))?$#', $path, $match)) {
        $id = (int)$match[1]; $action = $match[2] ?? '';
        if ($action === 'intent' && $method === 'POST') json_response(checkout_intent($id));
        if ($action === 'pay' && $method === 'POST') json_response(pay($id, $data['intent'] ?? null));
        if ($action === '') {
            $row = lead($id);
            if ($method === 'GET') json_response($row);
            if ($method === 'DELETE') { query('DELETE FROM leads WHERE id=? AND owner=?', [$id, $_SESSION['owner']]); json_response(['deleted' => true]); }
            if ($method === 'PATCH') {
                [$name, $email, $plan] = fields(array_merge($row, $data));
                if ($plan !== $row['plan']) throw new DomainException('Plan cannot change after signup.', 409);
                query('UPDATE leads SET name=?,email=? WHERE id=? AND owner=?', [$name, $email, $id, $_SESSION['owner']]); json_response(lead($id));
            }
        }
    }
    json_response(['error' => 'Route or method not found.'], 404);
} catch (JsonException $ex) { json_response(['error' => 'Malformed JSON.'], 400); }
catch (DomainException $ex) { json_response(['error' => $ex->getMessage()], $ex->getCode() ?: 422); }
catch (Throwable $ex) { error_log((string)$ex); json_response(['error' => 'Service unavailable. Please retry.'], 503); }
