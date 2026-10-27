import { useMemo, useState } from 'react';
import { useFieldArray, useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { Modal } from '@/shared/components/Modal';
import { useLeadFormCatalog } from '../api/useLeadFormCatalog';
import { useSaveLeadForm } from '../api/useSaveLeadForm';
import { parseApiError } from '../api/leadFormErrors';
import {
  buildLeadFormSchema,
  toLeadFormPayload,
  toLeadFormValues,
  type LeadForm,
  type LeadFormCatalogItem,
  type LeadFormValues,
} from '../schemas/leadForm';
import { LeadFormShareBox } from './LeadFormShareBox';

const controlClass =
  'w-full rounded-md border border-[var(--color-border)] bg-[var(--color-paper)] px-2 py-1.5 text-sm text-[var(--color-ink)] disabled:opacity-60';
const iconButtonClass =
  'rounded-md border border-[var(--color-border)] px-2 py-1 text-xs text-[var(--color-ink-muted)] hover:bg-[var(--color-surface)] disabled:opacity-40';

type DialogProps = { open: boolean; leadForm: LeadForm | null; onClose: () => void };

export function LeadFormBuilderDialog({ open, leadForm, onClose }: DialogProps) {
  return (
    <Modal open={open} onClose={onClose} title={leadForm ? 'Edit lead form' : 'New lead form'}>
      {/* Mounted only while open, so each open starts from a clean form. */}
      {open ? <BuilderBody leadForm={leadForm} onDone={onClose} /> : null}
    </Modal>
  );
}

function BuilderBody({ leadForm, onDone }: { leadForm: LeadForm | null; onDone: () => void }) {
  const catalog = useLeadFormCatalog();

  if (catalog.isPending) return <p className="text-sm text-[var(--color-ink-muted)]">Loading...</p>;
  if (catalog.isError) return <p className="text-sm text-red-600">Couldn't load the form builder. Try again.</p>;

  return <BuilderForm leadForm={leadForm} catalog={catalog.data.data} maxFields={catalog.data.max_fields} onDone={onDone} />;
}

type FormProps = {
  leadForm: LeadForm | null;
  catalog: LeadFormCatalogItem[];
  maxFields: number;
  onDone: () => void;
};

function BuilderForm({ leadForm, catalog, maxFields, onDone }: FormProps) {
  const save = useSaveLeadForm();
  const [banner, setBanner] = useState<string | null>(null);
  const schema = useMemo(() => buildLeadFormSchema(catalog, maxFields), [catalog, maxFields]);

  const {
    register,
    control,
    handleSubmit,
    setError,
    getValues,
    formState: { errors },
  } = useForm<LeadFormValues>({
    resolver: zodResolver(schema),
    defaultValues: toLeadFormValues(leadForm, catalog),
  });

  const { fields, append, remove, move } = useFieldArray({ control, name: 'fields' });

  function addField() {
    const used = getValues('fields').map((f) => f.key);
    const next = catalog.find((c) => !used.includes(c.key));
    if (next) append({ key: next.key, label: next.label, required: false });
  }

  function applyServerErrors(error: unknown) {
    const parsed = parseApiError(error);
    const rest: string[] = [];
    let mapped = false;

    for (const [key, messages] of Object.entries(parsed.errors)) {
      const message = messages[0] ?? parsed.message;
      const row = /^fields\.(\d+)\.(key|label)$/.exec(key);

      if (key === 'name' || key === 'source' || key === 'success_message') {
        setError(key, { type: 'server', message });
        mapped = true;
      } else if (row && row[2] === 'key') {
        setError(`fields.${Number(row[1])}.key`, { type: 'server', message });
        mapped = true;
      } else if (row) {
        setError(`fields.${Number(row[1])}.label`, { type: 'server', message });
        mapped = true;
      } else {
        rest.push(message);
      }
    }

    setBanner(rest.length > 0 ? rest.join(' ') : mapped ? null : parsed.message);
  }

  const onSubmit = handleSubmit((values) => {
    setBanner(null);
    save.mutate(
      { id: leadForm?.id ?? null, payload: toLeadFormPayload(values) },
      { onSuccess: onDone, onError: applyServerErrors },
    );
  });

  const rootError = errors.fields?.root?.message ?? errors.fields?.message;

  return (
    <form onSubmit={(e) => void onSubmit(e)} className="space-y-4" noValidate>
      <div className="space-y-1">
        <label htmlFor="lf-name" className="text-sm font-medium">Name</label>
        <input id="lf-name" className={controlClass} maxLength={160} {...register('name')} />
        {errors.name && <p role="alert" className="text-sm text-red-600">{errors.name.message}</p>}
      </div>

      <div className="grid gap-3 sm:grid-cols-2">
        <div className="space-y-1">
          <label htmlFor="lf-source" className="text-sm font-medium">Lead source tag</label>
          <input id="lf-source" className={controlClass} maxLength={100} placeholder="website" {...register('source')} />
          {errors.source && <p role="alert" className="text-sm text-red-600">{errors.source.message}</p>}
        </div>
        <label className="flex items-center gap-2 pt-6 text-sm">
          <input type="checkbox" {...register('is_active')} />
          Accepting submissions
        </label>
      </div>

      <div className="space-y-1">
        <label htmlFor="lf-message" className="text-sm font-medium">Thank-you message</label>
        <textarea
          id="lf-message"
          rows={2}
          maxLength={300}
          className={controlClass}
          placeholder="Thanks! We have received your details."
          {...register('success_message')}
        />
        {errors.success_message && <p role="alert" className="text-sm text-red-600">{errors.success_message.message}</p>}
      </div>

      <fieldset className="space-y-2">
        <legend className="text-sm font-medium">Fields</legend>
        <ul className="space-y-2">
          {fields.map((field, index) => {
            const rowErrors = errors.fields?.[index];
            return (
              <li key={field.id} className="rounded-md border border-[var(--color-border)] bg-[var(--color-surface)] p-3">
                <div className="grid gap-2 sm:grid-cols-[1fr_1.4fr_auto_auto] sm:items-start">
                  <select aria-label={`Field ${index + 1} type`} className={controlClass} {...register(`fields.${index}.key`)}>
                    {catalog.map((c) => (
                      <option key={c.key} value={c.key}>{c.label}</option>
                    ))}
                  </select>
                  <input
                    aria-label={`Field ${index + 1} label`}
                    className={controlClass}
                    maxLength={60}
                    {...register(`fields.${index}.label`)}
                  />
                  <label className="flex items-center gap-1 pt-1.5 text-sm">
                    <input type="checkbox" {...register(`fields.${index}.required`)} />
                    Required
                  </label>
                  <div className="flex gap-1">
                    <button type="button" className={iconButtonClass} aria-label={`Move field ${index + 1} up`} disabled={index === 0} onClick={() => move(index, index - 1)}>Up</button>
                    <button type="button" className={iconButtonClass} aria-label={`Move field ${index + 1} down`} disabled={index === fields.length - 1} onClick={() => move(index, index + 1)}>Down</button>
                    <button type="button" className={iconButtonClass} aria-label={`Remove field ${index + 1}`} disabled={fields.length <= 1} onClick={() => remove(index)}>Remove</button>
                  </div>
                </div>
                {(rowErrors?.key?.message ?? rowErrors?.label?.message) && (
                  <p role="alert" className="mt-2 text-sm text-red-600">{rowErrors?.key?.message ?? rowErrors?.label?.message}</p>
                )}
              </li>
            );
          })}
        </ul>
        {rootError && <p role="alert" className="text-sm text-red-600">{rootError}</p>}
        <button type="button" onClick={addField} disabled={fields.length >= maxFields} className={iconButtonClass}>
          Add field
        </button>
      </fieldset>

      {leadForm && <LeadFormShareBox form={leadForm} />}

      {banner && <p role="alert" className="text-sm text-red-600">{banner}</p>}

      <div className="flex justify-end gap-2">
        <button type="button" onClick={onDone} className="rounded-md border border-[var(--color-border)] px-4 py-2 text-sm">
          Cancel
        </button>
        <button
          type="submit"
          disabled={save.isPending}
          className="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white disabled:opacity-60"
        >
          {save.isPending ? 'Saving...' : leadForm ? 'Save changes' : 'Create form'}
        </button>
      </div>
    </form>
  );
}