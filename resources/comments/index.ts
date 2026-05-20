import { CommentsApi } from "./api";
import {
  buildIndicator,
  buildPopover,
  buildTopLevelBanner,
  ensureOverlayHost,
  findBlockContainer,
  findCommentSpans,
  findFieldContainer,
  readElementContext,
} from "./dom";
import type { Comment, CommentsBootstrap } from "./types";

function readBootstrap(): CommentsBootstrap | null {
  const tag = document.querySelector<HTMLScriptElement>(
    "script[data-craftai-comments-bootstrap]",
  );
  if (!tag?.textContent) return null;
  try {
    const parsed = JSON.parse(tag.textContent) as Record<string, unknown>;
    return {
      listUrl: String(parsed.listUrl ?? ""),
      resolveUrl: String(parsed.resolveUrl ?? ""),
      openThreadUrl: String(parsed.openThreadUrl ?? ""),
      csrfTokenName: String(parsed.csrfTokenName ?? "CRAFT_CSRF_TOKEN"),
      csrfTokenValue: String(parsed.csrfTokenValue ?? ""),
    };
  } catch {
    return null;
  }
}

/**
 * Orchestrator: fetches comments for the current element, renders the
 * indicator dots and the top-level banner, wires interaction. Re-runs
 * on demand (after a resolve/reply) to refresh the UI.
 */
class CommentsOverlay {
  private comments: Comment[] = [];
  private openPopover: { anchor: HTMLElement; popover: HTMLElement } | null = null;
  private mountedIndicators: HTMLElement[] = [];
  private mountedBanner: HTMLElement | null = null;

  constructor(
    private readonly api: CommentsApi,
    private readonly elementId: number,
    private readonly isDraft: boolean,
  ) {}

  async start(): Promise<void> {
    await this.refresh();
    document.addEventListener("click", (e) => this.handleOutsideClick(e));
    // Span clicks are wired on `mousedown` (capture phase) instead of
    // `click` because CKEditor 5's editing view installs its own
    // `mousedown` handler to manage caret placement / selection. By
    // running first in capture we get to open the popover before
    // CKEditor decides where the caret goes; without this the popover
    // sometimes never appears because CKEditor preventDefaults the
    // descendant click event in certain selection scenarios.
    document.addEventListener(
      "mousedown",
      (e) => this.handleSpanClick(e),
      true,
    );
    document.addEventListener("keydown", (e) => {
      if (e.key === "Escape") this.closePopover();
    });

    // The CKEditor "Comment" toolbar plugin dispatches this after a
    // successful comments/create call so the indicator dots show up
    // without waiting on a page reload. We also kick a delayed refresh
    // on the same event in case the CKEditor data round-trip hasn't
    // finished rendering the marker into the editing view yet.
    document.addEventListener("craftai:comments-changed", () => {
      void this.refresh();
    });

    // Authoritative click handler for marker spans inside a CKEditor
    // editing view. The CKEditor plugin dispatches this event from
    // *inside* the editor's own event system (view.document) — which
    // is the only reliable path for clicks that happen inside the
    // contenteditable surface, because CKEditor's MouseObserver and
    // SelectionObserver can swallow DOM-level listeners. The document-
    // level mousedown handler stays as the fallback for marker spans
    // rendered outside an editor (e.g. read-only previews).
    document.addEventListener("craftai:open-span-comment", (e) => {
      const detail = (e as CustomEvent<{
        referenceId?: unknown;
        rect?: { top: number; left: number; right: number; bottom: number; width: number; height: number } | null;
      }>).detail;
      if (!detail || typeof detail.referenceId !== "string") return;
      const matches = this.comments.filter(
        (c) => c.referenceId === detail.referenceId,
      );
      if (matches.length === 0) return;

      // Prefer the real DOM span as the popover anchor over a rect-
      // based synthetic. `handleOutsideClick` decides whether to close
      // by asking `anchor.contains(clickTarget)` — the DOM `click`
      // event that bubbles up a few ms after the CKEditor `click` would
      // see a *synthetic* anchor that doesn't contain the highlighted
      // span the user actually clicked, and close the popover ~250ms
      // after it opened. Falling back to `openForRect` keeps the
      // popover usable if the marker isn't actually in the DOM (e.g.
      // CKEditor mangled the attribute on render).
      const realSpan = findCommentSpans(detail.referenceId)[0] ?? null;
      if (realSpan) {
        this.openFor(matches, realSpan);
      } else {
        this.openForRect(matches, detail.rect ?? null);
      }
    });
  }

  async refresh(): Promise<void> {
    try {
      this.comments = await this.api.list({
        elementId: this.elementId,
        isDraft: this.isDraft,
      });
    } catch (err) {
      console.warn("[craft-ai] failed to load comments", err);
      return;
    }
    this.render();
  }

  private render(): void {
    this.clearMounted();

    if (this.comments.length === 0) return;

    // Bucket by (elementId, fieldHandle). A comment with no fieldHandle
    // whose elementId matches the page is a whole-entry note → banner.
    // A comment whose elementId differs is targeting a nested Matrix
    // block — find the block container by data-id and mount the dot on
    // the inner field there. Field-scoped page-level comments are the
    // existing simple case (dot on the field heading).
    const topLevel: Comment[] = [];
    const byKey = new Map<string, Comment[]>();

    for (const c of this.comments) {
      const onPageElement = c.elementId === this.elementId;
      if (onPageElement && !c.fieldHandle) {
        topLevel.push(c);
        continue;
      }
      // Field comments — including nested-block comments. The bucket key
      // is element+field so two comments on the same block+field merge
      // into one indicator, but different blocks stay separate even when
      // they share an inner-field handle (e.g. `blogHeadingText` in
      // every blogHeading block).
      const key = `${c.elementId}:${c.fieldHandle ?? ""}`;
      const arr = byKey.get(key) ?? [];
      arr.push(c);
      byKey.set(key, arr);
    }

    if (topLevel.length > 0) this.renderBanner(topLevel);

    for (const comments of byKey.values()) {
      this.renderFieldIndicator(comments);
    }

    // Decorate any CKEditor highlight spans currently in the DOM with
    // the matching comment count + title so they're discoverable even
    // before the user hovers (the popover opens via delegated click in
    // handleSpanClick). Done after the field-heading indicator pass so
    // a span that's missing from the DOM gracefully falls back to the
    // heading dot — both surfaces are wired and the user always has at
    // least one entry point to the conversation.
    for (const c of this.comments) {
      if (!c.referenceId) continue;
      for (const span of findCommentSpans(c.referenceId)) {
        const preview = c.body.length > 140 ? `${c.body.slice(0, 140)}…` : c.body;
        // Append a hint so editors don't think the hover tooltip is
        // the entire interaction — the popover lives behind a click.
        span.title = `${preview}\n\n(Click to view, reply, or resolve)`;
        span.dataset.craftAiCommentMarkActive = "1";
      }
    }
  }

  /**
   * Delegated click for CKEditor comment highlights. The marker spans
   * live inside contenteditable surfaces (the CKEditor editing view)
   * so we can't attach individual handlers without fighting the
   * editor's own DOM lifecycle — a delegated `document` click filters
   * to `[data-craft-ai-comment-id]` and opens the popover anchored to
   * the clicked span.
   *
   * We bail when the click landed inside an open popover; that's how
   * a user clicking the popover body itself stays inside the same
   * interaction without re-triggering this handler.
   */
  private handleSpanClick(e: MouseEvent): void {
    const target = e.target;
    if (!(target instanceof Element)) return;

    const span = target.closest<HTMLElement>("[data-craft-ai-comment-id]");
    if (!span) return;

    const refId = span.getAttribute("data-craft-ai-comment-id");
    if (!refId) return;

    const matches = this.comments.filter((c) => c.referenceId === refId);
    if (matches.length === 0) return;

    e.stopPropagation();
    e.preventDefault();
    this.openFor(matches, span);
  }

  private renderBanner(comments: Comment[]): void {
    const form = document.querySelector<HTMLElement>("#main-form, form#fieldlayout-form, form");
    if (!form) return;

    const banner = buildTopLevelBanner(comments);
    banner.addEventListener("click", () => this.openFor(comments, banner));
    form.prepend(banner);
    this.mountedBanner = banner;
  }

  private renderFieldIndicator(comments: Comment[]): void {
    // Every comment in this bucket shares (elementId, fieldHandle), so
    // we can use the first as the lookup key.
    const first = comments[0];
    if (!first) return;

    // For nested-block comments the container is *inside* the matrix
    // block — scope the field lookup to that block so we don't
    // accidentally land on the outer Matrix field heading.
    const scope = first.elementId === this.elementId
      ? null
      : findBlockContainer(first.elementId, first.elementUid);

    // Top-level field comments (elementId === page element) just need
    // the field handle. Nested-block field comments need both the
    // scope and the inner handle. A nested-block comment without a
    // fieldHandle means "feedback on the whole block" → indicator on
    // the block's titlebar.
    const container = first.fieldHandle
      ? findFieldContainer(first.fieldHandle, scope)
      : scope;
    if (!container) return;

    const heading = container.querySelector<HTMLElement>(".heading, .field-heading, label, .titlebar") ?? container;
    const indicator = buildIndicator(comments);
    indicator.addEventListener("click", (e) => {
      e.stopPropagation();
      e.preventDefault();
      this.openFor(comments, indicator);
    });
    heading.appendChild(indicator);
    this.mountedIndicators.push(indicator);
  }

  /**
   * Variant of `openFor` that anchors against a raw rect instead of a
   * DOM element. Used by `craftai:open-span-comment` (dispatched from
   * the CKEditor plugin) because the marker's DOM node lives inside
   * a contenteditable surface — we can still derive its rect, but
   * binding the popover's "click outside to close" logic to that DOM
   * node is brittle (CKEditor remounts the editing view on data
   * changes). Treating the rect as the anchor source means the
   * popover stays correctly positioned even if the editor view
   * re-renders.
   */
  private openForRect(
    comments: Comment[],
    rect: { top: number; left: number; right: number; bottom: number; width: number; height: number } | null,
  ): void {
    // Synthesize a hidden anchor element at the rect's position so
    // outside-click detection can still ask `anchor.contains(target)`
    // against a real DOM node. The anchor is removed alongside the
    // popover in `closePopover`.
    const anchor = document.createElement("span");
    anchor.style.position = "absolute";
    anchor.style.pointerEvents = "none";
    anchor.style.opacity = "0";
    if (rect) {
      anchor.style.top = `${window.scrollY + rect.top}px`;
      anchor.style.left = `${window.scrollX + rect.left}px`;
      anchor.style.width = `${rect.width}px`;
      anchor.style.height = `${rect.height}px`;
    }
    ensureOverlayHost().appendChild(anchor);
    this.openFor(comments, anchor);
  }

  private openFor(comments: Comment[], anchor: HTMLElement): void {
    this.closePopover();

    const popover = buildPopover(comments, {
      onClose: () => this.closePopover(),
      onResolve: async (commentId) => {
        // Capture the referenceId *before* refresh wipes the comment
        // from local state — we need to tell every live CKEditor on
        // the page to drop its highlight span so the user sees the
        // resolve land immediately, not just after a reload.
        const resolved = this.comments.find((c) => c.id === commentId);
        await this.api.resolve(commentId);
        if (resolved?.referenceId) {
          document.dispatchEvent(
            new CustomEvent("craftai:comment-resolved", {
              detail: {
                commentId: resolved.id,
                referenceId: resolved.referenceId,
              },
            }),
          );
        }
        await this.refresh();
      },
      onOpenInChat: async (comment) => {
        try {
          const { threadSessionId } = await this.api.openThread(comment.id);
          // Hand off to the existing chat widget so we don't ship a
          // second chat surface — the widget already handles sessions,
          // messages, attachments, and the agent loop.
          document.dispatchEvent(
            new CustomEvent("craftai:open-session", {
              detail: { sessionId: threadSessionId },
            }),
          );
          this.closePopover();
        } catch (err) {
          console.warn("[craft-ai] failed to open comment thread", err);
        }
      },
    });

    ensureOverlayHost().appendChild(popover);
    this.positionPopover(popover, anchor);
    this.openPopover = { anchor, popover };
  }

  private positionPopover(popover: HTMLElement, anchor: HTMLElement): void {
    const rect = anchor.getBoundingClientRect();
    // Measure the popover after it's been mounted so the clamp math
    // works against its real width/height (CSS gives it `width: 320px`
    // and an auto height, but the rendered height depends on body
    // length and reply count). Clamp within the viewport with an 8px
    // gutter on every side so the popover never lands offscreen on a
    // marker near the bottom-right corner of a long CKEditor field.
    const popoverRect = popover.getBoundingClientRect();
    const gutter = 8;
    const viewportW = window.innerWidth;
    const viewportH = window.innerHeight;

    let top = rect.bottom + gutter;
    let left = rect.left;

    if (left + popoverRect.width + gutter > viewportW) {
      left = Math.max(gutter, viewportW - popoverRect.width - gutter);
    }
    if (left < gutter) left = gutter;

    if (top + popoverRect.height + gutter > viewportH) {
      // Try flipping above the anchor; if that's worse, just clamp.
      const above = rect.top - popoverRect.height - gutter;
      top = above >= gutter
        ? above
        : Math.max(gutter, viewportH - popoverRect.height - gutter);
    }

    popover.style.top = `${window.scrollY + top}px`;
    popover.style.left = `${window.scrollX + left}px`;
  }

  private closePopover(): void {
    if (this.openPopover) {
      this.openPopover.popover.remove();
      this.openPopover = null;
    }
  }

  private handleOutsideClick(e: MouseEvent): void {
    if (!this.openPopover) return;
    const target = e.target as Node;
    if (this.openPopover.popover.contains(target)) return;
    if (this.openPopover.anchor.contains(target)) return;
    this.closePopover();
  }

  private clearMounted(): void {
    for (const el of this.mountedIndicators) el.remove();
    this.mountedIndicators = [];
    if (this.mountedBanner) {
      this.mountedBanner.remove();
      this.mountedBanner = null;
    }
    this.closePopover();
  }
}

async function mount(): Promise<void> {
  const bootstrap = readBootstrap();
  if (!bootstrap) return;

  const ctx = readElementContext();
  if (!ctx) return;

  const api = new CommentsApi(bootstrap);
  const overlay = new CommentsOverlay(api, ctx.elementId, ctx.isDraft);
  await overlay.start();
}

if (typeof document !== "undefined") {
  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", () => void mount(), { once: true });
  } else {
    void mount();
  }
}
