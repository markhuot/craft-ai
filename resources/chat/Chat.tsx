import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { ChatApi } from "./api";
import { Conversation, ConversationContent } from "./components/conversation";
import { Message, MessageContent } from "./components/message";
import {
  AttachmentChip,
  PromptInput,
  PromptInputAttachments,
  PromptInputSubmit,
  PromptInputTextarea,
  PromptInputToolbar,
  PromptInputUpload,
} from "./components/prompt-input";
import { Response } from "./components/response";
import { Tool, ToolContent, ToolHeader, ToolInput, ToolOutput } from "./components/tool";
import { openAssetSelector, type AssetSelectorOpener } from "./lib/assetSelector";
import type { Attachment, ChatBootstrap, ChatMessage, ContentBlock } from "./types";

export interface ChatProps {
  bootstrap: ChatBootstrap;
  api?: ChatApi;
  pollIntervalMs?: number;
  /** Override Craft's modal opener — primarily for tests. */
  openAssetSelector?: AssetSelectorOpener;
  /**
   * When false, hide the asset upload control and pending-attachment row.
   * The front-end widget runs outside the CP so it can't open Craft's asset
   * modal; the same component is reused there with this flag off.
   */
  enableAttachments?: boolean;
  /**
   * Used by the front-end widget to remember which page-context fingerprint
   * we last attached to a send for this session. Tests can supply a fake.
   */
  storage?: Pick<Storage, "getItem" | "setItem" | "removeItem">;
}

const CONTEXT_FP_STORAGE_PREFIX = "craftai-widget:context-fp:";

export function Chat({
  bootstrap,
  api: apiOverride,
  pollIntervalMs = 1500,
  openAssetSelector: openAssetSelectorOverride,
  enableAttachments = true,
  storage,
}: ChatProps) {
  const api = useMemo(
    () =>
      apiOverride ??
      new ChatApi({
        messagesUrl: bootstrap.messagesUrl,
        sendUrl: bootstrap.sendUrl,
        assetsInfoUrl: bootstrap.assetsInfoUrl,
        sessionId: bootstrap.sessionId,
        csrfTokenName: bootstrap.csrfTokenName,
        csrfTokenValue: bootstrap.csrfTokenValue,
      }),
    [apiOverride, bootstrap],
  );

  const opener = openAssetSelectorOverride ?? openAssetSelector;

  const [messages, setMessages] = useState<ChatMessage[]>(bootstrap.initialMessages);
  const [draft, setDraft] = useState("");
  const [pendingAttachments, setPendingAttachments] = useState<Attachment[]>([]);
  const [status, setStatus] = useState<"idle" | "submitting" | "streaming">("idle");
  const [error, setError] = useState<string | null>(null);

  const lastIdRef = useRef<number>(
    bootstrap.initialMessages.reduce((max, m) => Math.max(max, m.id), 0),
  );

  const poll = useCallback(async () => {
    try {
      const fetched = await api.fetchMessagesAfter(lastIdRef.current);
      if (fetched.length === 0) return;
      setMessages((prev) => {
        const seen = new Set(prev.map((m) => m.id));
        const merged = [...prev];
        for (const m of fetched) {
          if (!seen.has(m.id)) {
            merged.push(m);
            if (m.id > lastIdRef.current) lastIdRef.current = m.id;
          }
        }
        return merged;
      });
      const last = fetched[fetched.length - 1];
      if (last && last.role === "assistant") {
        setStatus("idle");
      }
    } catch {
      // transient — keep polling
    }
  }, [api]);

  useEffect(() => {
    // Fire once immediately so a freshly-mounted Chat (e.g. the widget
    // switching sessions) shows existing history without waiting a full
    // poll interval. Subsequent ticks pick up new messages from the agent.
    void poll();
    const id = setInterval(poll, pollIntervalMs);
    return () => clearInterval(id);
  }, [poll, pollIntervalMs]);

  const onSubmit = useCallback(
    async (e: React.FormEvent<HTMLFormElement>) => {
      e.preventDefault();
      const text = draft.trim();
      if (status !== "idle") return;
      if (text === "" && pendingAttachments.length === 0) return;
      setStatus("submitting");
      setError(null);

      // Decide whether the page context is "new" relative to what we last
      // attached on this session. If the fingerprint matches, omit the
      // payload — the LLM already has it.
      const store =
        storage ??
        (typeof window !== "undefined" && window.localStorage ? window.localStorage : null);
      const fp = bootstrap.contextFingerprint ?? "";
      const ctx = bootstrap.context;
      const fpKey = `${CONTEXT_FP_STORAGE_PREFIX}${bootstrap.sessionId}`;
      let attachContext: unknown = undefined;
      if (ctx !== undefined && ctx !== null && fp !== "") {
        let prior: string | null = null;
        try {
          prior = store?.getItem(fpKey) ?? null;
        } catch {
          prior = null;
        }
        if (prior !== fp) {
          attachContext = ctx;
        }
      }

      try {
        await api.sendMessage(
          text,
          pendingAttachments.map((a) => a.id),
          attachContext,
        );
        if (attachContext !== undefined && fp !== "") {
          // Only mark the fingerprint as sent on success — a failed send
          // should retry context attachment on the next attempt.
          try {
            store?.setItem(fpKey, fp);
          } catch {
            // localStorage can throw in private mode / quota-exceeded — keep
            // sending context until it sticks.
          }
        }
        setDraft("");
        setPendingAttachments([]);
        setStatus("streaming");
        await poll();
      } catch (err) {
        setStatus("idle");
        setError(err instanceof Error ? err.message : "Failed to send message");
      }
    },
    [api, bootstrap.context, bootstrap.contextFingerprint, bootstrap.sessionId, draft, pendingAttachments, poll, status, storage],
  );

  const onAddAttachments = useCallback(async () => {
    if (status !== "idle") return;
    try {
      const ids = await opener();
      if (ids.length === 0) return;
      const existing = new Set(pendingAttachments.map((a) => a.id));
      const newIds = ids.filter((id) => !existing.has(id));
      if (newIds.length === 0) return;
      const fetched = await api.fetchAssetInfo(newIds);
      if (fetched.length === 0) return;
      setPendingAttachments((prev) => {
        const seen = new Set(prev.map((a) => a.id));
        return [...prev, ...fetched.filter((a) => !seen.has(a.id))];
      });
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to attach assets");
    }
  }, [api, opener, pendingAttachments, status]);

  const onRemoveAttachment = useCallback((id: number) => {
    setPendingAttachments((prev) => prev.filter((a) => a.id !== id));
  }, []);

  return (
    <div className="craftai-chat ai:flex ai:flex-col ai:gap-3">
      <Conversation>
        <ConversationContent>
          {messages.length === 0 ? (
            <p
              data-testid="chat-empty"
              className="ai:text-sm ai:text-craftai-muted"
            >
              No messages yet — say something to start the conversation.
            </p>
          ) : (
            messages.map((m) => <RenderedMessage key={m.id} message={m} />)
          )}
          {status === "streaming" && (
            <p data-testid="chat-thinking" className="ai:text-xs ai:text-craftai-muted">
              Agent is thinking…
            </p>
          )}
        </ConversationContent>
      </Conversation>

      {error && (
        <p role="alert" className="ai:text-sm ai:text-red-600">
          {error}
        </p>
      )}

      <PromptInput onSubmit={onSubmit}>
        <PromptInputTextarea
          name="message"
          placeholder="Send a message…"
          value={draft}
          onChange={(e) => setDraft(e.target.value)}
          autoFocus
          onKeyDown={(e) => {
            if (e.key === "Enter" && !e.shiftKey && !e.altKey) {
              e.preventDefault();
              (e.currentTarget.form as HTMLFormElement | null)?.requestSubmit();
            }
          }}
        />
        {enableAttachments && (
          <PromptInputAttachments
            attachments={pendingAttachments}
            onRemove={onRemoveAttachment}
          />
        )}
        <PromptInputToolbar>
          {enableAttachments ? (
            <PromptInputUpload onClick={onAddAttachments} disabled={status !== "idle"} />
          ) : (
            <span />
          )}
          <PromptInputSubmit
            status={status}
            disabled={!draft.trim() && pendingAttachments.length === 0}
          />
        </PromptInputToolbar>
      </PromptInput>
    </div>
  );
}

// Blocks like thinking, tool_use, and tool_result render as their own
// standalone visual elements rather than nesting inside the role bubble. We
// keep text/error inside the bubble so they read as the assistant's voice.
const STANDALONE_BLOCK_TYPES = new Set(["thinking", "tool_use", "tool_result"]);

function RenderedMessage({ message }: { message: ChatMessage }) {
  // System messages are page-context notes synthesized server-side when the
  // user navigates between pages on the front-end. We render them as a
  // distinct inline note so the user can see what context the agent is
  // working from, without making them look like a normal chat turn.
  if (message.role === "system") {
    const text = message.content
      .map((b) => (b.type === "text" && typeof (b as { text?: unknown }).text === "string"
        ? (b as { text: string }).text
        : ""))
      .filter((t) => t !== "")
      .join("\n\n");
    if (text === "") return null;
    return (
      <div
        data-testid="message-system"
        className="ai:rounded ai:border ai:border-dashed ai:border-craftai-border ai:bg-slate-50 ai:p-2 ai:text-xs ai:text-slate-600"
      >
        <div className="ai:mb-1 ai:text-[10px] ai:font-medium ai:uppercase ai:tracking-wide ai:text-slate-500">
          Page context
        </div>
        <div className="ai:whitespace-pre-wrap">{text}</div>
      </div>
    );
  }

  // Walk content blocks and group consecutive inline blocks together so we
  // emit one bubble per run, with standalone blocks rendered as siblings in
  // their original order.
  const segments: Array<
    { kind: "bubble"; blocks: ContentBlock[] } | { kind: "standalone"; block: ContentBlock }
  > = [];
  for (const block of message.content) {
    if (STANDALONE_BLOCK_TYPES.has(block.type)) {
      segments.push({ kind: "standalone", block });
      continue;
    }
    const last = segments[segments.length - 1];
    if (last && last.kind === "bubble") {
      last.blocks.push(block);
    } else {
      segments.push({ kind: "bubble", blocks: [block] });
    }
  }

  // If the message has attachments but no inline content blocks (e.g., the
  // user clicked Send with only assets attached), surface a placeholder
  // bubble so the attachments still anchor to the user's turn.
  const attachments = message.attachments ?? [];
  if (segments.length === 0 && attachments.length > 0) {
    segments.push({ kind: "bubble", blocks: [] });
  }

  // Tag the first bubble — that's where we'll render the attachment row, so
  // each user turn shows its attached assets exactly once even if the
  // content was split across multiple bubbles.
  const firstBubbleIndex = segments.findIndex((s) => s.kind === "bubble");

  return (
    <>
      {segments.map((segment, i) =>
        segment.kind === "bubble" ? (
          <Message key={i} from={message.role}>
            <MessageContent from={message.role}>
              <div className="ai:mb-1 ai:text-[10px] ai:font-medium ai:uppercase ai:tracking-wide ai:text-craftai-muted">
                {message.role}
              </div>
              <div className="ai:space-y-2">
                {segment.blocks.map((block, j) => (
                  <RenderedBlock key={j} block={block} />
                ))}
                {i === firstBubbleIndex && attachments.length > 0 && (
                  <div
                    data-testid="message-attachments"
                    className="ai:flex ai:flex-wrap ai:gap-2 ai:pt-1"
                  >
                    {attachments.map((a) => (
                      <AttachmentChip key={a.id} attachment={a} />
                    ))}
                  </div>
                )}
              </div>
            </MessageContent>
          </Message>
        ) : (
          <RenderedBlock key={i} block={segment.block} />
        ),
      )}
    </>
  );
}

function RenderedBlock({ block }: { block: ContentBlock }) {
  if (block.type === "text" && typeof (block as { text?: unknown }).text === "string") {
    return <Response>{(block as { text: string }).text}</Response>;
  }
  if (block.type === "thinking" && typeof (block as { thinking?: unknown }).thinking === "string") {
    const b = block as { thinking: string };
    return (
      <div className="ai:rounded ai:border ai:border-slate-200 ai:bg-slate-50 ai:p-2 ai:text-sm ai:text-slate-600">
        <div className="ai:mb-1 ai:text-[10px] ai:font-medium ai:uppercase ai:tracking-wide ai:text-slate-500">
          Thinking
        </div>
        <div className="ai:whitespace-pre-wrap ai:italic">{b.thinking}</div>
      </div>
    );
  }
  if (block.type === "tool_use") {
    const b = block as { name: string; input: Record<string, unknown> };
    return (
      <Tool>
        <ToolHeader name={b.name} status="complete" />
        <ToolContent>
          <ToolInput input={b.input} />
        </ToolContent>
      </Tool>
    );
  }
  if (block.type === "error") {
    const b = block as { text?: string };
    return (
      <div
        role="alert"
        className="ai:rounded ai:border ai:border-red-300 ai:bg-red-50 ai:p-2 ai:text-sm ai:text-red-700"
      >
        <div className="ai:mb-1 ai:text-[10px] ai:font-medium ai:uppercase ai:tracking-wide">
          Error
        </div>
        {b.text ?? "The agent job failed."}
      </div>
    );
  }
  if (block.type === "tool_result") {
    const b = block as { content: string; is_error?: boolean };
    return (
      <Tool>
        <ToolHeader name="Tool result" status={b.is_error ? "error" : "complete"} />
        <ToolContent>
          <ToolOutput output={b.content} isError={b.is_error} />
        </ToolContent>
      </Tool>
    );
  }
  return (
    <pre className="ai:overflow-x-auto ai:rounded ai:bg-slate-50 ai:p-2 ai:text-[11px]">
      {JSON.stringify(block, null, 2)}
    </pre>
  );
}
