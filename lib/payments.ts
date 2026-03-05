export async function createPayment(orderId: string, amount: number) {
  if (process.env.STRIPE_SECRET_KEY) return { provider: 'stripe', redirectUrl: `${process.env.APP_URL}/checkout/success?order=${orderId}` };
  return { provider: 'yookassa', redirectUrl: `${process.env.APP_URL}/checkout/success?order=${orderId}` };
}
