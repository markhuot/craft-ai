import { useCallback, useEffect, useState, type RefObject } from "react";

export interface ResizeDividerProps {
  /** Container the percentage is computed against (left edge → 0%, right edge → 100%). */
  containerRef: RefObject<HTMLElement | null>;
  onResize: (percent: number) => void;
  ariaValueNow: number;
  ariaLabel?: string;
}

/**
 * Invisible, draggable column divider. Sits between the transcript and
 * preview panes; while the pointer is down we report the cursor's x-position
 * as a percentage of the container so the parent can drive a resizable split
 * without this component owning any of the layout state.
 */
export function ResizeDivider({
  containerRef,
  onResize,
  ariaValueNow,
  ariaLabel,
}: ResizeDividerProps) {
  const [dragging, setDragging] = useState(false);

  useEffect(() => {
    if (!dragging) return;

    const onMove = (e: PointerEvent) => {
      const container = containerRef.current;
      if (!container) return;
      const rect = container.getBoundingClientRect();
      if (rect.width <= 0) return;
      const x = e.clientX - rect.left;
      onResize((x / rect.width) * 100);
    };

    const onUp = () => setDragging(false);

    window.addEventListener("pointermove", onMove);
    window.addEventListener("pointerup", onUp);
    window.addEventListener("pointercancel", onUp);

    // Suppress text selection and pin the cursor so the user gets clear
    // resize feedback no matter which element is under the pointer.
    const prevUserSelect = document.body.style.userSelect;
    const prevCursor = document.body.style.cursor;
    document.body.style.userSelect = "none";
    document.body.style.cursor = "col-resize";

    return () => {
      window.removeEventListener("pointermove", onMove);
      window.removeEventListener("pointerup", onUp);
      window.removeEventListener("pointercancel", onUp);
      document.body.style.userSelect = prevUserSelect;
      document.body.style.cursor = prevCursor;
    };
  }, [dragging, onResize, containerRef]);

  const onPointerDown = useCallback((e: React.PointerEvent<HTMLDivElement>) => {
    // preventDefault stops happy-dom/Firefox from firing a synthetic
    // selectstart that would highlight nearby text mid-drag.
    e.preventDefault();
    setDragging(true);
  }, []);

  return (
    <div
      role="separator"
      aria-orientation="vertical"
      aria-label={ariaLabel ?? "Resize transcript and preview"}
      aria-valuenow={Math.round(ariaValueNow)}
      aria-valuemin={20}
      aria-valuemax={80}
      data-testid="preview-resize"
      data-dragging={dragging ? "true" : "false"}
      onPointerDown={onPointerDown}
      className={
        // Wider hit area than the visible hairline so it's easy to grab,
        // but the surface itself stays transparent — only on hover/drag
        // does a 1px guide line appear so the user knows where they're
        // clicking.
        "ai:relative ai:w-3 ai:shrink-0 ai:cursor-col-resize ai:touch-none ai:select-none " +
        "ai:after:absolute ai:after:inset-y-0 ai:after:left-1/2 ai:after:w-px ai:after:-translate-x-1/2 " +
        "ai:after:bg-transparent ai:transition-colors hover:ai:after:bg-craftai-border " +
        (dragging ? "ai:after:bg-craftai-border" : "")
      }
    />
  );
}
