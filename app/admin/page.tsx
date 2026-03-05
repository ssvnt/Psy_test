import { prisma } from '@/lib/db';

export default async function Admin(){
  const orders = await prisma.order.findMany({ take: 20, orderBy: { createdAt: 'desc' }, include: { user: true } });
  const sessions = await prisma.testSession.findMany({ take: 20, orderBy: { expiresAt: 'desc' }, include: { user: true } });
  return <main className='container-x py-10'><h1 className='text-2xl font-semibold'>Админка</h1>
  <h2 className='mt-6 font-semibold'>Orders</h2><table className='w-full text-sm'><tbody>{orders.map(o=><tr key={o.id}><td>{o.status}</td><td>{o.user.email}</td><td>{o.amount}</td></tr>)}</tbody></table>
  <h2 className='mt-6 font-semibold'>Sessions</h2><table className='w-full text-sm'><tbody>{sessions.map(s=><tr key={s.id}><td>{s.status}</td><td>{s.user.email}</td><td>{new Date(s.expiresAt).toLocaleString('ru-RU')}</td></tr>)}</tbody></table>
  </main>
}
