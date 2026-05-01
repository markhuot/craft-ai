import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { ChatApi } from "./api";
import { Conversation, ConversationContent } from "./components/conversation";
import { Message, MessageContent } from "./components/message";
import {
  PromptInput,
  PromptInputSubmit,
  PromptInputTextarea,
  PromptInputToolbar,
} from "./components/prompt-input";
import { Response } from "./components/response";
import { Tool, ToolContent, ToolHeader, ToolInput, ToolOutput } from "./components/tool";
import type { ChatBootstrap, ChatMessage, ContentBlock } from "./types";

export interface ChatProps {
  bootstrap: ChatBootstrap;
  api?: ChatApi;
  pollIntervalMs?: number;
}

export function Chat({ bootstrap, api: apiOverride, pollIntervalMs = 1500 }: ChatProps) {
  const api = useMemo(
    () =>
      apiOverride ??
      new ChatApi({
        messagesUrl: bootstrap.messagesUrl,
        sendUrl: bootstrap.sendUrl,
        sessionId: bootstrap.sessionId,
        csrfTokenName: bootstrap.csrfTokenName,
        csrfTokenValue: bootstrap.csrfTokenValue,
      }),
    [apiOverride, bootstrap],
  );

  const [messages, setMessages] = useState<ChatMessage[]>(bootstrap.initialMessages);
  const [draft, setDraft] = useState("");
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
    const id = setInterval(poll, pollIntervalMs);
    return () => clearInterval(id);
  }, [poll, pollIntervalMs]);

  const onSubmit = useCallback(
    async (e: React.FormEvent<HTMLFormElement>) => {
      e.preventDefault();
      const text = draft.trim();
      if (!text || status !== "idle") return;
      setStatus("submitting");
      setError(null);
      try {
        await api.sendMessage(text);
        setDraft("");
        setStatus("streaming");
        await poll();
      } catch (err) {
        setStatus("idle");
        setError(err instanceof Error ? err.message : "Failed to send message");
      }
    },
    [api, draft, poll, status],
  );

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
          required
          autoFocus
          onKeyDown={(e) => {
            if (e.key === "Enter" && (e.metaKey || e.ctrlKey)) {
              e.preventDefault();
              (e.currentTarget.form as HTMLFormElement | null)?.requestSubmit();
            }
          }}
        />
        <PromptInputToolbar>
          <PromptInputSubmit status={status} disabled={!draft.trim()} />
        </PromptInputToolbar>
      </PromptInput>
    </div>
  );
}

function RenderedMessage({ message }: { message: ChatMessage }) {
  return (
    <Message from={message.role}>
      <MessageContent from={message.role}>
        <div className="ai:mb-1 ai:text-[10px] ai:font-medium ai:uppercase ai:tracking-wide ai:text-craftai-muted">
          {message.role}
        </div>
        <div className="ai:space-y-2">
          {message.content.map((block, i) => (
            <RenderedBlock key={i} block={block} />
          ))}
        </div>
      </MessageContent>
    </Message>
  );
}

function RenderedBlock({ block }: { block: ContentBlock }) {
  if (block.type === "text" && typeof (block as { text?: unknown }).text === "string") {
    return <Response>{(block as { text: string }).text}</Response>;
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
  if (block.type === "tool_result") {
    const b = block as { content: string; is_error?: boolean };
    return (
      <Tool defaultOpen>
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
