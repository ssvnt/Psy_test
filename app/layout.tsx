import './globals.css';
import type { Metadata } from 'next';

export const metadata: Metadata = {
  title: 'ТМИГ — Профиль сильных сторон ребёнка',
  description: 'Тест множественного интеллекта Гарднера для родителей школьников'
};

export default function RootLayout({ children }: { children: React.ReactNode }) {
  return (
    <html lang="ru">
      <body>{children}</body>
    </html>
  );
}
