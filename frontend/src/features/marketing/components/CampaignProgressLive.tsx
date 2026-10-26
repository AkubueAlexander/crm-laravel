import { useTenant } from '@/shared/hooks/useTenant';
import { useCampaignProgress } from '../api/useCampaignProgress';

function Listener({ tenantId }: { tenantId: string | number }) {
    useCampaignProgress(tenantId);
    return null;
}

// Mount once on the campaigns screen. Renders nothing; subscribes only when a tenant is known,
// so the socket never asks for a channel like `tenant.undefined.campaigns`.
export function CampaignProgressLive() {
    const { tenant } = useTenant();
    if (!tenant?.id) return null;
    return <Listener tenantId={tenant.id} />;
}