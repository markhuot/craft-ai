import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { CompareApi } from "./api";
import { CompareHeader } from "./components/CompareHeader";
import { DiffBody } from "./components/DiffBody";
import { NarrativePanel } from "./components/NarrativePanel";
import type { Bootstrap, RevisionOption } from "./types";

export interface AppProps {
  bootstrap: Bootstrap;
  /** Inject a client in tests; otherwise built from the bootstrap. */
  api?: CompareApi;
}

/**
 * Rewrite the address bar to the current comparison without a navigation, so
 * a reload (or a copied URL) lands on the same A/B pair. We preserve only the
 * four params the compare view cares about — entryId, siteId, a, b — and drop
 * anything else off the query string.
 */
function syncUrl(baseUrl: string, entryId: number, siteId: number, a: string, b: string): void {
  if (typeof window === "undefined" || !window.history?.replaceState) return;
  try {
    const url = new URL(baseUrl, window.location.href);
    url.searchParams.set("entryId", String(entryId));
    url.searchParams.set("siteId", String(siteId));
    url.searchParams.set("a", a);
    url.searchParams.set("b", b);
    window.history.replaceState(window.history.state, "", url.toString());
  } catch {
    // A malformed base URL shouldn't break the diff — the address bar just
    // stays put.
  }
}

/**
 * Top-level compare view. Owns the A/B selection, the rendered diff, its
 * summary, and the narrative session id. The header drives selection and
 * recompute; the body shows the sandboxed diff; the side panel narrates it.
 *
 * Data flow: a picker change (or the Recompute button) calls `runDiff`, which
 * POSTs to the diff endpoint, stores the returned html/summary/sessionId, and
 * the NarrativePanel picks up the new session to start polling. Selection
 * changes also rewrite the URL so the view is reload-stable.
 */
export function App({ bootstrap, api: apiOverride }: AppProps) {
  const api = useMemo(
    () => apiOverride ?? CompareApi.fromBootstrap(bootstrap),
    [apiOverride, bootstrap],
  );

  const [revisions, setRevisions] = useState<RevisionOption[]>(bootstrap.revisions);
  const [a, setA] = useState(bootstrap.a);
  const [b, setB] = useState(bootstrap.b);
  const [html, setHtml] = useState<string | null>(null);
  const [sessionId, setSessionId] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  // Track the latest in-flight request so a stale response (user re-picked
  // before the prior diff returned) can't clobber the current one. Also lets
  // us refuse overlapping requests up front.
  const requestSeqRef = useRef(0);
  // Hold the live session id in a ref so `runDiff` can forward it to the
  // backend (reusing the same narrative session across recomputes) without
  // listing it as a dependency and re-allocating the callback each time.
  const sessionIdRef = useRef<string | null>(null);

  const runDiff = useCallback(
    async (nextA: string, nextB: string) => {
      if (nextA === "" || nextB === "") return;
      const seq = ++requestSeqRef.current;
      setLoading(true);
      setError(null);
      try {
        const result = await api.fetchDiff({
          entryId: bootstrap.entryId,
          siteId: bootstrap.siteId,
          a: nextA,
          b: nextB,
          sessionId: sessionIdRef.current,
        });
        // A newer request started while we awaited — discard this result.
        if (seq !== requestSeqRef.current) return;
        setHtml(result.html);
        if (result.sessionId) {
          sessionIdRef.current = result.sessionId;
          setSessionId(result.sessionId);
        }
        if (!result.ok) {
          setError("The diff request did not complete successfully.");
        }
      } catch (err) {
        if (seq !== requestSeqRef.current) return;
        setError(err instanceof Error ? err.message : "Failed to compute the diff.");
      } finally {
        if (seq === requestSeqRef.current) setLoading(false);
      }
    },
    [api, bootstrap.entryId, bootstrap.siteId],
  );

  // On mount, if both sides are pre-selected (the common case — B defaults to
  // 'current' and A may come from the URL), compute the diff right away.
  // Empty-deps: this is a one-shot kickoff, subsequent diffs go through the
  // picker/recompute handlers.
  useEffect(() => {
    if (bootstrap.a !== "" && bootstrap.b !== "") {
      void runDiff(bootstrap.a, bootstrap.b);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  // Refresh the revision list in the background so newly-saved revisions show
  // up in the pickers without a page reload. Non-fatal on failure — the
  // bootstrap list stays in place.
  useEffect(() => {
    let cancelled = false;
    void api
      .fetchRevisions({ entryId: bootstrap.entryId, siteId: bootstrap.siteId })
      .then((fetched) => {
        if (!cancelled && fetched.length > 0) setRevisions(fetched);
      })
      .catch(() => {
        /* keep the bootstrap list */
      });
    return () => {
      cancelled = true;
    };
  }, [api, bootstrap.entryId, bootstrap.siteId]);

  const onChangeA = useCallback(
    (ref: string) => {
      setA(ref);
      syncUrl(bootstrap.compareBaseUrl, bootstrap.entryId, bootstrap.siteId, ref, b);
      void runDiff(ref, b);
    },
    [b, bootstrap.compareBaseUrl, bootstrap.entryId, bootstrap.siteId, runDiff],
  );

  const onChangeB = useCallback(
    (ref: string) => {
      setB(ref);
      syncUrl(bootstrap.compareBaseUrl, bootstrap.entryId, bootstrap.siteId, a, ref);
      void runDiff(a, ref);
    },
    [a, bootstrap.compareBaseUrl, bootstrap.entryId, bootstrap.siteId, runDiff],
  );

  const onRecompute = useCallback(() => {
    void runDiff(a, b);
  }, [a, b, runDiff]);

  return (
    <div className="craftai-compare ai:flex ai:min-h-0 ai:flex-col ai:gap-4 ai:overflow-hidden">
      <CompareHeader
        entryTitle={bootstrap.entryTitle}
        revisions={revisions}
        a={a}
        b={b}
        busy={loading}
        onChangeA={onChangeA}
        onChangeB={onChangeB}
        onRecompute={onRecompute}
      />

      <div className="ai:grid ai:min-h-0 ai:flex-1 ai:gap-4 ai:lg:grid-cols-[2fr_1fr]">
        <div className="ai:flex ai:min-h-0 ai:min-w-0 ai:flex-col ai:gap-2">
          <DiffBody html={html} loading={loading} error={error} />
        </div>
        <NarrativePanel
          sessionId={sessionId}
          messagesUrl={bootstrap.messagesUrl}
          csrf={{ name: bootstrap.csrfTokenName, value: bootstrap.csrfTokenValue }}
        />
      </div>
    </div>
  );
}
