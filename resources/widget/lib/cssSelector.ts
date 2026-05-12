/**
 * Helpers for the front-end "target an element" picker. Used to convert a
 * pointed-at DOM node on the host page into a stable CSS selector plus a
 * short HTML snippet the agent can reason about.
 */

const INLINE_DISPLAYS = new Set(["inline", "contents", ""]);

/**
 * Walk up the tree until we find an element that visually behaves as a block.
 * Spans/links/strong/em etc. resolve up to their nearest block ancestor so the
 * picker selects the surrounding paragraph/heading/section rather than a
 * sub-word fragment.
 *
 * Returns null only if every ancestor up to <html> is somehow inline (which
 * should be impossible for a rendered page, but keeps the function total).
 */
export function findBlockAncestor(el: Element, skip?: (node: Element) => boolean): Element | null {
  let current: Element | null = el;
  while (current && current !== document.documentElement) {
    if (skip?.(current)) {
      current = current.parentElement;
      continue;
    }
    const display = window.getComputedStyle(current).display;
    if (!INLINE_DISPLAYS.has(display)) {
      return current;
    }
    current = current.parentElement;
  }
  return current;
}

/**
 * Build a unique-ish CSS selector for an element. Prefers a `#id` short-circuit;
 * otherwise walks ancestors using `tag:nth-of-type(n)` so siblings of the
 * same tag stay disambiguated. We deliberately ignore class names — most
 * front-end frameworks generate hashed class names that change on every build
 * and would make the selector useless to the agent across reloads.
 */
export function buildSelector(el: Element): string {
  if (el.id !== "") {
    return `#${cssEscape(el.id)}`;
  }
  const parts: string[] = [];
  let current: Element | null = el;
  while (current && current !== document.documentElement) {
    if (current.id !== "") {
      parts.unshift(`#${cssEscape(current.id)}`);
      break;
    }
    let part = current.tagName.toLowerCase();
    const parent: Element | null = current.parentElement;
    if (parent) {
      const tagName = current.tagName;
      const sameTagSiblings: Element[] = Array.from(parent.children).filter(
        (s): s is Element => s.tagName === tagName,
      );
      if (sameTagSiblings.length > 1) {
        const index = sameTagSiblings.indexOf(current) + 1;
        part += `:nth-of-type(${index})`;
      }
    }
    parts.unshift(part);
    current = parent;
  }
  return parts.join(" > ");
}

/**
 * Render a short, copyable description of the picked element — opening tag
 * with id/classes plus up to ~80 chars of inner text. Goes into the chip the
 * user sees and into the `<selected-element>` note the agent receives.
 */
export function buildSnippet(el: Element): string {
  const tag = el.tagName.toLowerCase();
  const id = el.id !== "" ? `#${el.id}` : "";
  const classes =
    el.classList.length > 0
      ? "." + Array.from(el.classList).slice(0, 3).join(".")
      : "";
  const rawText = (el.textContent ?? "").replace(/\s+/g, " ").trim();
  const text = rawText.length > 80 ? rawText.slice(0, 77) + "…" : rawText;
  return text === "" ? `<${tag}${id}${classes}>` : `<${tag}${id}${classes}>${text}</${tag}>`;
}

/**
 * Use the native CSS.escape when present (every supported browser), fall back
 * to a minimal escape for environments where it's missing (older JSDOM in
 * tests, for example). Only escapes the characters we actually emit in id/class
 * names; we're not running arbitrary user CSS through this.
 */
function cssEscape(value: string): string {
  if (typeof CSS !== "undefined" && typeof CSS.escape === "function") {
    return CSS.escape(value);
  }
  return value.replace(/([^a-zA-Z0-9_-])/g, "\\$1");
}
