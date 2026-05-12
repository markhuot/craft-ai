import { useEffect, useRef, useState } from "react";
import { buildSelector, buildSnippet, findBlockAncestor } from "./cssSelector";

export interface TargetSelection {
  selector: string;
  snippet: string;
  tag: string;
  text: string;
}

export interface UseElementPickerOptions {
  /** When true, the picker is live: listeners attached, overlay tracking the mouse. */
  active: boolean;
  /** Fires once the user clicks a valid element. */
  onPick: (selection: TargetSelection) => void;
  /** Fires when the user presses Escape or right-clicks. */
  onCancel: () => void;
  /**
   * Returns the widget's shadow host so we can exclude it (and anything
   * inside it) from the picker — without this the user could "target" the
   * floating panel itself.
   */
  getExcludedRoot?: () => Element | null;
}

interface HighlightRect {
  top: number;
  left: number;
  width: number;
  height: number;
}

/**
 * Drives the front-end "click to target an element" mode. While `active`,
 * tracks the mouse over the host page, paints a fixed-position highlight on
 * the nearest block-level ancestor, and resolves a click into a stable CSS
 * selector + HTML snippet. The component using this hook is responsible for
 * rendering the overlay div with `highlightRect`.
 */
export function useElementPicker({
  active,
  onPick,
  onCancel,
  getExcludedRoot,
}: UseElementPickerOptions) {
  const [highlightRect, setHighlightRect] = useState<HighlightRect | null>(null);
  // `onPick`/`onCancel` are usually inline callbacks from the parent that
  // change on every render. Stash them in a ref so the document-level
  // listeners we attach below don't have to be torn down/recreated on every
  // parent re-render — that flicker would lose the user's hover state.
  const onPickRef = useRef(onPick);
  const onCancelRef = useRef(onCancel);
  const excludedRootRef = useRef(getExcludedRoot);
  onPickRef.current = onPick;
  onCancelRef.current = onCancel;
  excludedRootRef.current = getExcludedRoot;

  useEffect(() => {
    if (!active) {
      setHighlightRect(null);
      return;
    }

    // Saved so we can restore the host page's cursor on teardown — without
    // this, an early unmount during picking would leave the page stuck
    // showing a crosshair.
    const previousCursor = document.body.style.cursor;
    document.body.style.cursor = "crosshair";

    let currentTarget: Element | null = null;

    const isExcluded = (node: Element | null): boolean => {
      if (!node) return false;
      const excluded = excludedRootRef.current?.() ?? null;
      if (!excluded) return false;
      return excluded === node || excluded.contains(node);
    };

    const onMouseMove = (e: MouseEvent) => {
      const raw = document.elementFromPoint(e.clientX, e.clientY);
      if (!raw || isExcluded(raw)) {
        currentTarget = null;
        setHighlightRect(null);
        return;
      }
      const block = findBlockAncestor(raw, (n) => isExcluded(n));
      if (!block || isExcluded(block)) {
        currentTarget = null;
        setHighlightRect(null);
        return;
      }
      currentTarget = block;
      const rect = block.getBoundingClientRect();
      setHighlightRect({
        top: rect.top,
        left: rect.left,
        width: rect.width,
        height: rect.height,
      });
    };

    const onClick = (e: MouseEvent) => {
      if (!currentTarget) return;
      if (isExcluded(currentTarget)) return;
      // Block the host page's own click handlers — a click on a <a> or
      // <button> during targeting must not navigate or submit.
      e.preventDefault();
      e.stopPropagation();
      const target = currentTarget;
      currentTarget = null;
      const selector = buildSelector(target);
      const snippet = buildSnippet(target);
      const text = (target.textContent ?? "").replace(/\s+/g, " ").trim();
      onPickRef.current({
        selector,
        snippet,
        tag: target.tagName.toLowerCase(),
        text,
      });
    };

    const onKeyDown = (e: KeyboardEvent) => {
      if (e.key === "Escape") {
        e.preventDefault();
        e.stopPropagation();
        onCancelRef.current();
      }
    };

    const onContextMenu = (e: MouseEvent) => {
      // Right-click is a familiar "cancel" gesture; intercept it so the
      // browser doesn't show its context menu mid-pick.
      e.preventDefault();
      e.stopPropagation();
      onCancelRef.current();
    };

    const onScroll = () => {
      // Bounding rect drifts as the user scrolls. Cheapest fix: drop the
      // highlight; the next mousemove rebuilds it from the new position.
      currentTarget = null;
      setHighlightRect(null);
    };

    document.addEventListener("mousemove", onMouseMove, true);
    document.addEventListener("click", onClick, true);
    document.addEventListener("keydown", onKeyDown, true);
    document.addEventListener("contextmenu", onContextMenu, true);
    window.addEventListener("scroll", onScroll, true);
    window.addEventListener("resize", onScroll, true);

    return () => {
      document.removeEventListener("mousemove", onMouseMove, true);
      document.removeEventListener("click", onClick, true);
      document.removeEventListener("keydown", onKeyDown, true);
      document.removeEventListener("contextmenu", onContextMenu, true);
      window.removeEventListener("scroll", onScroll, true);
      window.removeEventListener("resize", onScroll, true);
      document.body.style.cursor = previousCursor;
      setHighlightRect(null);
    };
  }, [active]);

  return { highlightRect };
}
