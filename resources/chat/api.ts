import type { Attachment, ChatMessage, MessagesResponse, PreviewRequest } from "./types";

export type FetchLike = (input: string | URL | Request, init?: RequestInit) => Promise<Response>;

export interface ChatApiOptions {
  messagesUrl: string;
  sendUrl: string;
  assetsInfoUrl: string;
  /** Empty string disables preview-related calls (used by tests/widget). */
  previewRespondUrl?: string;
  sessionId: string;
  csrfTokenName: string;
  csrfTokenValue: string;
  fetchImpl?: FetchLike;
}

export class ChatApi {
  private readonly opts: ChatApiOptions;
  private readonly fetchImpl: FetchLike;

  constructor(opts: ChatApiOptions) {
    this.opts = opts;
    this.fetchImpl = opts.fetchImpl ?? globalThis.fetch.bind(globalThis);
  }

  /**
   * Returns the message envelope. Older code expected a bare array — call
   * {@link parseMessagesResponse} (now built in) so legacy fixtures still work.
   */
  async fetchMessagesAfter(lastId: number): Promise<MessagesResponse> {
    const url = new URL(this.opts.messagesUrl, globalThis.location?.href ?? "http://localhost/");
    url.searchParams.set("sessionId", this.opts.sessionId);
    url.searchParams.set("after", String(lastId));

    const res = await this.fetchImpl(url.toString(), {
      headers: { Accept: "application/json" },
      credentials: "same-origin",
    });
    if (!res.ok) {
      throw new Error(`Failed to fetch messages: ${res.status}`);
    }
    const data: unknown = await res.json();
    return parseMessagesResponse(data);
  }

  async respondToPreviewRequest(
    id: number,
    status: "completed" | "errored",
    result: Record<string, unknown>,
  ): Promise<void> {
    if (!this.opts.previewRespondUrl) {
      throw new Error("Preview response URL is not configured");
    }
    const body = new FormData();
    body.append("id", String(id));
    body.append("status", status);
    body.append("result", JSON.stringify(result));
    body.append(this.opts.csrfTokenName, this.opts.csrfTokenValue);

    const res = await this.fetchImpl(this.opts.previewRespondUrl, {
      method: "POST",
      body,
      headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" },
      credentials: "same-origin",
    });
    if (!res.ok) {
      throw new Error(`Failed to respond to preview request: ${res.status}`);
    }
  }

  async sendMessage(
    message: string,
    assetIds: number[] = [],
    context?: unknown,
  ): Promise<void> {
    const body = new FormData();
    body.append("sessionId", this.opts.sessionId);
    body.append("message", message);
    if (assetIds.length > 0) {
      // Send as a JSON-encoded string so the controller gets a single,
      // unambiguous value. PHP's $_POST would otherwise see an array of
      // string ids, and many clients (incl. tests) build FormData by hand.
      body.append("assetIds", JSON.stringify(assetIds));
    }
    if (context !== undefined && context !== null) {
      body.append("context", JSON.stringify(context));
    }
    body.append(this.opts.csrfTokenName, this.opts.csrfTokenValue);

    const res = await this.fetchImpl(this.opts.sendUrl, {
      method: "POST",
      body,
      headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" },
      credentials: "same-origin",
    });
    if (!res.ok) {
      throw new Error(`Send failed: ${res.status}`);
    }
  }

  async fetchAssetInfo(ids: number[]): Promise<Attachment[]> {
    if (ids.length === 0) return [];

    const url = new URL(this.opts.assetsInfoUrl, globalThis.location?.href ?? "http://localhost/");
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
}

/**
 * Tolerate both the modern envelope (`{messages, previewRequest}`) and a
 * legacy bare-array body — older deployments and the existing tests both
 * stub `fetchImpl` returning `[]`. Defensive parsing here keeps a stale
 * fixture from breaking the chat surface.
 */
function parseMessagesResponse(data: unknown): MessagesResponse {
  if (Array.isArray(data)) {
    return { messages: data as ChatMessage[], previewRequest: null };
  }
  if (typeof data !== "object" || data === null) {
    return { messages: [], previewRequest: null };
  }
  const obj = data as { messages?: unknown; previewRequest?: unknown };
  const messages = Array.isArray(obj.messages) ? (obj.messages as ChatMessage[]) : [];
  return { messages, previewRequest: parsePreviewRequest(obj.previewRequest) };
}

function parsePreviewRequest(value: unknown): PreviewRequest | null {
  if (typeof value !== "object" || value === null) return null;
  const obj = value as Record<string, unknown>;
  if (typeof obj.id !== "number") return null;
  if (obj.type !== "open" && obj.type !== "get") return null;
  if (obj.status !== "pending") return null;
  const input = typeof obj.input === "object" && obj.input !== null
    ? (obj.input as Record<string, unknown>)
    : {};
  return { id: obj.id, type: obj.type, status: obj.status, input };
}
