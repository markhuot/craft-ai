/**
 * One block inside a tool_result `content` array. Text blocks render as
 * monospace output; image blocks render the image inline. Mirrors Anthropic's
 * tool_result content vocabulary so the persisted message and the API payload
 * stay in lock-step.
 */
export type ToolResultContentBlock =
  | { type: "text"; text: string }
  | {
      type: "image";
      source:
        | { type: "url"; url: string }
        | { type: "base64"; media_type: string; data: string };
    };

export type ContentBlock =
  | { type: "text"; text: string }
  | { type: "thinking"; thinking: string }
  | { type: "tool_use"; id?: string; name: string; input: Record<string, unknown> }
  | {
      type: "tool_result";
      tool_use_id?: string;
      /**
       * Plain string for legacy/text-only tool results; an array of blocks
       * when the tool returned mixed text + image content (e.g. generate_image).
       */
      content: string | ToolResultContentBlock[];
      is_error?: boolean;
    }
  | { type: "error"; text: string }
  | { type: string; [key: string]: unknown };

export type Role = "user" | "assistant" | "system" | "summary";

export interface Attachment {
  id: number;
  label: string;
  filename: string | null;
  kind: string | null;
  mimeType: string | null;
  thumbUrl: string | null;
}

export interface ChatMessage {
  id: number;
  role: Role;
  content: ContentBlock[];
  attachments?: Attachment[];
  dateCreated?: string;
  /**
   * Prompt tokens reported by the provider for the request that produced
   * this assistant message. Drives the chat's context-window progress gauge.
   * Null/undefined on user/system/summary turns and on assistant turns from
   * providers that don't report usage.
   */
  inputTokens?: number | null;
  /** Completion tokens for this assistant turn — paired with `inputTokens`. */
  outputTokens?: number | null;
}

export interface SessionListItem {
  sessionId: string;
  url: string;
  title?: string | null;
  active: boolean;
  messageCount: number;
  firstMessage: string;
  lastMessage: string;
}

export interface ChatBootstrap {
  sessionId: string;
  messagesUrl: string;
  sendUrl: string;
  sessionsUrl: string;
  newSessionUrl: string;
  sessionsIndexUrl: string;
  assetsInfoUrl: string;
  /**
   * Endpoint the preview pane uses to resolve a pending request once the
   * iframe has loaded (or its contents have been read). The widget on the
   * front-end populates this too even though OpenPreview/GetPreview are
   * CP-only — keeps the shared bootstrap shape stable.
   */
  previewRespondUrl: string;
  /** GET — current per-session tool-mode payload (mode + enabled list + available tools). */
  toolModeUrl: string;
  /** POST — persist a new tool-mode selection. */
  updateToolModeUrl: string;
  csrfTokenName: string;
  csrfTokenValue: string;
  initialMessages: ChatMessage[];
  initialSessions: SessionListItem[];
  /**
   * Page-context payload to attach to outgoing messages whose
   * `contextFingerprint` differs from the last one we sent on this session.
   * The CP-side chat omits both — the widget on the front-end provides them.
   */
  context?: unknown;
  contextFingerprint?: string;
  /**
   * Maximum tokens the configured model accepts. Drives the chat UI's
   * context-window gauge. Null/undefined when the host hasn't configured
   * one and the per-model defaults can't resolve a value — the gauge
   * silently hides itself in that case.
   */
  contextWindow?: number | null;
}

export type ToolMode = "full" | "draft" | "readonly" | "custom";

export type ToolKind = "read" | "draftWrite" | "liveWrite";

export interface AvailableTool {
  name: string;
  description: string;
  kind: ToolKind;
}

export interface ToolModePayload {
  toolMode: ToolMode;
  /** Only populated when toolMode === "custom". */
  enabledTools: string[] | null;
  availableTools: AvailableTool[];
}

/**
 * Out-of-band request from a blocking tool (OpenPreview/GetPreview) running
 * inside the agent loop. The chat surface picks these up by piggybacking on
 * the existing message poll, mounts/reads the iframe, and resolves them
 * through the preview-respond endpoint.
 */
export interface PreviewRequest {
  id: number;
  type: "open" | "get";
  status: "pending";
  input: Record<string, unknown>;
}

export interface MessagesResponse {
  messages: ChatMessage[];
  previewRequest: PreviewRequest | null;
  /**
   * URL of the most recent successfully-opened preview for this session,
   * or null if the session has never had one. Sticky on the server side —
   * survives page reload so the chat surface can render a "reopen preview"
   * affordance even after the in-memory iframe state is wiped.
   */
  lastPreviewUrl: string | null;
  /**
   * Maximum tokens the configured model accepts. Same value as
   * `ChatBootstrap.contextWindow` — re-emitted on every poll so the gauge
   * picks up live config changes without a page reload.
   */
  contextWindow?: number | null;
  /**
   * Built-in slash commands the user can invoke from the prompt input.
   * Server is the source of truth — the autocomplete menu renders whatever
   * the latest poll returned. Falls back to a single hardcoded `/compact`
   * entry when the server payload is missing (older deployments).
   */
  slashCommands?: SlashCommand[];
}

export interface SlashCommand {
  name: string;
  description: string;
  /**
   * When true, the autocomplete picker fills the prompt with `/name ` and
   * waits for the user to type arguments. When false (the default), it
   * fills the prompt with `/name` exactly and submits immediately.
   */
  takesArgs: boolean;
}

/**
 * One element the user picked on the host page via the widget's target tool.
 * Surfaces in the prompt input as a chip and gets prepended to the next
 * outgoing message as a `<selected-element>` note so the agent knows which
 * DOM node the user is talking about.
 */
export interface TargetSelection {
  selector: string;
  snippet: string;
  tag: string;
  text: string;
}
