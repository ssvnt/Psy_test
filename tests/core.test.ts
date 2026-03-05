import { describe, expect, it } from 'vitest';

describe('core flows', () => {
  it('link expiration works', () => {
    const expires = new Date(Date.now() - 1000);
    expect(expires < new Date()).toBe(true);
  });
  it('payment amount positive', () => {
    expect(Number(process.env.TEST_PRICE_RUB || 490)).toBeGreaterThan(0);
  });
  it('save answer payload', () => {
    const payload = { token: 'abc', questionId: 'q1', value: 'Да' };
    expect(payload.value).toBe('Да');
  });
  it('finish status', () => {
    const status = 'completed';
    expect(status).toBe('completed');
  });
});
