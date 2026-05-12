import { useMemo } from "react";
import { cn } from "../lib/utils";
import type { SlashCommand } from "../types";

export interface SlashCommandMenuProps {
  /** Live prompt text. Determines visibility and the prefix filter. */
  draft: string;
  /** Catalog from the server; empty array hides the menu. */
  commands: SlashCommand[];
  /** Index of the highlighted item within the *filtered* list. */
  selectedIndex: number;
  /** Click handler for an entry — same effect as Enter on it. */
  onPick: (command: SlashCommand) => void;
}

/**
 * Pop-up shown above the prompt textarea whenever the draft starts with
 * "/". Filters the server-provided command catalog by prefix and
 * highlights the entry the user has selected via arrow keys. This
 * component is presentation-only — Chat owns the selection state and the
 * keyboard wiring so the textarea can drive both navigation and submit.
 */
export function SlashCommandMenu({ draft, commands, selectedIndex, onPick }: SlashCommandMenuProps) {
  const filtered = useMemo(() => filterCommands(draft, commands), [draft, commands]);

  if (filtered.length === 0) return null;

  return (
    <div
      data-testid="slash-command-menu"
      role="listbox"
      aria-label="Slash commands"
      className={cn(
        "ai:absolute ai:bottom-full ai:left-0 ai:right-0 ai:mb-1",
        "ai:z-10 ai:overflow-hidden ai:rounded-md ai:border ai:border-craftai-border ai:bg-white ai:shadow-lg",
      )}
    >
      <ul className="ai:m-0 ai:list-none ai:p-1">
        {filtered.map((cmd, i) => {
          const selected = i === selectedIndex;
          return (
            <li key={cmd.name}>
              <button
                type="button"
                data-testid={`slash-command-item-${cmd.name}`}
                data-selected={selected ? "true" : "false"}
                aria-selected={selected}
                role="option"
                // Avoid stealing focus from the textarea on mousedown —
                // the autocomplete loses its filter context the instant
                // focus moves, and the click handler still fires.
                onMouseDown={(e) => {
                  e.preventDefault();
                  onPick(cmd);
                }}
                className={cn(
                  "ai:flex ai:w-full ai:flex-col ai:items-start ai:gap-0.5 ai:rounded ai:px-2 ai:py-1.5 ai:text-left ai:text-xs",
                  selected
                    ? "ai:bg-craftai-user ai:text-craftai-fg"
                    : "ai:text-craftai-fg hover:ai:bg-craftai-border/30",
                )}
              >
                <span className="ai:font-mono ai:text-[12px] ai:font-medium">/{cmd.name}</span>
                <span className="ai:text-[11px] ai:text-craftai-muted">{cmd.description}</span>
              </button>
            </li>
          );
        })}
      </ul>
    </div>
  );
}

/**
 * Pull the matching subset of commands for the current draft. The match
 * is a case-insensitive prefix on the command name; empty query (just
 * "/") shows everything.
 *
 * Exported so Chat can read the same filtered list to drive selection
 * bounds without re-implementing the rules.
 */
export function filterCommands(draft: string, commands: SlashCommand[]): SlashCommand[] {
  if (!draft.startsWith("/")) return [];
  const query = draft.slice(1).split(/\s/)[0]?.toLowerCase() ?? "";
  // A space after the command name (e.g. "/compact ") means the user has
  // committed to that command and is now typing args — hide the menu so
  // it doesn't compete with the arg text below the cursor.
  const afterFirstWord = draft.slice(1).indexOf(" ");
  if (afterFirstWord !== -1) return [];
  return commands.filter((c) => c.name.toLowerCase().startsWith(query));
}
