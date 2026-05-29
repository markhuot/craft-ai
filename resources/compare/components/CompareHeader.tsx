import { RevisionSelect } from "./RevisionSelect";
import type { RevisionOption } from "../types";

export interface CompareHeaderProps {
  entryTitle: string;
  revisions: RevisionOption[];
  /** Selected A ref ("compare"), may be ''. */
  a: string;
  /** Selected B ref ("against"), may be ''. */
  b: string;
  /** True while a diff is computing — disables the controls. */
  busy: boolean;
  onChangeA: (ref: string) => void;
  onChangeB: (ref: string) => void;
  onRecompute: () => void;
}

/**
 * Top bar of the compare view: the entry title, the two revision pickers, and
 * a Recompute action. Purely presentational — all state lives in {@link App}.
 */
export function CompareHeader({
  entryTitle,
  revisions,
  a,
  b,
  busy,
  onChangeA,
  onChangeB,
  onRecompute,
}: CompareHeaderProps) {
  // Recompute needs both sides chosen; while a diff is in flight we also lock
  // it so a double-click can't fire two overlapping requests.
  const canRecompute = !busy && a !== "" && b !== "";

  return (
    <header className="ai:flex ai:flex-col ai:gap-3 ai:border-b ai:border-craftai-border ai:pb-3">
      <div className="ai:flex ai:flex-wrap ai:items-center ai:justify-between ai:gap-2">
        <h1 className="ai:m-0 ai:text-lg ai:font-semibold ai:text-craftai-fg">
          {entryTitle}
        </h1>
      </div>

      <div className="ai:flex ai:flex-wrap ai:items-end ai:gap-3">
        <RevisionSelect
          label="A · Compare"
          value={a}
          options={revisions}
          onChange={onChangeA}
          disabled={busy}
        />
        <RevisionSelect
          label="B · Against"
          value={b}
          options={revisions}
          onChange={onChangeB}
          disabled={busy}
        />
        {/* Craft-native button. `loading` drives the CP's built-in spinner
            while a diff computes; `disabled` is mirrored as a class so the CP
            dims it the way it dims its own disabled buttons. */}
        <button
          type="button"
          onClick={onRecompute}
          disabled={!canRecompute}
          className={`btn${busy ? " loading" : ""}${canRecompute ? "" : " disabled"}`}
        >
          Recompute
        </button>
      </div>
    </header>
  );
}
