<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;

final class FunnelTest extends TestCase {
    private string $cookie = '';
    private string $csrf = '';
    private array $ids = [];
    private function request(string $path, string $method = 'GET', ?array $data = null, bool $form = false, bool $token = true, string $auth = ''): array {
        $headers = ['Cookie: '.$this->cookie, 'Content-Type: '.($form ? 'application/x-www-form-urlencoded' : 'application/json')];
        if ($token) $headers[] = 'X-CSRF-Token: '.$this->csrf;
        if ($auth !== '') $headers[] = 'Authorization: Basic '.base64_encode($auth);
        $context = stream_context_create(['http' => ['method' => $method, 'header' => implode("\r\n", $headers), 'content' => $data === null ? '' : ($form ? http_build_query($data) : json_encode($data)), 'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 10]]);
        $body = file_get_contents((getenv('TEST_BASE_URL') ?: 'http://127.0.0.1:18081').$path, false, $context);
        $responseHeaders = $http_response_header;
        preg_match('/\s(\d{3})\s/', $responseHeaders[0], $status);
        foreach ($responseHeaders as $header) if (preg_match('/^Set-Cookie: (PHPSESSID=[^;]+)/i', $header, $match)) $this->cookie = $match[1];
        return ['status' => (int)$status[1], 'body' => $body, 'json' => json_decode($body, true), 'headers' => implode("\n", $responseHeaders)];
    }
    protected function setUp(): void { $r = $this->request('/api/session'); self::assertSame(200, $r['status']); $this->csrf = $r['json']['csrf']; }
    protected function tearDown(): void { foreach ($this->ids as $id) $this->request('/api/leads/'.$id, 'DELETE'); }
    private function create(string $plan = 'paid'): array {
        $r = $this->request('/api/leads', 'POST', ['name' => 'Test Seller', 'email' => 'seller@example.com', 'plan' => $plan]);
        self::assertSame(201, $r['status'], $r['body']); $this->ids[] = $r['json']['id']; return $r['json'];
    }
    public function testLegacyCharacterizationAndSessionBridge(): void {
        $home = $this->request('/legacy/'); self::assertSame(200, $home['status']); self::assertStringContainsString('Get started', $home['body']);
        $form = $this->request('/legacy/signup.php'); self::assertStringContainsString('name="csrf"', $form['body']);
        $bad = $this->request('/legacy/signup.php', 'POST', ['name' => '', 'email' => 'bad', 'plan' => 'free'], true); self::assertSame(422, $bad['status']);
        foreach (['free', 'paid'] as $plan) {
            $signup = $this->request('/legacy/signup.php', 'POST', ['name' => 'Legacy <Seller>', 'email' => 'legacy@example.com', 'plan' => $plan], true);
            self::assertSame(303, $signup['status']);
            $rows = $this->request('/api/leads')['json']; $row = $rows[0]; $this->ids[] = $row['id'];
            self::assertSame('Legacy <Seller>', $row['name']); self::assertSame($plan === 'paid' ? 'pending' : 'free', $row['status']);
            $flag = $this->request('/api/session')['json']['migrateCheckout'];
            self::assertStringContainsString('Location: '.($flag ? '/app/checkout/' : '/legacy/payment.php?id=').$row['id'], $signup['headers']);
            if (!$flag) {
                $checkout = $this->request('/legacy/payment.php?id='.$row['id']);
                self::assertStringContainsString('Legacy &lt;Seller&gt;', $checkout['body']);
                if ($plan === 'paid') {
                    preg_match('/name="intent" value="([^"]+)"/', $checkout['body'], $match);
                    $paid = $this->request('/legacy/payment.php?id='.$row['id'], 'POST', ['intent' => $match[1]], true);
                    self::assertSame(303, $paid['status']); self::assertSame('paid', $this->request('/api/leads/'.$row['id'])['json']['status']);
                }
            }
        }
    }
    public function testApiCrudAndOwnership(): void {
        $row = $this->create('free'); $id = $row['id'];
        self::assertSame('free', $this->request('/api/leads/'.$id)['json']['status']);
        $updated = $this->request('/api/leads/'.$id, 'PATCH', ['name' => 'Updated Seller']); self::assertSame('Updated Seller', $updated['json']['name']);
        self::assertSame(409, $this->request('/api/leads/'.$id, 'PATCH', ['plan' => 'paid'])['status']);
        self::assertSame(409, $this->request('/api/leads/'.$id.'/intent', 'POST')['status']);
        $cookie = $this->cookie; $csrf = $this->csrf; $this->cookie = ''; $this->csrf = $this->request('/api/session')['json']['csrf'];
        foreach (['GET','PATCH','DELETE'] as $method) self::assertSame(404, $this->request('/api/leads/'.$id, $method, $method === 'PATCH' ? ['name'=>'Intruder'] : null)['status']);
        self::assertSame(404, $this->request('/api/leads/'.$id.'/intent', 'POST')['status']);
        self::assertSame([], $this->request('/api/leads')['json']);
        $this->cookie = $cookie; $this->csrf = $csrf;
        self::assertSame(200, $this->request('/api/leads/'.$id, 'DELETE')['status']);
        self::assertSame(404, $this->request('/api/leads/'.$id)['status']);
    }
    public function testIntentAndPaymentAreIdempotent(): void {
        $row = $this->create(); $path = '/api/leads/'.$row['id'];
        $intent = $this->request($path.'/intent', 'POST'); self::assertSame(2900, $intent['json']['amount']);
        self::assertSame($intent['json']['intent'], $this->request($path.'/intent', 'POST')['json']['intent']);
        self::assertSame(409, $this->request($path.'/pay', 'POST', ['intent' => 'wrong'])['status']);
        foreach ([1,2] as $_) self::assertSame('paid', $this->request($path.'/pay', 'POST', ['intent' => $intent['json']['intent']])['json']['status']);
    }
    public function testValidationCsrfAndAdminProtection(): void {
        self::assertSame(403, $this->request('/api/leads', 'POST', ['name'=>'A','email'=>'a@example.com','plan'=>'free'], false, false)['status']);
        self::assertSame(422, $this->request('/api/leads', 'POST', ['name'=>'A','email'=>'invalid','plan'=>'free'])['status']);
        self::assertSame(422, $this->request('/api/leads', 'POST', ['name'=>['array'],'email'=>'a@example.com','plan'=>'free'])['status']);
        self::assertSame(401, $this->request('/legacy/admin.php')['status']);
        $row = $this->create();
        $admin = $this->request('/legacy/admin.php', auth: (getenv('ADMIN_USER') ?: 'admin').':'.(getenv('ADMIN_PASSWORD') ?: 'local-admin-password'));
        self::assertSame(200, $admin['status']); self::assertStringContainsString('seller@example.com', $admin['body']);
    }
}
