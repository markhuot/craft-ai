import {
  type KeyboardEvent as ReactKeyboardEvent,
  useCallback,
  useEffect,
  useMemo,
  useRef,
  useState,
} from "react";
import { ArrowLeft, Loader2, MessageCircle, Paperclip, Plus, Send, X } from "lucide-react";
import { Chat } from "../chat/Chat";
import { PermissionMode } from "../chat/components/permission-mode";
import { openAssetSelector } from "../chat/lib/assetSelector";
import type {
  Attachment,
  AvailableTool,
  ChatBootstrap,
  SessionListItem,
  TargetSelection,
  ToolMode,
} from "../chat/types";
import { WidgetApi } from "./api";
import { useElementPicker } from "./lib/useElementPicker";
import type { CommentDraftRequest, WidgetBootstrap } from "./types";

type ViewMode = "closed" | "chat" | "sessions" | "compose-comment";

const STORAGE_KEY = "craftai-widget:active-session";
const OPEN_STORAGE_KEY = "craftai-widget:open";

export interface WidgetProps {
  bootstrap: WidgetBootstrap;
  api?: WidgetApi;
  /**
   * Tests can stub localStorage access so we don't reach into the real
   * `window.localStorage` (which jsdom/happy-dom doesn't always preserve).
   */
  storage?: Pick<Storage, "getItem" | "setItem" | "removeItem">;
}

export function Widget({ bootstrap, api: apiOverride, storage }: WidgetProps) {
  const api = useMemo(() => apiOverride ?? new WidgetApi({ bootstrap }), [apiOverride, bootstrap]);
  const store = storage ?? (typeof window !== "undefined" ? window.localStorage : undefined);

  // Initial view is computed synchronously from localStorage so a previously
  // open widget renders the panel on first paint (no bubble flash before the
  // effect runs). The actual session resolution still happens async in the
  // mount effect below.
  const [view, setView] = useState<ViewMode>(() =>
    store?.getItem(OPEN_STORAGE_KEY) === "true" ? "chat" : "closed",
  );
  const [sessions, setSessions] = useState<SessionListItem[]>([]);
  const [activeSessionId, setActiveSessionId] = useState<string | null>(() =>
    store ? store.getItem(STORAGE_KEY) : null,
  );
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  // Span-comment draft state. Populated when the CKEditor "Comment"
  // plugin dispatches `craftai:start-comment` — the composer view
  // reads from it and posts back via the WidgetApi.createComment. We
  // store the entire request payload (not just the body draft) so the
  // composer can show contextual chrome ("commenting on …") without
  // duplicating bootstrap state.
  const [commentDraft, setCommentDraft] = useState<CommentDraftRequest | null>(
    null,
  );
  // Targeting mode: while `targeting` is true the picker hook is live (mouse
  // tracking + highlight + Esc/click handling). Once the user picks an
  // element it goes into `selectedTarget` and the picker turns off again.
  // The selection survives across picker toggles so the user can re-pick
  // without losing context (they'd just overwrite it).
  const [targeting, setTargeting] = useState(false);
  const [selectedTarget, setSelectedTarget] = useState<TargetSelection | null>(null);
  const rootRef = useRef<HTMLDivElement | null>(null);
  // Guards the mount-time restoration effect against StrictMode's double-fire,
  // which would otherwise call openWidget twice and (when the user has no
  // sessions) provision two of them back-to-back.
  const restoredRef = useRef(false);

  // Exclude the widget itself from picker hits. The hook climbs from the
  // shadow host upward, but elementFromPoint on the host page only ever
  // returns the host element for clicks "through" the shadow tree — so
  // skipping the host (and the bubble it contains) is sufficient.
  const getExcludedRoot = useCallback(() => {
    const el = rootRef.current;
    if (!el) return null;
    // Walk up to the shadow host so the picker treats the entire widget
    // (including overlays we render in this tree) as off-limits.
    const root = el.getRootNode();
    if (root instanceof ShadowRoot) {
      return root.host;
    }
    return el;
  }, []);

  const { highlightRect } = useElementPicker({
    active: targeting,
    onPick: (selection) => {
      setSelectedTarget(selection);
      setTargeting(false);
    },
    onCancel: () => setTargeting(false),
    getExcludedRoot,
  });

  const startTargeting = useCallback(() => {
    setTargeting(true);
  }, []);

  const clearTarget = useCallback(() => {
    setSelectedTarget(null);
  }, []);

  const cancelTargeting = useCallback(() => {
    setTargeting(false);
  }, []);

  const persistSession = useCallback(
    (id: string | null) => {
      if (!store) return;
      if (id) {
        store.setItem(STORAGE_KEY, id);
      } else {
        store.removeItem(STORAGE_KEY);
      }
    },
    [store],
  );

  const persistOpen = useCallback(
    (open: boolean) => {
      if (!store) return;
      if (open) {
        store.setItem(OPEN_STORAGE_KEY, "true");
      } else {
        store.removeItem(OPEN_STORAGE_KEY);
      }
    },
    [store],
  );

  const loadSessions = useCallback(async (): Promise<SessionListItem[]> => {
    try {
      const list = await api.fetchSessions();
      setSessions(list);
      return list;
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to load sessions");
      return [];
    }
  }, [api]);

  const startNewSession = useCallback(async (): Promise<string | null> => {
    setBusy(true);
    setError(null);
    try {
      const id = await api.createSession();
      setActiveSessionId(id);
      persistSession(id);
      // Refresh the sidebar list so the new session shows up next time the
      // user opens the picker. Failure here is non-fatal — the chat itself
      // still works against the freshly-minted id.
      await loadSessions();
      return id;
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to start a new session");
      return null;
    } finally {
      setBusy(false);
    }
  }, [api, loadSessions, persistSession]);

  const openWidget = useCallback(async () => {
    setView("chat");
    persistOpen(true);
    setError(null);
    const list = await loadSessions();

    // Resolve which session to land on. Priority order:
    //   1. The most-recently-selected session, if it still exists server-side.
    //   2. The most-recently-active session in the user's list.
    //   3. A brand-new session if the user has none yet.
    const stored = store?.getItem(STORAGE_KEY) ?? null;
    if (stored && list.some((s) => s.sessionId === stored)) {
      setActiveSessionId(stored);
      return;
    }
    if (list.length > 0) {
      const next = list[0];
      if (next) {
        setActiveSessionId(next.sessionId);
        persistSession(next.sessionId);
        return;
      }
    }
    await startNewSession();
  }, [loadSessions, persistOpen, persistSession, startNewSession, store]);

  const closeWidget = useCallback(() => {
    setView("closed");
    persistOpen(false);
  }, [persistOpen]);

  // If the widget was open on the previous page (per localStorage), the
  // initial view state above already renders the panel. Kick off the same
  // session-resolution work that `openWidget` does so the user lands on the
  // right session without having to click the bubble again. Runs once on
  // mount; the open/closed state thereafter is driven by user clicks.
  useEffect(() => {
    if (restoredRef.current) return;
    restoredRef.current = true;
    if (view !== "chat") return;
    void openWidget();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const showSessions = useCallback(() => {
    setView("sessions");
    void loadSessions();
  }, [loadSessions]);

  const selectSession = useCallback(
    (id: string) => {
      setActiveSessionId(id);
      persistSession(id);
      setView("chat");
    },
    [persistSession],
  );

  // External "open this specific session" hook. The CP comments overlay
  // dispatches `craftai:open-session` when the user clicks a comment in
  // the popover; we honour it by switching to chat view and pinning the
  // requested session. Refreshing the sidebar list is best-effort — if
  // the request fails the chat still mounts against the supplied id and
  // the user can interact normally.
  useEffect(() => {
    const handler = (e: Event) => {
      const detail = (e as CustomEvent<{ sessionId?: unknown }>).detail;
      const sessionId =
        detail && typeof detail.sessionId === "string" ? detail.sessionId : null;
      if (!sessionId) return;
      setView("chat");
      persistOpen(true);
      setActiveSessionId(sessionId);
      persistSession(sessionId);
      void loadSessions();
    };
    document.addEventListener("craftai:open-session", handler);
    return () => document.removeEventListener("craftai:open-session", handler);
  }, [loadSessions, persistOpen, persistSession]);

  // The CKEditor "Comment" toolbar plugin dispatches this whenever the
  // editor selects text and clicks the toolbar button. We capture the
  // payload, switch the widget into composer mode, and pop the panel
  // open. The composer's own submit handler is responsible for closing
  // the loop (POST → dispatch `craftai:comment-created` → restore the
  // panel to its previous state).
  useEffect(() => {
    const handler = (e: Event) => {
      const detail = (e as CustomEvent<Partial<CommentDraftRequest>>).detail;
      if (
        !detail ||
        typeof detail.elementId !== "number" ||
        typeof detail.fieldHandle !== "string" ||
        typeof detail.referenceId !== "string"
      ) {
        return;
      }
      setCommentDraft({
        elementId: detail.elementId,
        isDraft: !!detail.isDraft,
        fieldHandle: detail.fieldHandle,
        referenceId: detail.referenceId,
        selectionText: detail.selectionText ?? "",
      });
      setView("compose-comment");
      persistOpen(true);
      setError(null);
    };
    document.addEventListener("craftai:start-comment", handler);
    return () => document.removeEventListener("craftai:start-comment", handler);
  }, [persistOpen]);

  /**
   * Close the composer without saving. Returns to the chat surface (if
   * one was active) or to closed — whichever feels least surprising
   * given that opening the composer overrode the previous view.
   */
  const cancelComposer = useCallback(() => {
    setCommentDraft(null);
    // Active session was preserved through the composer detour, so
    // landing back on the chat view (when one exists) is correct.
    setView(activeSessionId ? "chat" : "closed");
    if (!activeSessionId) persistOpen(false);
  }, [activeSessionId, persistOpen]);

  /**
   * Submit the composed comment. Server creates the CommentRecord
   * (reusing the composer's pre-created session if one was set up)
   * and we then dispatch `craftai:comment-created` so the CKEditor
   * plugin can wrap the matching span, plus a `craftai:comments-changed`
   * event so the overlay refreshes its list.
   *
   * The full toolbar state (sessionId, assetIds, toolMode,
   * enabledTools) flows through here from the composer because the
   * server uses the same endpoint whether the composer pre-created a
   * session or not — passing them along is just glue.
   */
  const submitComment = useCallback(
    async (input: {
      body: string;
      sessionId?: string;
      assetIds?: number[];
      toolMode?: ToolMode;
      enabledTools?: string[] | null;
    }): Promise<boolean> => {
      if (!commentDraft) return false;
      setBusy(true);
      setError(null);
      try {
        const created = await api.createComment(commentDraft, input.body, {
          sessionId: input.sessionId,
          assetIds: input.assetIds,
          toolMode: input.toolMode,
          enabledTools: input.enabledTools,
        });
        document.dispatchEvent(
          new CustomEvent("craftai:comment-created", {
            detail: {
              commentId: created.id,
              referenceId: created.referenceId ?? commentDraft.referenceId,
              elementId: commentDraft.elementId,
              isDraft: commentDraft.isDraft,
              fieldHandle: commentDraft.fieldHandle,
              sessionId: created.sessionId,
            },
          }),
        );
        // Nudge the overlay to refetch so the indicator dot renders
        // even before the editor's downcast pass completes — the two
        // event names are intentionally separate (one is "a comment
        // exists, wrap the span," the other is "your cached list is
        // stale") because future callers might want one without the
        // other.
        document.dispatchEvent(
          new CustomEvent("craftai:comments-changed", {
            detail: { source: "widget-composer", commentId: created.id },
          }),
        );
        // Hand the user off to the session their comment just created
        // so they can keep talking to the agent about it without an
        // extra click. The previously-active session is preserved
        // under the hood (loadSessions repopulates the sidebar with
        // both) — they can switch back via the sessions list.
        setCommentDraft(null);
        setActiveSessionId(created.sessionId);
        persistSession(created.sessionId);
        setView("chat");
        persistOpen(true);
        void loadSessions();
        return true;
      } catch (err) {
        setError(err instanceof Error ? err.message : "Failed to save the comment");
        return false;
      } finally {
        setBusy(false);
      }
    },
    [api, commentDraft, loadSessions, persistOpen, persistSession],
  );

  // Close on Escape from anywhere within the widget. We intentionally listen
  // on the shadow root's owner document so the host page's other Escape
  // handlers still fire too. Suppressed while targeting — the picker hook
  // owns Escape in that mode (it'd be jarring if pressing Escape to back
  // out of element-picking also closed the chat).
  useEffect(() => {
    if (view === "closed") return;
    if (targeting) return;
    const onKey = (e: KeyboardEvent) => {
      if (e.key === "Escape") closeWidget();
    };
    document.addEventListener("keydown", onKey);
    return () => document.removeEventListener("keydown", onKey);
  }, [view, targeting, closeWidget]);

  if (view === "closed") {
    return (
      <div
        ref={rootRef}
        className="craftai-widget-root"
        data-testid="widget-root"
        data-view="closed"
      >
        <button
          type="button"
          className="craftai-widget-bubble"
          aria-label="Open Craft AI chat"
          data-testid="widget-bubble"
          onClick={() => {
            void openWidget();
          }}
        >
          <MessageCircle aria-hidden className="ai:h-6 ai:w-6" />
        </button>
      </div>
    );
  }

  return (
    <div
      ref={rootRef}
      className="craftai-widget-root"
      data-testid="widget-root"
      data-view={view}
      data-targeting={targeting ? "true" : undefined}
    >
      {targeting && (
        <>
          <div
            data-testid="target-banner"
            className="craftai-target-banner"
            role="status"
          >
            <span>Click an element on the page to target it</span>
            <button
              type="button"
              onClick={cancelTargeting}
              aria-label="Cancel targeting"
              className="craftai-target-cancel"
            >
              Cancel (Esc)
            </button>
          </div>
          {highlightRect && (
            <div
              data-testid="target-highlight"
              className="craftai-target-highlight"
              style={{
                top: highlightRect.top,
                left: highlightRect.left,
                width: highlightRect.width,
                height: highlightRect.height,
              }}
            />
          )}
        </>
      )}
      <div
        className="craftai-widget-panel"
        role="dialog"
        aria-label="Craft AI chat"
        data-testid="widget-panel"
        data-targeting={targeting ? "true" : undefined}
      >
        <header className="ai:flex ai:items-center ai:gap-2 ai:border-b ai:border-craftai-border ai:px-3 ai:py-2">
          {view === "chat" && (
            <button
              type="button"
              aria-label="Show sessions"
              data-testid="widget-back"
              onClick={showSessions}
              className="ai:inline-flex ai:h-8 ai:w-8 ai:items-center ai:justify-center ai:rounded ai:text-craftai-fg hover:ai:bg-craftai-border/30"
            >
              <ArrowLeft aria-hidden className="ai:h-4 ai:w-4" />
            </button>
          )}
          {view === "compose-comment" && (
            <button
              type="button"
              aria-label="Cancel comment"
              data-testid="widget-back"
              onClick={cancelComposer}
              className="ai:inline-flex ai:h-8 ai:w-8 ai:items-center ai:justify-center ai:rounded ai:text-craftai-fg hover:ai:bg-craftai-border/30"
            >
              <ArrowLeft aria-hidden className="ai:h-4 ai:w-4" />
            </button>
          )}
          <h2 className="ai:flex-1 ai:m-0 ai:truncate ai:text-sm ai:font-medium ai:text-craftai-fg">
            {view === "compose-comment"
              ? "Leave a comment"
              : view === "sessions"
                ? "Sessions"
                : titleForSession(sessions, activeSessionId)}
          </h2>
          <button
            type="button"
            aria-label="Close Craft AI chat"
            data-testid="widget-close"
            onClick={closeWidget}
            className="ai:inline-flex ai:h-8 ai:w-8 ai:items-center ai:justify-center ai:rounded ai:text-craftai-fg hover:ai:bg-craftai-border/30"
          >
            <X aria-hidden className="ai:h-4 ai:w-4" />
          </button>
        </header>

        {error && (
          <p
            role="alert"
            data-testid="widget-error"
            className="ai:m-0 ai:border-b ai:border-red-200 ai:bg-red-50 ai:px-3 ai:py-2 ai:text-xs ai:text-red-700"
          >
            {error}
          </p>
        )}

        <div className="ai:flex ai:min-h-0 ai:flex-1 ai:flex-col ai:overflow-hidden">
          {view === "compose-comment" && commentDraft ? (
            <CommentComposer
              draft={commentDraft}
              busy={busy}
              api={api}
              onSubmit={submitComment}
              onCancel={cancelComposer}
            />
          ) : view === "sessions" ? (
            <SessionsView
              sessions={sessions}
              activeSessionId={activeSessionId}
              busy={busy}
              onSelect={selectSession}
              onNew={() => {
                void startNewSession().then((id) => {
                  if (id) setView("chat");
                });
              }}
            />
          ) : activeSessionId ? (
            <div className="ai:flex ai:min-h-0 ai:flex-1 ai:flex-col ai:overflow-hidden ai:p-3">
              <Chat
                key={activeSessionId}
                bootstrap={chatBootstrapFor(bootstrap, activeSessionId)}
                enableAttachments={false}
                enableTargeting
                selectedTarget={selectedTarget}
                onStartTargeting={startTargeting}
                onClearTarget={clearTarget}
              />
            </div>
          ) : (
            <div
              data-testid="widget-loading"
              className="ai:flex ai:flex-1 ai:items-center ai:justify-center ai:text-sm ai:text-craftai-muted"
            >
              {busy ? "Starting a session…" : "Loading…"}
            </div>
          )}
        </div>
      </div>
    </div>
  );
}

interface SessionsViewProps {
  sessions: SessionListItem[];
  activeSessionId: string | null;
  busy: boolean;
  onSelect: (id: string) => void;
  onNew: () => void;
}

function SessionsView({ sessions, activeSessionId, busy, onSelect, onNew }: SessionsViewProps) {
  return (
    <div className="ai:flex ai:min-h-0 ai:flex-1 ai:flex-col" data-testid="widget-sessions">
      <div className="ai:border-b ai:border-craftai-border ai:p-2">
        <button
          type="button"
          onClick={onNew}
          disabled={busy}
          data-testid="widget-new-session"
          className="ai:inline-flex ai:w-full ai:items-center ai:justify-center ai:gap-1.5 ai:rounded-md ai:bg-craftai-accent ai:px-3 ai:py-1.5 ai:text-sm ai:font-medium ai:text-white ai:transition ai:disabled:opacity-60"
        >
          <Plus aria-hidden className="ai:h-4 ai:w-4" />
          New session
        </button>
      </div>
      {sessions.length === 0 ? (
        <p className="ai:m-0 ai:p-4 ai:text-center ai:text-sm ai:text-craftai-muted">
          No sessions yet. Start one above.
        </p>
      ) : (
        <ul className="ai:flex ai:min-h-0 ai:flex-1 ai:flex-col ai:list-none ai:overflow-y-auto ai:p-2">
          {sessions.map((s) => (
            <li key={s.sessionId} className="ai:list-none">
              <button
                type="button"
                onClick={() => onSelect(s.sessionId)}
                aria-current={s.sessionId === activeSessionId ? "true" : undefined}
                className={
                  "ai:flex ai:w-full ai:flex-col ai:items-start ai:gap-0.5 ai:rounded ai:border ai:border-transparent ai:px-2 ai:py-1.5 ai:text-left ai:text-sm hover:ai:bg-craftai-border/20 " +
                  (s.sessionId === activeSessionId
                    ? "ai:border-craftai-accent ai:bg-craftai-user"
                    : "")
                }
              >
                <span className="ai:block ai:w-full ai:truncate ai:font-medium">
                  {s.title?.trim() || s.sessionId.slice(0, 8)}
                </span>
                <span className="ai:block ai:text-[11px] ai:text-craftai-muted">
                  {s.messageCount} {s.messageCount === 1 ? "message" : "messages"}
                  {s.lastMessage ? ` · ${s.lastMessage}` : ""}
                </span>
              </button>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

interface CommentComposerProps {
  draft: CommentDraftRequest;
  busy: boolean;
  api: WidgetApi;
  onSubmit: (input: {
    body: string;
    sessionId?: string;
    assetIds?: number[];
    toolMode?: ToolMode;
    enabledTools?: string[] | null;
  }) => Promise<boolean>;
  onCancel: () => void;
}

/**
 * Inline composer used in place of the old `window.prompt` flow.
 *
 * The comment is "kicking off a session" in the same way the chat
 * widget does, so the composer mirrors the chat's prompt-input
 * toolbar: a real multi-line textarea, an asset upload button, and a
 * permission-mode dropdown. To make the permission mode work we
 * pre-create the comment's session on mount — the same SessionRecord
 * the eventual discussion thread will be forked from — and use that
 * session id for all toolbar interactions. Cancelling leaves the
 * empty session behind; that's a known minor cost that's much smaller
 * than the alternative (delaying the dropdown until after submit).
 *
 * The selection snippet is shown read-only at the top as a reminder
 * of what the editor highlighted. We trim and truncate it to one
 * short line so a paragraph-length selection doesn't push the
 * textarea off-screen — the source of truth lives in the field's HTML
 * anyway, not in the composer.
 */
function CommentComposer({ draft, busy, api, onSubmit, onCancel }: CommentComposerProps) {
  const [body, setBody] = useState("");
  const [composerSessionId, setComposerSessionId] = useState<string | null>(null);
  const [pendingAttachments, setPendingAttachments] = useState<Attachment[]>([]);
  const [toolMode, setToolMode] = useState<ToolMode>("full");
  const [enabledTools, setEnabledTools] = useState<string[] | null>(null);
  const [availableTools, setAvailableTools] = useState<AvailableTool[]>([]);
  const [toolModeLoaded, setToolModeLoaded] = useState(false);
  const [bootstrapError, setBootstrapError] = useState<string | null>(null);
  const textareaRef = useRef<HTMLTextAreaElement | null>(null);
  // Guards the bootstrap effect from running twice in dev under React's
  // StrictMode (which would otherwise mint two orphan sessions per open).
  const bootstrappedRef = useRef(false);

  useEffect(() => {
    if (bootstrappedRef.current) return;
    bootstrappedRef.current = true;

    (async () => {
      try {
        const sessionId = await api.createSession();
        setComposerSessionId(sessionId);
        // Best-effort toolbar hydration. A failed tool-mode fetch
        // shouldn't block the user from leaving a comment — the
        // server defaults to "full" anyway, and we'll just hide the
        // permission dropdown until the data arrives (or forever).
        try {
          const payload = await api.fetchToolMode(sessionId);
          setToolMode(payload.toolMode);
          setEnabledTools(payload.enabledTools);
          setAvailableTools(payload.availableTools);
          setToolModeLoaded(true);
        } catch {
          // swallow — composer stays usable without the dropdown
        }
      } catch (err) {
        setBootstrapError(
          err instanceof Error ? err.message : "Failed to start a session",
        );
      }
    })();
  }, [api]);

  useEffect(() => {
    textareaRef.current?.focus();
  }, []);

  const trimmedBody = body.trim();
  const canSubmit = !busy && trimmedBody !== "" && composerSessionId !== null;

  const submit = useCallback(async () => {
    if (!canSubmit || !composerSessionId) return;
    const ok = await onSubmit({
      body: trimmedBody,
      sessionId: composerSessionId,
      assetIds: pendingAttachments.map((a) => a.id),
      toolMode,
      enabledTools: toolMode === "custom" ? (enabledTools ?? []) : null,
    });
    if (ok) {
      setBody("");
      setPendingAttachments([]);
    }
  }, [
    canSubmit,
    composerSessionId,
    enabledTools,
    onSubmit,
    pendingAttachments,
    toolMode,
    trimmedBody,
  ]);

  const handleKeyDown = useCallback(
    (e: ReactKeyboardEvent<HTMLTextAreaElement>) => {
      // Enter alone submits, Shift+Enter keeps the textarea behavior of
      // inserting a newline. Matches the existing chat composer so
      // editors don't need to learn a second muscle pattern.
      if (e.key === "Enter" && !e.shiftKey && !e.metaKey && !e.ctrlKey) {
        e.preventDefault();
        void submit();
      } else if (e.key === "Escape") {
        e.preventDefault();
        onCancel();
      }
    },
    [onCancel, submit],
  );

  const onAddAttachments = useCallback(async () => {
    const ids = await openAssetSelector({ multiSelect: true });
    if (ids.length === 0) return;
    try {
      // De-dupe against anything already pending so a repeat pick
      // doesn't double-stack the same asset.
      const seen = new Set(pendingAttachments.map((a) => a.id));
      const fresh = ids.filter((id) => !seen.has(id));
      if (fresh.length === 0) return;
      const info = await api.fetchAssetInfo(fresh);
      setPendingAttachments((prev) => [...prev, ...info]);
    } catch (err) {
      console.warn("[craft-ai] composer: failed to fetch asset info", err);
      // Even without enriched metadata, ship a minimal chip so the
      // user can still send (and remove the wrong one if needed).
      const seen = new Set(pendingAttachments.map((a) => a.id));
      const minimal: Attachment[] = ids
        .filter((id) => !seen.has(id))
        .map((id) => ({
          id,
          label: `Asset #${id}`,
          filename: null,
          kind: null,
          mimeType: null,
          thumbUrl: null,
        }));
      setPendingAttachments((prev) => [...prev, ...minimal]);
    }
  }, [api, pendingAttachments]);

  const removeAttachment = useCallback((id: number) => {
    setPendingAttachments((prev) => prev.filter((a) => a.id !== id));
  }, []);

  // PermissionMode owns the menu state; we just persist the result.
  // The server has the same toolMode whitelist as SessionsController
  // (full/draft/readonly/custom) — passing anything else returns 400.
  const onPermissionChange = useCallback(
    (mode: ToolMode, next: string[] | null) => {
      setToolMode(mode);
      setEnabledTools(next);
      // The composer flushes toolMode + enabledTools on submit so a
      // canceled comment doesn't leave a side-effected session behind.
      // Persisting eagerly would feel snappier but the dropdown is
      // small and the server round-trip would just be wasted on the
      // ~30% of opens that end in Cancel.
    },
    [],
  );

  // Show the selection on one tight line — paragraph-length selections
  // would otherwise dominate the composer's vertical space.
  const selectionPreview = useMemo(() => {
    const collapsed = draft.selectionText.replace(/\s+/g, " ").trim();
    if (collapsed === "") return null;
    return collapsed.length > 140 ? `${collapsed.slice(0, 137)}…` : collapsed;
  }, [draft.selectionText]);

  return (
    <div
      className="ai:flex ai:min-h-0 ai:flex-1 ai:flex-col ai:gap-3 ai:p-3"
      data-testid="widget-compose-comment"
    >
      <div className="ai:flex ai:flex-col ai:gap-1 ai:rounded-md ai:border ai:border-craftai-border ai:bg-craftai-border/10 ai:px-3 ai:py-2 ai:text-xs ai:text-craftai-muted">
        <span>
          Commenting on field <code className="ai:rounded ai:bg-white ai:px-1 ai:py-0.5 ai:text-[11px] ai:text-craftai-fg">{draft.fieldHandle}</code>
        </span>
        {selectionPreview && (
          <span className="ai:italic ai:text-craftai-fg">
            “{selectionPreview}”
          </span>
        )}
      </div>

      {bootstrapError && (
        <p
          role="alert"
          className="ai:m-0 ai:rounded-md ai:border ai:border-red-200 ai:bg-red-50 ai:px-3 ai:py-2 ai:text-xs ai:text-red-700"
        >
          {bootstrapError}
        </p>
      )}

      <form
        className="ai:flex ai:min-h-0 ai:flex-1 ai:flex-col ai:gap-2 ai:rounded-lg ai:border ai:border-craftai-border ai:bg-white ai:p-2 ai:shadow-sm"
        onSubmit={(e) => {
          e.preventDefault();
          void submit();
        }}
      >
        {pendingAttachments.length > 0 && (
          <ul
            className="ai:m-0 ai:flex ai:list-none ai:flex-wrap ai:gap-1.5 ai:p-0"
            data-testid="widget-compose-comment-attachments"
          >
            {pendingAttachments.map((a) => (
              <li
                key={a.id}
                className="ai:inline-flex ai:items-center ai:gap-1 ai:rounded ai:border ai:border-craftai-border ai:bg-craftai-border/10 ai:px-2 ai:py-0.5 ai:text-[11px] ai:text-craftai-fg"
              >
                <span className="ai:truncate ai:max-w-[140px]" title={a.label}>
                  {a.label}
                </span>
                <button
                  type="button"
                  onClick={() => removeAttachment(a.id)}
                  aria-label={`Remove ${a.label}`}
                  className="ai:inline-flex ai:h-4 ai:w-4 ai:items-center ai:justify-center ai:rounded ai:text-craftai-muted hover:ai:bg-craftai-border/40 hover:ai:text-craftai-fg"
                >
                  <X aria-hidden className="ai:h-3 ai:w-3" />
                </button>
              </li>
            ))}
          </ul>
        )}

        <textarea
          ref={textareaRef}
          value={body}
          onChange={(e) => setBody(e.target.value)}
          onKeyDown={handleKeyDown}
          placeholder="Type your comment… (Markdown supported, Enter to send, Shift+Enter for newline)"
          rows={6}
          className="ai:block ai:min-h-0 ai:flex-1 ai:resize-none ai:rounded-md ai:border-0 ai:bg-transparent ai:px-2 ai:py-1 ai:text-sm ai:focus:outline-none ai:focus:ring-0"
          data-testid="widget-compose-comment-textarea"
          disabled={busy || composerSessionId === null}
        />

        <div className="ai:flex ai:items-center ai:justify-between ai:gap-2">
          <div className="ai:flex ai:items-center ai:gap-1.5">
            <button
              type="button"
              onClick={() => {
                void onAddAttachments();
              }}
              disabled={busy || composerSessionId === null}
              data-testid="widget-compose-comment-upload"
              className="ai:inline-flex ai:items-center ai:gap-1 ai:rounded-md ai:border ai:border-craftai-border ai:bg-white ai:px-2 ai:py-1 ai:text-xs ai:font-medium ai:text-craftai-fg ai:transition hover:ai:bg-craftai-border/20 ai:disabled:opacity-50"
              aria-label="Upload"
            >
              <Paperclip aria-hidden className="ai:h-3.5 ai:w-3.5" />
              Upload
            </button>
            {toolModeLoaded && availableTools.length > 0 && (
              <PermissionMode
                mode={toolMode}
                enabledTools={enabledTools}
                availableTools={availableTools}
                onChange={onPermissionChange}
                disabled={busy || composerSessionId === null}
              />
            )}
          </div>

          <div className="ai:flex ai:items-center ai:gap-2">
            <button
              type="button"
              onClick={onCancel}
              disabled={busy}
              className="ai:inline-flex ai:items-center ai:rounded-md ai:border ai:border-craftai-border ai:bg-white ai:px-3 ai:py-1.5 ai:text-xs ai:font-medium ai:text-craftai-fg ai:transition hover:ai:bg-craftai-border/20 ai:disabled:opacity-50"
            >
              Cancel
            </button>
            <button
              type="submit"
              disabled={!canSubmit}
              className="ai:inline-flex ai:items-center ai:gap-1.5 ai:rounded-md ai:bg-craftai-accent ai:px-3 ai:py-1.5 ai:text-xs ai:font-medium ai:text-white ai:transition ai:disabled:opacity-50"
              data-testid="widget-compose-comment-submit"
            >
              {busy ? (
                <>
                  <Loader2 aria-hidden className="ai:h-3.5 ai:w-3.5 ai:animate-spin" />
                  Saving…
                </>
              ) : (
                <>
                  <Send aria-hidden className="ai:h-3.5 ai:w-3.5" />
                  Add comment
                </>
              )}
            </button>
          </div>
        </div>
      </form>
    </div>
  );
}

function titleForSession(sessions: SessionListItem[], id: string | null): string {
  if (!id) return "Chat";
  const found = sessions.find((s) => s.sessionId === id);
  return found?.title?.trim() || "Chat";
}

/**
 * Adapt the widget-side bootstrap to the `ChatBootstrap` shape that
 * `<Chat />` expects. We pass empty `initialMessages`/`initialSessions`
 * arrays so the chat fetches history on mount; assetsInfoUrl is unused
 * because we render with `enableAttachments={false}`.
 */
function chatBootstrapFor(bootstrap: WidgetBootstrap, sessionId: string): ChatBootstrap {
  return {
    sessionId,
    messagesUrl: bootstrap.messagesUrl,
    sendUrl: bootstrap.sendUrl,
    sessionsUrl: bootstrap.sessionsUrl,
    newSessionUrl: bootstrap.newSessionUrl,
    sessionsIndexUrl: bootstrap.sessionsIndexUrl,
    assetsInfoUrl: "",
    previewRespondUrl: bootstrap.previewRespondUrl,
    toolModeUrl: bootstrap.toolModeUrl,
    updateToolModeUrl: bootstrap.updateToolModeUrl,
    csrfTokenName: bootstrap.csrfTokenName,
    csrfTokenValue: bootstrap.csrfTokenValue,
    initialMessages: [],
    initialSessions: [],
    context: bootstrap.context,
    contextFingerprint: bootstrap.contextFingerprint,
    contextWindow: bootstrap.contextWindow,
  };
}
