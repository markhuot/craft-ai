import type { SessionListItem } from "../chat/types";
import type { WidgetBootstrap } from "./types";

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
}
