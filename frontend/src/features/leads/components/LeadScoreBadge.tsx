// 9.7: read-only score pill. Colour bands are display-only; the score comes from the API.
export function scoreBand(score: number): { label: string; className: string } {
  if (score >= 75) return { label: 'Hot', className: 'bg-green-100 text-green-800' };
  if (score >= 50) return { label: 'Warm', className: 'bg-blue-100 text-blue-800' };
  if (score >= 25) return { label: 'Cool', className: 'bg-amber-100 text-amber-800' };
  return { label: 'Cold', className: 'bg-gray-100 text-gray-700' };
}

export function LeadScoreBadge({ score }: { score: number }) {
  const band = scoreBand(score);
  return (
    <span
      title={band.label}
      className={`inline-flex min-w-[2.5rem] justify-center rounded-full px-2 py-0.5 text-xs font-semibold tabular-nums ${band.className}`}
    >
      {score}
    </span>
  );
}