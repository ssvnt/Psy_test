import LandingClient from '@/components/LandingClient';

export default function Page() {
  return (
    <main>
      <header className='container-x py-5 flex justify-between items-center'>
        <a href='/' className='font-semibold'>Профиль интеллекта</a>
        <nav className='hidden md:flex gap-4 text-sm'>
          <a href='#why'>Зачем</a><a href='#steps'>Как это работает</a><a href='/contacts'>Контакты</a>
        </nav>
        <a className='btn-primary' href='/checkout'>Пройти тест</a>
      </header>
      <LandingClient />
      <footer className='container-x py-10 text-sm flex gap-4'>
        <a href='/policy'>Политика</a><a href='/offer'>Оферта</a><a href='/contacts'>Контакты</a>
      </footer>
    </main>
  );
}
