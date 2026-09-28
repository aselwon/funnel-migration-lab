export type Lead = { id: number; name: string; email: string; plan: 'free' | 'paid'; status: 'free' | 'pending' | 'paid'; checkoutUrl?: string };
export type Session = { csrf: string; migrateCheckout: boolean };
let session: Promise<Session> | undefined;
export function getSession() {
  return session ??= fetch('/api/session', { credentials: 'same-origin' }).then(async r => { if (!r.ok) throw new Error('Cannot start your session. Please reload.'); return r.json() as Promise<Session>; }).catch(error => { session = undefined; throw error; });
}
export async function api<T>(path: string, method = 'GET', body?: unknown): Promise<T> {
  const { csrf } = await getSession();
  const res = await fetch('/api' + path, { method, credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, body: body === undefined ? undefined : JSON.stringify(body) });
  const data = await res.json();
  if (!res.ok) throw new Error(data.error || 'Something went wrong. Please retry.');
  return data as T;
}
