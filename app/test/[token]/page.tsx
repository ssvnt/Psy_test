import { prisma } from '@/lib/db';
import TestRunner from '@/components/TestRunner';

export default async function TestPage({ params }: { params: Promise<{token: string}> }) {
  const { token } = await params;
  const session = await prisma.testSession.findUnique({ where: { token }, include: { user: true } });
  if (!session || new Date() > session.expiresAt) return <main className='container-x py-12'><h1 className='text-2xl'>Ссылка недействительна или истекла</h1><a href='/checkout' className='btn-primary mt-4 inline-block'>Повторить оплату</a></main>;
  const questions = await prisma.question.findMany({ orderBy: { orderIndex: 'asc' }, take: 70 });
  return <main className='container-x py-8'>
    <h1 className='text-2xl font-semibold'>Как подготовиться к тестированию</h1>
    <details className='card mt-4'><summary>Открыть рекомендации</summary><ol className='list-decimal pl-6 mt-3 space-y-1'><li>Проходить в спокойной обстановке, без спешки.</li><li>Нет правильных/неправильных ответов.</li><li>Отвечайте честно: как чаще бывает.</li><li>Делайте паузы при усталости.</li><li>Не подсказывайте ребёнку.</li><li>20–30 секунд на вопрос.</li><li>В конце нажмите «Завершить».</li></ol></details>
    <TestRunner token={token} email={session.user.email} questions={questions.map(q=>({id:q.id,text:q.text,options:q.options as string[]}))}/>
  </main>;
}
