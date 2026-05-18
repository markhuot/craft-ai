export interface CommentsBootstrap {
  listUrl: string;
  resolveUrl: string;
  openThreadUrl: string;
  csrfTokenName: string;
  csrfTokenValue: string;
}

export interface Comment {
  id: number;
  sessionId: string;
  sessionUrl: string;
  /**
   * When set, the comment has been opened for discussion and this is
   * the forked session that carries the reply thread. The popover
   * routes "open in chat" through this id (after a lazy fork on first
   * open) so each comment owns its own conversation surface.
   */
  threadSessionId: string | null;
  threadSessionUrl: string | null;
  /** User reply count in the fork — zero until the comment is opened. */
  replyCount: number;
  elementId: number;
  /**
   * UID of `elementId`. For Matrix-nested entries this is the value Craft
   * sets on `.matrixblock[data-uid="…"]`, so the overlay can locate the
   * right block container when `data-id` lookups aren't sufficient
   * (e.g. across draft/canonical id collisions).
   */
  elementUid: string | null;
  isDraft: boolean;
  fieldHandle: string | null;
  /** Raw markdown source as the agent wrote it. */
  body: string;
  /**
   * Sanitized HTML rendering of `body` — produced server-side via
   * cebe/markdown + HTMLPurifier. Safe to drop directly into innerHTML.
   * Empty string when `body` is empty.
   */
  bodyHtml: string;
  status: "open" | "resolved";
  resolvedAt: string | null;
  resolvedBy: "user" | "agent" | null;
  authorMessageId: number | null;
  dateCreated: string;
  elementTitle: string | null;
  elementEditUrl: string | null;
}

export interface OpenThreadResponse {
  ok: boolean;
  created: boolean;
  threadSessionId: string;
  sessionUrl: string;
  comment: Comment;
}

export interface CommentListResponse {
  comments: Comment[];
}

export interface ElementContext {
  elementId: number;
  isDraft: boolean;
}
