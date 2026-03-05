'use client';
import { useEffect, useState } from 'react';

type Q = { id: string; text: string; options: string[] };
export default function TestRunner({ token, questions, email }: { token: string; questions: Q[]; email: string }) {
  const [i,setI]=useState(0); const [answers,setAnswers]=useState<Record<string,string>>({}); const [done,setDone]=useState(false);
  useEffect(()=>{ const iv=setInterval(()=>saveDraft(),10000); return ()=>clearInterval(iv); });
  async function saveDraft(){
    const q=questions[i]; if(!q||!answers[q.id]) return;
    await fetch('/api/test/answer',{method:'POST',body:JSON.stringify({token,questionId:q.id,value:answers[q.id]})});
  }
  async function finish(){
    await Promise.all(Object.entries(answers).map(([questionId,value])=>fetch('/api/test/answer',{method:'POST',body:JSON.stringify({token,questionId,value})})));
    await fetch('/api/test/finish',{method:'POST',body:JSON.stringify({token})});
    setDone(true);
  }
  if(done) return <section className='card mt-6'><h2 className='text-xl'>Спасибо! Мы получили ваши ответы.</h2><p>Результаты теста придут вам на почту: {email}</p><p>{process.env.NEXT_PUBLIC_RESULT_SLA_TEXT || 'в течение 24 часов'}</p><a href='/' className='btn-primary mt-3 inline-block'>Вернуться на главную</a></section>
  const q=questions[i];
  return <section className='card mt-6'><p>Вопрос {i+1} из {questions.length}</p><h3 className='text-lg my-3'>{q.text}</h3><div className='flex gap-3'>{q.options.map((o)=><button aria-label={o} key={o} className={`btn border ${answers[q.id]===o?'bg-lime':''}`} onClick={()=>setAnswers({...answers,[q.id]:o})}>{o}</button>)}</div>
  <div className='mt-4 flex gap-3'><button className='btn border' onClick={()=>setI(Math.max(0,i-1))}>Назад</button><button className='btn border' onClick={()=>setI(Math.min(questions.length-1,i+1))}>Далее</button><button className='btn border' onClick={saveDraft}>Сохранить и выйти</button>{i===questions.length-1 && <button className='btn-primary' onClick={()=>confirm('Вы уверены?')&&finish()}>Завершить</button>}</div></section>
}
