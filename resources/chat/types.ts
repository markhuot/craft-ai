export type ContentBlock =
  | { type: "text"; text: string }
  | { type: "thinking"; thinking: string }
  | { type: "tool_use"; id?: string; name: string; input: Record<string, unknown> }
  | { type: "tool_result"; tool_use_id?: string; content: string; is_error?: boolean }
  | { type: "error"; text: string }
  | { type: string; [key: string]: unknown };

export type Role = "user" | "assistant" | "system";

export interface ChatMessage {
  id: number;
  role: Role;
  content: ContentBlock[];
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
  csrfTokenName: string;
  csrfTokenValue: string;
  initialMessages: ChatMessage[];
  initialSessions: SessionListItem[];
}
