import { Modal } from '@/shared/components/Modal';
import type { ActivityTargetType } from '../schemas/activity';
import { Timeline } from './Timeline';

export type ActivityTimelineTarget = {
    type: ActivityTargetType;
    id: number;
    label: string; // shown in the modal title, e.g. the lead or contact name
};

type ActivityTimelineModalProps = {
    target: ActivityTimelineTarget | null;
    onClose: () => void;
};

// Drop-in for any list or detail view: hold an ActivityTimelineTarget in local
// state (8.1: ephemeral UI state) and render this once.
export function ActivityTimelineModal({ target, onClose }: ActivityTimelineModalProps) {
    return (
        <Modal open={!!target} onClose={onClose} title={target ? `Activity - ${target.label}` : 'Activity'}>
            {target && (
                <div className="max-h-[70vh] overflow-y-auto pr-1">
                    <Timeline key={`${target.type}-${target.id}`} targetType={target.type} targetId={target.id} />
                </div>
            )}
        </Modal>
    );
}
