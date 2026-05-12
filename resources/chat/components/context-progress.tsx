import { useMemo } from "react";
import { cn } from "../lib/utils";

export interface ContextProgressProps {
  /**
   * Tokens used by the most recent assistant turn. We use this as a proxy
   * for "what the next request will start with" — input tokens dominate the
   * prompt budget while output tokens get folded into history.
   */
  used: number;
  /** Model's max prompt-tokens. The gauge hides itself when this is null. */
  contextWindow: number | null;
}

/**
 * Tiny circular progress indicator that lives in the chat toolbar. Tracks
 * how much of the model's context window the conversation has consumed so
 * the user can see the auto-compaction threshold approaching before it
 * triggers. Renders nothing when the host hasn't configured a contextWindow.
 *
 * Color thresholds:
 *   < 70%  — green   (plenty of headroom)
 *   70-90% — yellow  (getting full)
 *   ≥ 90%  — red     (next turn may trip auto-compaction)
 */
export function ContextProgress({ used, contextWindow }: ContextProgressProps) {
  const data = useMemo(() => {
    if (contextWindow === null || contextWindow <= 0) return null;
    const ratio = Math.min(Math.max(used / contextWindow, 0), 1);
    const pct = Math.round(ratio * 100);
    let stroke: string;
    if (ratio >= 0.9) stroke = "#dc2626";
    else if (ratio >= 0.7) stroke = "#d97706";
    else stroke = "#16a34a";
    return { ratio, pct, stroke };
  }, [used, contextWindow]);

  if (data === null || contextWindow === null) return null;

  // Circle geometry: r=7, circumference = 2π·7 ≈ 43.98. Stroke dasharray
  // animates the filled arc from 0% (full gap) to 100% (no gap).
  const circumference = 2 * Math.PI * 7;
  const dashOffset = circumference * (1 - data.ratio);

  const title = `Context used: ${used.toLocaleString()} / ${contextWindow.toLocaleString()} tokens (${data.pct}%)`;

  return (
    <span
      data-testid="context-progress"
      data-pct={data.pct}
      title={title}
      aria-label={title}
      className={cn(
        "ai:inline-flex ai:items-center ai:gap-1 ai:rounded-md ai:border ai:border-craftai-border ai:bg-white ai:px-2 ai:py-1 ai:text-[10px] ai:font-medium",
      )}
    >
      <svg
        width="18"
        height="18"
        viewBox="0 0 18 18"
        aria-hidden
        className="ai:shrink-0"
      >
        {/* Track */}
        <circle cx="9" cy="9" r="7" fill="none" stroke="#e3e5e8" strokeWidth="2" />
        {/* Progress arc — starts at 12 o'clock by rotating the SVG -90deg */}
        <circle
          cx="9"
          cy="9"
          r="7"
          fill="none"
          stroke={data.stroke}
          strokeWidth="2"
          strokeLinecap="round"
          strokeDasharray={circumference}
          strokeDashoffset={dashOffset}
          transform="rotate(-90 9 9)"
          style={{ transition: "stroke-dashoffset 200ms ease, stroke 200ms ease" }}
        />
      </svg>
      <span className="ai:tabular-nums" style={{ color: data.stroke }}>
        {data.pct}%
      </span>
    </span>
  );
}
