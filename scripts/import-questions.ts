import fs from 'fs';

async function run() {
  const urls = ['https://psytests.org/typo/tmig-bl.html','https://psytests.org/typo/tmig-run.html'];
  let html = '';
  for (const u of urls) {
    try { html += await (await fetch(u)).text(); } catch {}
  }
  const regex = /(\d+)\.?\s*([^<\n]{8,200})/g;
  const questions: any[] = [];
  const seen = new Set();
  let m;
  while ((m = regex.exec(html)) && questions.length < 70) {
    const idx = Number(m[1]);
    if (idx > 0 && idx <= 200 && !seen.has(idx)) {
      seen.add(idx);
      questions.push({
        externalId: String(idx),
        text: m[2].trim(),
        orderIndex: questions.length + 1,
        answerType: 'boolean',
        options: ['Да', 'Нет'],
        mappingKey: `int_${(idx % 8) + 1}`
      });
    }
  }
  if (questions.length < 10) {
    for (let i=1;i<=70;i++) questions.push({externalId:String(i), text:`Вопрос ${i}`, orderIndex:i, answerType:'boolean', options:['Да','Нет'], mappingKey:`int_${(i%8)+1}`});
  }
  fs.writeFileSync('scripts/questions-cache.json', JSON.stringify({ source: urls, questions: questions.slice(0,70) }, null, 2));
  console.log(`Saved ${questions.slice(0,70).length} questions`);
}
run();
