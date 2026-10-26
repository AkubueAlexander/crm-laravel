import { useEffect, useMemo, useRef, useState } from 'react';
import { zodResolver } from '@hookform/resolvers/zod';
import { Link, useNavigate } from '@tanstack/react-router';
import { isAxiosError } from 'axios';
import { useForm, useWatch } from 'react-hook-form';
import {
  apiErrorMessage,
  useDebouncedValue,
  usePermission,
} from '@/features/marketing/api/segmentDeps';
import { useTemplate } from '@/features/marketing/api/useTemplate';
import { useCreateTemplate, useUpdateTemplate } from '@/features/marketing/api/useSaveTemplate';
import { useTemplatePreview } from '@/features/marketing/api/useTemplatePreview';
import { useTemplateVariables } from '@/features/marketing/api/useTemplateVariables';
import { TemplatePreviewPane } from '@/features/marketing/components/TemplatePreviewPane';
import type { EmailTemplateDetail } from '@/features/marketing/schemas/template';
import {
  buildTemplateFormSchema,
  toTemplatePayload,
  type TemplateFormValues,
  type TemplateVariablesResponse,
} from '@/features/marketing/schemas/templateForm';

const inputClass =
  'w-full rounded-md border border-[var(--color-border)] bg-[var(--color-paper)] px-3 py-2 text-sm text-[var(--color-ink)] disabled:opacity-60';

type Props = { templateId?: number };

export function TemplateEditorPage({ templateId }: Props) {
  const variablesQuery = useTemplateVariables();
  const templateQuery = useTemplate(templateId ?? null);
  const editing = templateId !== undefined;

  if (variablesQuery.isPending || (editing && templateQuery.isPending)) {
    return <p className="p-6 text-sm text-[var(--color-ink-muted)]">Loading...</p>;
  }
  if (variablesQuery.isError) {
    return (
      <p role="alert" className="p-6 text-sm text-red-600">
        {apiErrorMessage(variablesQuery.error, 'Could not load the template editor.')}
      </p>
    );
  }
  if (editing && templateQuery.isError) {
    return (
      <div className="space-y-3 p-6">
        <p role="alert" className="text-sm text-red-600">
          {apiErrorMessage(templateQuery.error, 'Could not load this template.')}
        </p>
        <Link to="/marketing/templates" className="text-sm underline">
          Back to templates
        </Link>
      </div>
    );
  }

  return (
    <TemplateEditorForm
      key={templateId ?? 'new'}
      templateId={templateId}
      template={templateQuery.data ?? null}
      variables={variablesQuery.data}
    />
  );
}

type FormProps = {
  templateId?: number;
  template: EmailTemplateDetail | null;
  variables: TemplateVariablesResponse;
};

type TextField = 'subject' | 'body';

function TemplateEditorForm({ templateId, template, variables }: FormProps) {
  const navigate = useNavigate();
  const canManage = usePermission('marketing.manage');
  const editing = templateId !== undefined;

  const createTemplate = useCreateTemplate();
  const updateTemplate = useUpdateTemplate(templateId ?? 0);
  const [banner, setBanner] = useState<string | null>(null);

  const limits = variables.limits;
  const variableNames = useMemo(() => variables.data.map((v) => v.name), [variables.data]);
  const schema = useMemo(() => buildTemplateFormSchema(limits, variableNames), [limits, variableNames]);

  const form = useForm<TemplateFormValues>({
    resolver: zodResolver(schema),
    defaultValues: {
      name: template?.name ?? '',
      subject: template?.subject ?? '',
      body: template?.body ?? '',
    },
    mode: 'onBlur',
  });
  const {
    register,
    handleSubmit,
    control,
    setError,
    setValue,
    getValues,
    formState: { errors, isSubmitting, isDirty },
  } = form;

  // Warn before a full page unload with unsaved edits.
  useEffect(() => {
    if (!isDirty) return;
    const onBeforeUnload = (event: BeforeUnloadEvent) => event.preventDefault();
    window.addEventListener('beforeunload', onBeforeUnload);
    return () => window.removeEventListener('beforeunload', onBeforeUnload);
  }, [isDirty]);

  // Live preview: debounced values go to the API renderer (same one the send job uses).
  const subjectValue = useWatch({ control, name: 'subject' }) ?? '';
  const bodyValue = useWatch({ control, name: 'body' }) ?? '';
  const debouncedSubject = useDebouncedValue(subjectValue, 500);
  const debouncedBody = useDebouncedValue(bodyValue, 500);
  const preview = useTemplatePreview(debouncedSubject, debouncedBody);

  // Variable picker: insert at the caret of whichever of subject/body was focused last.
  const subjectRef = useRef<HTMLInputElement | null>(null);
  const bodyRef = useRef<HTMLTextAreaElement | null>(null);
  const lastField = useRef<TextField>('body');

  const subjectReg = register('subject');
  const bodyReg = register('body');

  function insertVariable(name: string) {
    const token = `{{ ${name} }}`;
    const field = lastField.current;
    const el = field === 'subject' ? subjectRef.current : bodyRef.current;
    const current = getValues(field) ?? '';
    const start = el?.selectionStart ?? current.length;
    const end = el?.selectionEnd ?? start;
    setValue(field, current.slice(0, start) + token + current.slice(end), {
      shouldDirty: true,
      shouldTouch: true,
    });
    requestAnimationFrame(() => {
      el?.focus();
      const caret = start + token.length;
      el?.setSelectionRange(caret, caret);
    });
  }

  function applyApiErrors(error: unknown) {
    setBanner(null);

    if (isAxiosError(error) && error.response?.status === 422) {
      const body = error.response.data as { errors?: Record<string, string[]> } | undefined;
      const unmatched: string[] = [];
      let mapped = false;

      for (const [key, messages] of Object.entries(body?.errors ?? {})) {
        const message = messages[0];
        if (!message) continue;
        if (key === 'name' || key === 'subject' || key === 'body') {
          setError(key, { type: 'server', message });
          mapped = true;
        } else {
          unmatched.push(message);
        }
      }

      if (unmatched.length > 0) {
        setBanner(unmatched.join(' '));
      } else if (!mapped) {
        setBanner(apiErrorMessage(error, 'The template could not be saved.'));
      }
      return;
    }

    setBanner(apiErrorMessage(error, 'The template could not be saved.'));
  }

  const onSubmit = handleSubmit(async (values) => {
    setBanner(null);
    const payload = toTemplatePayload(values);
    try {
      if (editing) {
        await updateTemplate.mutateAsync(payload);
      } else {
        await createTemplate.mutateAsync(payload);
      }
      await navigate({ to: '/marketing/templates' });
    } catch (error) {
      applyApiErrors(error);
    }
  });

  if (!editing && !canManage) {
    return (
      <div className="space-y-3 p-6">
        <p className="text-sm text-[var(--color-ink-muted)]">You do not have access to create templates.</p>
        <Link to="/marketing/templates" className="text-sm underline">
          Back to templates
        </Link>
      </div>
    );
  }

  const heading = editing ? (canManage ? 'Edit template' : 'Template') : 'New template';

  return (
    <div className="mx-auto max-w-6xl space-y-6 p-6">
      <div className="flex items-center justify-between gap-4">
        <h1 className="text-xl font-semibold text-[var(--color-ink)]">{heading}</h1>
        <Link to="/marketing/templates" className="text-sm underline">
          Back to templates
        </Link>
      </div>

      {!canManage ? (
        <p className="rounded-md border border-[var(--color-border)] bg-[var(--color-surface)] p-3 text-sm text-[var(--color-ink-muted)]">
          You have read-only access to this template.
        </p>
      ) : null}

      {banner ? (
        <div role="alert" className="rounded-md border border-red-300 bg-red-50 p-3 text-sm text-red-700">
          {banner}
        </div>
      ) : null}

      <form onSubmit={onSubmit} noValidate>
        <div className="grid gap-6 lg:grid-cols-2">
          <fieldset disabled={!canManage || isSubmitting} className="min-w-0 space-y-5">
            <div className="space-y-1">
              <label htmlFor="template-name" className="text-sm font-medium text-[var(--color-ink)]">
                Name
              </label>
              <input id="template-name" type="text" className={inputClass} {...register('name')} />
              {errors.name?.message ? (
                <p role="alert" className="text-sm text-red-600">
                  {errors.name.message}
                </p>
              ) : null}
            </div>

            <div className="space-y-1">
              <label htmlFor="template-subject" className="text-sm font-medium text-[var(--color-ink)]">
                Subject
              </label>
              <input
                id="template-subject"
                type="text"
                className={inputClass}
                {...subjectReg}
                ref={(el) => {
                  subjectReg.ref(el);
                  subjectRef.current = el;
                }}
                onFocus={() => {
                  lastField.current = 'subject';
                }}
              />
              {errors.subject?.message ? (
                <p role="alert" className="text-sm text-red-600">
                  {errors.subject.message}
                </p>
              ) : null}
            </div>

            <div className="space-y-1">
              <div className="flex items-center justify-between">
                <label htmlFor="template-body" className="text-sm font-medium text-[var(--color-ink)]">
                  Body (Markdown)
                </label>
                <span className="text-xs text-[var(--color-ink-muted)]">
                  {bodyValue.length.toLocaleString()} / {limits.body.toLocaleString()}
                </span>
              </div>

              <div className="flex flex-wrap items-center gap-1.5" aria-label="Insert a variable">
                <span className="text-xs text-[var(--color-ink-muted)]">Insert:</span>
                {variables.data.map((variable) => (
                  <button
                    key={variable.name}
                    type="button"
                    title={`${variable.label} (e.g. ${variable.sample})`}
                    onClick={() => insertVariable(variable.name)}
                    className="rounded-md border border-[var(--color-border)] px-2 py-0.5 font-mono text-xs text-[var(--color-ink)] hover:bg-[var(--color-surface)]"
                  >
                    {variable.name}
                  </button>
                ))}
              </div>

              <textarea
                id="template-body"
                rows={18}
                spellCheck
                className={`${inputClass} font-mono`}
                {...bodyReg}
                ref={(el) => {
                  bodyReg.ref(el);
                  bodyRef.current = el;
                }}
                onFocus={() => {
                  lastField.current = 'body';
                }}
              />
              {errors.body?.message ? (
                <p role="alert" className="text-sm text-red-600">
                  {errors.body.message}
                </p>
              ) : null}
              <p className="text-xs text-[var(--color-ink-muted)]">
                Markdown is supported. Raw HTML is removed. Variables look like{' '}
                <code className="font-mono">{'{{ first_name }}'}</code>.
              </p>
            </div>

            {canManage ? (
              <div className="flex items-center gap-3">
                <button
                  type="submit"
                  disabled={isSubmitting}
                  className="rounded-md bg-[var(--color-ink)] px-4 py-2 text-sm font-medium text-[var(--color-paper)] disabled:opacity-50"
                >
                  {isSubmitting ? 'Saving...' : editing ? 'Save changes' : 'Create template'}
                </button>
                <Link to="/marketing/templates" className="text-sm underline">
                  Cancel
                </Link>
              </div>
            ) : null}
          </fieldset>

          <div className="min-w-0 lg:sticky lg:top-4 lg:self-start">
            <TemplatePreviewPane
              data={preview.data}
              isPending={preview.isPending}
              isFetching={preview.isFetching}
              isError={preview.isError}
            />
          </div>
        </div>
      </form>
    </div>
  );
}