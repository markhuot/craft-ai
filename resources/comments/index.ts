import { CommentsApi } from "./api";
import {
  buildIndicator,
  buildPopover,
  buildTopLevelBanner,
  ensureOverlayHost,
  findBlockContainer,
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
    document.addEventListener("keydown", (e) => {
      if (e.key === "Escape") this.closePopover();
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

  private openFor(comments: Comment[], anchor: HTMLElement): void {
    this.closePopover();

    const popover = buildPopover(comments, {
      onClose: () => this.closePopover(),
      onResolve: async (commentId) => {
        await this.api.resolve(commentId);
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
    const top = window.scrollY + rect.bottom + 8;
    const left = window.scrollX + rect.left;
    popover.style.top = `${top}px`;
    popover.style.left = `${left}px`;
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
