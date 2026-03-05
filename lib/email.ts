import nodemailer from 'nodemailer';

const tr = nodemailer.createTransport({
  host: process.env.SMTP_HOST,
  port: Number(process.env.SMTP_PORT || 587),
  secure: false,
  auth: process.env.SMTP_USER ? { user: process.env.SMTP_USER, pass: process.env.SMTP_PASS } : undefined
});

export async function sendEmail(to: string, subject: string, html: string) {
  if (!process.env.SMTP_HOST) return;
  await tr.sendMail({ from: process.env.MAIL_FROM || 'noreply@example.com', to, subject, html });
}

export const templates = {
  paidLink: (link: string) => `<h2>Ваша ссылка на тест (активна 7 дней)</h2><p><a href='${link}'>Перейти к тесту</a></p>`,
  started: `<h2>Вы начали тест</h2><p>Можно вернуться по этой же ссылке в течение 7 дней.</p>`,
  finished: `<h2>Мы получили ваши ответы</h2><p>Результаты придут на почту в течение 24 часов.</p>`,
  result: (primary: string, secondary: string, recs: string) => `<h2>Ваш результат</h2><p>Ведущий интеллект: ${primary}</p><p>Второстепенный: ${secondary}</p><p>${recs}</p>`
};
