import type { AuditEntry } from '../schemas/audit';

const DASH = '\u2014';

function str(value: unknown): string {
    return typeof value === 'string' && value !== '' ? value : DASH;
}

function roleName(value: unknown): string {
    const s = str(value);
    return s === DASH ? s : s.charAt(0).toUpperCase() + s.slice(1);
}

export function sourceLabel(source: string): string {
    if (source === 'deal_stage') return 'Deals';
    if (source === 'users') return 'Users';
    return source;
}

// One plain sentence per event. The actor is shown in its own column, so it is not repeated here.
export function describeAuditEntry(entry: AuditEntry): string {
    const label = entry.subject?.label ?? DASH;
    const d = entry.details;

    switch (entry.event) {
        case 'deal.stage_changed':
            return `Moved deal "${label}" from ${str(d.from)} to ${str(d.to)}`;
        case 'user.role_changed':
            return `Changed the role of ${label} from ${roleName(d.from)} to ${roleName(d.to)}`;
        case 'user.deactivated':
            return `Deactivated ${label}`;
        case 'user.reactivated':
            return `Reactivated ${label}`;
        case 'invitation.created':
            return `Invited ${label} as ${roleName(d.role)}`;
        case 'invitation.reissued':
            return `Re-issued the invitation for ${label} as ${roleName(d.role)}`;
        case 'invitation.resent':
            return `Resent the invitation for ${label}`;
        case 'invitation.revoked':
            return `Revoked the invitation for ${label}`;
        case 'invitation.accepted':
            return `Accepted an invitation and joined as ${roleName(d.role)}`;
        default:
            return entry.event;
    }
}
