import { useEffect, useLayoutEffect, useRef } from "react";
import type { RevisionOption } from "../types";

export interface RevisionSelectProps {
  /** Short label shown above the control (e.g. "A" / "B"). */
  label: string;
  /** Currently-selected `ref`, or '' when nothing is picked. */
  value: string;
  options: RevisionOption[];
  onChange: (ref: string) => void;
  disabled?: boolean;
}

/**
 * The slice of Craft CP's bundled selectize plugin we touch. Craft registers
 * selectize.js globally in the control panel, so on a real CP page
 * `jQuery(el).selectize(...)` upgrades a native `<select>` into a searchable
 * typeahead. We type only what we call; everything else is `unknown`.
 */
interface SelectizeInstance {
  setValue(value: string, silent?: boolean): void;
  enable(): void;
  disable(): void;
  destroy(): void;
  on(event: "change", handler: (value: string) => void): void;
}

interface SelectizeEnabledElement extends HTMLSelectElement {
  selectize?: SelectizeInstance;
}

interface SelectizeJQuery {
  0: SelectizeEnabledElement;
  selectize(settings?: Record<string, unknown>): SelectizeJQuery;
}

type JQueryStatic = (el: Element) => SelectizeJQuery;

declare global {
  interface Window {
    $?: JQueryStatic;
    jQuery?: JQueryStatic;
  }
}

/**
 * Resolve the CP's jQuery + selectize plugin if they're present. Returns
 * `null` outside the control panel (e.g. tests, or a Craft build without
 * selectize) so the caller can fall back to the bare native `<select>`.
 */
function getSelectizeFactory(): JQueryStatic | null {
  if (typeof window === "undefined") return null;
  const jq = window.jQuery ?? window.$;
  if (typeof jq !== "function") return null;
  // selectize attaches itself as a jQuery plugin; bail if it isn't loaded.
  const probe = jq(document.createElement("select"));
  return typeof probe.selectize === "function" ? jq : null;
}

/**
 * Compose an option's visible text: the human label, the author when known,
 * and a date when present. Mirrors the chat session row's `· author`
 * separator so the two surfaces read consistently.
 */
function optionText(option: RevisionOption): string {
  let text = option.label;
  if (option.savedBy) {
    text += ` · ${option.savedBy}`;
  }
  const date = option.dateUpdated ?? option.dateCreated;
  if (date) {
    text += ` · ${formatDate(date)}`;
  }
  return text;
}

/** Best-effort short date. Falls back to the raw string if it won't parse. */
function formatDate(value: string): string {
  const parsed = new Date(value);
  if (Number.isNaN(parsed.getTime())) return value;
  return parsed.toLocaleString(undefined, {
    month: "short",
    day: "numeric",
    hour: "numeric",
    minute: "2-digit",
  });
}

/**
 * Revision picker over the entry's version list. On a real CP page the
 * `<select>` is progressively enhanced into Craft's searchable typeahead via
 * the bundled selectize plugin; typeahead matters on entries with long
 * revision histories, where scanning a flat native list is painful. Without
 * selectize (tests, or a Craft build that lacks it) it's a bare native select.
 *
 * The `<select>` is created and mutated **imperatively** rather than rendered
 * as JSX. selectize relocates the select node out of where React put it (into
 * its own `.selectize-control`), so if React also owned the subtree its next
 * reconcile — e.g. dropping the placeholder `<option>` after the first pick —
 * would `removeChild` a node that had moved and throw "NotFoundError" mid
 * commit. Letting React own only the container and keeping the select outside
 * its tree removes that whole class of conflict.
 */
export function RevisionSelect({
  label,
  value,
  options,
  onChange,
  disabled = false,
}: RevisionSelectProps) {
  const containerRef = useRef<HTMLDivElement>(null);
  const selectRef = useRef<HTMLSelectElement | null>(null);
  const instanceRef = useRef<SelectizeInstance | null>(null);
  // Keep the latest onChange reachable from the long-lived change handler
  // without rebuilding the control on every render.
  const onChangeRef = useRef(onChange);
  onChangeRef.current = onChange;

  // Build the native <select> + its options inside our container, then enhance
  // with selectize when it's available. useLayoutEffect so the upgrade lands
  // before paint. Re-runs (tearing down and rebuilding) only when the option
  // set changes — selectize has no clean "diff my options" API, and the
  // container is ours to clear, so a clean rebuild is the safe move.
  useLayoutEffect(() => {
    const container = containerRef.current;
    if (!container) return;
    const jq = getSelectizeFactory();

    const select = document.createElement("select");
    // Native fallback needs a placeholder row to read as "nothing chosen";
    // selectize uses its own `placeholder` setting instead, so skip the empty
    // <option> there (it would otherwise show as a selectable choice).
    if (!jq) {
      const placeholder = document.createElement("option");
      placeholder.value = "";
      placeholder.textContent = "Pick a revision…";
      select.appendChild(placeholder);
    }
    for (const option of options) {
      const el = document.createElement("option");
      el.value = option.ref;
      el.textContent = optionText(option);
      select.appendChild(el);
    }
    select.value = value;
    select.disabled = disabled;
    container.appendChild(select);
    selectRef.current = select;

    if (jq) {
      const $el = jq(select).selectize({
        // Render the dropdown on <body> so it isn't clipped by the header's
        // overflow, and keep the placeholder visible when nothing is chosen.
        dropdownParent: "body",
        placeholder: "Pick a revision…",
        // Plain text options — no custom markup, no remote loading.
        plugins: [],
      });
      const instance = $el[0]?.selectize ?? null;
      instanceRef.current = instance;
      instance?.on("change", (next: string) => onChangeRef.current(next));
      if (disabled) instance?.disable();
      // Craft scopes its styled single-select trigger (button-style background
      // + caret) behind a `.selectize.select` ancestor of the generated
      // `.selectize-control`; selectize doesn't add those, so tag the container.
      container.classList.add("selectize", "select");
    } else {
      select.addEventListener("change", (e) => {
        onChangeRef.current((e.target as HTMLSelectElement).value);
      });
    }

    return () => {
      instanceRef.current?.destroy();
      instanceRef.current = null;
      selectRef.current = null;
      container.classList.remove("selectize", "select");
      // Drop whatever selectize built (or our bare select) so the next build
      // starts clean.
      container.replaceChildren();
    };
    // Rebuild whenever the rendered options change (count or identity/labels).
  }, [optionsKey(options)]);

  // Mirror the controlled `value` without re-emitting change.
  useEffect(() => {
    const instance = instanceRef.current;
    if (instance) instance.setValue(value, true);
    else if (selectRef.current) selectRef.current.value = value;
  }, [value]);

  // Mirror the disabled (busy) state.
  useEffect(() => {
    const instance = instanceRef.current;
    if (instance) {
      if (disabled) instance.disable();
      else instance.enable();
    } else if (selectRef.current) {
      selectRef.current.disabled = disabled;
    }
  }, [disabled]);

  return (
    <label className="ai:flex ai:flex-col ai:gap-1 ai:text-xs">
      <span className="ai:font-medium ai:uppercase ai:tracking-wide ai:text-craftai-muted">
        {label}
      </span>
      {/* React owns only this container; the <select> + selectize live inside
          it and are managed imperatively (see the layout effect). */}
      <div ref={containerRef} />
    </label>
  );
}

/**
 * Stable signature of the rendered options. Drives the selectize rebuild
 * effect so it fires only when the visible choices actually change (a new
 * revision lands, a label shifts) — not on every parent render.
 */
function optionsKey(options: RevisionOption[]): string {
  return options.map((option) => `${option.ref} ${optionText(option)}`).join("");
}
