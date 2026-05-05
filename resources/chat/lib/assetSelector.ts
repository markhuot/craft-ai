// Thin wrapper around Craft.createElementSelectorModal. Centralized so the
// rest of the chat app doesn't reach into the global Craft object directly,
// and so tests can replace the opener without monkey-patching window.

interface CraftElementInfo {
  id: number | string;
  label?: string;
  url?: string;
  hasThumb?: boolean;
}

interface CraftSelectorModalSettings {
  multiSelect?: boolean;
  modalTitle?: string;
  sources?: string[] | null;
  onSelect?: (elements: CraftElementInfo[]) => void;
  onCancel?: () => void;
}

interface CraftGlobal {
  csrfTokenName?: string;
  csrfTokenValue?: string;
  createElementSelectorModal?: (
    elementType: string,
    settings: CraftSelectorModalSettings,
  ) => unknown;
  [key: string]: unknown;
}

declare global {
  interface Window {
    Craft?: CraftGlobal;
  }
}

export interface OpenAssetSelectorOptions {
  multiSelect?: boolean;
  title?: string;
}

export type AssetSelectorOpener = (options?: OpenAssetSelectorOptions) => Promise<number[]>;

/**
 * Open Craft's asset selector modal. Resolves to the chosen asset ids; if the
 * user cancels (or the Craft global is unavailable, e.g. in tests), resolves
 * to an empty array so callers can treat "nothing happened" the same as
 * "selected nothing."
 */
export const openAssetSelector: AssetSelectorOpener = (options = {}) =>
  new Promise<number[]>((resolve) => {
    const Craft = typeof window !== "undefined" ? window.Craft : undefined;
    if (!Craft || typeof Craft.createElementSelectorModal !== "function") {
      resolve([]);
      return;
    }

    let resolved = false;
    const finish = (ids: number[]) => {
      if (resolved) return;
      resolved = true;
      resolve(ids);
    };

    Craft.createElementSelectorModal("craft\\elements\\Asset", {
      multiSelect: options.multiSelect ?? true,
      modalTitle: options.title ?? "Choose assets",
      onSelect: (elements) => {
        const ids = (elements ?? [])
          .map((el) => (typeof el.id === "string" ? parseInt(el.id, 10) : el.id))
          .filter((id): id is number => Number.isFinite(id) && id > 0);
        finish(ids);
      },
      onCancel: () => finish([]),
    });
  });
