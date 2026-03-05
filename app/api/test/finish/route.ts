import { NextRequest, NextResponse } from 'next/server';
import { prisma } from '@/lib/db';
import { scoreAnswers } from '@/lib/scoring';
import { sendEmail, templates } from '@/lib/email';

export async function POST(req: NextRequest) {
  const { token } = await req.json();
  const session = await prisma.testSession.findUnique({ where: { token }, include: { answers: { include: { question: true } }, user: true } });
  if (!session) return NextResponse.json({ error: 'not found' }, { status: 404 });
  const sc = scoreAnswers(session.answers.map(a=>({mappingKey:a.question.mappingKey, value:a.value})));
  await prisma.result.upsert({ where: { sessionId: session.id }, update: { primaryIntelligence: sc.primary, secondaryIntelligence: sc.secondary, scoresJson: sc.scores }, create: { sessionId: session.id, primaryIntelligence: sc.primary, secondaryIntelligence: sc.secondary, scoresJson: sc.scores } });
  await prisma.testSession.update({ where: { id: session.id }, data: { status: 'completed', completedAt: new Date() } });
  await sendEmail(session.user.email, 'Мы получили ваши ответы', templates.finished);
  return NextResponse.json({ ok: true });
}
