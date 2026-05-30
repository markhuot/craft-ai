import type { DiscoveredField, ElementContext } from "./types";

/**
 * Same element-context probe the comments overlay uses. We share the
 * shape rather than the implementation so each bundle stays
 * self-contained (no cross-bundle imports — they ship as independent
 * ESM modules served through Craft's asset publisher).
 */
export function readElementContext(): ElementContext | null {
  const siteId = readSiteId();

  const draftInput = document.querySelector<HTMLInputElement>(
    'form input[name="draftId"]',
  );
  if (draftInput && draftInput.value && draftInput.value !== "0") {
    const id = Number.parseInt(draftInput.value, 10);
    if (Number.isFinite(id) && id > 0) {
      return { elementId: id, isDraft: true, siteId };
    }
  }

  const elementInput = document.querySelector<HTMLInputElement>(
    'form input[name="elementId"], form input[name="sourceId"]',
  );
  if (elementInput && elementInput.value) {
    const id = Number.parseInt(elementInput.value, 10);
    if (Number.isFinite(id) && id > 0) {
      return { elementId: id, isDraft: false, siteId };
    }
  }

  return null;
}

/**
 * Read the current site id off the form. Craft's element editor renders
 * a hidden `<input name="siteId" value="…">` whenever the element has a
 * site, so this is the canonical source for "which locale is the editor
 * looking at right now." Returns null when the input is absent or
 * non-numeric — the controller falls back to the primary site in that
 * case.
 */
function readSiteId(): number | null {
  const input = document.querySelector<HTMLInputElement>(
    'form input[name="siteId"]',
  );
  if (!input || !input.value) return null;
  const id = Number.parseInt(input.value, 10);
  return Number.isFinite(id) && id > 0 ? id : null;
}

/**
 * Find every custom field container currently in the DOM. Craft 5
 * stamps `data-attribute="{handle}"` on the `.field` wrapper and uses
 * `id="…fields-{handle}-field"` as a secondary handle source — we read
 * either, preferring `data-attribute` because it's the canonical one.
 *
 * Scope: only the main `#content-container` is scanned. The right-hand
 * `#details-container` holds the meta sidebar — Author, Post Date,
 * status, etc. Those aren't free-text custom fields the AI can fill, so
 * we deliberately leave them out. When `#content-container` is absent
 * (non-standard screens, tests) we fall back to the whole document but
 * still skip anything living inside `#details-container`.
 *
 * Fields inside collapsed matrix blocks / inactive tabs aren't returned
 * here; the caller re-scans via MutationObserver whenever the DOM
 * changes so a field that appears later picks up its star then.
 */
export function findAllFields(root?: ParentNode): DiscoveredField[] {
  const scanRoot = root ?? resolveScanRoot();
  const out: DiscoveredField[] = [];
  const seen = new Set<HTMLElement>();

  const candidates = scanRoot.querySelectorAll<HTMLElement>(
    "div.field, .field[data-attribute]",
  );

  for (const container of Array.from(candidates)) {
    if (seen.has(container)) continue;
    seen.add(container);

    // Belt-and-suspenders: even when we fall back to scanning the whole
    // document, never decorate the meta sidebar's fields.
    if (container.closest("#details-container")) continue;

    const handle = readFieldHandle(container);
    if (!handle) continue;

    // Craft re-uses `.field` markup for synthetic UI elements (the
    // entry's "Title" wrapper, status switches, etc.) — we only want
    // the ones backed by a custom field. The simplest heuristic is the
    // id pattern: real custom fields always end in `-fields-<handle>-field`,
    // built-ins use `-{handle}-field` without the `fields-` segment.
    if (!isCustomFieldContainer(container)) continue;

    out.push({
      container,
      handle,
      label: readFieldLabel(container),
      blockContainer: findEnclosingBlock(container),
    });
  }

  return out;
}

/**
 * The root we hand to `querySelectorAll`. Prefer Craft's main content
 * column so the meta sidebar (`#details-container`) is excluded by
 * construction; fall back to the whole document when that container is
 * missing (custom screens, tests).
 */
function resolveScanRoot(): ParentNode {
  if (typeof document === "undefined") return document;
  return document.querySelector<HTMLElement>("#content-container") ?? document;
}

/**
 * Pull the field's handle off the container. Prefers `data-attribute`
 * (Craft's canonical source) and falls back to the id suffix the
 * namespaced field renderer stamps onto every wrapper.
 */
function readFieldHandle(container: HTMLElement): string | null {
  const dataHandle =
    container.getAttribute("data-attribute") ??
    container.getAttribute("data-handle") ??
    container.getAttribute("data-field-handle");
  if (dataHandle) return dataHandle;

  const id = container.id;
  if (!id) return null;
  const match = id.match(/(?:^|-)fields-([A-Za-z0-9_]+)-field$/);
  return match?.[1] ?? null;
}

/**
 * True only for custom-field wrappers, false for the built-in title /
 * slug / status / etc. Built-ins share the `.field` class but lack the
 * `fields-` segment in their id and don't carry `data-attribute`.
 */
function isCustomFieldContainer(container: HTMLElement): boolean {
  if (container.hasAttribute("data-attribute")) return true;
  const id = container.id;
  if (!id) return false;
  return /(?:^|-)fields-[A-Za-z0-9_]+-field$/.test(id);
}

function readFieldLabel(container: HTMLElement): string | null {
  const heading = container.querySelector<HTMLElement>(
    ".heading > label, .field-heading > label, label.heading, .heading .field-label",
  );
  if (heading) {
    const text = heading.textContent?.replace(/\s+/g, " ").trim();
    if (text) return text;
  }
  // Some Craft 4 builds and a handful of plugins put the label text
  // directly in the `.heading` div without a child <label>. Treat the
  // heading's own text as a last resort so we still ship something
  // meaningful in the system note.
  const headingDiv = container.querySelector<HTMLElement>(".heading, .field-heading");
  if (headingDiv) {
    const text = headingDiv.textContent?.replace(/\s+/g, " ").trim();
    if (text) return text;
  }
  return null;
}

function findEnclosingBlock(container: HTMLElement): HTMLElement | null {
  return container.closest<HTMLElement>(".matrixblock");
}

/**
 * Read the block's nested-entry id off `data-id` (Craft stamps the
 * entry id there) so the backend can scope the fill request to the
 * right inner entry.
 */
export function readBlockElementId(block: HTMLElement): number | null {
  const raw = block.getAttribute("data-id");
  if (!raw) return null;
  const id = Number.parseInt(raw, 10);
  return Number.isFinite(id) && id > 0 ? id : null;
}

export function readBlockTypeHandle(block: HTMLElement): string | null {
  return (
    block.getAttribute("data-type-handle") ??
    block.getAttribute("data-type") ??
    null
  );
}

/**
 * Build the star button. Click handlers are wired by the caller so a
 * single delegated listener can own them.
 */
export function buildStarButton(label: string | null): HTMLButtonElement {
  const btn = document.createElement("button");
  btn.type = "button";
  btn.className = "craftai-aistar-button";
  btn.setAttribute(
    "aria-label",
    label ? `AI fill ${label}` : "AI fill this field",
  );
  btn.title = label ? `AI fill "${label}"` : "AI fill this field";
  btn.dataset.craftAiStar = "1";

  // Inline SVG keeps the bundle dependency-free. The four-point sparkle
  // matches the CP's general "AI" iconography (the existing chat widget
  // uses MessageCircle, so we picked a complementary mark for fields).
  btn.innerHTML = STAR_SVG;

  return btn;
}

const STAR_SVG = `
<svg viewBox="0 0 16 16" aria-hidden="true" focusable="false" xmlns="http://www.w3.org/2000/svg">
  <path d="M8 0.5 L9.4 5.6 L14.5 7 L9.4 8.4 L8 13.5 L6.6 8.4 L1.5 7 L6.6 5.6 Z" />
  <path d="M13 0.5 L13.6 2.4 L15.5 3 L13.6 3.6 L13 5.5 L12.4 3.6 L10.5 3 L12.4 2.4 Z" opacity="0.7" />
</svg>
`.trim();

/**
 * Spinner replacement for the star while a request is in flight.
 */
export function setBusy(btn: HTMLButtonElement, busy: boolean): void {
  if (busy) {
    btn.classList.add("craftai-aistar-button--busy");
    btn.setAttribute("aria-busy", "true");
    btn.disabled = true;
  } else {
    btn.classList.remove("craftai-aistar-button--busy");
    btn.removeAttribute("aria-busy");
    btn.disabled = false;
  }
}
