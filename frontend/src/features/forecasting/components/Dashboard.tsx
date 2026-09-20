import { useForecast } from '../api/useForecast';
import { ForecastSummary } from './ForecastSummary';
import { ForecastChart } from './ForecastChart';

export function ForecastDashboard() {
    const { data: forecast, isPending, isError } = useForecast();

    if (isPending) return <div className="text-sm text-[var(--color-ink-muted)]">Loading forecast…</div>;
    if (isError || !forecast) return <div className="text-sm text-[var(--color-danger)]">Couldn't load forecast.</div>;

    return (
        <div className="flex flex-col gap-4">
            <ForecastSummary forecast={forecast} />
            <ForecastChart byStage={forecast.by_stage} />
        </div>
    );
}
