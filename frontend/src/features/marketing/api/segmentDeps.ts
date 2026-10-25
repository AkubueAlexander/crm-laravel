// Single adapter over the shared app helpers used by the segment builder.
// If a shared import path or the shape of useTenant() differs in this repo,
// this is the ONLY file that needs a fix.
import { useTenant } from '@/shared/hooks/useTenant';

export { apiClient } from '@/shared/lib/apiClient';
export { apiErrorMessage } from '@/shared/lib/apiErrorMessage';
export { useDebouncedValue } from '@/shared/hooks/useDebouncedValue';
export { usePermission } from '@/shared/hooks/usePermission';

type TenantShape =
  | {
      id?: number;
      tenantId?: number;
      tenant?: { id?: number };
      data?: { id?: number };
    }
  | null
  | undefined;

/** Tenant id used to namespace every TanStack Query key (1.2). */
export function useTenantId(): number {
  const tenant = useTenant() as unknown as TenantShape;
  return tenant?.tenantId ?? tenant?.id ?? tenant?.tenant?.id ?? tenant?.data?.id ?? 0;
}