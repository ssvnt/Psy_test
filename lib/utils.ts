export const maskEmail = (email: string) => {
  const [name, domain] = email.split('@');
  if (!domain) return '***';
  return `${name.slice(0, 2)}***@${domain}`;
};

export const ruDate = (d: Date | string) => new Date(d).toLocaleString('ru-RU');
