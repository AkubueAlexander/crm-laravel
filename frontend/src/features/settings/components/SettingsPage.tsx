import { usePermission } from '@/shared/hooks/usePermission';
import { useCurrentUser } from '@/features/auth/api/useCurrentUser';
import { BrandingSettingsForm } from './BrandingSettingsForm';
import { PipelineSettingsForm } from './PipelineSettingsForm';

const card = 'rounded-lg border border-[var(--color-border)] bg-[var(--color-surface)] p-6';

export function SettingsPage() {
    const { isPending } = useCurrentUser();
    // Display-only (2.3): every settings endpoint re-checks settings.manage server-side.
    const canManage = usePermission('settings.manage');

    // usePermission is false until the user loads; do not flash "no access" meanwhile.
    if (isPending) return null;

    if (!canManage) {
        return <p className="text-sm text-[var(--color-ink-muted)]">You do not have access to workspace settings.</p>;
    }

    return (
        <div className="max-w-3xl space-y-6">
            <h1 className="text-lg font-medium text-[var(--color-ink)]">Settings</h1>

            <section className={card}>
                <h2 className="mb-4 text-base font-medium text-[var(--color-ink)]">Branding</h2>
                <BrandingSettingsForm />
            </section>

            <section className={card}>
                <h2 className="mb-4 text-base font-medium text-[var(--color-ink)]">Pipeline</h2>
                <PipelineSettingsForm />
            </section>
        </div>
    );
}
