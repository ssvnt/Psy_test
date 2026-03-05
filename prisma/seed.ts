import { PrismaClient } from '@prisma/client';
import fs from 'fs';
const prisma = new PrismaClient();

async function main() {
  const p = 'scripts/questions-cache.json';
  const fallback = fs.existsSync(p) ? JSON.parse(fs.readFileSync(p, 'utf-8')) : {
    questions: Array.from({length:70}).map((_,i)=>({externalId:String(i+1), text:`Вопрос ${i+1}`, orderIndex:i+1, answerType:'boolean', options:['Да','Нет'], mappingKey:`int_${(i%8)+1}`}))
  };
  await prisma.question.deleteMany();
  await prisma.question.createMany({ data: fallback.questions.map((q:any)=>({ ...q, options: q.options })) });
}
main().finally(()=>prisma.$disconnect());
