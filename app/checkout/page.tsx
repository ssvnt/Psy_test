'use client';
import { useState } from 'react';

export default function CheckoutPage() {
  const [loading, setLoading] = useState(false);
  const [err, setErr] = useState('');
  async function submit(formData: FormData) {
    setLoading(true); setErr('');
    const res = await fetch('/api/checkout/start', { method: 'POST', body: JSON.stringify(Object.fromEntries(formData.entries())) });
    const json = await res.json();
    if (!res.ok) { setErr(json.error || 'Ошибка'); setLoading(false); return; }
    location.href = json.redirectUrl;
  }
  return <main className='container-x py-10'>
    <h1 className='text-2xl font-semibold'>1. Данные → 2. Оплата → 3. Ссылка на тест</h1>
    <form action={submit} className='card mt-6 space-y-3'>
      <input name='name' required placeholder='Имя родителя' className='w-full border rounded p-2'/>
      <input name='email' required type='email' placeholder='Email' className='w-full border rounded p-2'/>
      <input name='phone' placeholder='Телефон (необязательно)' className='w-full border rounded p-2'/>
      <input name='city' placeholder='Город (необязательно)' className='w-full border rounded p-2'/>
      <input name='childName' placeholder='Имя ребёнка' className='w-full border rounded p-2'/>
      <input name='childAge' required type='number' placeholder='Возраст ребёнка' className='w-full border rounded p-2'/>
      <label className='block'><input type='checkbox' required name='consent'/> Я даю согласие на обработку персональных данных</label>
      <label className='block'><input type='checkbox' required name='offer'/> Я принимаю условия оферты</label>
      <label className='block'><input type='checkbox' name='marketingConsent'/> Хочу получать полезные материалы</label>
      <p>Цена: {process.env.NEXT_PUBLIC_TEST_PRICE_RUB || '490'} ₽</p>
      <button disabled={loading} className='btn-primary'>{loading?'Переход...':'Перейти к оплате'}</button>
      {err && <p className='text-red-500'>{err}</p>}
    </form>
  </main>;
}
