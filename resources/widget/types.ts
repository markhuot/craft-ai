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
  /**
   * Endpoint the comment composer (opened from the CKEditor "Comment"
   * toolbar plugin) POSTs to when the editor submits a span-scoped
   * comment. Optional because the bootstrap is read on every CP page
   * and the composer is only relevant on entry-edit screens.
   */
  commentsCreateUrl?: string;
  /**
   * GET endpoint that returns attachment metadata (label, filename,
   * thumbUrl) for a list of asset IDs. The comment composer hits it
   * so the chips after the upload picker show actual filenames rather
   * than bare IDs. Optional because the widget itself doesn't use
   * attachments outside the composer.
   */
  assetsInfoUrl?: string;
}

/**
 * Payload carried by the `craftai:start-comment` event the CKEditor
 * "Comment" toolbar plugin dispatches when the editor selects text and
 * clicks the button. The widget reads the event, opens the composer
 * pre-populated with the surrounding context, and posts the result back
 * via `craftai:comment-created` so the CKEditor plugin can wrap the
 * matching span with the new referenceId.
 */
export interface CommentDraftRequest {
  elementId: number;
  isDraft: boolean;
  fieldHandle: string;
  /**
   * Client-generated UUID used as the span marker. The editor mints it
   * before dispatching so the marker can be applied locally as soon as
   * the create call resolves — no second server round-trip for the id.
   */
  referenceId: string;
  /** Short preview of the highlighted text, shown in the composer header. */
  selectionText: string;
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
