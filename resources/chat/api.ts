import type { ChatMessage } from "./types";

export type FetchLike = (input: string | URL | Request, init?: RequestInit) => Promise<Response>;

export interface ChatApiOptions {
  messagesUrl: string;
  sendUrl: string;
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

  async fetchMessagesAfter(lastId: number): Promise<ChatMessage[]> {
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
    if (!Array.isArray(data)) return [];
    return data as ChatMessage[];
  }

  async sendMessage(message: string): Promise<void> {
    const body = new FormData();
    body.append("sessionId", this.opts.sessionId);
    body.append("message", message);
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
}
