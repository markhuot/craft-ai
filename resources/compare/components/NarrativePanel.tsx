import { useEffect, useRef, useState } from "react";
import { CompareApi, type CompareMessage } from "../api";
import { Markdown } from "./markdown";

export interface NarrativePanelProps {
  /** Agent session explaining the current diff, or null before one exists. */
  sessionId: string | null;
  /** GET endpoint for the message poll (shared chat `messagesUrl`). */
  messagesUrl: string;
  /** CSRF pair — carried for parity with the other clients; GET doesn't use it. */
  csrf: { name: string; value: string };
}

const POLL_INTERVAL_MS = 1500;

/** Flatten a message's text blocks into a single markdown string. */
function messageText(message: CompareMessage): string {
  return message.content
    .map((block) =>
      block.type === "text" && typeof block.text === "string" ? block.text : "",
    )
    .filter((text) => text !== "")
    .join("\n\n");
}

/**
 * Side panel that narrates the diff. When a `sessionId` is present it polls
 * the shared messages endpoint (`?sessionId=…&after=…`) on an interval,
 * accumulating assistant turns and rendering their text as markdown. The
 * `after` cursor only ever advances, so each poll fetches just the new tail.
 *
 * A subtle "thinking…" line shows while the agent is still working — i.e.
 * the latest message we've seen isn't yet an assistant turn (or none has
 * arrived at all).
 */
export function NarrativePanel({ sessionId, messagesUrl, csrf }: NarrativePanelProps) {
  const [messages, setMessages] = useState<CompareMessage[]>([]);
  // True until we've seen an assistant turn land — drives the thinking hint.
  const [awaitingAssistant, setAwaitingAssistant] = useState(false);

  useEffect(() => {
    // Reset between sessions so a recompute that mints a new session id
    // doesn't briefly show the previous diff's narrative.
    setMessages([]);
    if (!sessionId) {
      setAwaitingAssistant(false);
      return;
    }
    setAwaitingAssistant(true);

    const api = new CompareApi({
      diffUrl: "",
      revisionsUrl: "",
      messagesUrl,
      csrfTokenName: csrf.name,
      csrfTokenValue: csrf.value,
    });

    let cancelled = false;
    let lastId = 0;

    const poll = async () => {
      try {
        const fetched = await api.fetchMessagesAfter(sessionId, lastId);
        if (cancelled || fetched.length === 0) return;
        setMessages((prev) => {
          const seen = new Set(prev.map((m) => m.id));
          const merged = [...prev];
          for (const message of fetched) {
            if (!seen.has(message.id)) {
              merged.push(message);
              if (message.id > lastId) lastId = message.id;
            }
          }
          return merged;
        });
        // Once an assistant turn is the most recent thing we've seen, the
        // run has produced output — drop the thinking hint.
        const last = fetched[fetched.length - 1];
        if (last && last.role === "assistant") {
          setAwaitingAssistant(false);
        }
      } catch {
        // transient — the next tick retries
      }
    };

    // Fire once immediately so the panel fills in without waiting a full
    // interval, then poll on a timer cleared on unmount / session change.
    void poll();
    const id = setInterval(poll, POLL_INTERVAL_MS);
    return () => {
      cancelled = true;
      clearInterval(id);
    };
  }, [sessionId, messagesUrl, csrf.name, csrf.value]);

  // Only assistant turns carry the narrative; user/system/summary rows (and
  // assistant turns that were pure tool calls) are dropped.
  const narrativeBlocks = messages
    .filter((message) => message.role === "assistant")
    .map((message) => ({ id: message.id, text: messageText(message) }))
    .filter((entry) => entry.text !== "");

  return (
    <aside
      data-testid="narrative-panel"
      className="ai:flex ai:min-h-0 ai:flex-col ai:gap-2 ai:overflow-hidden ai:rounded-md ai:border ai:border-craftai-border ai:bg-craftai-bg ai:p-3"
      aria-label="Narrative"
    >
      <h2 className="ai:m-0 ai:text-sm ai:font-semibold ai:text-craftai-fg">
        What changed &amp; why
      </h2>

      <div className="ai:flex ai:min-h-0 ai:flex-1 ai:flex-col ai:gap-3 ai:overflow-y-auto">
        {!sessionId ? (
          <p className="ai:m-0 ai:text-xs ai:text-craftai-muted">
            Run a comparison to see a narrative of what changed.
          </p>
        ) : (
          <>
            {narrativeBlocks.map((entry) => (
              <Markdown key={entry.id}>{entry.text}</Markdown>
            ))}
            {(awaitingAssistant || narrativeBlocks.length === 0) && (
              <p
                data-testid="narrative-thinking"
                className="ai:m-0 ai:text-xs ai:text-craftai-muted"
              >
                Thinking…
              </p>
            )}
          </>
        )}
      </div>
    </aside>
  );
}
