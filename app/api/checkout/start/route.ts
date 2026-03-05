import { NextRequest, NextResponse } from 'next/server';
import { z } from 'zod';
import { prisma } from '@/lib/db';
import { createPayment } from '@/lib/payments';
import { rateLimit } from '@/lib/security';
import crypto from 'crypto';
import { sendEmail, templates } from '@/lib/email';

const schema = z.object({ name:z.string().min(2), email:z.string().email(), phone:z.string().optional(), city:z.string().optional(), childName:z.string().optional(), childAge:z.coerce.number().min(5).max(18), marketingConsent:z.any().optional() });

export async function POST(req: NextRequest) {
  if (!rateLimit(req.headers.get('x-forwarded-for') || 'local')) return NextResponse.json({ error: 'Слишком много попыток' }, { status: 429 });
  const data = schema.parse(await req.json());
  const existing = await prisma.user.findUnique({ where: { email: data.email } });
  if (existing) {
    const active = await prisma.testSession.findFirst({ where: { userId: existing.id, status: 'active', expiresAt: { gt: new Date() } } });
    if (active) return NextResponse.json({ redirectUrl: `${process.env.APP_URL}/test/${active.token}` });
  }
  const user = existing ?? await prisma.user.create({ data: { name: data.name, email: data.email, phone: data.phone, city: data.city, marketingConsent: Boolean(data.marketingConsent) } });
  const child = await prisma.child.create({ data: { userId: user.id, name: data.childName || null, age: data.childAge } });
  const order = await prisma.order.create({ data: { userId: user.id, amount: Number(process.env.TEST_PRICE_RUB || 490), provider: 'yookassa' } });
  const payment = await createPayment(order.id, order.amount);
  await prisma.order.update({ where: { id: order.id }, data: { provider: payment.provider, providerPaymentId: crypto.randomUUID(), status: 'paid', paidAt: new Date() } });
  const token = crypto.randomBytes(24).toString('base64url');
  const session = await prisma.testSession.create({ data: { userId: user.id, childId: child.id, token, expiresAt: new Date(Date.now() + 7*24*3600*1000) } });
  await sendEmail(user.email, 'Ваша ссылка на тест (активна 7 дней)', templates.paidLink(`${process.env.APP_URL}/test/${token}`));
  return NextResponse.json({ redirectUrl: `${process.env.APP_URL}/checkout/success?order=${order.id}`, sessionId: session.id });
}
