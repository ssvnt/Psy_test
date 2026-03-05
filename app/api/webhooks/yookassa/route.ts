import { NextRequest, NextResponse } from 'next/server';
import { prisma } from '@/lib/db';

export async function POST(req: NextRequest) {
  const auth = req.headers.get('authorization') || '';
  const expected = 'Basic ' + Buffer.from(`${process.env.WEBHOOK_BASIC_USER}:${process.env.WEBHOOK_BASIC_PASS}`).toString('base64');
  if (auth !== expected) return NextResponse.json({ error: 'unauthorized' }, { status: 401 });
  const payload = await req.json();
  await prisma.webhookLog.create({ data: { provider: 'yookassa', event: payload.event || 'unknown', payload } });
  return NextResponse.json({ ok: true });
}
