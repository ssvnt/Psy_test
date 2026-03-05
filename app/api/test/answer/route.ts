import { NextRequest, NextResponse } from 'next/server';
import { z } from 'zod';
import { prisma } from '@/lib/db';

const schema = z.object({ token: z.string(), questionId: z.string(), value: z.string().min(1).max(20) });

export async function POST(req: NextRequest) {
  const { token, questionId, value } = schema.parse(await req.json());
  const session = await prisma.testSession.findUnique({ where: { token } });
  if (!session || session.expiresAt < new Date()) return NextResponse.json({ error: 'invalid session' }, { status: 400 });
  await prisma.answer.upsert({ where: { sessionId_questionId: { sessionId: session.id, questionId } }, update: { value }, create: { sessionId: session.id, questionId, value } });
  return NextResponse.json({ ok: true });
}
