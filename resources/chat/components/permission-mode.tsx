import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { Check, ChevronDown, ShieldCheck } from "lucide-react";
import { cn } from "../lib/utils";
import type { AvailableTool, ToolKind, ToolMode } from "../types";

/**
 * Drop-down menu the user clicks to scope which tools the agent has access
 * to for the rest of this session. Sits in the prompt toolbar between the
 * upload and submit buttons.
 *
 * The four canonical modes (Full / Draft / Read-only / Custom) are filtered
 * server-side by ToolKind on the descriptor; Custom additionally persists an
 * explicit allowlist of tool names. The button label reflects the current
 * mode so the user always sees what surface is exposed without opening the
 * menu.
 */

const MODE_LABELS: Record<ToolMode, string> = {
  full: "Full",
  draft: "Draft",
  readonly: "Read-only",
  custom: "Custom",
};

const MODE_DESCRIPTIONS: Record<ToolMode, string> = {
  full: "All tools enabled.",
  draft: "Read + draft writes. No changes to live content.",
  readonly: "Read tools only. Agent can't change anything.",
  custom: "Pick exactly which tools the agent can use.",
};

const KIND_HEADINGS: Record<ToolKind, string> = {
  read: "Read",
  draftWrite: "Draft writes",
  liveWrite: "Live writes",
};

const KIND_ORDER: ToolKind[] = ["read", "draftWrite", "liveWrite"];

export interface PermissionModeProps {
  mode: ToolMode;
  enabledTools: string[] | null;
  availableTools: AvailableTool[];
  /**
   * Called after the user picks a new mode (or toggles a tool in custom
   * mode). The parent is responsible for persisting it to the backend; this
   * component is purely the UI surface.
   */
  onChange: (mode: ToolMode, enabledTools: string[] | null) => void;
  disabled?: boolean;
}

export function PermissionMode({
  mode,
  enabledTools,
  availableTools,
  onChange,
  disabled,
}: PermissionModeProps) {
  const [open, setOpen] = useState(false);
  const containerRef = useRef<HTMLDivElement | null>(null);

  // Close on outside click. We intentionally listen on document so a click
  // anywhere outside the menu (including the iframe pane next to it) closes
  // the menu, mirroring how Craft's own action menus behave.
  useEffect(() => {
    if (!open) return;
    const onPointer = (e: MouseEvent) => {
      const root = containerRef.current;
      if (root && !root.contains(e.target as Node)) {
        setOpen(false);
      }
    };
    const onKey = (e: KeyboardEvent) => {
      if (e.key === "Escape") setOpen(false);
    };
    document.addEventListener("mousedown", onPointer);
    document.addEventListener("keydown", onKey);
    return () => {
      document.removeEventListener("mousedown", onPointer);
      document.removeEventListener("keydown", onKey);
    };
  }, [open]);

  const handleSelectMode = useCallback(
    (next: ToolMode) => {
      if (next === "custom") {
        // Seed Custom with the current effective allowlist so the user
        // doesn't start from a blank checkboxes panel. If we already had a
        // custom list, reuse it; otherwise compute what the previous mode
        // was exposing and pin those.
        const seed =
          enabledTools && enabledTools.length > 0
            ? enabledTools
            : effectiveToolNames(mode, availableTools);
        onChange("custom", seed);
        return;
      }
      onChange(next, null);
      setOpen(false);
    },
    [availableTools, enabledTools, mode, onChange],
  );

  const handleToggleTool = useCallback(
    (name: string) => {
      const current = new Set(enabledTools ?? []);
      if (current.has(name)) {
        current.delete(name);
      } else {
        current.add(name);
      }
      // Re-emit as a stable list ordered the same way as availableTools so
      // the persisted JSON in the DB doesn't churn on every toggle.
      const next = availableTools
        .map((t) => t.name)
        .filter((n) => current.has(n));
      onChange("custom", next);
    },
    [availableTools, enabledTools, onChange],
  );

  const grouped = useMemo(() => groupByKind(availableTools), [availableTools]);

  return (
    <div ref={containerRef} className="ai:relative ai:inline-flex" data-slot="permission-mode">
      <button
        type="button"
        data-testid="permission-mode-button"
        data-mode={mode}
        aria-haspopup="menu"
        aria-expanded={open}
        disabled={disabled}
        onClick={() => setOpen((v) => !v)}
        className={cn(
          "ai:inline-flex ai:items-center ai:gap-1.5 ai:rounded-md ai:border ai:border-craftai-border ai:bg-white ai:px-3 ai:py-1.5 ai:text-xs ai:font-medium ai:text-craftai-fg ai:transition hover:ai:bg-craftai-border/20 ai:disabled:opacity-50 ai:disabled:cursor-not-allowed",
        )}
      >
        <ShieldCheck className="ai:h-3.5 ai:w-3.5" aria-hidden />
        {MODE_LABELS[mode]}
        <ChevronDown className="ai:h-3 ai:w-3" aria-hidden />
      </button>

      {open && (
        <div
          role="menu"
          data-testid="permission-mode-menu"
          className="ai:absolute ai:bottom-full ai:left-0 ai:mb-2 ai:z-30 ai:w-72 ai:max-h-[60vh] ai:overflow-y-auto ai:rounded-md ai:border ai:border-craftai-border ai:bg-white ai:p-1 ai:shadow-lg"
        >
          <ul className="ai:m-0 ai:list-none ai:p-0">
            {(Object.keys(MODE_LABELS) as ToolMode[]).map((m) => (
              <li key={m} className="ai:list-none">
                <button
                  type="button"
                  role="menuitemradio"
                  aria-checked={mode === m}
                  data-testid={`permission-mode-option-${m}`}
                  onClick={() => handleSelectMode(m)}
                  className="ai:flex ai:w-full ai:items-start ai:gap-2 ai:rounded ai:px-2 ai:py-1.5 ai:text-left ai:text-xs hover:ai:bg-craftai-border/20"
                >
                  <span
                    aria-hidden
                    className="ai:inline-flex ai:h-4 ai:w-4 ai:flex-shrink-0 ai:items-center ai:justify-center"
                  >
                    {mode === m ? <Check className="ai:h-3.5 ai:w-3.5" /> : null}
                  </span>
                  <span className="ai:flex ai:flex-col ai:gap-0.5">
                    <span className="ai:font-medium ai:text-craftai-fg">{MODE_LABELS[m]}</span>
                    <span className="ai:text-[11px] ai:text-craftai-muted">
                      {MODE_DESCRIPTIONS[m]}
                    </span>
                  </span>
                </button>
              </li>
            ))}
          </ul>

          {mode === "custom" && (
            <div
              data-testid="permission-mode-custom-list"
              className="ai:mt-1 ai:border-t ai:border-craftai-border ai:pt-2"
            >
              {availableTools.length === 0 ? (
                <p className="ai:px-2 ai:py-1 ai:text-[11px] ai:text-craftai-muted">
                  No tools available.
                </p>
              ) : (
                KIND_ORDER.map((kind) => {
                  const tools = grouped[kind];
                  if (!tools || tools.length === 0) return null;
                  return (
                    <fieldset
                      key={kind}
                      className="ai:m-0 ai:border-0 ai:p-0"
                      data-kind={kind}
                    >
                      <legend className="ai:mb-1 ai:px-2 ai:text-[10px] ai:font-medium ai:uppercase ai:tracking-wide ai:text-craftai-muted">
                        {KIND_HEADINGS[kind]}
                      </legend>
                      <ul className="ai:m-0 ai:list-none ai:p-0">
                        {tools.map((tool) => {
                          const checked = (enabledTools ?? []).includes(tool.name);
                          return (
                            <li key={tool.name} className="ai:list-none">
                              <label
                                className="ai:flex ai:w-full ai:items-start ai:gap-2 ai:rounded ai:px-2 ai:py-1 ai:text-left ai:text-xs hover:ai:bg-craftai-border/20"
                                title={tool.description}
                              >
                                <input
                                  type="checkbox"
                                  data-testid={`permission-mode-tool-${tool.name}`}
                                  checked={checked}
                                  onChange={() => handleToggleTool(tool.name)}
                                  className="ai:mt-0.5 ai:h-3.5 ai:w-3.5"
                                />
                                <span className="ai:flex ai:min-w-0 ai:flex-col">
                                  <span className="ai:truncate ai:font-mono ai:text-[11px] ai:text-craftai-fg">
                                    {tool.name}
                                  </span>
                                </span>
                              </label>
                            </li>
                          );
                        })}
                      </ul>
                    </fieldset>
                  );
                })
              )}
            </div>
          )}
        </div>
      )}
    </div>
  );
}

function groupByKind(tools: AvailableTool[]): Record<ToolKind, AvailableTool[]> {
  const out: Record<ToolKind, AvailableTool[]> = { read: [], draftWrite: [], liveWrite: [] };
  for (const tool of tools) {
    out[tool.kind].push(tool);
  }
  return out;
}

/**
 * Compute the tool names a given mode would expose. Used as the seed list
 * when the user switches to Custom — they start with the same set the
 * previous mode was already showing, instead of an empty selection.
 */
function effectiveToolNames(mode: ToolMode, available: AvailableTool[]): string[] {
  if (mode === "full") return available.map((t) => t.name);
  if (mode === "readonly") return available.filter((t) => t.kind === "read").map((t) => t.name);
  if (mode === "draft")
    return available
      .filter((t) => t.kind === "read" || t.kind === "draftWrite")
      .map((t) => t.name);
  return [];
}
