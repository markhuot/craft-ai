export interface AiStarBootstrap {
  fillFieldUrl: string;
  csrfTokenName: string;
  csrfTokenValue: string;
}

export interface ElementContext {
  elementId: number;
  isDraft: boolean;
  /**
   * Craft site id the editor is currently viewing the element on. Read
   * from the form's hidden `siteId` input, which Craft's element editor
   * stamps onto every edit screen. Null when the page lacks it (e.g.
   * single-site installs or third-party CP screens that omit it) — the
   * server falls back to the install's primary site in that case.
   */
  siteId: number | null;
}

export interface FillFieldRequest {
  elementId: number;
  isDraft: boolean;
  fieldHandle: string;
  fieldLabel?: string;
  blockElementId?: number;
  blockTypeHandle?: string;
  siteId?: number;
}

export interface FillFieldResponse {
  ok: boolean;
  sessionId: string;
  sessionUrl: string;
}

/**
 * Internal record produced by the field scanner — one per visible field
 * container the overlay should decorate.
 */
export interface DiscoveredField {
  /** The `.field` container in the DOM. */
  container: HTMLElement;
  /** The Craft field handle, derived from `data-attribute` or the id. */
  handle: string;
  /** Human-readable label scraped from the field's heading, if any. */
  label: string | null;
  /**
   * For matrix-nested fields, the enclosing `.matrixblock` container.
   * Null when the field sits at the top level of the entry.
   */
  blockContainer: HTMLElement | null;
}
