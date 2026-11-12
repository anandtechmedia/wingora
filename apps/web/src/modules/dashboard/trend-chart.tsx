import type { TrendPoint } from '@/api/dashboard';
import { useI18n } from '@/i18n/i18n';

const W = 640;
const H = 200;
const PAD = { l: 44, r: 12, t: 12, b: 26 };

function niceCeil(value: number): number {
  if (value <= 5) return 5;
  const magnitude = 10 ** Math.floor(Math.log10(value));
  const step = [1, 2, 2.5, 5, 10].map((s) => s * magnitude).find((s) => value <= s * Math.ceil(value / s) && Math.ceil(value / s) <= 5) ?? magnitude * 10;
  return step * Math.ceil(value / step);
}

/** Dependency-free SVG: headcount line with hires/exits bars. The data table carries the figures for assistive tech. */
export function TrendChart({ series }: { series: TrendPoint[] }) {
  const { t, locale } = useI18n();
  const monthLabel = (month: string, style: 'short' | 'long' = 'short') =>
    new Intl.DateTimeFormat(locale, { month: style, year: style === 'long' ? 'numeric' : '2-digit', timeZone: 'UTC' }).format(new Date(`${month}-01T00:00:00Z`));
  const number = new Intl.NumberFormat(locale);

  const max = niceCeil(Math.max(...series.map((p) => Math.max(p.headcount, p.hires, p.exits)), 1));
  const innerW = W - PAD.l - PAD.r;
  const innerH = H - PAD.t - PAD.b;
  const slot = innerW / series.length;
  const x = (i: number) => PAD.l + slot * i + slot / 2;
  const y = (v: number) => PAD.t + innerH * (1 - v / max);
  const bar = Math.min(10, slot / 4);
  const ticks = [0, 0.5, 1].map((f) => Math.round(max * f));
  const labelEvery = Math.ceil(series.length / 6);
  const line = series.map((p, i) => `${i === 0 ? 'M' : 'L'}${x(i).toFixed(1)},${y(p.headcount).toFixed(1)}`).join(' ');

  return (
    <div className="flex flex-col gap-3">
      <ul className="flex flex-wrap gap-4 text-xs text-fg-secondary" aria-hidden="true">
        <li className="flex items-center gap-1.5"><span className="h-0.5 w-4 rounded-full bg-accent" />{t('dashboard.headcount')}</li>
        <li className="flex items-center gap-1.5"><span className="size-2.5 rounded-sm bg-success-solid" />{t('dashboard.hires')}</li>
        <li className="flex items-center gap-1.5"><span className="size-2.5 rounded-sm bg-danger-solid" />{t('dashboard.exits')}</li>
      </ul>
      <svg viewBox={`0 0 ${W} ${H}`} className="h-auto w-full" aria-hidden="true" focusable="false">
        {ticks.map((v) => (
          <g key={v}>
            <line x1={PAD.l} x2={W - PAD.r} y1={y(v)} y2={y(v)} className="stroke-line" strokeDasharray={v === 0 ? undefined : '3 4'} />
            <text x={PAD.l - 8} y={y(v)} dy="0.32em" textAnchor="end" className="fill-fg-muted text-xs">{number.format(v)}</text>
          </g>
        ))}
        {series.map((p, i) => (
          <g key={p.month}>
            <rect x={x(i) - bar - 1} y={y(p.hires)} width={bar} height={y(0) - y(p.hires)} rx={1.5} className="fill-success-solid" />
            <rect x={x(i) + 1} y={y(p.exits)} width={bar} height={y(0) - y(p.exits)} rx={1.5} className="fill-danger-solid" />
            {i % labelEvery === 0 && <text x={x(i)} y={H - 6} textAnchor="middle" className="fill-fg-muted text-xs">{monthLabel(p.month)}</text>}
          </g>
        ))}
        <path d={line} fill="none" strokeWidth={2} strokeLinejoin="round" strokeLinecap="round" className="stroke-accent" />
        {series.map((p, i) => <circle key={p.month} cx={x(i)} cy={y(p.headcount)} r={3} className="fill-surface stroke-accent" strokeWidth={2} />)}
      </svg>
      <table className="sr-only">
        <caption>{t('dashboard.trendsDescription')}</caption>
        <thead><tr><th scope="col">{t('dashboard.month')}</th><th scope="col">{t('dashboard.headcount')}</th><th scope="col">{t('dashboard.hires')}</th><th scope="col">{t('dashboard.exits')}</th></tr></thead>
        <tbody>
          {series.map((p) => (
            <tr key={p.month}><th scope="row">{monthLabel(p.month, 'long')}</th><td>{number.format(p.headcount)}</td><td>{number.format(p.hires)}</td><td>{number.format(p.exits)}</td></tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
