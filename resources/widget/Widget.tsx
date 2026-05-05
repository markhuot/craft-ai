import { useCallback, useEffect, useMemo, useState } from "react";
import { ArrowLeft, MessageCircle, Plus, X } from "lucide-react";
import { Chat } from "../chat/Chat";
import type { ChatBootstrap, SessionListItem } from "../chat/types";
import { WidgetApi } from "./api";
import type { WidgetBootstrap } from "./types";

type ViewMode = "closed" | "chat" | "sessions";

const STORAGE_KEY = "craftai-widget:active-session";

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

  const [view, setView] = useState<ViewMode>("closed");
  const [sessions, setSessions] = useState<SessionListItem[]>([]);
  const [activeSessionId, setActiveSessionId] = useState<string | null>(() =>
    store ? store.getItem(STORAGE_KEY) : null,
  );
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

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
  }, [loadSessions, persistSession, startNewSession, store]);

  const closeWidget = useCallback(() => {
    setView("closed");
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

  // Close on Escape from anywhere within the widget. We intentionally listen
  // on the shadow root's owner document so the host page's other Escape
  // handlers still fire too.
  useEffect(() => {
    if (view === "closed") return;
    const onKey = (e: KeyboardEvent) => {
      if (e.key === "Escape") closeWidget();
    };
    document.addEventListener("keydown", onKey);
    return () => document.removeEventListener("keydown", onKey);
  }, [view, closeWidget]);

  if (view === "closed") {
    return (
      <div className="craftai-widget-root" data-testid="widget-root" data-view="closed">
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
    <div className="craftai-widget-root" data-testid="widget-root" data-view={view}>
      <div
        className="craftai-widget-panel"
        role="dialog"
        aria-label="Craft AI chat"
        data-testid="widget-panel"
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
          <h2 className="ai:flex-1 ai:m-0 ai:truncate ai:text-sm ai:font-medium ai:text-craftai-fg">
            {view === "sessions" ? "Sessions" : titleForSession(sessions, activeSessionId)}
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
          {view === "sessions" ? (
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
    csrfTokenName: bootstrap.csrfTokenName,
    csrfTokenValue: bootstrap.csrfTokenValue,
    initialMessages: [],
    initialSessions: [],
    context: bootstrap.context,
    contextFingerprint: bootstrap.contextFingerprint,
  };
}
