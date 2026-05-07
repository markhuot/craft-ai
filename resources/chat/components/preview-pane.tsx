import { forwardRef, useEffect, useImperativeHandle, useRef, useState } from "react";
import { Maximize2, Minimize2, X } from "lucide-react";

export type PreviewPaneMode = "peek" | "expanded";

export interface PreviewPaneHandle {
  /**
   * Read the iframe's contents from the host. Returns text or HTML
   * depending on the requested mode. Throws on cross-origin iframes —
   * the iframe document isn't accessible from JS in that case.
   */
  readContents(mode: "text" | "full"): string;
}

export interface PreviewPaneProps {
  url: string;
  mode: PreviewPaneMode;
  /**
   * `true` while the agent is waiting on this iframe (i.e., between
   * mount and onload). Used to overlay a small spinner so the user
   * sees something is happening.
   */
  loading: boolean;
  /**
   * Bump this counter to force the iframe to reload even if `url` hasn't
   * changed. Repeat `open_preview` calls for the URL the iframe is
   * already showing would otherwise be a React no-op (same `src` string
   * skipped by the DOM diff), so `onLoad` would never fire and the new
   * request would time out.
   */
  reloadKey?: number;
  onLoad: (finalUrl: string) => void;
  onError: (message: string) => void;
  onExpand: () => void;
  onCollapse: () => void;
  onClose: () => void;
}

/**
 * The iframe pane itself — header bar with expand/collapse/close, plus the
 * iframe. Layout/positioning (peek slot vs. fixed-overlay) is the parent's
 * concern; this component only knows about its two visual modes for the
 * controls it surfaces.
 *
 * Exposes a `readContents` imperative handle so the chat surface can pull
 * iframe text/HTML when a `GetPreview` tool request comes through.
 */
export const PreviewPane = forwardRef<PreviewPaneHandle, PreviewPaneProps>(function PreviewPane(
  { url, mode, loading, reloadKey = 0, onLoad, onError, onExpand, onCollapse, onClose },
  ref,
) {
  const iframeRef = useRef<HTMLIFrameElement | null>(null);
  // Tracks the URL we last fired onLoad for. Dedupes by the *resolved*
  // location (not the requested `url` prop) so a real in-iframe navigation
  // — user clicks a link inside the preview, frame loads a different page —
  // still surfaces an onLoad with the new URL, while a re-fired load event
  // for the same document collapses to a single callback.
  const [loadedUrl, setLoadedUrl] = useState<string | null>(null);

  useImperativeHandle(
    ref,
    () => ({
      readContents(contentMode) {
        const frame = iframeRef.current;
        if (!frame) {
          throw new Error("Preview frame is not mounted.");
        }
        // contentDocument throws a SecurityError on cross-origin frames.
        // The catch path lets the parent surface that as a graceful tool
        // error instead of an uncaught exception in the React render.
        let doc: Document | null;
        try {
          doc = frame.contentDocument;
        } catch (err) {
          throw new Error(
            err instanceof Error
              ? `Cross-origin preview: ${err.message}`
              : "Cross-origin preview: cannot read iframe contents.",
          );
        }
        if (!doc) {
          throw new Error("Preview frame has no document yet.");
        }
        if (contentMode === "full") {
          return doc.documentElement?.outerHTML ?? "";
        }
        return doc.body?.innerText ?? doc.documentElement?.textContent ?? "";
      },
    }),
    [],
  );

  useEffect(() => {
    // Parent swapped the requested URL out from under us — clear so the
    // next load signal fires fresh even if the iframe ends up at the same
    // address we last reported. Also reacts to reloadKey bumps so a
    // repeat-open of the same URL doesn't get dedup'd against the prior
    // resolved location.
    setLoadedUrl(null);
  }, [url, reloadKey]);

  const handleLoad = () => {
    let finalUrl = url;
    try {
      const href = iframeRef.current?.contentWindow?.location?.href;
      if (href && href !== "about:blank") finalUrl = href;
    } catch {
      // Cross-origin: keep the requested URL, the agent can fall back
      // to fetch_webpage if it needs to verify the actual destination.
    }
    if (loadedUrl === finalUrl) return;
    setLoadedUrl(finalUrl);
    onLoad(finalUrl);
  };

  const handleError = () => {
    onError("Preview iframe failed to load.");
  };

  return (
    <div
      data-testid="preview-pane"
      data-mode={mode}
      className="ai:flex ai:min-h-0 ai:flex-1 ai:flex-col ai:overflow-hidden ai:rounded ai:border ai:border-craftai-border ai:bg-white"
      aria-label="Page preview"
    >
      <header className="ai:flex ai:items-center ai:gap-2 ai:border-b ai:border-craftai-border ai:bg-craftai-bg ai:px-2 ai:py-1.5 ai:text-xs">
        <span
          data-testid="preview-url"
          className="ai:flex-1 ai:truncate ai:text-craftai-muted"
          title={loadedUrl ?? url}
        >
          {loadedUrl ?? url}
        </span>
        {mode === "peek" ? (
          <button
            type="button"
            data-testid="preview-expand"
            aria-label="Expand preview"
            onClick={onExpand}
            className="ai:inline-flex ai:h-7 ai:w-7 ai:items-center ai:justify-center ai:rounded ai:text-craftai-fg hover:ai:bg-craftai-border/30"
          >
            <Maximize2 aria-hidden className="ai:h-3.5 ai:w-3.5" />
          </button>
        ) : (
          <button
            type="button"
            data-testid="preview-shrink"
            aria-label="Shrink preview"
            onClick={onCollapse}
            className="ai:inline-flex ai:h-7 ai:w-7 ai:items-center ai:justify-center ai:rounded ai:text-craftai-fg hover:ai:bg-craftai-border/30"
          >
            <Minimize2 aria-hidden className="ai:h-3.5 ai:w-3.5" />
          </button>
        )}
        <button
          type="button"
          data-testid="preview-close"
          aria-label="Close preview"
          onClick={onClose}
          className="ai:inline-flex ai:h-7 ai:w-7 ai:items-center ai:justify-center ai:rounded ai:text-craftai-fg hover:ai:bg-craftai-border/30"
        >
          <X aria-hidden className="ai:h-4 ai:w-4" />
        </button>
      </header>

      <div className="ai:relative ai:flex ai:min-h-0 ai:flex-1 ai:bg-white">
        <iframe
          // Keying on (url, reloadKey) forces React to unmount the prior
          // iframe and mount a new one whenever the parent signals a fresh
          // open — otherwise setting iframe.src to the same value is a no-op
          // and onLoad never fires for the second request.
          key={`${url}#${reloadKey}`}
          ref={iframeRef}
          data-testid="preview-iframe"
          src={url}
          title="Preview"
          onLoad={handleLoad}
          onError={handleError}
          className="ai:h-full ai:w-full ai:border-0"
        />
        {loading && (
          <div
            data-testid="preview-loading"
            className="ai:pointer-events-none ai:absolute ai:right-2 ai:top-2 ai:rounded ai:bg-black/60 ai:px-2 ai:py-1 ai:text-[11px] ai:text-white"
          >
            Loading…
          </div>
        )}
      </div>
    </div>
  );
});
