const map = new Map<string, {count: number; ts: number}>();
export function rateLimit(key: string, limit = 20, windowMs = 60000) {
  const now = Date.now();
  const row = map.get(key);
  if (!row || now - row.ts > windowMs) {
    map.set(key, { count: 1, ts: now });
    return true;
  }
  if (row.count >= limit) return false;
  row.count += 1;
  return true;
}

export const redactPII = (text: string) => text.replace(/([\w.-]+)@([\w.-]+)/g, '***@$2');
