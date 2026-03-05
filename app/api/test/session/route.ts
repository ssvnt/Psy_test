import { NextRequest, NextResponse } from 'next/server';
import { prisma } from '@/lib/db';

export async function GET(req: NextRequest) {
  const token = req.nextUrl.searchParams.get('token');
  if (!token) return NextResponse.json({ error: 'token required' }, { status: 400 });
  const session = await prisma.testSession.findUnique({ where: { token }, include: { user: true } });
  if (!session || session.expiresAt < new Date()) return NextResponse.json({ valid: false });
  return NextResponse.json({ valid: true, email: session.user.email });
}
