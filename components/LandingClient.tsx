'use client';
import { useEffect, useRef, useState } from 'react';

export default function LandingClient() {
  const sectionRef = useRef<HTMLElement>(null);
  const aRef = useRef<HTMLAudioElement>(null);
  const bRef = useRef<HTMLAudioElement>(null);
  const [sound, setSound] = useState(false);
  const [mode, setMode] = useState<'default'|'calm'>('default');
  const [choice, setChoice] = useState<'red'|'blue'|null>(null);

  useEffect(() => {
    const obs = new IntersectionObserver(([entry]) => {
      const inverted = entry.isIntersecting;
      document.body.dataset.theme = inverted ? 'inverted' : 'default';
      if (sound) {
        setMode(inverted ? 'calm' : 'default');
        const a = aRef.current; const b = bRef.current;
        if (!a || !b) return;
        const fade = (from: HTMLAudioElement, to: HTMLAudioElement) => {
          to.play();
          let step = 0;
          const iv = setInterval(() => {
            step += 0.1;
            from.volume = Math.max(0, 1 - step);
            to.volume = Math.min(1, step);
            if (step >= 1) { from.pause(); clearInterval(iv); }
          }, 80);
        };
        inverted ? fade(a,b) : fade(b,a);
      }
    }, { threshold: 0.35 });
    if (sectionRef.current) obs.observe(sectionRef.current);
    return () => obs.disconnect();
  }, [sound]);

  return <>
    <audio ref={aRef} src="/audio/trackA.mp3" loop />
    <audio ref={bRef} src="/audio/trackB.mp3" loop />
    <button className='btn-accent fixed right-4 bottom-4 z-20' onClick={() => { setSound(!sound); if (!sound) { aRef.current?.play(); } else { aRef.current?.pause(); bRef.current?.pause(); }}}>{sound ? 'Звук: вкл' : 'Включить звук'} {mode==='calm'?'• спокойный режим':''}</button>
    <section className='container-x py-20' id='hero'><h1 className='text-4xl font-bold leading-tight'>Вы уверены в завтрашнем дне?<br/>Уверены ли вы, что сможете обеспечить счастливое будущее вашего ребёнка?<br/>Знаете ли вы, что предрасположенность к неудачам или успеху формируется в ранней школе?</h1><p className='mt-6 text-lg'>Раннее определение профиля сильных сторон помогает выбрать подходящую траекторию развития.</p><div className='mt-6 flex gap-3'><a className='btn-primary' href='/checkout'>Начать (регистрация и оплата)</a><a className='btn border' href='#steps'>Узнать как проходит тест</a></div></section>
    <section className='container-x py-12' id='why'><h2 className='text-2xl font-semibold'>Почему это важно</h2><p className='mt-4 text-[var(--muted)]'>Создатели сайта задавали себе те же вопросы и искали ответ. Мы увидели: когда понимаешь сильные стороны ребёнка, легче строить внешкольную жизнь и поддерживать уверенность. Это не медицинская диагностика, а инструмент понимания профиля сильных сторон.</p></section>
    <section ref={sectionRef} className='container-x py-14' id='solution'><h2 className='text-2xl font-semibold'>Как мы решили это для своих детей</h2><p className='mt-3'>Единственная опора в жизни человека — это опора внутри него самого. Мы убеждены, что раннее определение типа интеллекта даёт ориентир и более корректный профессиональный путь.</p></section>
    <section className='container-x py-12 grid md:grid-cols-2 gap-4'><div className='card'><div className='h-28 bg-slate-200 rounded mb-3'/><b>Путь 1</b><p>Учился не тому и не так, работает на нелюбимой работе.</p></div><div className='card'><div className='h-28 bg-lime/40 rounded mb-3'/><b>Путь 2</b><p>Развивал то, что даётся легко, и преуспел в жизни.</p></div></section>
    <section className='container-x py-12'><h3 className='text-xl font-semibold mb-4'>Матрица выбора</h3><div className='flex gap-3'><button className='btn bg-red-400 text-white' onClick={()=>setChoice('red')}>Красная таблетка</button><button className='btn bg-blue-500 text-white' onClick={()=>setChoice('blue')}>Синяя таблетка</button></div>{choice==='red'&&<p className='mt-3'>Ок, вы можете ничего не менять — но риски остаются. <a href='/checkout' className='underline'>Все равно узнать профиль интеллекта</a></p>}{choice==='blue'&&<p className='mt-3'>Вы делаете осознанный шаг. <a href='/checkout' className='underline'>Перейти к регистрации</a></p>}</section>
    <section className='container-x py-12' id='steps'><h3 className='text-xl font-semibold'>7 шагов</h3><ol className='mt-3 space-y-2 list-decimal pl-6'><li>Пройдите тест с ребёнком.</li><li>Оплата.</li><li>Рекомендации по подготовке.</li><li>Ссылка активна 7 дней.</li><li>Тест.</li><li>Подтверждение завершения.</li><li>Результаты придут на почту.</li></ol></section>
    <section className='container-x py-12'><h3 className='text-xl font-semibold'>Как подготовиться</h3><ul className='list-disc pl-6 mt-2 text-[var(--muted)]'><li>Спокойная обстановка.</li><li>Без правильных/неправильных ответов.</li><li>Честные ответы.</li><li>Паузы при усталости.</li><li>Не подсказывать ребёнку.</li></ul><a className='btn-primary mt-4 inline-block' href='/checkout'>Перейти к регистрации</a></section>
    <section className='container-x py-12'><h3 className='text-xl font-semibold'>FAQ</h3><div className='space-y-2 mt-3 text-[var(--muted)]'><p>Сколько времени занимает тест? — 20–35 минут.</p><p>Нужен ли ребёнок рядом? — желательно, но можно по наблюдениям.</p><p>Что я получу? — профиль сильных сторон и рекомендации по развитию.</p><p>Почему ссылка 7 дней? — чтобы пройти в удобное время.</p></div></section>
  </>;
}
