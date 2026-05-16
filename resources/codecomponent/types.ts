export interface Permissions {
  twig: boolean;
  css: boolean;
  js: boolean;
  prompt: boolean;
}

export interface FieldValues {
  twig: string;
  css: string;
  js: string;
  /**
   * UUID of the chat session bound to this (field × element) pair. Null
   * until the user opens the Prompt tab for the first time — the editor
   * lazily POSTs `newSessionUrl` to mint one and writes it back into the
   * hidden input alongside the other tab values.
   */
  agentSessionId: string | null;
}

export interface ElementSummary {
  type: string;
  id: number | null;
  title: string | null;
  sectionHandle: string | null;
  /**
   * True when the element is a draft — including the provisional drafts
   * Craft auto-creates around matrix-block edits in the CP. Drives the
   * `update_code_component` call-target hint in the system note: a draft
   * requires `draftId`, not `entryId`.
   */
  isDraft: boolean;
  isProvisionalDraft: boolean;
  /** Draft pointer; only set when `isDraft` is true. */
  draftId: number | null;
  /** Canonical entry id for a draft; null when there is no canonical yet. */
  canonicalId: number | null;
  /** Owner entry id for nested elements like matrix blocks. */
  ownerId: number | null;
}

/** URLs the embedded `<Chat>` needs from the host CP page. */
export interface ChatUrls {
  messagesUrl: string;
  sendUrl: string;
  sessionsUrl: string;
  newSessionUrl: string;
  sessionsIndexUrl: string;
  previewRespondUrl: string;
  toolModeUrl: string;
  updateToolModeUrl: string;
}

/** Field-only endpoints the React editor uses to stay in sync with disk. */
export interface PersistUrls {
  /** GET — current persisted tab values, queried on a poll cycle. */
  stateUrl: string;
  /** POST — write the agent session id to disk immediately on mint. */
  persistSessionUrl: string;
}

export interface FieldBootstrap {
  inputId: string;
  fieldId: number;
  fieldHandle: string;
  fieldName: string;
  /**
   * Bracketed input name prefix the React app uses to update the hidden
   * inputs Craft will serialize on form save (e.g. `fields[component]`).
   */
  inputName: string;
  permissions: Permissions;
  values: FieldValues;
  element: ElementSummary | null;
  chat: ChatUrls;
  persist: PersistUrls;
}

export type TabId = "twig" | "css" | "js" | "prompt";
