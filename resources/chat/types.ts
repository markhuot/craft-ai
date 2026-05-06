export type ContentBlock =
  | { type: "text"; text: string }
  | { type: "thinking"; thinking: string }
  | { type: "tool_use"; id?: string; name: string; input: Record<string, unknown> }
  | { type: "tool_result"; tool_use_id?: string; content: string; is_error?: boolean }
  | { type: "error"; text: string }
  | { type: string; [key: string]: unknown };

export type Role = "user" | "assistant" | "system";

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
}
