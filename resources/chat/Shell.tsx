import { useCallback, useEffect, useState } from "react";
import { Chat } from "./Chat";
import type { ChatBootstrap, SessionListItem } from "./types";

export interface ShellProps {
  bootstrap: ChatBootstrap;
  pollIntervalMs?: number;
}

export function Shell({ bootstrap, pollIntervalMs = 10000 }: ShellProps) {
  const [sessions, setSessions] = useState<SessionListItem[]>(bootstrap.initialSessions);

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
      <SessionsSidebar
        sessions={sessions}
        currentSessionId={bootstrap.sessionId}
        newSessionUrl={bootstrap.newSessionUrl}
        csrfTokenName={bootstrap.csrfTokenName}
        csrfTokenValue={bootstrap.csrfTokenValue}
      />
      <main className="ai:flex-1 ai:min-w-0">
        {bootstrap.sessionId ? (
          <Chat bootstrap={bootstrap} />
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
}

function SessionsSidebar({
  sessions,
  currentSessionId,
  newSessionUrl,
  csrfTokenName,
  csrfTokenValue,
}: SidebarProps) {
  return (
    <aside
      data-testid="sessions-sidebar"
      className="ai:w-64 ai:shrink-0 ai:flex ai:flex-col ai:gap-3"
    >
      <form method="post" action={newSessionUrl} acceptCharset="UTF-8">
        <input type="hidden" name={csrfTokenName} value={csrfTokenValue} />
        <button type="submit" className="btn submit add icon ai:w-full">
          New Session
        </button>
      </form>

      {sessions.length === 0 ? (
        <p className="ai:text-xs ai:text-craftai-muted">No sessions yet.</p>
      ) : (
        <ul className="ai:flex ai:flex-col ai:gap-1 ai:list-none ai:p-0 ai:m-0">
          {sessions.map((s) => (
            <SessionRow key={s.sessionId} session={s} isCurrent={s.sessionId === currentSessionId} />
          ))}
        </ul>
      )}
    </aside>
  );
}

function SessionRow({ session, isCurrent }: { session: SessionListItem; isCurrent: boolean }) {
  const statusClass = session.active ? "yellow" : "green";
  const statusLabel = session.active ? "Active" : "Idle";
  const label = session.title?.trim() || session.sessionId.slice(0, 8);

  return (
    <li>
      <a
        href={session.url}
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
