/**
 * Resolve the (elementId, isDraft) identity of the entry currently being
 * edited in the Craft control panel.
 *
 * THE TRAP: on a *draft* edit screen Craft sets the form's hidden
 * `elementId` input to the **canonical** id and emits **no** `draftId`
 * input at all (see `ElementsController::_prepareEditor`, where the id is
 * `getCanonicalId()` for drafts/revisions). The real draftId lives only
 * in the JS settings object handed to `new Craft.ElementEditor(...)`,
 * which Craft stashes on the editor container via jQuery
 * `.data('elementEditor')` (full-page) or `.data('elementEditorSettings')`
 * (slideout, before the editor boots). A naive "read the draftId input,
 * else the elementId input" therefore reports the canonical identity even
 * while you're editing a draft — so comments authored against that draft
 * (stamped `(draftId, isDraft=1)`) never match, and newly-created comments
 * get mis-stamped onto the canonical.
 *
 * We resolve in priority order:
 *   1. The `<script data-craftai-element-context>` blob the plugin emits
 *      server-side into the editor content (see the InjectElementContext
 *      listener). This is the deterministic source — the server knows the
 *      real draft/canonical identity — so it wins outright.
 *   2. Craft.ElementEditor settings — authoritative client-side fallback
 *      for any editor surface that doesn't carry the server blob.
 *   3. The `draftId` URL param — Craft keeps it in sync for full-page
 *      drafts (including provisional drafts after their first autosave).
 *   4. The hidden `elementId`/`sourceId` input — the canonical id with
 *      isDraft=false. Correct only when we genuinely are on the canonical,
 *      which is why it runs last. Also the path tests exercise (no Craft
 *      runtime, no location search).
 */
export interface ElementContext {
  elementId: number;
  isDraft: boolean;
}

function toPositiveInt(value: unknown): number | null {
  if (value === null || value === undefined || value === "") return null;
  const n = typeof value === "number" ? value : Number.parseInt(String(value), 10);
  return Number.isFinite(n) && n > 0 ? n : null;
}

// eslint-disable-next-line @typescript-eslint/no-explicit-any
function getJQuery(): any {
  const w = window as unknown as {
    Craft?: { $?: unknown };
    jQuery?: unknown;
    $?: unknown;
  };
  return (w.Craft && (w.Craft as { $?: unknown }).$) || w.jQuery || w.$ || null;
}

/**
 * Pull the identity out of a Craft.ElementEditor settings object. Drafts
 * win on their draftId; everything else (canonical, revision, provisional
 * with a null draftId) collapses to the canonical id with isDraft=false.
 */
// eslint-disable-next-line @typescript-eslint/no-explicit-any
function contextFromSettings(settings: any): ElementContext | null {
  if (!settings || typeof settings !== "object") return null;
  const draftId = toPositiveInt(settings.draftId);
  if (draftId) return { elementId: draftId, isDraft: true };
  const canonical =
    toPositiveInt(settings.canonicalId) ?? toPositiveInt(settings.elementId);
  if (canonical) return { elementId: canonical, isDraft: false };
  return null;
}

/**
 * Read the server-emitted element context. The plugin injects this into
 * the editor content, so it lands inside the entry's form — when a rootEl
 * is supplied (a CKEditor field) we prefer the blob in its own form first
 * so an open slideout doesn't read a background full-page editor's tag.
 */
function readFromScriptTag(rootEl?: HTMLElement | null): ElementContext | null {
  const scopes: ParentNode[] = [];
  const form = rootEl?.closest("form");
  if (form) scopes.push(form);
  scopes.push(document);

  for (const scope of scopes) {
    const tag = scope.querySelector<HTMLScriptElement>(
      "script[data-craftai-element-context]",
    );
    if (!tag?.textContent) continue;
    try {
      const ctx = contextFromSettings(JSON.parse(tag.textContent));
      if (ctx) return ctx;
    } catch {
      // Malformed JSON — try the next scope / fall through to heuristics.
    }
  }
  return null;
}

function readFromElementEditor(rootEl?: HTMLElement | null): ElementContext | null {
  const jq = getJQuery();
  if (!jq) return null;

  // Candidate containers, nearest-first. When called from inside a
  // CKEditor field (rootEl set) we prefer the enclosing form/screen so a
  // slideout opened over a listing resolves to the slideout's editor
  // rather than a background full-page editor.
  const containers: unknown[] = [];
  if (rootEl) {
    const form = rootEl.closest("form");
    if (form) containers.push(form);
    const screen = rootEl.closest(".slideout, .cp-screen, [data-element-editor]");
    if (screen) containers.push(screen);
  }
  for (const sel of ["#main-form", ".slideout", "[data-element-editor]"]) {
    try {
      jq(sel).each(function (this: unknown) {
        containers.push(this);
      });
    } catch {
      // Selector engine unavailable / unsupported — skip.
    }
  }

  for (const el of containers) {
    let settings: unknown = null;
    try {
      const $el = jq(el);
      if (typeof $el.data !== "function") continue;
      const inst = $el.data("elementEditor");
      settings =
        inst && inst.settings ? inst.settings : $el.data("elementEditorSettings");
    } catch {
      settings = null;
    }
    const ctx = contextFromSettings(settings);
    if (ctx) return ctx;
  }
  return null;
}

function readFromUrl(): ElementContext | null {
  try {
    const params = new URLSearchParams(window.location.search);
    // A revision is read-only; don't treat it as a draft — fall through so
    // its comments resolve against the canonical instead.
    if (toPositiveInt(params.get("revisionId"))) return null;
    const draftId = toPositiveInt(params.get("draftId"));
    if (draftId) return { elementId: draftId, isDraft: true };
  } catch {
    // No window.location (tests / non-browser) — fall through.
  }
  return null;
}

function readFromHiddenInputs(rootEl?: HTMLElement | null): ElementContext | null {
  const scope: ParentNode = (rootEl && rootEl.closest("form")) || document;

  const draftInput = scope.querySelector<HTMLInputElement>('input[name="draftId"]');
  if (draftInput && draftInput.value && draftInput.value !== "0") {
    const id = toPositiveInt(draftInput.value);
    if (id) return { elementId: id, isDraft: true };
  }

  const elementInput = scope.querySelector<HTMLInputElement>(
    'input[name="elementId"], input[name="sourceId"]',
  );
  if (elementInput && elementInput.value) {
    const id = toPositiveInt(elementInput.value);
    if (id) return { elementId: id, isDraft: false };
  }

  return null;
}

/**
 * Detect the entry/draft currently being edited. `rootEl` (optional) lets
 * callers inside a CKEditor field bias resolution toward the nearest
 * form/slideout; omit it to resolve from the page at large. Returns null
 * when no element edit is in progress (settings page, listing, etc.).
 */
export function readElementContext(
  rootEl?: HTMLElement | null,
): ElementContext | null {
  return (
    readFromScriptTag(rootEl) ??
    readFromElementEditor(rootEl) ??
    readFromUrl() ??
    readFromHiddenInputs(rootEl)
  );
}
