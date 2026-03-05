import { prisma } from '@/lib/db';
import { ruDate } from '@/lib/utils';

export default async function Success({ searchParams }: { searchParams: Promise<{order?: string}>}) {
  const sp = await searchParams;
  const orderId = sp.order;
  const order = orderId ? await prisma.order.findUnique({ where: { id: orderId } }) : null;
  const session = order ? await prisma.testSession.findFirst({ where: { userId: order.userId }, orderBy: { createdAt: 'desc' as any } }) : null;
  return <main className='container-x py-12'><h1 className='text-2xl font-semibold'>Оплата прошла. Ссылка отправлена на ваш email.</h1>
  {session && <p className='mt-4'>Ссылка активна до: {ruDate(session.expiresAt)}</p>}
  {session && <a className='btn-primary mt-4 inline-block' href={`/test/${session.token}`}>Перейти к тесту сейчас</a>}
  </main>;
}
