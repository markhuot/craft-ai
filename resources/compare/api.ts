import type { Bootstrap, DiffResponse, DiffSide, DiffSummary, RevisionOption } from "./types";

export type FetchLike = (input: string | URL | Request, init?: RequestInit) => Promise<Response>;

/**
 * One assistant/user message as the shared `MessagesController` serializes it.
 * We only care about the id (for the `after` cursor), role, and the text
 * blocks inside `content` — the narrative panel renders those as markdown and
 * ignores everything else (tool calls, thinking, attachments).
 */
export interface CompareMessage {
  id: number;
  role: string;
  content: Array<{ type: string; text?: string; [key: string]: unknown }>;
  [key: string]: unknown;
}

export interface CompareApiOptions {
  diffUrl: string;
  revisionsUrl: string;
  messagesUrl: string;
  csrfTokenName: string;
  csrfTokenValue: string;
  fetchImpl?: FetchLike;
}

export interface FetchDiffParams {
  entryId: number;
  siteId: number;
  a: string;
  b: string;
  /** Reuse an existing narrative session when recomputing, if we have one. */
  sessionId?: string | null;
}

/**
 * Dedicated client for the compare surface. Deliberately standalone — it
 * copies the CSRF/fetch shape from the chat bundle's api.ts rather than
 * importing it, since each bundle ships independently.
 */
export class CompareApi {
  private readonly opts: CompareApiOptions;
  private readonly fetchImpl: FetchLike;

  constructor(opts: CompareApiOptions) {
    this.opts = opts;
    this.fetchImpl = opts.fetchImpl ?? globalThis.fetch.bind(globalThis);
  }

  /** Build one from the bootstrap payload — the common case. */
  static fromBootstrap(bootstrap: Bootstrap, fetchImpl?: FetchLike): CompareApi {
    return new CompareApi({
      diffUrl: bootstrap.diffUrl,
      revisionsUrl: bootstrap.revisionsUrl,
      messagesUrl: bootstrap.messagesUrl,
      csrfTokenName: bootstrap.csrfTokenName,
      csrfTokenValue: bootstrap.csrfTokenValue,
      fetchImpl,
    });
  }

  /**
   * POST a diff request. Returns the rendered (self-contained) HTML plus the
   * summary counts and the narrative session id. The body carries the CSRF
   * token as a form field — same as the chat send.
   */
  async fetchDiff({ entryId, siteId, a, b, sessionId }: FetchDiffParams): Promise<DiffResponse> {
    const body = new FormData();
    body.append("entryId", String(entryId));
    body.append("siteId", String(siteId));
    body.append("a", a);
    body.append("b", b);
    if (sessionId) {
      body.append("sessionId", sessionId);
    }
    body.append(this.opts.csrfTokenName, this.opts.csrfTokenValue);

    const res = await this.fetchImpl(this.opts.diffUrl, {
      method: "POST",
      body,
      headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" },
      credentials: "same-origin",
    });
    if (!res.ok) {
      throw new Error(`Diff failed: ${res.status}`);
    }
    return parseDiffResponse(await res.json());
  }

  /**
   * GET the revision list for an entry/site. Used to refresh the pickers
   * after a recompute — the bootstrap seeds the first render, this keeps it
   * current if new revisions land while the page is open.
   */
  async fetchRevisions({
    entryId,
    siteId,
  }: {
    entryId: number;
    siteId: number;
  }): Promise<RevisionOption[]> {
    const url = new URL(this.opts.revisionsUrl, globalThis.location?.href ?? "http://localhost/");
    url.searchParams.set("entryId", String(entryId));
    url.searchParams.set("siteId", String(siteId));

    const res = await this.fetchImpl(url.toString(), {
      headers: { Accept: "application/json" },
      credentials: "same-origin",
    });
    if (!res.ok) {
      throw new Error(`Failed to fetch revisions: ${res.status}`);
    }
    const data: unknown = await res.json();
    if (
      typeof data !== "object" ||
      data === null ||
      !Array.isArray((data as { revisions?: unknown }).revisions)
    ) {
      return [];
    }
    return parseRevisionOptions((data as { revisions: unknown[] }).revisions);
  }

  /**
   * GET messages newer than `lastId` for the narrative session. Mirrors the
   * chat surface's `?sessionId=…&after=…` poll, but we only return the bare
   * message array — the narrative panel doesn't need the preview/context
   * envelope fields.
   */
  async fetchMessagesAfter(sessionId: string, lastId: number): Promise<CompareMessage[]> {
    const url = new URL(this.opts.messagesUrl, globalThis.location?.href ?? "http://localhost/");
    url.searchParams.set("sessionId", sessionId);
    url.searchParams.set("after", String(lastId));

    const res = await this.fetchImpl(url.toString(), {
      headers: { Accept: "application/json" },
      credentials: "same-origin",
    });
    if (!res.ok) {
      throw new Error(`Failed to fetch messages: ${res.status}`);
    }
    return parseMessages(await res.json());
  }
}

/**
 * Defensive parse for the diff response. The server is the source of truth,
 * but we coerce each field so a partial/legacy payload can't leave the UI in
 * an unrenderable state — `html: null` simply renders the empty diff state.
 */
function parseDiffResponse(data: unknown): DiffResponse {
  if (typeof data !== "object" || data === null) {
    return {
      ok: false,
      reused: false,
      sessionId: null,
      artifactId: null,
      artifactUrl: null,
      html: null,
      summary: null,
      a: null,
      b: null,
    };
  }
  const obj = data as Record<string, unknown>;
  return {
    ok: obj.ok === true,
    reused: obj.reused === true,
    sessionId:
      typeof obj.sessionId === "string" && obj.sessionId !== "" ? obj.sessionId : null,
    artifactId: typeof obj.artifactId === "number" ? obj.artifactId : null,
    artifactUrl:
      typeof obj.artifactUrl === "string" && obj.artifactUrl !== "" ? obj.artifactUrl : null,
    html: typeof obj.html === "string" && obj.html !== "" ? obj.html : null,
    summary: parseSummary(obj.summary),
    a: parseSide(obj.a),
    b: parseSide(obj.b),
  };
}

function parseSummary(value: unknown): DiffSummary | null {
  if (typeof value !== "object" || value === null) return null;
  const obj = value as Record<string, unknown>;
  const num = (v: unknown): number => (typeof v === "number" && Number.isFinite(v) ? v : 0);
  return {
    changed: num(obj.changed),
    added: num(obj.added),
    removed: num(obj.removed),
    unchanged: num(obj.unchanged),
  };
}

function parseSide(value: unknown): DiffSide | null {
  if (typeof value !== "object" || value === null) return null;
  const obj = value as Record<string, unknown>;
  const ref = typeof obj.ref === "string" ? obj.ref : "";
  if (ref === "") return null;
  return {
    ...obj,
    ref,
    label: typeof obj.label === "string" ? obj.label : ref,
  };
}

/**
 * Parse the revisions endpoint payload. Drops any entry without a usable
 * `ref` so a malformed row can't render a blank, unselectable option.
 */
function parseRevisionOptions(value: unknown[]): RevisionOption[] {
  const out: RevisionOption[] = [];
  for (const entry of value) {
    if (typeof entry !== "object" || entry === null) continue;
    const obj = entry as Record<string, unknown>;
    if (typeof obj.ref !== "string" || obj.ref === "") continue;
    out.push({
      ref: obj.ref,
      label: typeof obj.label === "string" ? obj.label : obj.ref,
      revisionNum: typeof obj.revisionNum === "number" ? obj.revisionNum : null,
      savedBy: typeof obj.savedBy === "string" && obj.savedBy !== "" ? obj.savedBy : null,
      dateCreated: typeof obj.dateCreated === "string" ? obj.dateCreated : undefined,
      dateUpdated: typeof obj.dateUpdated === "string" ? obj.dateUpdated : undefined,
      isCurrent: obj.isCurrent === true,
    });
  }
  return out;
}

/**
 * Tolerate both the modern `{messages: [...]}` envelope and a bare array.
 * Only id/role/content survive — the narrative panel ignores the rest.
 */
function parseMessages(data: unknown): CompareMessage[] {
  const raw = Array.isArray(data)
    ? data
    : typeof data === "object" &&
        data !== null &&
        Array.isArray((data as { messages?: unknown }).messages)
      ? (data as { messages: unknown[] }).messages
      : [];

  const out: CompareMessage[] = [];
  for (const entry of raw) {
    if (typeof entry !== "object" || entry === null) continue;
    const obj = entry as Record<string, unknown>;
    if (typeof obj.id !== "number") continue;
    out.push({
      ...obj,
      id: obj.id,
      role: typeof obj.role === "string" ? obj.role : "",
      content: Array.isArray(obj.content)
        ? (obj.content as CompareMessage["content"])
        : [],
    });
  }
  return out;
}
