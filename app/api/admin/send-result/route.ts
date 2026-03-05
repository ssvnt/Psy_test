import { NextRequest, NextResponse } from 'next/server';
import { prisma } from '@/lib/db';
import { sendEmail, templates } from '@/lib/email';

export async function POST(req: NextRequest) {
  const auth = req.headers.get('authorization') || '';
  const expected = 'Basic ' + Buffer.from(`${process.env.ADMIN_USER}:${process.env.ADMIN_PASS}`).toString('base64');
  if (auth !== expected) return NextResponse.json({ error: 'unauthorized' }, { status: 401 });
  const { sessionId, text } = await req.json();
  const session = await prisma.testSession.findUnique({ where: { id: sessionId }, include: { user: true, result: true } });
  if (!session || !session.result) return NextResponse.json({ error: 'not found' }, { status: 404 });
  await sendEmail(session.user.email, 'Результаты теста', templates.result(session.result.primaryIntelligence, session.result.secondaryIntelligence, text || 'Рекомендации внутри письма.'));
  await prisma.result.update({ where: { id: session.result.id }, data: { status: 'sent', emailedAt: new Date() } });
  return NextResponse.json({ ok: true });
}
