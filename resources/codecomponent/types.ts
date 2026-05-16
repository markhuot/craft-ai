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
}

export type TabId = "twig" | "css" | "js" | "prompt";
