export interface CommentsBootstrap {
  listUrl: string;
  resolveUrl: string;
  replyUrl: string;
  csrfTokenName: string;
  csrfTokenValue: string;
}

export interface Comment {
  id: number;
  sessionId: string;
  sessionUrl: string;
  elementId: number;
  isDraft: boolean;
  fieldHandle: string | null;
  blockPath: string | null;
  body: string;
  status: "open" | "resolved";
  resolvedAt: string | null;
  resolvedBy: "user" | "agent" | null;
  authorMessageId: number | null;
  dateCreated: string;
  elementTitle: string | null;
  elementEditUrl: string | null;
}

export interface CommentListResponse {
  comments: Comment[];
}

export interface ElementContext {
  elementId: number;
  isDraft: boolean;
}
