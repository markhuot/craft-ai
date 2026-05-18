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
 * Locate the DOM node for a given field handle, optionally scoped to a
 * specific container (e.g. a Matrix block). Craft's field renderer uses a
 * few different id/class patterns depending on the Craft version and the
 * field placement (top-level vs. inside a tab vs. nested in a Matrix
 * block), so we try several selectors in priority order and return the
 * first match.
 *
 * When `scope` is null, looks across the whole page (top-level fields).
 * When scope is a block container, restricts the search to that block —
 * needed so a comment on a block's inner `blogHeadingText` lands on the
 * heading inside that specific block, not on a same-named field
 * elsewhere on the page. Inside a block the namespaced field id ends in
 * `…-fields-{handle}-field`, so the id-suffix lookup handles that
 * naturally without us having to reconstruct the full namespaced id.
 *
 * Returns null when the field is on a tab we haven't switched to yet, or
 * when the block has been collapsed — the indicator just won't appear
 * until the field becomes visible.
 */
export function findFieldContainer(
  handle: string,
  scope: HTMLElement | null = null,
): HTMLElement | null {
  const root: ParentNode = scope ?? document;
  const escaped = cssEscape(handle);
  const attr = cssAttrEscape(handle);

  const selectors = scope
    ? [
        `[id$="-fields-${escaped}-field"]`,
        `[id$="-fields-${escaped}"]`,
        `[data-handle="${attr}"]`,
        `[data-field-handle="${attr}"]`,
      ]
    : [
        `#fields-${escaped}-field`,
        `#fields-${escaped}`,
        `[data-handle="${attr}"]`,
        `[data-field-handle="${attr}"]`,
      ];

  for (const sel of selectors) {
    const el = root.querySelector<HTMLElement>(sel);
    if (el) return el;
  }
  return null;
}

/**
 * Locate the `.matrixblock` element for a nested entry. Craft stamps the
 * block's numeric entry id on `data-id` and its UID on `data-uid` (see
 * Craft's `_components/fieldtypes/Matrix/block.twig`), so we try id
 * first and fall back to uid. Returns null when the block hasn't been
 * rendered on this page (collapsed, on another tab, or part of a
 * Matrix-on-Matrix tree the user hasn't expanded yet).
 */
export function findBlockContainer(
  elementId: number,
  elementUid: string | null,
): HTMLElement | null {
  const byId = document.querySelector<HTMLElement>(
    `.matrixblock[data-id="${cssAttrEscape(String(elementId))}"]`,
  );
  if (byId) return byId;

  if (elementUid) {
    const byUid = document.querySelector<HTMLElement>(
      `.matrixblock[data-uid="${cssAttrEscape(elementUid)}"]`,
    );
    if (byUid) return byUid;
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
 * Render the comment-thread popover content. The popover lists each
 * comment scoped to the trigger (a field or the top-level banner) as a
 * clickable row — the row itself is the "open in chat" affordance, with
 * a resolve button tucked to the side. There is intentionally no inline
 * reply textarea here: replies live inside the chat widget so we don't
 * fork a second chat surface.
 */
export function buildPopover(
  comments: Comment[],
  callbacks: {
    onResolve: (commentId: number) => void;
    onOpenInChat: (comment: Comment) => void;
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
    onOpenInChat: (comment: Comment) => void;
  },
): HTMLLIElement {
  const item = document.createElement("li");
  item.className = "craftai-comments-popover__item";
  item.dataset.commentId = String(comment.id);

  // The whole row is the "open the chat thread" target. We use a real
  // <button> so keyboard focus + Enter activation come for free, and the
  // inner content stays semantic (the body markdown renders as normal
  // block elements rather than a click-target's flat text).
  const rowBtn = document.createElement("button");
  rowBtn.type = "button";
  rowBtn.className = "craftai-comments-popover__row";
  rowBtn.setAttribute(
    "aria-label",
    `Open chat thread for comment ${comment.id}`,
  );
  rowBtn.addEventListener("click", (e) => {
    e.stopPropagation();
    callbacks.onOpenInChat(comment);
  });

  // bodyHtml comes pre-rendered + sanitized from the server (see
  // CommentMarkdown::render) so we can drop it in via innerHTML to match
  // the rich rendering the main chat does. Fall back to textContent when
  // the server didn't ship a bodyHtml — older deploys, or a future API
  // shape that omits the field — so the popover always shows _something_.
  const body = document.createElement("div");
  body.className = "craftai-comments-popover__body";
  if (typeof comment.bodyHtml === "string" && comment.bodyHtml !== "") {
    body.innerHTML = comment.bodyHtml;
  } else {
    body.textContent = comment.body;
  }
  rowBtn.appendChild(body);

  const meta = document.createElement("p");
  meta.className = "craftai-comments-popover__meta";
  const metaParts: string[] = [`comment #${comment.id}`];
  if (comment.fieldHandle) metaParts.push(`field: ${comment.fieldHandle}`);
  if (comment.replyCount > 0) {
    metaParts.push(
      comment.replyCount === 1 ? "1 reply" : `${comment.replyCount} replies`,
    );
  } else if (comment.threadSessionId) {
    metaParts.push("thread open");
  }
  meta.textContent = metaParts.join(" · ");
  rowBtn.appendChild(meta);

  item.appendChild(rowBtn);

  const actions = document.createElement("div");
  actions.className = "craftai-comments-popover__actions";

  const openBtn = document.createElement("button");
  openBtn.type = "button";
  openBtn.className = "btn submit";
  openBtn.textContent = comment.replyCount > 0 || comment.threadSessionId
    ? "Continue chat"
    : "Reply in chat";
  openBtn.addEventListener("click", (e) => {
    e.stopPropagation();
    callbacks.onOpenInChat(comment);
  });
  actions.appendChild(openBtn);

  const resolveBtn = document.createElement("button");
  resolveBtn.type = "button";
  resolveBtn.className = "btn";
  resolveBtn.textContent = "Resolve";
  resolveBtn.addEventListener("click", (e) => {
    e.stopPropagation();
    callbacks.onResolve(comment.id);
  });
  actions.appendChild(resolveBtn);

  item.appendChild(actions);

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
