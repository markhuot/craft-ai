import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { Crosshair, Globe, X } from "lucide-react";
import { ChatApi } from "./api";
import { ContextProgress } from "./components/context-progress";
import { Conversation, ConversationContent } from "./components/conversation";
import { Message, MessageContent } from "./components/message";
import { SlashCommandMenu, filterCommands } from "./components/slash-command-menu";
import {
  PreviewPane,
  type PreviewPaneHandle,
  type PreviewPaneMode,
} from "./components/preview-pane";
import { PermissionMode } from "./components/permission-mode";
import {
  AttachmentChip,
  PromptInput,
  PromptInputAttachments,
  PromptInputSubmit,
  PromptInputTextarea,
  PromptInputToolbar,
  PromptInputUpload,
} from "./components/prompt-input";
import { ResizeDivider } from "./components/resize-divider";
import { Response } from "./components/response";
import { Tool, ToolContent, ToolHeader, ToolInput, ToolOutput } from "./components/tool";
import { openAssetSelector, type AssetSelectorOpener } from "./lib/assetSelector";
import { useResizableSplit } from "./lib/useResizableSplit";
import type {
  Attachment,
  AvailableTool,
  ChatBootstrap,
  ChatMessage,
  ContentBlock,
  PreviewRequest,
  SlashCommand,
  TargetSelection,
  ToolMode,
} from "./types";

/**
 * Fallback slash commands when the server hasn't sent a catalog yet (older
 * deployments, or the very first render before the first poll completes).
 * Keep this list short — the server is the source of truth.
 */
const DEFAULT_SLASH_COMMANDS: SlashCommand[] = [
  {
    name: "compact",
    description: "Summarize the conversation so far to free context window.",
    takesArgs: false,
  },
];

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
  /**
   * Fires whenever the chat transitions in or out of peek mode (preview open
   * and not expanded). The Shell uses this to auto-collapse the sessions
   * sidebar so the peek pane gets more horizontal room.
   */
  onPreviewPeekChange?: (peek: boolean) => void;
  /**
   * When true, render the "target an element" affordance in the toolbar.
   * The widget on the front-end opts in; the CP chat surface leaves it off
   * because clicking host-page DOM doesn't map to anything meaningful inside
   * the control panel.
   */
  enableTargeting?: boolean;
  /**
   * Element the user has currently picked, if any. Owned by the widget so it
   * can persist across the picker entering/leaving "active" mode. Rendered
   * as a chip above the textarea and prepended to the next outgoing message.
   */
  selectedTarget?: TargetSelection | null;
  /** Fired when the user clicks the target icon — widget kicks off the picker. */
  onStartTargeting?: () => void;
  /** Fired when the user clicks the chip's X to drop the current selection. */
  onClearTarget?: () => void;
}

const CONTEXT_FP_STORAGE_PREFIX = "craftai-widget:context-fp:";
// Defaults match the pre-resizable layout so first-time users see the same
// split until they drag. Persisted under separate keys per mode because the
// modes have very different "primary pane" mental models — peek treats the
// transcript as the focus, expanded treats the preview as the focus.
const PEEK_TRANSCRIPT_DEFAULT_PCT = 67;
const EXPANDED_TRANSCRIPT_DEFAULT_PCT = 33;
const PEEK_TRANSCRIPT_STORAGE_KEY = "craftai-chat:transcript-pct:peek";
const EXPANDED_TRANSCRIPT_STORAGE_KEY = "craftai-chat:transcript-pct:expanded";

export function Chat({
  bootstrap,
  api: apiOverride,
  pollIntervalMs = 1500,
  openAssetSelector: openAssetSelectorOverride,
  enableAttachments = true,
  storage,
  onPreviewPeekChange,
  enableTargeting = false,
  selectedTarget = null,
  onStartTargeting,
  onClearTarget,
}: ChatProps) {
  const api = useMemo(
    () =>
      apiOverride ??
      new ChatApi({
        messagesUrl: bootstrap.messagesUrl,
        sendUrl: bootstrap.sendUrl,
        assetsInfoUrl: bootstrap.assetsInfoUrl,
        previewRespondUrl: bootstrap.previewRespondUrl,
        toolModeUrl: bootstrap.toolModeUrl,
        updateToolModeUrl: bootstrap.updateToolModeUrl,
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

  // Tool-mode selection for this session. Hydrated lazily on mount so the
  // Chat component works the same way for the CP view (where sessionId is
  // known up front via bootstrap) and the widget (where sessionId is picked
  // client-side after the user opens a session).
  const [toolMode, setToolMode] = useState<ToolMode>("full");
  const [enabledTools, setEnabledTools] = useState<string[] | null>(null);
  const [availableTools, setAvailableTools] = useState<AvailableTool[]>([]);
  const [toolModeLoaded, setToolModeLoaded] = useState(false);

  // The preview pane survives across requests on the same session — opening
  // a second URL replaces the first, but a `GetPreview` between them reads
  // whatever's currently mounted.
  const [previewUrl, setPreviewUrl] = useState<string | null>(null);
  const [previewMode, setPreviewMode] = useState<PreviewPaneMode>("peek");
  const [pendingOpenRequestId, setPendingOpenRequestId] = useState<number | null>(null);
  // Counter passed to PreviewPane — bumping it forces the iframe to remount
  // even when the URL is unchanged. Without this, a repeat open_preview for
  // the URL the iframe is already showing would be a React no-op and the
  // tool would time out waiting for an onLoad that never fires.
  const [previewReloadKey, setPreviewReloadKey] = useState(0);
  // Sticky pointer at the most recent URL the agent has opened in this
  // session, sourced from the messages poll envelope. Drives the toolbar
  // globe so a page reload (which wipes the previewUrl React state) still
  // lets the user re-mount the iframe with one click.
  const [lastPreviewUrl, setLastPreviewUrl] = useState<string | null>(null);
  // Server-reported max prompt tokens for the configured model. Seeded
  // from bootstrap and refreshed on every messages poll so the gauge
  // picks up a config change without a page reload.
  const [contextWindow, setContextWindow] = useState<number | null>(
    typeof bootstrap.contextWindow === "number" && bootstrap.contextWindow > 0
      ? bootstrap.contextWindow
      : null,
  );
  // Catalog of available slash commands. Hydrated from the server on each
  // poll; the default keeps the autocomplete usable before the first poll
  // (or against older backends that don't surface the catalog yet).
  const [slashCommands, setSlashCommands] = useState<SlashCommand[]>(DEFAULT_SLASH_COMMANDS);
  // Highlighted index within the *filtered* slash command list. Bounded
  // by the filtered length on every draft change; reset to 0 when the
  // filter changes so the highlight always lands on the top entry first.
  const [slashSelectedIndex, setSlashSelectedIndex] = useState(0);
  // The actual URL the iframe is currently displaying — updates whenever
  // PreviewPane reports a load (initial mount, redirects, in-iframe link
  // clicks). Used to ride along on the next outgoing message as page
  // context so the agent learns about navigations the user makes by
  // clicking links inside the preview.
  const [previewLiveUrl, setPreviewLiveUrl] = useState<string | null>(null);
  const previewPaneRef = useRef<PreviewPaneHandle | null>(null);
  const layoutContainerRef = useRef<HTMLDivElement | null>(null);
  const [peekTranscriptPct, setPeekTranscriptPct] = useResizableSplit(
    PEEK_TRANSCRIPT_STORAGE_KEY,
    PEEK_TRANSCRIPT_DEFAULT_PCT,
  );
  const [expandedTranscriptPct, setExpandedTranscriptPct] = useResizableSplit(
    EXPANDED_TRANSCRIPT_STORAGE_KEY,
    EXPANDED_TRANSCRIPT_DEFAULT_PCT,
  );
  // Requests we've already started to handle. Polling can return the same
  // pending row repeatedly until we resolve it server-side; without dedup,
  // a slow respond() POST would race itself.
  const handledRequestIdsRef = useRef<Set<number>>(new Set());

  const lastIdRef = useRef<number>(
    bootstrap.initialMessages.reduce((max, m) => Math.max(max, m.id), 0),
  );

  const poll = useCallback(async () => {
    try {
      const fetched = await api.fetchMessagesAfter(lastIdRef.current);
      if (fetched.messages.length > 0) {
        setMessages((prev) => {
          const seen = new Set(prev.map((m) => m.id));
          const merged = [...prev];
          for (const m of fetched.messages) {
            if (!seen.has(m.id)) {
              merged.push(m);
              if (m.id > lastIdRef.current) lastIdRef.current = m.id;
            }
          }
          return merged;
        });
        const last = fetched.messages[fetched.messages.length - 1];
        if (last && last.role === "assistant") {
          setStatus("idle");
        }
      }
      if (fetched.previewRequest) {
        void handlePreviewRequest(fetched.previewRequest);
      }
      // The server's authoritative pointer at the most recent open. On the
      // first poll after a page reload this is what restores the globe
      // toggle to the toolbar even though previewUrl is null.
      setLastPreviewUrl(fetched.lastPreviewUrl);
      // Pick up the latest context-window setting on every poll. The
      // value rarely changes mid-session but admins can edit
      // config/craft-ai.php without restarting and we want the gauge
      // to reflect it on the next tick.
      if (typeof fetched.contextWindow === "number" && fetched.contextWindow > 0) {
        setContextWindow(fetched.contextWindow);
      }
      // Server is authoritative for the slash command catalog — refresh
      // on every poll so adding a command server-side propagates without
      // a UI deploy.
      if (Array.isArray(fetched.slashCommands)) {
        setSlashCommands(fetched.slashCommands);
      }
    } catch {
      // transient — keep polling
    }
    // handlePreviewRequest is stable via useCallback below; including it in
    // the dep array would force every render to allocate a new poll closure.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [api]);

  // Wrap respondToPreviewRequest so a failed POST surfaces in the chat
  // surface instead of silently rejecting the promise. The agent's wait
  // loop will eventually time out either way, but a visible error tells
  // the user why their preview command went quiet.
  const safeRespond = useCallback(
    async (
      id: number,
      status: "completed" | "errored",
      result: Record<string, unknown>,
    ) => {
      try {
        await api.respondToPreviewRequest(id, status, result);
      } catch (err) {
        const detail = err instanceof Error ? err.message : "Unknown error";
        setError(`Preview response failed: ${detail}. The agent will time out and recover.`);
      }
    },
    [api],
  );

  const handlePreviewRequest = useCallback(
    async (req: PreviewRequest) => {
      if (handledRequestIdsRef.current.has(req.id)) return;
      handledRequestIdsRef.current.add(req.id);

      if (req.type === "open") {
        const url = typeof req.input.url === "string" ? req.input.url : "";
        if (!url) {
          await safeRespond(req.id, "errored", {
            error: "OpenPreview was called without a url.",
          });
          return;
        }
        setPreviewUrl(url);
        // Optimistically update the globe pointer so the toolbar reflects
        // the new URL instantly, before the server's next poll round-trips.
        setLastPreviewUrl(url);
        // Bump the reload key so PreviewPane remounts the iframe even when
        // the URL hasn't changed (e.g. the agent re-opens the same URL
        // after an edit). Without this, React's diff skips the src= update
        // and onLoad never fires, hanging the tool until timeout.
        setPreviewReloadKey((n) => n + 1);
        // Don't touch previewMode here. The user owns that — peek is the
        // default after `closePreview` resets it (or initial mount), and an
        // explicit expand-button click flips to expanded. Re-opening (e.g.
        // the agent refreshing the URL after an edit) must not yank an
        // expanded pane back to peek mid-conversation.
        // The iframe's onLoad handler resolves the request once the new
        // URL finishes loading; we pin the request id so onLoad/onError
        // know which one to ack.
        setPendingOpenRequestId(req.id);
        return;
      }

      if (req.type === "get") {
        const fullHtml = req.input.fullHtml === true;
        if (!previewPaneRef.current) {
          await safeRespond(req.id, "errored", {
            error: "No preview is open. Call open_preview first or use fetch_webpage.",
          });
          return;
        }
        let content: string;
        try {
          content = previewPaneRef.current.readContents(fullHtml ? "full" : "text");
        } catch (err) {
          await safeRespond(req.id, "errored", {
            error: err instanceof Error ? err.message : "Failed to read preview contents.",
          });
          return;
        }
        await safeRespond(req.id, "completed", {
          content,
          mode: fullHtml ? "full" : "text",
        });
      }
    },
    [safeRespond],
  );

  const handlePreviewLoaded = useCallback(
    (finalUrl: string) => {
      // Reflect every load — including in-iframe link clicks that don't
      // go through the agent — so the next user message can attach the
      // actual URL as context. Fingerprint dedup in onSubmit prevents
      // the same URL from being re-sent across messages.
      //
      // Note we deliberately don't touch lastPreviewUrl here: that pointer
      // is server-authoritative (only the agent's resolved opens persist),
      // so a client-only override would be undone by the next poll.
      setPreviewLiveUrl(finalUrl);
      const id = pendingOpenRequestId;
      if (id === null) return;
      setPendingOpenRequestId(null);
      void safeRespond(id, "completed", {
        loadedAt: Date.now(),
        finalUrl,
      });
    },
    [safeRespond, pendingOpenRequestId],
  );

  const handlePreviewError = useCallback(
    (message: string) => {
      const id = pendingOpenRequestId;
      if (id === null) return;
      setPendingOpenRequestId(null);
      void safeRespond(id, "errored", { error: message });
    },
    [safeRespond, pendingOpenRequestId],
  );

  const closePreview = useCallback(() => {
    setPreviewUrl(null);
    setPreviewMode("peek");
    // Stop riding the closed iframe's URL into context sends. The globe
    // still keeps lastPreviewUrl so the user can reopen, but until they do
    // there's no "preview the user is looking at" to advertise.
    setPreviewLiveUrl(null);
    if (pendingOpenRequestId !== null) {
      // Resolve any in-flight OpenPreview as an error so the agent doesn't
      // hang waiting for a load that will never fire.
      void safeRespond(pendingOpenRequestId, "errored", {
        error: "User closed the preview before it finished loading.",
      });
      setPendingOpenRequestId(null);
    }
    // Note: lastPreviewUrl is intentionally preserved so the globe stays
    // available. "Close" is hide-for-now, not permanent dismissal.
  }, [safeRespond, pendingOpenRequestId]);

  const togglePreview = useCallback(() => {
    if (previewUrl !== null) {
      closePreview();
      return;
    }
    if (lastPreviewUrl) {
      // User-initiated reopen — no agent is waiting on this, so we don't
      // pin a pendingOpenRequestId. Iframe just shows up.
      setPreviewUrl(lastPreviewUrl);
      setPreviewMode("peek");
    }
  }, [previewUrl, lastPreviewUrl, closePreview]);

  useEffect(() => {
    // Fire once immediately so a freshly-mounted Chat (e.g. the widget
    // switching sessions) shows existing history without waiting a full
    // poll interval. Subsequent ticks pick up new messages from the agent.
    void poll();
    const id = setInterval(poll, pollIntervalMs);
    return () => clearInterval(id);
  }, [poll, pollIntervalMs]);

  useEffect(() => {
    // Reset between session switches (the widget remounts <Chat /> with a
    // new key, but be defensive in case a future caller doesn't) so a stale
    // payload doesn't briefly leak into the UI.
    setToolModeLoaded(false);
    let cancelled = false;
    void api
      .fetchToolMode()
      .then((payload) => {
        if (cancelled) return;
        setToolMode(payload.toolMode);
        setEnabledTools(payload.enabledTools);
        setAvailableTools(payload.availableTools);
        setToolModeLoaded(true);
      })
      .catch(() => {
        if (cancelled) return;
        // Failure is non-fatal — the menu hides itself until tools load,
        // and the agent run still uses whatever the DB says (default 'full').
        setToolModeLoaded(true);
      });
    return () => {
      cancelled = true;
    };
  }, [api]);

  const handleToolModeChange = useCallback(
    (mode: ToolMode, nextEnabled: string[] | null) => {
      // Optimistic update so the menu reflects the user's choice instantly.
      // If the POST fails we roll back to the server's authoritative state.
      const prevMode = toolMode;
      const prevEnabled = enabledTools;
      setToolMode(mode);
      setEnabledTools(nextEnabled);
      void api
        .updateToolMode(mode, nextEnabled)
        .then((payload) => {
          setToolMode(payload.toolMode);
          setEnabledTools(payload.enabledTools);
          setAvailableTools(payload.availableTools);
        })
        .catch((err) => {
          setToolMode(prevMode);
          setEnabledTools(prevEnabled);
          setError(err instanceof Error ? err.message : "Failed to update permission mode");
        });
    },
    [api, enabledTools, toolMode],
  );

  // Tell the host (Shell) when the chat is showing a peek-mode preview, so
  // it can collapse the sessions sidebar to free up horizontal space.
  // Expanded mode breaks out into a fixed overlay that already covers the
  // sidebar, so we treat that case as "not peeking" for this signal.
  useEffect(() => {
    if (!onPreviewPeekChange) return;
    onPreviewPeekChange(previewUrl !== null && previewMode === "peek");
  }, [onPreviewPeekChange, previewUrl, previewMode]);

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
      //
      // Two sources of context can flow here:
      //   * The widget bootstrap, set once when the chat mounts on a
      //     front-end page (rich element-level details).
      //   * A live preview URL the user is currently looking at. If the
      //     iframe has loaded a real URL, we prefer that — it represents
      //     the user's actual focus, including pages they reached by
      //     clicking links inside the preview. The fingerprint key is
      //     namespaced so it never collides with bootstrap SHA-1 hashes.
      const store =
        storage ??
        (typeof window !== "undefined" && window.localStorage ? window.localStorage : null);
      const fpKey = `${CONTEXT_FP_STORAGE_PREFIX}${bootstrap.sessionId}`;
      let activeCtx: unknown = undefined;
      let activeFp = "";
      if (previewLiveUrl !== null && previewLiveUrl !== "") {
        activeCtx = { url: previewLiveUrl };
        activeFp = `preview:${previewLiveUrl}`;
      } else if (
        bootstrap.context !== undefined &&
        bootstrap.context !== null &&
        (bootstrap.contextFingerprint ?? "") !== ""
      ) {
        activeCtx = bootstrap.context;
        activeFp = bootstrap.contextFingerprint!;
      }
      let attachContext: unknown = undefined;
      if (activeCtx !== undefined && activeFp !== "") {
        let prior: string | null = null;
        try {
          prior = store?.getItem(fpKey) ?? null;
        } catch {
          prior = null;
        }
        if (prior !== activeFp) {
          attachContext = activeCtx;
        }
      }

      // When the user has picked an element on the host page, prepend a
      // structured note so the agent learns which DOM node "this" refers to.
      // The note is plain text inside the user turn — no backend changes
      // needed — and `selector` gives the agent something to feed back into
      // tools that read templates or rendered HTML.
      const outgoingText = selectedTarget
        ? `<selected-element selector=${JSON.stringify(selectedTarget.selector)}>\n${selectedTarget.snippet}\n</selected-element>\n\n${text}`
        : text;

      try {
        await api.sendMessage(
          outgoingText,
          pendingAttachments.map((a) => a.id),
          attachContext,
        );
        if (attachContext !== undefined && activeFp !== "") {
          // Only mark the fingerprint as sent on success — a failed send
          // should retry context attachment on the next attempt.
          try {
            store?.setItem(fpKey, activeFp);
          } catch {
            // localStorage can throw in private mode / quota-exceeded — keep
            // sending context until it sticks.
          }
        }
        setDraft("");
        setPendingAttachments([]);
        if (selectedTarget && onClearTarget) {
          onClearTarget();
        }
        setStatus("streaming");
        await poll();
      } catch (err) {
        setStatus("idle");
        setError(err instanceof Error ? err.message : "Failed to send message");
      }
    },
    [api, bootstrap.context, bootstrap.contextFingerprint, bootstrap.sessionId, draft, onClearTarget, pendingAttachments, poll, previewLiveUrl, selectedTarget, status, storage],
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

  // Live-filtered slash command list. The menu component does its own
  // filter for rendering, but Chat needs the same list to clamp the
  // selected index, decide whether Enter picks vs. submits, and resolve
  // which entry the user is selecting when they hit Enter.
  const filteredSlashCommands = useMemo(
    () => filterCommands(draft, slashCommands),
    [draft, slashCommands],
  );
  const slashMenuOpen = filteredSlashCommands.length > 0;

  // Keep the highlight inside the filtered range. Without this, typing
  // characters that shrink the list would leave the selection past the
  // last visible entry and Enter would no-op silently.
  useEffect(() => {
    if (!slashMenuOpen) {
      setSlashSelectedIndex(0);
      return;
    }
    setSlashSelectedIndex((idx) =>
      idx >= filteredSlashCommands.length ? 0 : idx,
    );
  }, [slashMenuOpen, filteredSlashCommands.length]);

  const sendCommand = useCallback(
    async (commandText: string) => {
      if (status !== "idle") return;
      setStatus("submitting");
      setError(null);
      try {
        await api.sendMessage(commandText, [], undefined);
        setDraft("");
        setStatus("streaming");
        await poll();
      } catch (err) {
        setStatus("idle");
        setError(err instanceof Error ? err.message : "Failed to send command");
      }
    },
    [api, poll, status],
  );

  const pickSlashCommand = useCallback(
    (command: SlashCommand) => {
      if (command.takesArgs) {
        // Drop the user back into the textarea with the command name
        // pre-typed and a trailing space so they can keep typing args.
        // No submit — they still need to provide arguments first.
        setDraft(`/${command.name} `);
        return;
      }
      // Parameterless command: fire it directly. We bypass the regular
      // onSubmit so the user only has to hit Enter once.
      void sendCommand(`/${command.name}`);
    },
    [sendCommand],
  );

  // The "context used" gauge tracks the most recent assistant turn's
  // prompt-token count. We use a single most-recent value (not the running
  // max) because auto-compaction resets usage downward — surfacing the
  // running max would lie about how much room is currently free.
  const latestAssistantUsage = useMemo(() => {
    for (let i = messages.length - 1; i >= 0; i--) {
      const m = messages[i];
      if (m && m.role === "assistant" && typeof m.inputTokens === "number") {
        return (m.inputTokens ?? 0) + (m.outputTokens ?? 0);
      }
    }
    return 0;
  }, [messages]);

  const transcript = (
    <div className="craftai-chat ai:flex ai:min-h-0 ai:flex-1 ai:flex-col ai:gap-3">
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

      <PromptInput onSubmit={onSubmit} className="ai:relative">
        <SlashCommandMenu
          draft={draft}
          commands={slashCommands}
          selectedIndex={slashSelectedIndex}
          onPick={pickSlashCommand}
        />
        <PromptInputTextarea
          name="message"
          placeholder="Send a message…"
          value={draft}
          onChange={(e) => setDraft(e.target.value)}
          autoFocus
          onKeyDown={(e) => {
            // Slash menu keyboard nav takes priority when the menu is open.
            // ArrowDown/Up move the highlight; Enter picks the highlighted
            // command (and may auto-submit); Escape closes the menu by
            // collapsing the draft back to its non-slash prefix.
            if (slashMenuOpen) {
              if (e.key === "ArrowDown") {
                e.preventDefault();
                setSlashSelectedIndex((idx) =>
                  (idx + 1) % filteredSlashCommands.length,
                );
                return;
              }
              if (e.key === "ArrowUp") {
                e.preventDefault();
                setSlashSelectedIndex((idx) =>
                  (idx - 1 + filteredSlashCommands.length) %
                  filteredSlashCommands.length,
                );
                return;
              }
              if (e.key === "Enter" && !e.shiftKey && !e.altKey) {
                e.preventDefault();
                const picked = filteredSlashCommands[slashSelectedIndex];
                if (picked) pickSlashCommand(picked);
                return;
              }
              if (e.key === "Escape") {
                e.preventDefault();
                setDraft("");
                return;
              }
              if (e.key === "Tab") {
                // Tab autocompletes to the highlighted command name
                // without firing it, so the user can keep typing args.
                e.preventDefault();
                const picked = filteredSlashCommands[slashSelectedIndex];
                if (picked) {
                  setDraft(picked.takesArgs ? `/${picked.name} ` : `/${picked.name}`);
                }
                return;
              }
            }
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
        {selectedTarget && (
          <div
            data-testid="target-chip"
            className="ai:flex ai:items-center ai:gap-1.5 ai:rounded-md ai:border ai:border-craftai-accent/40 ai:bg-craftai-user ai:px-2 ai:py-1 ai:text-xs ai:text-craftai-fg"
          >
            <Crosshair className="ai:h-3.5 ai:w-3.5 ai:text-craftai-accent" aria-hidden />
            <span className="ai:min-w-0 ai:flex-1 ai:truncate" title={selectedTarget.selector}>
              {selectedTarget.snippet}
            </span>
            {onClearTarget && (
              <button
                type="button"
                aria-label="Clear selected element"
                onClick={onClearTarget}
                className="ai:inline-flex ai:h-4 ai:w-4 ai:items-center ai:justify-center ai:rounded ai:text-craftai-muted hover:ai:bg-craftai-border/40"
              >
                <X className="ai:h-3 ai:w-3" aria-hidden />
              </button>
            )}
          </div>
        )}
        <PromptInputToolbar>
          <div className="ai:flex ai:items-center ai:gap-1.5">
            {enableAttachments && (
              <PromptInputUpload onClick={onAddAttachments} disabled={status !== "idle"} />
            )}
            {enableTargeting && onStartTargeting && (
              <button
                type="button"
                onClick={onStartTargeting}
                disabled={status !== "idle"}
                aria-label="Target an element on the page"
                title="Click an element on the page to attach it to your next message"
                data-testid="target-toggle"
                className="ai:inline-flex ai:items-center ai:justify-center ai:rounded-md ai:border ai:border-craftai-border ai:bg-white ai:p-1.5 ai:text-craftai-fg ai:transition hover:ai:bg-craftai-border/20 ai:disabled:opacity-50 ai:disabled:cursor-not-allowed"
              >
                <Crosshair className="ai:h-3.5 ai:w-3.5" aria-hidden />
              </button>
            )}
            {toolModeLoaded && availableTools.length > 0 && (
              <PermissionMode
                mode={toolMode}
                enabledTools={enabledTools}
                availableTools={availableTools}
                onChange={handleToolModeChange}
                disabled={status !== "idle"}
              />
            )}
            <ContextProgress used={latestAssistantUsage} contextWindow={contextWindow} />
            {lastPreviewUrl !== null && previewUrl === null && (
              // Only render when there's a preview to reopen but it's
              // currently hidden. While the pane is mounted, the X on its
              // own header is the close affordance — a redundant toolbar
              // button would just be visual noise.
              <button
                type="button"
                onClick={togglePreview}
                aria-label="Show preview"
                title="Show preview"
                data-testid="preview-toggle"
                className="ai:inline-flex ai:items-center ai:gap-1.5 ai:rounded-md ai:border ai:border-craftai-border ai:bg-white ai:px-3 ai:py-1.5 ai:text-xs ai:font-medium ai:text-craftai-fg ai:transition hover:ai:bg-craftai-border/20"
              >
                <Globe className="ai:h-3.5 ai:w-3.5" aria-hidden />
                Preview
              </button>
            )}
          </div>
          <PromptInputSubmit
            status={status}
            disabled={!draft.trim() && pendingAttachments.length === 0}
          />
        </PromptInputToolbar>
      </PromptInput>
    </div>
  );

  // No preview open — keep the original flow exactly so non-CP surfaces and
  // tests that don't exercise preview behavior see no layout change.
  if (previewUrl === null) {
    return transcript;
  }

  const previewPane = (
    <PreviewPane
      ref={previewPaneRef}
      url={previewUrl}
      mode={previewMode}
      loading={pendingOpenRequestId !== null}
      reloadKey={previewReloadKey}
      onLoad={handlePreviewLoaded}
      onError={handlePreviewError}
      onExpand={() => setPreviewMode("expanded")}
      onCollapse={() => setPreviewMode("peek")}
      onClose={closePreview}
    />
  );

  if (previewMode === "expanded") {
    // Break out of the CP page container so we can claim the whole viewport.
    // Default split is 1/3 chat / 2/3 preview, but the user can drag the
    // divider and the choice persists across reloads.
    return (
      <div
        ref={layoutContainerRef}
        data-testid="chat-with-preview"
        data-preview-mode="expanded"
        className="ai:fixed ai:inset-0 ai:z-50 ai:flex ai:bg-craftai-bg"
      >
        <div
          data-testid="chat-transcript-pane"
          style={{ width: `${expandedTranscriptPct}%` }}
          className="ai:flex ai:min-w-0 ai:flex-col ai:overflow-hidden ai:p-3"
        >
          {transcript}
        </div>
        <ResizeDivider
          containerRef={layoutContainerRef}
          onResize={setExpandedTranscriptPct}
          ariaValueNow={expandedTranscriptPct}
        />
        <div className="ai:flex ai:flex-1 ai:min-w-0 ai:flex-col ai:p-3">
          {previewPane}
        </div>
      </div>
    );
  }

  // Peek: stay inside the CP page container. Default split is 2/3 chat /
  // 1/3 preview, with the same drag-to-resize divider as expanded mode.
  // Clicking expand on the preview header flips us to the fixed overlay
  // above; closing returns to the transcript-only view.
  return (
    <div
      ref={layoutContainerRef}
      data-testid="chat-with-preview"
      data-preview-mode="peek"
      className="ai:flex ai:min-h-0 ai:flex-1"
    >
      <div
        data-testid="chat-transcript-pane"
        style={{ width: `${peekTranscriptPct}%` }}
        className="ai:flex ai:min-w-0 ai:flex-col"
      >
        {transcript}
      </div>
      <ResizeDivider
        containerRef={layoutContainerRef}
        onResize={setPeekTranscriptPct}
        ariaValueNow={peekTranscriptPct}
      />
      <div className="ai:flex ai:flex-1 ai:min-w-0 ai:flex-col">
        {previewPane}
      </div>
    </div>
  );
}

// Blocks like thinking, tool_use, and tool_result render as their own
// standalone visual elements rather than nesting inside the role bubble. We
// keep text/error inside the bubble so they read as the assistant's voice.
const STANDALONE_BLOCK_TYPES = new Set(["thinking", "tool_use", "tool_result"]);

function RenderedMessage({ message }: { message: ChatMessage }) {
  // Summary messages mark a compaction boundary — everything before this
  // row in the transcript got squashed into the summary text and the LLM
  // no longer sees the originals. We render it as a distinct card so the
  // user knows where the conversation got "rebased."
  if (message.role === "summary") {
    const text = message.content
      .map((b) =>
        b.type === "text" && typeof (b as { text?: unknown }).text === "string"
          ? (b as { text: string }).text
          : "",
      )
      .filter((t) => t !== "")
      .join("\n\n");
    return (
      <div
        data-testid="message-summary"
        className="ai:rounded ai:border ai:border-amber-300 ai:bg-amber-50 ai:p-3 ai:text-xs ai:text-amber-900"
      >
        <div className="ai:mb-1 ai:flex ai:items-center ai:gap-2 ai:text-[10px] ai:font-semibold ai:uppercase ai:tracking-wide">
          <span aria-hidden>↺</span>
          Conversation summarized
        </div>
        <div className="ai:whitespace-pre-wrap ai:leading-relaxed">
          {text || "Earlier turns in this conversation were summarized to fit the context window."}
        </div>
      </div>
    );
  }

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
    const b = block as {
      content: string | Array<{ type: string; [key: string]: unknown }>;
      is_error?: boolean;
    };
    return (
      <Tool defaultOpen={b.is_error}>
        <ToolHeader name="Tool result" status={b.is_error ? "error" : "complete"} />
        <ToolContent>
          <ToolOutput
            output={b.content as Parameters<typeof ToolOutput>[0]["output"]}
            isError={b.is_error}
          />
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
