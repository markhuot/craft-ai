import { useCallback, useEffect, useState } from "react";
import { Menu, PanelLeftClose, PanelLeftOpen, X } from "lucide-react";
import { Chat } from "./Chat";
import type { ChatBootstrap, SessionListItem } from "./types";

export interface ShellProps {
  bootstrap: ChatBootstrap;
  pollIntervalMs?: number;
}

export function Shell({ bootstrap, pollIntervalMs = 10000 }: ShellProps) {
  const [sessions, setSessions] = useState<SessionListItem[]>(bootstrap.initialSessions);
  const [sidebarOpen, setSidebarOpen] = useState(false);
  // The user can collapse/expand the desktop sidebar at any time via the
  // toggle in its header. Opening peek mode also auto-collapses the sidebar
  // so the iframe pane gets more horizontal room — we apply that on each
  // false→true peek transition so re-opening the preview always starts
  // compact regardless of what the user did during a previous session.
  const [previewPeek, setPreviewPeek] = useState(false);
  const [desktopSidebarCollapsed, setDesktopSidebarCollapsed] = useState(false);

  useEffect(() => {
    if (previewPeek) setDesktopSidebarCollapsed(true);
  }, [previewPeek]);

  const expandDesktopSidebar = useCallback(() => setDesktopSidebarCollapsed(false), []);
  const collapseDesktopSidebar = useCallback(() => setDesktopSidebarCollapsed(true), []);

  const fetchSessions = useCallback(async () => {
    try {
      const res = await fetch(bootstrap.sessionsUrl, {
        headers: { Accept: "application/json" },
        credentials: "same-origin",
      });
      if (!res.ok) return;
      const data: unknown = await res.json();
      if (
        typeof data === "object" &&
        data !== null &&
        Array.isArray((data as { sessions?: unknown }).sessions)
      ) {
        setSessions((data as { sessions: SessionListItem[] }).sessions);
      }
    } catch {
      // transient — next tick will try again
    }
  }, [bootstrap.sessionsUrl]);

  useEffect(() => {
    void fetchSessions();
    const id = setInterval(fetchSessions, pollIntervalMs);
    return () => clearInterval(id);
  }, [fetchSessions, pollIntervalMs]);

  const closeSidebar = useCallback(() => setSidebarOpen(false), []);

  useEffect(() => {
    if (!sidebarOpen) return;
    const onKey = (e: KeyboardEvent) => {
      if (e.key === "Escape") closeSidebar();
    };
    document.addEventListener("keydown", onKey);
    return () => document.removeEventListener("keydown", onKey);
  }, [sidebarOpen, closeSidebar]);

  if (sessions.length === 0 && !bootstrap.sessionId) {
    return (
      <EmptyState
        newSessionUrl={bootstrap.newSessionUrl}
        csrfTokenName={bootstrap.csrfTokenName}
        csrfTokenValue={bootstrap.csrfTokenValue}
      />
    );
  }

  return (
    <div className="ai:flex ai:gap-4 ai:items-stretch">
      <div
        data-testid="sidebar-backdrop"
        aria-hidden="true"
        onClick={closeSidebar}
        className={
          "ai:fixed ai:inset-0 ai:z-40 ai:bg-black/40 ai:transition-opacity ai:md:hidden " +
          (sidebarOpen
            ? "ai:opacity-100 ai:pointer-events-auto"
            : "ai:opacity-0 ai:pointer-events-none")
        }
      />

      <SessionsSidebar
        sessions={sessions}
        currentSessionId={bootstrap.sessionId}
        newSessionUrl={bootstrap.newSessionUrl}
        csrfTokenName={bootstrap.csrfTokenName}
        csrfTokenValue={bootstrap.csrfTokenValue}
        isOpen={sidebarOpen}
        onClose={closeSidebar}
        desktopCollapsed={desktopSidebarCollapsed}
        onDesktopExpand={expandDesktopSidebar}
        onDesktopCollapse={collapseDesktopSidebar}
      />

      <main className="ai:flex-1 ai:min-w-0 ai:flex ai:flex-col ai:gap-3">
        <button
          type="button"
          data-testid="sidebar-toggle"
          aria-label="Show sessions"
          aria-expanded={sidebarOpen}
          aria-controls="craftai-sessions-sidebar"
          onClick={() => setSidebarOpen(true)}
          className="ai:md:hidden ai:self-start ai:inline-flex ai:items-center ai:gap-1.5 ai:rounded-md ai:border ai:border-craftai-border ai:bg-white ai:px-3 ai:py-1.5 ai:text-xs ai:font-medium ai:text-craftai-fg hover:ai:bg-craftai-border/20"
        >
          <Menu className="ai:h-3.5 ai:w-3.5" aria-hidden />
          Sessions
        </button>

        {bootstrap.sessionId ? (
          <Chat bootstrap={bootstrap} onPreviewPeekChange={setPreviewPeek} />
        ) : (
          <div
            data-testid="shell-empty"
            className="ai:rounded-lg ai:border ai:border-craftai-border ai:p-6 ai:text-sm ai:text-craftai-muted"
          >
            Select a session from the sidebar, or start a new one.
          </div>
        )}
      </main>
    </div>
  );
}

interface EmptyStateProps {
  newSessionUrl: string;
  csrfTokenName: string;
  csrfTokenValue: string;
}

function EmptyState({ newSessionUrl, csrfTokenName, csrfTokenValue }: EmptyStateProps) {
  return (
    <div
      data-testid="shell-zero-state"
      className="ai:flex ai:flex-col ai:items-center ai:justify-center ai:text-center ai:py-16 ai:px-6 ai:gap-4"
    >
      <form method="post" action={newSessionUrl} acceptCharset="UTF-8">
        <input type="hidden" name={csrfTokenName} value={csrfTokenValue} />
        <button type="submit" className="btn submit add icon">
          New Session
        </button>
      </form>
      <p className="ai:max-w-md ai:text-sm ai:text-craftai-muted">
        Sessions are conversations with the AI assistant. Each session keeps its own history and
        runs independently, so you can start multiple sessions concurrently and let them work in
        parallel.
      </p>
    </div>
  );
}

interface SidebarProps {
  sessions: SessionListItem[];
  currentSessionId: string;
  newSessionUrl: string;
  csrfTokenName: string;
  csrfTokenValue: string;
  isOpen: boolean;
  onClose: () => void;
  desktopCollapsed: boolean;
  onDesktopExpand: () => void;
  /**
   * When provided, render the collapse toggle next to the New Session button
   * so the user can hide the sidebar at any time. Omitting it hides the
   * toggle (e.g. tests that don't want to assert on it).
   */
  onDesktopCollapse?: () => void;
}

export function SessionsSidebar({
  sessions,
  currentSessionId,
  newSessionUrl,
  csrfTokenName,
  csrfTokenValue,
  isOpen,
  onClose,
  desktopCollapsed,
  onDesktopExpand,
  onDesktopCollapse,
}: SidebarProps) {
  return (
    <>
      <aside
        id="craftai-sessions-sidebar"
        data-testid="sessions-sidebar"
        data-open={isOpen ? "true" : "false"}
        data-desktop-collapsed={desktopCollapsed ? "true" : "false"}
        aria-label="Sessions"
        className={
          "ai:flex ai:flex-col ai:gap-3 ai:bg-craftai-bg ai:transition-transform " +
          // Mobile: fixed-position drawer that slides in from the left. The
          // mobile drawer ignores desktopCollapsed — that's a desktop-only
          // concern. On mobile we want the drawer behavior unchanged.
          "ai:fixed ai:inset-y-0 ai:left-0 ai:z-50 ai:w-64 ai:p-4 ai:border-r ai:border-craftai-border ai:overflow-y-auto " +
          (isOpen ? "ai:translate-x-0" : "ai:-translate-x-full") +
          // Desktop: inline column, unless collapsed in which case we hand
          // off to the thin strip below.
          " ai:md:static ai:md:translate-x-0 ai:md:p-0 ai:md:border-0 ai:md:overflow-visible ai:md:shrink-0" +
          (desktopCollapsed ? " ai:md:hidden" : "")
        }
      >
        <div className="ai:flex ai:items-center ai:justify-between ai:md:hidden">
          <span className="ai:text-sm ai:font-medium">Sessions</span>
          <button
            type="button"
            aria-label="Close sessions"
            onClick={onClose}
            className="ai:inline-flex ai:h-7 ai:w-7 ai:items-center ai:justify-center ai:rounded hover:ai:bg-craftai-border/20"
          >
            <X className="ai:h-4 ai:w-4" aria-hidden />
          </button>
        </div>

        <div className="ai:flex ai:items-center ai:gap-2">
          <form
            method="post"
            action={newSessionUrl}
            acceptCharset="UTF-8"
            className="ai:flex-1 ai:min-w-0"
          >
            <input type="hidden" name={csrfTokenName} value={csrfTokenValue} />
            <button type="submit" className="btn submit add icon ai:w-full">
              New Session
            </button>
          </form>
          {onDesktopCollapse && (
            <button
              type="button"
              aria-label="Collapse sessions"
              data-testid="sessions-collapse"
              onClick={onDesktopCollapse}
              className="ai:hidden ai:md:inline-flex ai:h-7 ai:w-7 ai:shrink-0 ai:items-center ai:justify-center ai:rounded ai:text-craftai-fg hover:ai:bg-craftai-border/30"
            >
              <PanelLeftClose className="ai:h-4 ai:w-4" aria-hidden />
            </button>
          )}
        </div>

        {sessions.length === 0 ? (
          <p className="ai:text-xs ai:text-craftai-muted">No sessions yet.</p>
        ) : (
          <ul className="ai:flex ai:flex-col ai:gap-1 ai:list-none ai:p-0 ai:m-0">
            {sessions.map((s) => (
              <SessionRow
                key={s.sessionId}
                session={s}
                isCurrent={s.sessionId === currentSessionId}
                onNavigate={onClose}
              />
            ))}
          </ul>
        )}
      </aside>

      {desktopCollapsed && (
        <div
          data-testid="sessions-collapsed"
          className="ai:hidden ai:md:flex ai:flex-col ai:items-center ai:pt-1"
        >
          <button
            type="button"
            aria-label="Show sessions"
            data-testid="sessions-expand"
            onClick={onDesktopExpand}
            className="ai:inline-flex ai:h-7 ai:w-7 ai:items-center ai:justify-center ai:rounded ai:text-craftai-fg hover:ai:bg-craftai-border/30"
          >
            <PanelLeftOpen className="ai:h-4 ai:w-4" aria-hidden />
          </button>
        </div>
      )}
    </>
  );
}

function SessionRow({
  session,
  isCurrent,
  onNavigate,
}: {
  session: SessionListItem;
  isCurrent: boolean;
  onNavigate: () => void;
}) {
  const statusClass = session.active ? "yellow" : "green";
  const statusLabel = session.active ? "Active" : "Idle";
  const label = session.title?.trim() || session.sessionId.slice(0, 8);

  return (
    <li>
      <a
        href={session.url}
        onClick={onNavigate}
        aria-current={isCurrent ? "page" : undefined}
        className={
          "ai:flex ai:items-start ai:gap-2 ai:rounded ai:px-2 ai:py-1.5 ai:text-sm ai:no-underline " +
          (isCurrent
            ? "ai:bg-craftai-border/40 ai:text-craftai-fg"
            : "ai:text-craftai-fg hover:ai:bg-craftai-border/20")
        }
      >
        <span
          className={`status ${statusClass}`}
          title={statusLabel}
          aria-hidden="true"
          style={{ marginTop: 4 }}
        />
        <span className="ai:flex-1 ai:min-w-0">
          <span className="ai:block ai:truncate">{label}</span>
          <span className="ai:block ai:text-[11px] ai:text-craftai-muted">
            {session.messageCount} {session.messageCount === 1 ? "message" : "messages"}
            {session.lastMessage ? ` · ${session.lastMessage}` : ""}
          </span>
        </span>
      </a>
    </li>
  );
}
