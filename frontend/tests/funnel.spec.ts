import { test, expect } from '@playwright/test';

test.afterEach(async ({ page }) => {
  const request = page.request;
  const session = await (await request.get('/api/session')).json();
  const rows = await (await request.get('/api/leads')).json();
  for (const row of rows) await request.delete('/api/leads/'+row.id, { headers: { 'X-CSRF-Token': session.csrf } });
});

test('React paid funnel, refresh and shared legacy admin', async ({ page, baseURL }) => {
  const request = page.request;
  await page.goto('/app/'); await expect(page.getByRole('heading', { name: 'Start small. Sell smarter.' })).toBeVisible();
  await page.getByRole('link', { name: 'Choose Pro' }).click();
  await page.getByLabel('Your name').fill('React Seller'); await page.getByLabel('Email address').fill('react@example.com');
  await page.getByRole('button', { name: 'Continue' }).click();
  const session = await (await request.get('/api/session')).json();
  await expect(page).toHaveURL(session.migrateCheckout ? /\/app\/checkout\/\d+/ : /\/legacy\/payment.php\?id=\d+/);
  await page.getByRole('button', { name: 'Simulate payment' }).click();
  await expect(page.getByText('Status: paid', { exact: false })).toBeVisible();
  await page.reload(); await expect(page.getByText('Status: paid', { exact: false })).toBeVisible();
  const auth = Buffer.from(`${process.env.ADMIN_USER || 'admin'}:${process.env.ADMIN_PASSWORD || 'local-admin-password'}`).toString('base64');
  const admin = await request.get(`${baseURL}/legacy/admin.php`, { headers: { Authorization: `Basic ${auth}` } });
  expect(admin.status()).toBe(200); expect(await admin.text()).toContain('react@example.com');
});

test('Legacy signup is visible through API and React; free needs no payment', async ({ page }) => {
  const request = page.request;
  await page.goto('/legacy/'); await page.getByRole('link', { name: 'Get started' }).click();
  await page.getByLabel('Name', { exact: true }).fill('Legacy Seller'); await page.getByLabel('Email', { exact: true }).fill('legacy@example.com');
  await page.getByRole('button', { name: 'Continue' }).click();
  await expect(page.getByText('Status: free', { exact: false })).toBeVisible();
  const rows = await (await request.get('/api/leads')).json(); expect(rows[0].name).toBe('Legacy Seller');
  await page.goto('/app/checkout/'+rows[0].id); await expect(page.getByText('Status: free', { exact: false })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Simulate payment' })).toHaveCount(0);
});

test('React free signup and checkout error state', async ({ page }) => {
  await page.goto('/app/signup?plan=free'); await page.getByLabel('Your name').fill('Free Seller'); await page.getByLabel('Email address').fill('free@example.com');
  await page.getByRole('button', { name: 'Continue' }).click(); await expect(page.getByText('Status: free', { exact: false })).toBeVisible();
  await page.goto('/app/checkout/999999999'); await expect(page.getByRole('alert')).toHaveText('Lead not found.');
});
