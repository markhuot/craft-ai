import { useCallback, useEffect, useState } from "react";

const MIN_PERCENT = 20;
const MAX_PERCENT = 80;

function clamp(value: number, fallback: number): number {
  if (!Number.isFinite(value)) return Math.min(MAX_PERCENT, Math.max(MIN_PERCENT, fallback));
  return Math.min(MAX_PERCENT, Math.max(MIN_PERCENT, value));
}

/**
 * Reads/writes a single percentage to localStorage so the user's preferred
 * transcript/preview split survives across sessions and reloads. The value
 * is clamped to [20, 80] so a stale or hostile localStorage entry can't
 * collapse one pane to a sliver.
 */
export function useResizableSplit(
  storageKey: string,
  defaultPercent: number,
): [number, (next: number) => void] {
  const [percent, setPercentState] = useState<number>(() => {
    if (typeof window === "undefined") return clamp(defaultPercent, defaultPercent);
    try {
      const raw = window.localStorage.getItem(storageKey);
      if (raw === null) return clamp(defaultPercent, defaultPercent);
      return clamp(Number.parseFloat(raw), defaultPercent);
    } catch {
      return clamp(defaultPercent, defaultPercent);
    }
  });

  const setPercent = useCallback(
    (next: number) => {
      setPercentState(clamp(next, defaultPercent));
    },
    [defaultPercent],
  );

  useEffect(() => {
    if (typeof window === "undefined") return;
    try {
      window.localStorage.setItem(storageKey, String(percent));
    } catch {
      // private mode / quota exceeded — keep the in-memory value, drop the persist.
    }
  }, [storageKey, percent]);

  return [percent, setPercent];
}
