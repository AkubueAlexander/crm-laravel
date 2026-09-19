import { create } from 'zustand';


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
