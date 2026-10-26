import { useEffect, type FormEvent } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import type { Campaign } from '../schemas/campaign';
import { scheduleFormSchema, type LifecycleKind, type ScheduleFormValues } from '../schemas/campaignForm';

type Intent = { campaign: Campaign; kind: LifecycleKind };

type Props = {
    intent: Intent | null;
    isPending: boolean;
    error: string | null;
    // scheduledAt is an ISO instant, only passed for 'schedule'.
    onConfirm: (scheduledAt?: string) => void;
    onClose: () => void;
};

type Copy = { title: string; body: (name: string) => string; confirm: string; pending: string; danger: boolean };

const COPY: Record<LifecycleKind, Copy> = {
    send: {
        title: 'Send campaign now?',
        body: (n) => `\u201c${n}\u201d will be sent immediately to everyone in its segment. This cannot be undone.`,
        confirm: 'Send now',
        pending: 'Sending...',
        danger: false,
    },
    retry: {
        title: 'Retry sending?',
        body: (n) => `\u201c${n}\u201d will start sending again.`,
        confirm: 'Retry send',
        pending: 'Starting...',
        danger: false,
    },
    schedule: {
        title: 'Schedule campaign',
        body: (n) => `Choose when \u201c${n}\u201d should be sent. You can unschedule it until it starts.`,
        confirm: 'Schedule',
        pending: 'Scheduling...',
        danger: false,
    },
    cancel: {
        title: 'Cancel campaign?',
        body: (n) => `\u201c${n}\u201d will be cancelled. Sending stops and it cannot be restarted.`,
        confirm: 'Cancel campaign',
        pending: 'Cancelling...',
        danger: true,
    },
};

function localInputValue(d: Date): string {
    const p = (n: number) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}T${p(d.getHours())}:${p(d.getMinutes())}`;
}

function LifecycleBody({ intent, isPending, error, onConfirm, onClose }: Props & { intent: Intent }) {
    const copy = COPY[intent.kind];
    const isSchedule = intent.kind === 'schedule';

    const {
        register,
        handleSubmit,
        formState: { errors },
    } = useForm<ScheduleFormValues>({
        resolver: zodResolver(scheduleFormSchema),
        defaultValues: { scheduled_at: '' },
    });

    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape' && !isPending) onClose();
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [isPending, onClose]);

    const submitSchedule = handleSubmit((v) => onConfirm(new Date(v.scheduled_at).toISOString()));

    function onSubmit(e: FormEvent<HTMLFormElement>) {
        if (isSchedule) {
            void submitSchedule(e);
        } else {
            e.preventDefault();
            onConfirm();
        }
    }

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
            <div
                role="dialog"
                aria-modal="true"
                aria-labelledby="campaign-lifecycle-title"
                className="w-full max-w-md rounded-lg border border-[var(--color-border)] bg-[var(--color-surface)] p-5 shadow-lg"
            >
                <h2 id="campaign-lifecycle-title" className="text-base font-medium text-[var(--color-ink)]">
                    {copy.title}
                </h2>
                <p className="mt-2 text-sm text-[var(--color-ink-muted)]">{copy.body(intent.campaign.name)}</p>

                <form onSubmit={onSubmit} noValidate className="mt-4 flex flex-col gap-4">
                    {isSchedule && (
                        <div>
                            <label htmlFor="campaign-scheduled-at" className="mb-1 block text-sm font-medium">
                                Send at
                            </label>
                            <input
                                id="campaign-scheduled-at"
                                type="datetime-local"
                                min={localInputValue(new Date())}
                                aria-invalid={errors.scheduled_at ? true : undefined}
                                className="w-full rounded-md border px-3 py-2 text-sm"
                                {...register('scheduled_at')}
                            />
                            {errors.scheduled_at && (
                                <p role="alert" className="mt-1 text-xs text-red-700">
                                    {errors.scheduled_at.message}
                                </p>
                            )}
                        </div>
                    )}

                    {error && (
                        <p role="alert" className="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                            {error}
                        </p>
                    )}

                    <div className="flex justify-end gap-2">
                        <button
                            type="button"
                            onClick={onClose}
                            disabled={isPending}
                            className="rounded-md border px-3 py-1.5 text-sm disabled:opacity-50"
                        >
                            {intent.kind === 'cancel' ? 'Keep campaign' : 'Close'}
                        </button>
                        <button
                            type="submit"
                            disabled={isPending}
                            className={`rounded-md px-3 py-1.5 text-sm text-white hover:opacity-90 disabled:opacity-50 ${
                                copy.danger ? 'bg-red-600' : 'bg-[var(--color-ink)]'
                            }`}
                        >
                            {isPending ? copy.pending : copy.confirm}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    );
}

// Keyed by campaign + kind so every open starts with a fresh form.
export function CampaignLifecycleDialog(props: Props) {
    if (props.intent === null) return null;
    return <LifecycleBody key={`${props.intent.campaign.id}:${props.intent.kind}`} {...props} intent={props.intent} />;
}