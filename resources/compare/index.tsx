import { StrictMode } from "react";
import { createRoot } from "react-dom/client";
import { App } from "./App";
import type { Bootstrap, RevisionOption } from "./types";

/**
 * Pull the JSON bootstrap out of the
 * `<script type="application/json" data-craftai-compare-bootstrap>` tag the
 * Twig view renders inside the root element. Mirrors the chat bundle's
 * `readBootstrap` — coerce every field so a malformed payload fails loudly
 * here rather than deep inside a component render.
 */
function readBootstrap(root: HTMLElement): Bootstrap {
  const dataEl = root.querySelector<HTMLScriptElement>(
    "script[data-craftai-compare-bootstrap]",
  );
  if (!dataEl?.textContent) {
    throw new Error("craft-ai: missing compare bootstrap data");
  }
  const parsed: unknown = JSON.parse(dataEl.textContent);
  if (typeof parsed !== "object" || parsed === null) {
    throw new Error("craft-ai: invalid compare bootstrap data");
  }
  const obj = parsed as Record<string, unknown>;

  const revisions = Array.isArray(obj.revisions)
    ? parseRevisions(obj.revisions)
    : [];

  return {
    entryId: Number(obj.entryId ?? 0),
    entryTitle: String(obj.entryTitle ?? ""),
    siteId: Number(obj.siteId ?? 0),
    a: String(obj.a ?? ""),
    b: String(obj.b ?? "current"),
    revisions,
    diffUrl: String(obj.diffUrl ?? ""),
    revisionsUrl: String(obj.revisionsUrl ?? ""),
    messagesUrl: String(obj.messagesUrl ?? ""),
    compareBaseUrl: String(obj.compareBaseUrl ?? ""),
    csrfTokenName: String(obj.csrfTokenName ?? "CRAFT_CSRF_TOKEN"),
    csrfTokenValue: String(obj.csrfTokenValue ?? ""),
  };
}

/** Coerce the bootstrap revision rows into well-typed options. */
function parseRevisions(value: unknown[]): RevisionOption[] {
  const out: RevisionOption[] = [];
  for (const entry of value) {
    if (typeof entry !== "object" || entry === null) continue;
    const obj = entry as Record<string, unknown>;
    if (typeof obj.ref !== "string" || obj.ref === "") continue;
    out.push({
      ref: obj.ref,
      label: typeof obj.label === "string" ? obj.label : obj.ref,
      revisionNum: typeof obj.revisionNum === "number" ? obj.revisionNum : null,
      savedBy: typeof obj.savedBy === "string" && obj.savedBy !== "" ? obj.savedBy : null,
      dateCreated: typeof obj.dateCreated === "string" ? obj.dateCreated : undefined,
      dateUpdated: typeof obj.dateUpdated === "string" ? obj.dateUpdated : undefined,
      isCurrent: obj.isCurrent === true,
    });
  }
  return out;
}

function mount() {
  const roots = document.querySelectorAll<HTMLElement>("[data-craftai-compare-root]");
  roots.forEach((el) => {
    if (el.dataset.craftaiMounted === "1") return;
    el.dataset.craftaiMounted = "1";
    const bootstrap = readBootstrap(el);
    createRoot(el).render(
      <StrictMode>
        <App bootstrap={bootstrap} />
      </StrictMode>,
    );
  });
}

if (document.readyState === "loading") {
  document.addEventListener("DOMContentLoaded", mount, { once: true });
} else {
  mount();
}
