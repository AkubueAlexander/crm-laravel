import type { DuplicateMatch } from '../schemas/contact';

type DuplicateWarningProps = {
    matches: DuplicateMatch[];
    threshold: number;
};

// 5.1: inline in the RHF form, non-blocking — never prevents submission.
export function DuplicateWarning({ matches, threshold }: DuplicateWarningProps) {
    if (matches.length === 0) return null;

    return (
        <div role="alert" className="mt-2 rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900">
            <p className="font-medium">Possible duplicate{matches.length > 1 ? 's' : ''} found</p>
            <ul className="mt-1 space-y-1">
                {matches.map((match) => (
                    <li key={match.contact.id} className="flex items-center justify-between gap-2">
                        <span>
                            {match.contact.full_name}
                            {match.contact.email ? ` \u2014 ${match.contact.email}` : ''}
                        </span>
                        <span className="shrink-0 text-xs text-amber-700">{match.score}% match</span>
                    </li>
                ))}
            </ul>
            <p className="mt-1 text-xs text-amber-700">Threshold: {threshold}</p>
        </div>
    );
}