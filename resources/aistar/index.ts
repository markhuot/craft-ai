import { AiStarApi } from "./api";
import {
  buildStarButton,
  findAllFields,
  readBlockElementId,
  readBlockTypeHandle,
  readElementContext,
  setBusy,
} from "./dom";
import type { AiStarBootstrap, DiscoveredField, ElementContext } from "./types";

function readBootstrap(): AiStarBootstrap | null {
  const tag = document.querySelector<HTMLScriptElement>(
    "script[data-craftai-aistar-bootstrap]",
  );
  if (!tag?.textContent) return null;
  try {
    const parsed = JSON.parse(tag.textContent) as Record<string, unknown>;
    return {
      fillFieldUrl: String(parsed.fillFieldUrl ?? ""),
      csrfTokenName: String(parsed.csrfTokenName ?? "CRAFT_CSRF_TOKEN"),
      csrfTokenValue: String(parsed.csrfTokenValue ?? ""),
    };
  } catch {
    return null;
  }
}

const MOUNTED_ATTR = "data-craft-ai-star-mounted";

/**
 * Orchestrator: scans the page for custom fields, mounts a star next to
 * each one, and re-scans when the DOM mutates (matrix block expansion,
 * tab switches, etc.). Click handler does the POST → open-session
 * round-trip so the widget surfaces the new chat without a navigation.
 */
class AiStarOverlay {
  private observer: MutationObserver | null = null;
  private rescanQueued = false;

  constructor(
    private readonly api: AiStarApi,
    private readonly ctx: ElementContext,
  ) {}

  start(): void {
    this.scan();

    // The CP edit screen is highly dynamic — matrix blocks expand /
    // collapse, tabs switch, fields get re-rendered after preview. A
    // single MutationObserver on the form root with a debounced rescan
    // is the lowest-overhead way to keep the stars in sync. We avoid
    // re-mounting on stars we've already placed via MOUNTED_ATTR so a
    // mutation that doesn't add new fields is essentially free.
    const formRoot =
      document.querySelector<HTMLElement>(
        "#main-form, form#fieldlayout-form, form",
      ) ?? document.body;

    this.observer = new MutationObserver(() => this.queueScan());
    this.observer.observe(formRoot, {
      childList: true,
      subtree: true,
    });
  }

  private queueScan(): void {
    if (this.rescanQueued) return;
    this.rescanQueued = true;
    // requestAnimationFrame collapses bursty mutations (a matrix block
    // expand can fire dozens of childList events) into a single rescan.
    requestAnimationFrame(() => {
      this.rescanQueued = false;
      this.scan();
    });
  }

  private scan(): void {
    const fields = findAllFields();
    for (const field of fields) {
      if (field.container.getAttribute(MOUNTED_ATTR) === "1") continue;
      this.mountStar(field);
      field.container.setAttribute(MOUNTED_ATTR, "1");
    }
  }

  private mountStar(field: DiscoveredField): void {
    const heading =
      field.container.querySelector<HTMLElement>(
        ".heading, .field-heading",
      ) ?? field.container;

    const btn = buildStarButton(field.label);
    btn.addEventListener("click", (e) => {
      e.stopPropagation();
      e.preventDefault();
      void this.handleClick(field, btn);
    });
    heading.appendChild(btn);
  }

  private async handleClick(
    field: DiscoveredField,
    btn: HTMLButtonElement,
  ): Promise<void> {
    setBusy(btn, true);
    try {
      const block = field.blockContainer;
      const blockElementId = block ? readBlockElementId(block) : null;
      const blockTypeHandle = block ? readBlockTypeHandle(block) : null;

      const res = await this.api.fillField({
        elementId: this.ctx.elementId,
        isDraft: this.ctx.isDraft,
        fieldHandle: field.handle,
        fieldLabel: field.label ?? undefined,
        blockElementId: blockElementId ?? undefined,
        blockTypeHandle: blockTypeHandle ?? undefined,
        siteId: this.ctx.siteId ?? undefined,
      });

      // Hand off to the existing chat widget — same hook the comments
      // popover uses to surface a comment's discussion thread. Dispatched
      // on `document` so the widget's shadow-root listener (registered
      // against the host page's document) sees it.
      document.dispatchEvent(
        new CustomEvent("craftai:open-session", {
          detail: { sessionId: res.sessionId },
        }),
      );
    } catch (err) {
      console.warn("[craft-ai] AI fill request failed", err);
      // Surface a minimal alert so editors aren't stuck wondering why
      // nothing happened. The console line above keeps the technical
      // detail for support.
      const message =
        err instanceof Error && err.message ? err.message : "AI fill failed";
      window.alert(`AI fill failed: ${message}`);
    } finally {
      setBusy(btn, false);
    }
  }
}

function mount(): void {
  const bootstrap = readBootstrap();
  if (!bootstrap) return;

  const ctx = readElementContext();
  if (!ctx) return;

  const api = new AiStarApi(bootstrap);
  const overlay = new AiStarOverlay(api, ctx);
  overlay.start();
}

if (typeof document !== "undefined") {
  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", () => mount(), { once: true });
  } else {
    mount();
  }
}
