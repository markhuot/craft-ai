export type ContentBlock =
  | { type: "text"; text: string }
  | { type: "tool_use"; id?: string; name: string; input: Record<string, unknown> }
  | { type: "tool_result"; tool_use_id?: string; content: string; is_error?: boolean }
  | { type: string; [key: string]: unknown };

export type Role = "user" | "assistant" | "system";

export interface ChatMessage {
  id: number;
  role: Role;
  content: ContentBlock[];
  dateCreated?: string;
}

export interface ChatBootstrap {
  sessionId: string;
  messagesUrl: string;
  sendUrl: string;
  csrfTokenName: string;
  csrfTokenValue: string;
  initialMessages: ChatMessage[];
}
