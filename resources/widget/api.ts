import type {
  Attachment,
  AvailableTool,
  SessionListItem,
  ToolMode,
  ToolModePayload,
} from "../chat/types";
import type { CommentDraftRequest, WidgetBootstrap } from "./types";

/**
 * Shape returned by `craft-ai/comments/create`. We only model the
 * fields the widget needs to consume — referenceId for the editor to
 * wrap the span, sessionId so we could (in a future iteration) hop
 * into the discussion thread on submit, and id for the dispatched
 * event so listeners can correlate.
 */
export interface CreatedComment {
  id: number;
  referenceId: string | null;
  sessionId: string;
}

export type FetchLike = (input: string | URL | Request, init?: RequestInit) => Promise<Response>;

export interface WidgetApiOptions {
  bootstrap: WidgetBootstrap;
  fetchImpl?: FetchLike;
}

/**
 * HTTP client for the widget shell. Only covers the endpoints the widget
 * itself owns (sessions list + creating a new session); the in-panel chat
 * reuses the existing `ChatApi` from `resources/chat/api.ts` once a session
 * is selected.
 */
export class WidgetApi {
  private readonly bootstrap: WidgetBootstrap;
  private readonly fetchImpl: FetchLike;

  constructor(opts: WidgetApiOptions) {
    this.bootstrap = opts.bootstrap;
    this.fetchImpl = opts.fetchImpl ?? globalThis.fetch.bind(globalThis);
  }

  async fetchSessions(): Promise<SessionListItem[]> {
    const res = await this.fetchImpl(this.bootstrap.sessionsUrl, {
      headers: { Accept: "application/json" },
      credentials: "same-origin",
    });
    if (!res.ok) {
      throw new Error(`Failed to load sessions: ${res.status}`);
    }
    const data: unknown = await res.json();
    if (
      typeof data !== "object" ||
      data === null ||
      !Array.isArray((data as { sessions?: unknown }).sessions)
    ) {
      return [];
    }
    return (data as { sessions: SessionListItem[] }).sessions;
  }

  async createSession(): Promise<string> {
    const body = new FormData();
    body.append(this.bootstrap.csrfTokenName, this.bootstrap.csrfTokenValue);
    // Explicitly identify ourselves as the widget surface so the server
    // doesn't fall back to inferring "cp" purely from the request being
    // a CP request. The widget is injected on CP pages too (so editors
    // can prompt "review this" while on a draft) and we don't want it to
    // pick up preview-pane / dedicated-chat-only tools — the LLM would
    // call them and get stuck in an error loop since the widget has no
    // iframe to drive.
    body.append("clientType", "widget");

    const res = await this.fetchImpl(this.bootstrap.newSessionUrl, {
      method: "POST",
      body,
      headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" },
      credentials: "same-origin",
    });
    if (!res.ok) {
      throw new Error(`Failed to create session: ${res.status}`);
    }
    const data: unknown = await res.json();
    if (
      typeof data !== "object" ||
      data === null ||
      typeof (data as { sessionId?: unknown }).sessionId !== "string"
    ) {
      throw new Error("Malformed response from new-session endpoint");
    }
    return (data as { sessionId: string }).sessionId;
  }

  /**
   * Derive the comments/create URL when the bootstrap was rendered by
   * an older build that didn't ship the dedicated `commentsCreateUrl`
   * field. The new-session URL and the create-comment URL are both
   * `craft-ai` action URLs that only differ in the controller/action
   * segment, so a string substitution gets us there without another
   * round-trip to the server.
   */
  private resolveCreateCommentUrl(): string {
    if (this.bootstrap.commentsCreateUrl) {
      return this.bootstrap.commentsCreateUrl;
    }
    return this.bootstrap.newSessionUrl.replace(
      /sessions\/new$/,
      "comments/create",
    );
  }

  /**
   * POST a span-scoped comment authored in the widget composer. The
   * server creates the CommentRecord + its placeholder session and
   * returns enough information for the editor to apply the span marker
   * (referenceId) and for callers to hop into the discussion thread
   * if they want.
   */
  async createComment(
    draft: CommentDraftRequest,
    body: string,
    opts: {
      sessionId?: string;
      assetIds?: number[];
      toolMode?: string;
      enabledTools?: string[] | null;
    } = {},
  ): Promise<CreatedComment> {
    const form = new FormData();
    form.set("elementId", String(draft.elementId));
    form.set("isDraft", draft.isDraft ? "1" : "0");
    form.set("fieldHandle", draft.fieldHandle);
    form.set("referenceId", draft.referenceId);
    form.set("body", body);
    if (opts.sessionId) {
      form.set("sessionId", opts.sessionId);
    }
    for (const id of opts.assetIds ?? []) {
      form.append("assetIds[]", String(id));
    }
    if (opts.toolMode) {
      form.set("toolMode", opts.toolMode);
    }
    if (opts.enabledTools !== undefined && opts.enabledTools !== null) {
      for (const name of opts.enabledTools) {
        form.append("enabledTools[]", name);
      }
    }
    form.set(this.bootstrap.csrfTokenName, this.bootstrap.csrfTokenValue);

    const res = await this.fetchImpl(this.resolveCreateCommentUrl(), {
      method: "POST",
      body: form,
      headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" },
      credentials: "same-origin",
    });
    if (!res.ok) {
      throw new Error(`Failed to create comment: ${res.status}`);
    }
    const payload: unknown = await res.json();
    if (
      typeof payload !== "object" ||
      payload === null ||
      typeof (payload as { comment?: unknown }).comment !== "object" ||
      (payload as { comment?: unknown }).comment === null
    ) {
      throw new Error("Malformed response from comments/create endpoint");
    }
    const comment = (payload as { comment: Record<string, unknown> }).comment;
    if (typeof comment.id !== "number" || typeof comment.sessionId !== "string") {
      throw new Error("Comment payload is missing id or sessionId");
    }
    return {
      id: comment.id,
      referenceId:
        typeof comment.referenceId === "string" ? comment.referenceId : null,
      sessionId: comment.sessionId,
    };
  }

  /**
   * Pull attachment metadata for a list of asset IDs so the composer
   * can render chips with filenames + thumbnails. Returns an empty
   * array (and warns) when the bootstrap lacks the URL — keeps the
   * composer usable without metadata rather than throwing inline.
   */
  async fetchAssetInfo(ids: number[]): Promise<Attachment[]> {
    if (ids.length === 0) return [];
    if (!this.bootstrap.assetsInfoUrl) {
      return [];
    }
    const url = new URL(
      this.bootstrap.assetsInfoUrl,
      globalThis.location?.href ?? "http://localhost/",
    );
    url.searchParams.set("ids", JSON.stringify(ids));
    const res = await this.fetchImpl(url.toString(), {
      headers: { Accept: "application/json" },
      credentials: "same-origin",
    });
    if (!res.ok) {
      throw new Error(`Failed to fetch asset info: ${res.status}`);
    }
    const data: unknown = await res.json();
    if (
      typeof data !== "object" ||
      data === null ||
      !Array.isArray((data as { assets?: unknown }).assets)
    ) {
      return [];
    }
    return (data as { assets: Attachment[] }).assets;
  }

  /**
   * Read the current tool-mode + tool catalog for a session. Mirrors
   * `ChatApi.fetchToolMode` so the composer can drive the same
   * permission-mode dropdown the chat surface uses.
   */
  async fetchToolMode(sessionId: string): Promise<ToolModePayload> {
    const url = new URL(
      this.bootstrap.toolModeUrl,
      globalThis.location?.href ?? "http://localhost/",
    );
    url.searchParams.set("sessionId", sessionId);
    const res = await this.fetchImpl(url.toString(), {
      headers: { Accept: "application/json" },
      credentials: "same-origin",
    });
    if (!res.ok) {
      throw new Error(`Failed to fetch tool mode: ${res.status}`);
    }
    const data: unknown = await res.json();
    return parseToolModePayload(data);
  }
}

/**
 * Best-effort decoder for the tool-mode payload. Mirrors the equivalent
 * parser in ChatApi rather than importing the chat-side one — keeping
 * the widget self-contained means a future refactor of the chat
 * surface can't break the composer.
 */
function parseToolModePayload(data: unknown): ToolModePayload {
  const obj = (typeof data === "object" && data !== null ? data : {}) as Record<
    string,
    unknown
  >;
  const mode = typeof obj.toolMode === "string" ? (obj.toolMode as ToolMode) : "full";
  const enabledTools = Array.isArray(obj.enabledTools)
    ? (obj.enabledTools as unknown[]).filter(
        (v): v is string => typeof v === "string",
      )
    : null;
  const availableTools: AvailableTool[] = Array.isArray(obj.availableTools)
    ? (obj.availableTools as AvailableTool[])
    : [];
  return { toolMode: mode, enabledTools, availableTools };
}
