import { useState } from 'react';
import { useConvertLead } from '../api/useConvertLead';

interface ConvertLeadButtonProps {
  leadId: number;
  leadName: string;
  alreadyConverted?: boolean;
}

/**
 * 8a.2 â€” row-level action for LeadList. Inline confirm rather than a modal,
 * matching how Contacts/Invoices row actions (8c.2-style) stay lightweight.
 */
export function ConvertLeadButton({
  leadId,
  leadName,
  alreadyConverted,
}: ConvertLeadButtonProps) {
  const [confirming, setConfirming] = useState(false);
  const { mutate, isPending, isError, error } = useConvertLead(leadId);

  if (alreadyConverted) {
    return <span className="text-xs text-gray-400">Converted</span>;
  }

  if (confirming) {
    return (
      <div className="flex items-center gap-2 text-xs">
        <span>Convert {leadName}?</span>
        <button
          type="button"
          className="font-medium text-emerald-600 hover:underline disabled:opacity-50"
          disabled={isPending}
          onClick={() => mutate(undefined, { onSettled: () => setConfirming(false) })}
        >
          {isPending ? 'Convertingâ€¦' : 'Confirm'}
        </button>
        <button
          type="button"
          className="text-gray-500 hover:underline"
          disabled={isPending}
          onClick={() => setConfirming(false)}
        >
          Cancel
        </button>
        {isError && <span className="text-red-600">{(error as Error).message}</span>}
      </div>
    );
  }

  return (
    <button
      type="button"
      className="text-sm font-medium text-emerald-600 hover:underline"
      onClick={() => setConfirming(true)}
    >
      Convert
    </button>
  );
}