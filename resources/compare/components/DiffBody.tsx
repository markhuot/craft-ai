export interface DiffBodyProps {
  /** Self-contained diff document, or null/'' before the first diff. */
  html: string | null;
  /** True while a diff is computing — shows an overlay over the prior diff. */
  loading: boolean;
  /** Non-null when the last diff request failed. */
  error: string | null;
}

/**
 * Cheap, stable hash of the rendered HTML. Used only as a React `key` so the
 * iframe remounts on each recompute (setting `srcDoc` to the same string is a
 * DOM no-op, so without a fresh key a recompute that produced identical bytes
 * — or React reusing the node — could leave a stale frame mounted). Collisions
 * are harmless: at worst the iframe doesn't remount for two byte-identical
 * documents, which is the desired behavior anyway.
 */
function hashHtml(html: string): number {
  let hash = 0;
  for (let i = 0; i < html.length; i++) {
    hash = (hash * 31 + html.charCodeAt(i)) | 0;
  }
  return hash;
}

/**
 * Renders the rendered diff inside a fully-sandboxed iframe. The diff HTML is
 * treated as untrusted: `sandbox=""` with NO allow-scripts and NO
 * allow-same-origin, and we never touch `contentDocument`. The document is
 * delivered via `srcDoc` so there's no extra network round-trip.
 */
export function DiffBody({ html, loading, error }: DiffBodyProps) {
  const hasHtml = html !== null && html !== "";

  return (
    <div className="ai:relative ai:flex ai:min-h-0 ai:flex-1 ai:flex-col ai:overflow-hidden ai:rounded-md ai:border ai:border-craftai-border ai:bg-white">
      {error && (
        <p
          role="alert"
          className="ai:m-0 ai:border-b ai:border-red-200 ai:bg-red-50 ai:px-3 ai:py-2 ai:text-sm ai:text-red-700"
        >
          {error}
        </p>
      )}

      {hasHtml ? (
        <iframe
          // Remount on each distinct document so a recompute always paints
          // fresh — React skips a same-value srcDoc update otherwise.
          key={hashHtml(html)}
          title="Revision diff"
          // Untrusted content: empty sandbox, no script/same-origin escape.
          sandbox=""
          srcDoc={html}
          className="ai:h-full ai:w-full ai:flex-1 ai:border-0"
        />
      ) : (
        <div
          data-testid="diff-empty"
          className="ai:flex ai:flex-1 ai:items-center ai:justify-center ai:p-8 ai:text-center ai:text-sm ai:text-craftai-muted"
        >
          Pick a revision to compare against the current entry.
        </div>
      )}

      {loading && (
        <div
          data-testid="diff-loading"
          className="ai:absolute ai:inset-0 ai:flex ai:items-center ai:justify-center ai:bg-white/70"
        >
          <span className="ai:rounded ai:bg-black/70 ai:px-3 ai:py-1.5 ai:text-xs ai:text-white">
            Computing diff…
          </span>
        </div>
      )}
    </div>
  );
}
