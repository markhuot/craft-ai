import type {
  Comment,
  CommentListResponse,
  CommentsBootstrap,
  ElementContext,
} from "./types";

/**
 * Thin fetch wrapper around the CommentsController endpoints. Bundles CSRF
 * + JSON headers so the calling code can read like normal async/await
 * against the typed response shapes.
 */
export class CommentsApi {
  constructor(private readonly bootstrap: CommentsBootstrap) {}

  async list(ctx: ElementContext): Promise<Comment[]> {
    const url = new URL(this.bootstrap.listUrl, window.location.origin);
    url.searchParams.set("elementId", String(ctx.elementId));
    url.searchParams.set("isDraft", ctx.isDraft ? "1" : "0");
    url.searchParams.set("status", "open");

    const res = await fetch(url.toString(), {
      method: "GET",
      headers: { Accept: "application/json" },
      credentials: "same-origin",
    });
    if (!res.ok) throw new Error(`comments.list failed: ${res.status}`);
    const payload = (await res.json()) as CommentListResponse;
    return payload.comments ?? [];
  }

  async resolve(commentId: number): Promise<Comment> {
    const body = new FormData();
    body.set("commentId", String(commentId));
    body.set(this.bootstrap.csrfTokenName, this.bootstrap.csrfTokenValue);

    const res = await fetch(this.bootstrap.resolveUrl, {
      method: "POST",
      headers: { Accept: "application/json" },
      credentials: "same-origin",
      body,
    });
    if (!res.ok) throw new Error(`comments.resolve failed: ${res.status}`);
    const payload = (await res.json()) as { comment: Comment };
    return payload.comment;
  }

  async reply(commentId: number, message: string): Promise<{ sessionUrl: string }> {
    const body = new FormData();
    body.set("commentId", String(commentId));
    body.set("message", message);
    body.set(this.bootstrap.csrfTokenName, this.bootstrap.csrfTokenValue);

    const res = await fetch(this.bootstrap.replyUrl, {
      method: "POST",
      headers: { Accept: "application/json" },
      credentials: "same-origin",
      body,
    });
    if (!res.ok) throw new Error(`comments.reply failed: ${res.status}`);
    return (await res.json()) as { sessionUrl: string };
  }
}
