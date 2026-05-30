import { afterEach, describe, expect, test } from "bun:test";

import { readElementContext } from "../elementContext";

// Per-node jQuery `.data()` store keyed by the DOM node, mirroring how
// Craft stashes the live ElementEditor instance on its container.
const dataStore = new WeakMap<Element, Record<string, unknown>>();

/**
 * Install a minimal stand-in for Craft's jQuery: enough of `(sel|node)`,
 * `.each()`, and `.data(key)` for `readElementContext` to walk containers
 * and pull settings, backed by real `document.querySelectorAll`.
 */
function installFakeCraft(): void {
  const jq = (arg: unknown) => {
    let nodes: Element[] = [];
    if (typeof arg === "string") {
      nodes = Array.from(document.querySelectorAll(arg));
    } else if (arg instanceof Element) {
      nodes = [arg];
    }
    return {
      each(cb: (this: Element, index: number, el: Element) => void) {
        nodes.forEach((n, i) => cb.call(n, i, n));
      },
      data(key: string): unknown {
        const n = nodes[0];
        if (!n) return undefined;
        return dataStore.get(n)?.[key];
      },
    };
  };
  (window as unknown as { Craft?: unknown }).Craft = { $: jq };
}

function setEditorSettings(selector: string, settings: Record<string, unknown>): void {
  const el = document.querySelector(selector);
  if (!el) throw new Error(`no element for ${selector}`);
  dataStore.set(el, { elementEditor: { settings } });
}

afterEach(() => {
  document.body.innerHTML = "";
  delete (window as unknown as { Craft?: unknown }).Craft;
  // Reset the URL so a draftId param from one test can't leak into the next.
  window.history.replaceState(null, "", "/");
});

function setServerContext(
  context: Record<string, unknown>,
  selector = "#main-form",
): void {
  const host = document.querySelector(selector) ?? document.body;
  const tag = document.createElement("script");
  tag.type = "application/json";
  tag.setAttribute("data-craftai-element-context", "");
  tag.textContent = JSON.stringify(context);
  host.appendChild(tag);
}

describe("readElementContext — server-emitted script tag (deterministic, wins)", () => {
  test("reads the real draftId from the server blob even when the form's elementId is the canonical", () => {
    document.body.innerHTML = `
      <form id="main-form">
        <input type="hidden" name="elementId" value="334">
      </form>`;
    setServerContext({
      elementId: 438,
      canonicalId: 334,
      draftId: 99,
      isDraft: true,
      siteId: 1,
    });

    expect(readElementContext()).toEqual({ elementId: 99, isDraft: true });
  });

  test("collapses a canonical blob (null draftId) to the canonical id", () => {
    document.body.innerHTML = `<form id="main-form"></form>`;
    setServerContext({ elementId: 334, canonicalId: 334, draftId: null });

    expect(readElementContext()).toEqual({ elementId: 334, isDraft: false });
  });

  test("wins over the ElementEditor settings and the hidden input", () => {
    document.body.innerHTML = `
      <form id="main-form">
        <input type="hidden" name="elementId" value="334">
      </form>`;
    installFakeCraft();
    setEditorSettings("#main-form", { draftId: 7, canonicalId: 334 });
    setServerContext({ elementId: 438, canonicalId: 334, draftId: 99 });

    expect(readElementContext()).toEqual({ elementId: 99, isDraft: true });
  });

  test("scopes to the nearest form's blob when given a root element", () => {
    document.body.innerHTML = `
      <form id="bg"></form>
      <form id="slideout"></form>`;
    setServerContext({ elementId: 100, canonicalId: 100, draftId: null }, "#bg");
    setServerContext({ elementId: 438, canonicalId: 334, draftId: 99 }, "#slideout");

    const root = document.querySelector<HTMLElement>("#slideout")!;
    expect(readElementContext(root)).toEqual({ elementId: 99, isDraft: true });
  });

  test("falls through to heuristics when the blob is malformed", () => {
    document.body.innerHTML = `
      <form id="main-form">
        <input type="hidden" name="elementId" value="334">
      </form>`;
    const tag = document.createElement("script");
    tag.setAttribute("data-craftai-element-context", "");
    tag.textContent = "{not json";
    document.querySelector("#main-form")!.appendChild(tag);

    expect(readElementContext()).toEqual({ elementId: 334, isDraft: false });
  });
});

describe("readElementContext — ElementEditor settings (authoritative)", () => {
  test("reads the real draftId off the editor settings even when the form's elementId is the canonical", () => {
    // The exact production trap: hidden elementId is the canonical (334),
    // there is NO draftId input, but the editor knows it's draft 99.
    document.body.innerHTML = `
      <form id="main-form">
        <input type="hidden" name="elementId" value="334">
      </form>`;
    installFakeCraft();
    setEditorSettings("#main-form", {
      draftId: 99,
      canonicalId: 334,
      elementId: 438,
    });

    expect(readElementContext()).toEqual({ elementId: 99, isDraft: true });
  });

  test("collapses a canonical (null draftId) to the canonical id", () => {
    document.body.innerHTML = `<form id="main-form"></form>`;
    installFakeCraft();
    setEditorSettings("#main-form", {
      draftId: null,
      canonicalId: 334,
      elementId: 334,
    });

    expect(readElementContext()).toEqual({ elementId: 334, isDraft: false });
  });

  test("wins over a stale/canonical hidden input", () => {
    document.body.innerHTML = `
      <form id="main-form">
        <input type="hidden" name="elementId" value="334">
      </form>`;
    installFakeCraft();
    setEditorSettings("#main-form", { draftId: 77, canonicalId: 334 });

    expect(readElementContext()).toEqual({ elementId: 77, isDraft: true });
  });
});

describe("readElementContext — URL fallback", () => {
  test("uses the draftId query param when no editor settings are present", () => {
    window.history.replaceState(null, "", "/admin/entries/blog/334?draftId=99");
    document.body.innerHTML = `
      <form id="main-form">
        <input type="hidden" name="elementId" value="334">
      </form>`;

    expect(readElementContext()).toEqual({ elementId: 99, isDraft: true });
  });

  test("ignores a revisionId param and falls through to the canonical input", () => {
    window.history.replaceState(null, "", "/admin/entries/blog/334?revisionId=12");
    document.body.innerHTML = `
      <form id="main-form">
        <input type="hidden" name="elementId" value="334">
      </form>`;

    expect(readElementContext()).toEqual({ elementId: 334, isDraft: false });
  });
});

describe("readElementContext — hidden-input fallback (no Craft runtime)", () => {
  test("prefers a draftId input when present", () => {
    document.body.innerHTML = `
      <form>
        <input type="hidden" name="elementId" value="100">
        <input type="hidden" name="draftId" value="42">
      </form>`;
    expect(readElementContext()).toEqual({ elementId: 42, isDraft: true });
  });

  test("falls back to elementId when no draftId is present", () => {
    document.body.innerHTML = `
      <form><input type="hidden" name="elementId" value="100"></form>`;
    expect(readElementContext()).toEqual({ elementId: 100, isDraft: false });
  });

  test("supports sourceId as an elementId alias", () => {
    document.body.innerHTML = `
      <form><input type="hidden" name="sourceId" value="200"></form>`;
    expect(readElementContext()).toEqual({ elementId: 200, isDraft: false });
  });

  test("ignores a draftId of zero", () => {
    document.body.innerHTML = `
      <form>
        <input type="hidden" name="elementId" value="100">
        <input type="hidden" name="draftId" value="0">
      </form>`;
    expect(readElementContext()).toEqual({ elementId: 100, isDraft: false });
  });

  test("returns null when nothing identifies an element", () => {
    document.body.innerHTML = `<form></form>`;
    expect(readElementContext()).toBeNull();
  });

  test("scopes to the nearest form when given a root element", () => {
    document.body.innerHTML = `
      <form><input type="hidden" name="elementId" value="11"></form>
      <form id="other"><input type="hidden" name="elementId" value="22"></form>`;
    const root = document.querySelector<HTMLElement>("#other")!;
    expect(readElementContext(root)).toEqual({ elementId: 22, isDraft: false });
  });
});
