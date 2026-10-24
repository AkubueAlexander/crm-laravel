import { useEffect, useState } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { apiErrorMessage } from '@/shared/lib/apiErrorMessage';
import { useTenant } from '@/shared/hooks/useTenant';
import { useUpdateBranding } from '../api/useUpdateBranding';
import {
    brandingAccent,
    brandingFormSchema,
    brandingLogo,
    isHexColor,
    type BrandingFormInput,
} from '../schemas/settings';

const secondaryButton = 'text-sm text-[var(--color-ink-muted)] hover:text-[var(--color-ink)]';
const primaryButton = 'rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white disabled:opacity-50';

export function BrandingSettingsForm() {
    const { tenant } = useTenant();
    const update = useUpdateBranding();

    // The saved state comes straight from the tenant in the ['auth', 'me'] cache (server state, 8.1).
    const currentLogo = brandingLogo(tenant?.branding);
    const currentColor = brandingAccent(tenant?.branding) ?? '';

    // Remounting the file input is the only way to clear a chosen file.
    const [fileKey, setFileKey] = useState(0);
    const [preview, setPreview] = useState<string | null>(null);

    // RHF + Zod (8.3). The server re-validates everything and re-encodes the image (14.2).
    const form = useForm<BrandingFormInput>({
        resolver: zodResolver(brandingFormSchema),
        defaultValues: { primary_color: currentColor, remove_logo: false, logo: undefined },
    });

    const color = form.watch('primary_color');
    const removeLogo = form.watch('remove_logo');
    const chosen = form.watch('logo') ?? null;
    const validColor = isHexColor(color);

    useEffect(() => {
        if (!chosen) {
            setPreview(null);
            return;
        }

        const url = URL.createObjectURL(chosen);
        setPreview(url);

        return () => URL.revokeObjectURL(url);
    }, [chosen]);

    const shownLogo = preview ?? (removeLogo ? undefined : currentLogo);
    const { errors, isDirty } = form.formState;

    const chooseFile = (file: File | undefined) => {
        form.setValue('logo', file, { shouldDirty: true, shouldValidate: true });

        // A new file replaces the old one; "remove" would contradict it.
        if (file) form.setValue('remove_logo', false, { shouldDirty: true });
    };

    const clearSelection = () => {
        chooseFile(undefined);
        setFileKey((key) => key + 1);
    };

    const submit = form.handleSubmit((values) => {
        update.mutate(values, {
            onSuccess: (saved) => {
                form.reset({ primary_color: brandingAccent(saved.branding) ?? '', remove_logo: false, logo: undefined });
                setFileKey((key) => key + 1);
            },
        });
    });

    return (
        <form onSubmit={submit} className="space-y-5" noValidate>
            <div>
                <p className="mb-2 text-sm font-medium text-[var(--color-ink)]">Logo</p>
                <div className="flex items-center gap-4">
                    <div className="flex h-16 w-16 shrink-0 items-center justify-center overflow-hidden rounded border border-[var(--color-border)] bg-[var(--color-paper)]">
                        {shownLogo ? (
                            <img src={shownLogo} alt="Logo preview" className="h-full w-full object-contain" />
                        ) : (
                            <span className="text-xs text-[var(--color-ink-muted)]">None</span>
                        )}
                    </div>

                    <div className="space-y-2">
                        <div className="flex items-center gap-3">
                            <input
                                key={fileKey}
                                type="file"
                                accept=".png,.jpg,.jpeg,.webp,image/png,image/jpeg,image/webp"
                                aria-label="Upload a logo"
                                onChange={(event) => chooseFile(event.target.files?.[0])}
                                className="block text-sm text-[var(--color-ink)]"
                            />
                            {chosen && (
                                <button type="button" onClick={clearSelection} className={secondaryButton}>
                                    Clear selection
                                </button>
                            )}
                        </div>
                        <p className="text-xs text-[var(--color-ink-muted)]">
                            PNG, JPG or WebP, up to 1 MB. It is resized to fit 256 px.
                        </p>

                        {currentLogo && (
                            <label className="flex items-center gap-2 text-sm text-[var(--color-ink)]">
                                <input type="checkbox" {...form.register('remove_logo')} />
                                Remove the current logo
                                {chosen && (
                                    <span className="text-xs text-[var(--color-ink-muted)]">
                                        (the new file replaces it anyway)
                                    </span>
                                )}
                            </label>
                        )}
                    </div>
                </div>
                {errors.logo && (
                    <p role="alert" className="mt-1 text-xs text-red-600">
                        {errors.logo.message}
                    </p>
                )}
            </div>

            <div>
                <label htmlFor="branding-color" className="mb-2 block text-sm font-medium text-[var(--color-ink)]">
                    Accent color
                </label>
                <div className="flex items-center gap-2">
                    <input
                        type="color"
                        aria-label="Pick an accent color"
                        value={validColor ? color.toLowerCase() : '#4f46e5'}
                        onChange={(event) =>
                            form.setValue('primary_color', event.target.value, { shouldDirty: true, shouldValidate: true })
                        }
                        className="h-9 w-12 cursor-pointer rounded border"
                    />
                    <input
                        id="branding-color"
                        type="text"
                        placeholder="#4f46e5"
                        aria-invalid={errors.primary_color ? true : undefined}
                        {...form.register('primary_color')}
                        className="w-32 rounded-md border px-3 py-2 text-sm"
                    />
                    <div
                        className="flex h-9 w-9 items-center justify-center rounded text-xs font-semibold text-white"
                        style={{ background: validColor ? color : 'var(--color-accent)' }}
                        aria-hidden="true"
                    >
                        {tenant?.name?.[0]?.toUpperCase() ?? '.'}
                    </div>
                    {color && (
                        <button
                            type="button"
                            onClick={() =>
                                form.setValue('primary_color', '', { shouldDirty: true, shouldValidate: true })
                            }
                            className={secondaryButton}
                        >
                            Clear
                        </button>
                    )}
                </div>
                {errors.primary_color && (
                    <p role="alert" className="mt-1 text-xs text-red-600">
                        {errors.primary_color.message}
                    </p>
                )}
                <p className="mt-1 text-xs text-[var(--color-ink-muted)]">
                    Used for the workspace tile in the sidebar. Leave empty for the default.
                </p>
            </div>

            {update.isError && (
                <p role="alert" className="text-sm text-red-600">
                    {apiErrorMessage(update.error)}
                </p>
            )}

            {update.isSuccess && !isDirty && (
                <p role="status" className="text-sm text-green-700">
                    Saved.
                </p>
            )}

            <div className="flex justify-end">
                <button type="submit" disabled={!isDirty || update.isPending} className={primaryButton}>
                    {update.isPending ? 'Saving...' : 'Save branding'}
                </button>
            </div>
        </form>
    );
}
