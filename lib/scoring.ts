export function scoreAnswers(answers: { mappingKey: string | null; value: string }[]) {
  const scores: Record<string, number> = {};
  answers.forEach((a) => {
    const key = a.mappingKey || 'general';
    scores[key] = (scores[key] || 0) + (a.value === 'Да' || a.value === '1' ? 1 : 0);
  });
  const sorted = Object.entries(scores).sort((a,b) => b[1]-a[1]);
  return {
    primary: sorted[0]?.[0] || 'не определён',
    secondary: sorted[1]?.[0] || 'не определён',
    scores
  };
}
