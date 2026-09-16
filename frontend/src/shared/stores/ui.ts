import { create } from 'zustand';

/**
 * 8.1: small and deliberately boring. Only state that could NOT be derived
 * from an API response belongs here. Server data (deals, contacts, the
 * current user) is never mirrored into this store — see useCurrentUser /
 * useTenant instead, which read from TanStack Query's cache.
 */
type UiState = {
    sidebarCollapsed: boolean;
    toggleSidebar: () => void;

    activeModal: string | null;
    openModal: (id: string) => void;
    closeModal: () => void;
};

export const useUiStore = create<UiState>((set) => ({
    sidebarCollapsed: false,
    toggleSidebar: () => set((s) => ({ sidebarCollapsed: !s.sidebarCollapsed })),

    activeModal: null,
    openModal: (id) => set({ activeModal: id }),
    closeModal: () => set({ activeModal: null }),
}));
