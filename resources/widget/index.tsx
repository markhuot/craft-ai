import { StrictMode } from "react";
import { createRoot, type Root } from "react-dom/client";
import { Widget } from "./Widget";
import type { PageContext, WidgetBootstrap } from "./types";

const ELEMENT_NAME = "craft-ai-widget";

function readBootstrap(): WidgetBootstrap | null {
  const tag = document.querySelector<HTMLScriptElement>(
    "script[data-craftai-widget-bootstrap]",
  );
  if (!tag?.textContent) return null;
  try {
    const parsed: unknown = JSON.parse(tag.textContent);
    if (typeof parsed !== "object" || parsed === null) return null;
    const obj = parsed as Record<string, unknown>;
    return {
      jsUrl: String(obj.jsUrl ?? ""),
      cssUrl: String(obj.cssUrl ?? ""),
      sessionsUrl: String(obj.sessionsUrl ?? ""),
      newSessionUrl: String(obj.newSessionUrl ?? ""),
      sessionsIndexUrl: String(obj.sessionsIndexUrl ?? ""),
      messagesUrl: String(obj.messagesUrl ?? ""),
      sendUrl: String(obj.sendUrl ?? ""),
      previewRespondUrl: String(obj.previewRespondUrl ?? ""),
      csrfTokenName: String(obj.csrfTokenName ?? "CRAFT_CSRF_TOKEN"),
      csrfTokenValue: String(obj.csrfTokenValue ?? ""),
      context: normalizeContext(obj.context),
      contextFingerprint: String(obj.contextFingerprint ?? ""),
    };
  } catch {
    return null;
  }
}

function normalizeContext(value: unknown): PageContext {
  const empty: PageContext = {
    url: null,
    path: null,
    query: {},
    siteHandle: null,
    template: null,
    element: null,
  };
  if (typeof value !== "object" || value === null) return empty;
  const obj = value as Record<string, unknown>;
  const queryRaw = obj.query;
  const query: PageContext["query"] = {};
  if (typeof queryRaw === "object" && queryRaw !== null) {
    for (const [k, v] of Object.entries(queryRaw)) {
      if (v === null || typeof v === "string" || typeof v === "number" || typeof v === "boolean") {
        query[k] = v;
      }
    }
  }
  let element: PageContext["element"] = null;
  if (typeof obj.element === "object" && obj.element !== null) {
    const e = obj.element as Record<string, unknown>;
    if (typeof e.id === "number" && typeof e.type === "string") {
      element = {
        type: e.type,
        id: e.id,
        title: typeof e.title === "string" ? e.title : null,
        sectionHandle: typeof e.sectionHandle === "string" ? e.sectionHandle : null,
      };
    }
  }
  return {
    url: typeof obj.url === "string" ? obj.url : null,
    path: typeof obj.path === "string" ? obj.path : null,
    query,
    siteHandle: typeof obj.siteHandle === "string" ? obj.siteHandle : null,
    template: typeof obj.template === "string" ? obj.template : null,
    element,
  };
}

/**
 * Custom element that hosts the chat widget inside a Shadow DOM. Style and
 * markup never leak into the host page (or vice-versa), which is the whole
 * point of using a web component here — the widget runs on arbitrary
 * front-end templates and can't make assumptions about the surrounding CSS.
 */
class CraftAIWidgetElement extends HTMLElement {
  private root: Root | null = null;
  private shadow: ShadowRoot | null = null;

  bootstrap: WidgetBootstrap | null = null;

  connectedCallback(): void {
    if (this.shadow) return;
    if (!this.bootstrap) return;

    this.shadow = this.attachShadow({ mode: "open" });

    // Stylesheet is loaded inside the shadow root so the cascade stops at
    // the boundary. We don't gate React mounting on the link's `load`
    // event — Tailwind's reset is light enough that an unstyled flash of
    // the bubble for ~1 frame is acceptable, and waiting on a slow
    // connection would feel worse.
    const link = document.createElement("link");
    link.rel = "stylesheet";
    link.href = this.bootstrap.cssUrl;
    this.shadow.appendChild(link);

    const mountPoint = document.createElement("div");
    this.shadow.appendChild(mountPoint);

    const root = createRoot(mountPoint);
    this.root = root;
    root.render(
      <StrictMode>
        <Widget bootstrap={this.bootstrap} />
      </StrictMode>,
    );
  }

  disconnectedCallback(): void {
    // React 19 logs a warning if you sync-unmount during commit; defer.
    queueMicrotask(() => {
      this.root?.unmount();
      this.root = null;
    });
  }
}

function mount() {
  const bootstrap = readBootstrap();
  if (!bootstrap) return;

  if (!customElements.get(ELEMENT_NAME)) {
    customElements.define(ELEMENT_NAME, CraftAIWidgetElement);
  }

  // Avoid double-mounting if the script is somehow loaded twice (e.g.
  // Turbo-style nav that re-evaluates body scripts).
  if (document.querySelector(ELEMENT_NAME)) return;

  const el = document.createElement(ELEMENT_NAME) as CraftAIWidgetElement;
  el.bootstrap = bootstrap;
  document.body.appendChild(el);
}

if (typeof document !== "undefined") {
  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", mount, { once: true });
  } else {
    mount();
  }
}

export { CraftAIWidgetElement };
