/**
 * Shared types for the "compare two entry revisions" bundle. The Twig view
 * hands us a JSON bootstrap (see {@link Bootstrap}); everything else flows from
 * the diff/revisions/messages endpoints described there.
 */

/**
 * A version the user can pick on either side of the comparison. `ref` is the
 * opaque token the backend understands — one of `"current"`, `"rev:<id>"`, or
 * `"draft:<id>"`. The rest is display sugar for the picker.
 */
export interface RevisionOption {
  ref: string;
  label: string;
  /** Sequential revision number, or null for drafts / the current entry. */
  revisionNum: number | null;
  /** Author who saved this revision, or null when unknown. */
  savedBy: string | null;
  dateCreated?: string;
  dateUpdated?: string;
  /** True for the entry's current/live version — surfaced as the default B side. */
  isCurrent: boolean;
}

/**
 * Bootstrap payload the Twig view embeds in a
 * `<script type="application/json" data-craftai-compare-bootstrap>` tag. The
 * URLs are absolute CP routes; the CSRF pair is forwarded into POST bodies.
 */
export interface Bootstrap {
  entryId: number;
  entryTitle: string;
  siteId: number;
  /** Initial A ref, may be '' (nothing picked yet). */
  a: string;
  /** Initial B ref, defaults to 'current'. */
  b: string;
  revisions: RevisionOption[];
  /** POST — craft-ai/compare/diff */
  diffUrl: string;
  /** GET — craft-ai/compare/revisions */
  revisionsUrl: string;
  /** GET — craft-ai/messages (shared with the chat surface) */
  messagesUrl: string;
  /** cpUrl('ai/compare') — base for history.replaceState rewrites. */
  compareBaseUrl: string;
  csrfTokenName: string;
  csrfTokenValue: string;
}

/** Summary counts the backend computes for the rendered diff. */
export interface DiffSummary {
  changed: number;
  added: number;
  removed: number;
  unchanged: number;
}

/** One resolved side of the comparison, echoed back by the diff endpoint. */
export interface DiffSide {
  ref: string;
  label: string;
  [key: string]: unknown;
}

/**
 * Response from `POST craft-ai/compare/diff`. `html` is a self-contained
 * document rendered server-side — we drop it into a sandboxed iframe via
 * `srcDoc` and never read its contentDocument. `sessionId` (when present)
 * points the narrative panel at the agent run explaining the diff.
 */
export interface DiffResponse {
  ok: boolean;
  /**
   * True when the server reused a memoized comparison (same A/B pair, unchanged
   * content) — the narration session + artifact already existed, so no new
   * session was created and no LLM run was kicked off.
   */
  reused: boolean;
  sessionId: string | null;
  artifactId: number | null;
  artifactUrl: string | null;
  html: string | null;
  summary: DiffSummary | null;
  a: DiffSide | null;
  b: DiffSide | null;
}
