import type { Comment, ElementContext } from "./types";

/**
 * Detect the entry/draft currently being edited by scanning the page's
 * main form for the hidden inputs Craft injects on element edit screens.
 * Returns null when no entry edit is in progress (settings page, listing,
 * etc.), in which case the comments bundle short-circuits and does
 * nothing.
 *
 * `draftId` is the most reliable signal that we're looking at a draft;
 * `elementId` falls back to the canonical entry. We deliberately do NOT
 * trust query-string params — Craft can render the draft form for an
 * unsaved fresh draft where the URL has no element id at all.
 */
export function readElementContext(): ElementContext | null {
  const draftInput = document.querySelector<HTMLInputElement>(
    'form input[name="draftId"]',
  );
  if (draftInput && draftInput.value && draftInput.value !== "0") {
    const id = Number.parseInt(draftInput.value, 10);
    if (Number.isFinite(id) && id > 0) {
      return { elementId: id, isDraft: true };
    }
  }

  const elementInput = document.querySelector<HTMLInputElement>(
    'form input[name="elementId"], form input[name="sourceId"]',
  );
  if (elementInput && elementInput.value) {
    const id = Number.parseInt(elementInput.value, 10);
    if (Number.isFinite(id) && id > 0) {
      return { elementId: id, isDraft: false };
    }
  }

  return null;
}

/**
 * Locate the DOM node for a given field handle on the current edit page.
 * Craft's field renderer uses a few different id/class patterns depending
 * on the Craft version and the field placement (top-level vs. inside a
 * tab vs. nested in a Matrix block), so we try several selectors in
 * priority order and return the first match.
 *
 * Returns null when the field is on a tab we haven't switched to yet —
 * the indicator just won't appear until that tab is opened. (Future
 * improvement: subscribe to tab-change events.)
 */
export function findFieldContainer(handle: string): HTMLElement | null {
  const selectors = [
    `#fields-${cssEscape(handle)}-field`,
    `#fields-${cssEscape(handle)}`,
    `[data-handle="${cssAttrEscape(handle)}"]`,
    `[data-field-handle="${cssAttrEscape(handle)}"]`,
  ];
  for (const sel of selectors) {
    const el = document.querySelector<HTMLElement>(sel);
    if (el) return el;
  }
  return null;
}

/**
 * The page-wide "open comments" floating panel target. Created lazily on
 * first use so we don't pollute the DOM on pages without comments.
 */
export function ensureOverlayHost(): HTMLElement {
  let host = document.getElementById("craftai-comments-host");
  if (host) return host;
  host = document.createElement("div");
  host.id = "craftai-comments-host";
  host.className = "craftai-comments-host";
  document.body.appendChild(host);
  return host;
}

/**
 * Build the small dot indicator that sits on a field's heading. Click
 * handlers are wired by the caller so a single delegated listener can
 * own them.
 */
export function buildIndicator(comments: Comment[]): HTMLButtonElement {
  const btn = document.createElement("button");
  btn.type = "button";
  btn.className = "craftai-comments-indicator";
  btn.setAttribute("aria-label", `${comments.length} review comment(s)`);
  btn.title = comments.length === 1
    ? "1 review comment"
    : `${comments.length} review comments`;
  btn.dataset.commentIds = comments.map((c) => c.id).join(",");

  const dot = document.createElement("span");
  dot.className = "craftai-comments-indicator__dot";
  dot.textContent = String(comments.length);
  btn.appendChild(dot);

  return btn;
}

/**
 * Build the top-level (whole-entry) banner that shows comments without a
 * fieldHandle. Mounted at the top of the form so the editor sees it
 * before scrolling.
 */
export function buildTopLevelBanner(comments: Comment[]): HTMLElement {
  const banner = document.createElement("div");
  banner.className = "craftai-comments-banner";

  const summary = document.createElement("strong");
  summary.textContent = comments.length === 1
    ? "AI review comment on this entry"
    : `${comments.length} AI review comments on this entry`;
  banner.appendChild(summary);

  return banner;
}

/**
 * Render the comment-thread popover content. The popover itself is the
 * shared overlay host; this builds the body for one specific group of
 * comments (all comments scoped to the same field, plus any sibling
 * top-level comments the caller decided to include).
 */
export function buildPopover(
  comments: Comment[],
  callbacks: {
    onResolve: (commentId: number) => void;
    onReply: (commentId: number, message: string) => void;
    onClose: () => void;
  },
): HTMLElement {
  const wrap = document.createElement("div");
  wrap.className = "craftai-comments-popover";

  const header = document.createElement("div");
  header.className = "craftai-comments-popover__header";
  const title = document.createElement("span");
  title.className = "craftai-comments-popover__title";
  title.textContent = comments.length === 1
    ? "AI review comment"
    : `${comments.length} AI review comments`;
  header.appendChild(title);

  const close = document.createElement("button");
  close.type = "button";
  close.className = "craftai-comments-popover__close";
  close.setAttribute("aria-label", "Close");
  close.textContent = "×";
  close.addEventListener("click", callbacks.onClose);
  header.appendChild(close);

  wrap.appendChild(header);

  const list = document.createElement("ul");
  list.className = "craftai-comments-popover__list";
  for (const comment of comments) {
    list.appendChild(renderCommentItem(comment, callbacks));
  }
  wrap.appendChild(list);

  return wrap;
}

function renderCommentItem(
  comment: Comment,
  callbacks: {
    onResolve: (commentId: number) => void;
    onReply: (commentId: number, message: string) => void;
  },
): HTMLLIElement {
  const item = document.createElement("li");
  item.className = "craftai-comments-popover__item";
  item.dataset.commentId = String(comment.id);

  const body = document.createElement("p");
  body.className = "craftai-comments-popover__body";
  body.textContent = comment.body;
  item.appendChild(body);

  const meta = document.createElement("p");
  meta.className = "craftai-comments-popover__meta";
  const metaParts: string[] = [`comment #${comment.id}`];
  if (comment.fieldHandle) metaParts.push(`field: ${comment.fieldHandle}`);
  meta.textContent = metaParts.join(" · ");
  if (comment.sessionUrl) {
    const link = document.createElement("a");
    link.href = comment.sessionUrl;
    link.textContent = "Open chat";
    link.className = "craftai-comments-popover__link";
    meta.appendChild(document.createTextNode(" · "));
    meta.appendChild(link);
  }
  item.appendChild(meta);

  const replyForm = document.createElement("form");
  replyForm.className = "craftai-comments-popover__form";
  const textarea = document.createElement("textarea");
  textarea.placeholder = "Reply…";
  textarea.rows = 2;
  textarea.className = "craftai-comments-popover__textarea";
  replyForm.appendChild(textarea);

  const actions = document.createElement("div");
  actions.className = "craftai-comments-popover__actions";

  const replyBtn = document.createElement("button");
  replyBtn.type = "submit";
  replyBtn.className = "btn submit";
  replyBtn.textContent = "Reply";
  actions.appendChild(replyBtn);

  const resolveBtn = document.createElement("button");
  resolveBtn.type = "button";
  resolveBtn.className = "btn";
  resolveBtn.textContent = "Resolve";
  resolveBtn.addEventListener("click", () => callbacks.onResolve(comment.id));
  actions.appendChild(resolveBtn);

  replyForm.appendChild(actions);
  replyForm.addEventListener("submit", (e) => {
    e.preventDefault();
    const text = textarea.value.trim();
    if (text === "") return;
    callbacks.onReply(comment.id, text);
    textarea.value = "";
  });
  item.appendChild(replyForm);

  return item;
}

/**
 * Robust CSS.escape polyfill for the field-handle selectors. Craft field
 * handles are alphanumeric in practice but we shouldn't assume — a
 * legacy install could have hyphens or other characters that need escaping.
 */
function cssEscape(value: string): string {
  if (typeof CSS !== "undefined" && typeof CSS.escape === "function") {
    return CSS.escape(value);
  }
  return value.replace(/[^a-zA-Z0-9_-]/g, "\\$&");
}

function cssAttrEscape(value: string): string {
  return value.replace(/["\\]/g, "\\$&");
}
