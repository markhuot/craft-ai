/**
 * Bootstrap data injected by the PHP-side
 * `View::EVENT_AFTER_RENDER_PAGE_TEMPLATE` listener. The same `<script>` tag
 * is read by the entry module to seed the React tree, so every URL the
 * widget needs to talk to is resolved server-side and frozen at page load.
 */
export interface WidgetBootstrap {
  jsUrl: string;
  cssUrl: string;
  sessionsUrl: string;
  newSessionUrl: string;
  sessionsIndexUrl: string;
  messagesUrl: string;
  sendUrl: string;
  previewRespondUrl: string;
  toolModeUrl: string;
  updateToolModeUrl: string;
  csrfTokenName: string;
  csrfTokenValue: string;
  /**
   * Snapshot of the page the widget is rendered on. The chat surface attaches
   * this to the next outgoing message *only* when its fingerprint differs
   * from the last one we sent on this session — see `Chat.tsx`.
   */
  context: PageContext;
  /**
   * Stable hash of `context`, computed server-side so client/server agree on
   * what "the same context" means. Cached per-session in localStorage.
   */
  contextFingerprint: string;
  /**
   * Max prompt tokens for the configured model — drives the chat UI's
   * context-window gauge. Null when the host hasn't configured one.
   */
  contextWindow?: number | null;
}

export interface PageContext {
  /**
   * `'cp'` when the widget was injected on a Craft control-panel page,
   * `'site'` when it was injected on the front-end. The chat surface
   * forwards this to the server-side context serializer so the LLM
   * sees an appropriate "you are looking at the CP" vs. "front-end"
   * framing.
   */
  surface: "cp" | "site";
  url: string | null;
  path: string | null;
  query: Record<string, string | number | boolean | null>;
  siteHandle: string | null;
  template: string | null;
  element: PageContextElement | null;
}

export interface PageContextElement {
  type: string;
  id: number;
  title: string | null;
  sectionHandle: string | null;
  /** True when the element is a draft (only meaningful for entries). */
  isDraft: boolean;
  /** Set when `isDraft` is true so the agent can call `get_draft`. */
  draftId: number | null;
  /** Set when `isDraft` is true so the agent can compare to the canonical. */
  canonicalId: number | null;
}
