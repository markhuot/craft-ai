import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { CodeEditor, type CodeLanguage } from "./CodeEditor";
import { PromptTab } from "./PromptTab";
import type { FieldBootstrap, FieldValues, TabId } from "./types";

interface EditorProps {
  bootstrap: FieldBootstrap;
  /** Backing hidden inputs (one per persisted key) that we mutate every
   * time the corresponding editor state changes. Craft picks the values
   * up on form submit without us needing a custom save handler. */
  hiddenInputs: {
    twig: HTMLInputElement | null;
    css: HTMLInputElement | null;
    js: HTMLInputElement | null;
    agentSessionId: HTMLInputElement | null;
  };
  /**
   * Craft writes the CSRF token + name into a pair of globals on the page.
   * We pull from `window.Craft` if present and fall back to the bootstrap
   * fields so tests can supply their own values without monkey-patching
   * the global.
   */
  csrfTokenName: string;
  csrfTokenValue: string;
}

const TAB_LABELS: Record<TabId, string> = {
  twig: "Twig",
  css: "CSS",
  js: "JS",
  prompt: "Prompt",
};

// How often the editor polls the server for fresh tab values. The agent
// writes to the field via `update_code_component` on its own cadence,
// completely out-of-band from the form the user has open. Polling closes
// that gap so the React tabs reflect the latest persisted state. Three
// seconds is a deliberate floor: short enough that an agent write feels
// "live" in the editor, long enough that the polling load is invisible
// to a CP page that's already doing a lot.
const POLL_INTERVAL_MS = 3000;

export function Editor({ bootstrap, hiddenInputs, csrfTokenName, csrfTokenValue }: EditorProps) {
  const [values, setValues] = useState<FieldValues>(bootstrap.values);
  // Mirror of the last server snapshot we observed. Tracked separately
  // from `values` so the poll loop can tell who wrote last — if the user
  // has changed a tab locally (values[tab] !== serverValues[tab]) we
  // never clobber their in-flight edit with a server snapshot, even if
  // the server also moved.
  const serverValuesRef = useRef<FieldValues>(bootstrap.values);

  const availableTabs = useMemo<TabId[]>(() => {
    // Prompt leads the tab list so a fresh field opens onto the agent
    // chat — that's the primary surface this field exists to expose.
    // The code tabs follow in source-order (Twig → CSS → JS) for users
    // who want to inspect or hand-edit what the agent produced.
    const tabs: TabId[] = [];
    if (bootstrap.permissions.prompt) tabs.push("prompt");
    if (bootstrap.permissions.twig) tabs.push("twig");
    if (bootstrap.permissions.css) tabs.push("css");
    if (bootstrap.permissions.js) tabs.push("js");
    return tabs;
  }, [bootstrap.permissions]);

  const [activeTab, setActiveTab] = useState<TabId>(availableTabs[0] ?? "twig");

  const setCodeValue = useCallback(
    (language: CodeLanguage, next: string) => {
      setValues((prev) => ({ ...prev, [language]: next }));
      const input = hiddenInputs[language];
      if (input) {
        input.value = next;
        notifyCraftOfChange(input);
      }
      // Note: we intentionally do *not* update `serverValuesRef[language]`
      // here. Keeping that ref pinned to the last value we observed from
      // the server is what tells the polling loop "the user has edits in
      // flight — don't overwrite this tab." When Craft's autosave flushes
      // and the next poll comes back, the server snapshot will catch up
      // and the divergence resolves naturally.
    },
    [hiddenInputs],
  );

  const setSessionId = useCallback(
    (next: string | null) => {
      setValues((prev) => ({ ...prev, agentSessionId: next }));
      const input = hiddenInputs.agentSessionId;
      if (input) {
        input.value = next ?? "";
        // Craft's element-editor watches for change/input events to mark
        // the form dirty + flush an autosave. Even with the immediate
        // AJAX persist below, this dispatch is still worth doing: it
        // keeps the form's dirty state honest so the user doesn't see
        // a stale "Saved just now" badge while the field is in a state
        // that differs from what the form thinks it has.
        notifyCraftOfChange(input);
      }
      serverValuesRef.current = { ...serverValuesRef.current, agentSessionId: next };

      // Don't wait for the user to save the entry — write the session
      // pointer to disk now, while the chat is still in front of them.
      // Without this, navigating away mid-conversation would orphan the
      // thread because the agent's own writes to the field's other tabs
      // never mark the form dirty (saveElement on the agent side bypasses
      // the CP form entirely), so Craft's autosave never fires.
      const target = bootstrap.element;
      if (next !== null && target !== null && bootstrap.persist.persistSessionUrl !== "") {
        const body = new FormData();
        body.set(csrfTokenName, csrfTokenValue);
        body.set("fieldHandle", bootstrap.fieldHandle);
        body.set("sessionId", next);
        if (target.draftId !== null) {
          body.set("draftId", String(target.draftId));
        } else if (target.id !== null) {
          body.set("entryId", String(target.id));
        }
        void fetch(bootstrap.persist.persistSessionUrl, {
          method: "POST",
          headers: { Accept: "application/json" },
          credentials: "same-origin",
          body,
        }).catch(() => {
          // Best-effort: the next user-save would still flush the value
          // through the form path, and a single failed POST shouldn't
          // surface as a chat error. Server-side errors are observable
          // in Craft's request log.
        });
      }
    },
    [
      bootstrap.element,
      bootstrap.fieldHandle,
      bootstrap.persist.persistSessionUrl,
      csrfTokenName,
      csrfTokenValue,
      hiddenInputs,
    ],
  );

  // Poll the server for fresh tab values so agent-driven writes show up
  // in the editor live. The poll only runs when there's a resolved
  // element to query — a brand-new entry that hasn't been saved yet has
  // no disk row to read from, so polling would 404 on every cycle.
  useEffect(() => {
    const target = bootstrap.element;
    if (target === null) return;
    const stateUrl = bootstrap.persist.stateUrl;
    if (stateUrl === "") return;
    const hasIdentifier = target.draftId !== null || target.id !== null;
    if (!hasIdentifier) return;

    let cancelled = false;

    const poll = async () => {
      const params = new URLSearchParams();
      params.set("fieldHandle", bootstrap.fieldHandle);
      if (target.draftId !== null) {
        params.set("draftId", String(target.draftId));
      } else if (target.id !== null) {
        params.set("entryId", String(target.id));
      }
      try {
        const res = await fetch(`${stateUrl}?${params.toString()}`, {
          method: "GET",
          headers: { Accept: "application/json" },
          credentials: "same-origin",
        });
        if (!res.ok || cancelled) return;
        const fresh = (await res.json()) as Partial<FieldValues>;
        if (cancelled) return;

        // Per-tab last-writer-wins: if the server has moved since the
        // last poll AND we don't have a local edit in progress (i.e.
        // `values[tab] === serverValuesRef.current[tab]`), pull the
        // server value into the editor. Otherwise leave the local edit
        // alone — the next user save will persist it.
        const prevServer = serverValuesRef.current;
        const updates: Partial<FieldValues> = {};
        for (const tab of ["twig", "css", "js"] as const) {
          const next = typeof fresh[tab] === "string" ? (fresh[tab] as string) : prevServer[tab];
          if (next !== prevServer[tab]) {
            updates[tab] = next;
          }
        }
        const freshSession =
          typeof fresh.agentSessionId === "string" || fresh.agentSessionId === null
            ? fresh.agentSessionId ?? null
            : prevServer.agentSessionId;
        if (freshSession !== prevServer.agentSessionId) {
          updates.agentSessionId = freshSession;
        }
        if (Object.keys(updates).length === 0) return;

        setValues((current) => {
          const merged: FieldValues = { ...current };
          for (const tab of ["twig", "css", "js"] as const) {
            if (!(tab in updates)) continue;
            // Only adopt the server value when the user hasn't drifted
            // from the previous server snapshot — a divergent local
            // value means the user has unsaved edits we'd otherwise
            // erase.
            if (current[tab] !== prevServer[tab]) continue;
            const value = updates[tab] as string;
            merged[tab] = value;
            const input = hiddenInputs[tab];
            if (input) input.value = value;
          }
          if ("agentSessionId" in updates && current.agentSessionId === prevServer.agentSessionId) {
            const value = updates.agentSessionId ?? null;
            merged.agentSessionId = value;
            const input = hiddenInputs.agentSessionId;
            if (input) input.value = value ?? "";
          }
          return merged;
        });
        serverValuesRef.current = {
          ...prevServer,
          ...updates,
        } as FieldValues;
      } catch {
        // transient — next tick will try again
      }
    };

    void poll();
    const id = setInterval(poll, POLL_INTERVAL_MS);
    return () => {
      cancelled = true;
      clearInterval(id);
    };
  }, [bootstrap.element, bootstrap.fieldHandle, bootstrap.persist.stateUrl, hiddenInputs]);

  if (availableTabs.length === 0) {
    return (
      <p className="ai:m-0 ai:text-sm ai:text-craftai-muted">
        You do not have permission to edit any tabs of this field.
      </p>
    );
  }

  return (
    <div className="ai:flex ai:flex-col ai:gap-2">
      <div
        role="tablist"
        aria-label={`${bootstrap.fieldName} tabs`}
        className="ai:flex ai:items-end ai:gap-1 ai:border-b ai:border-craftai-border"
      >
        {availableTabs.map((tab) => (
          <button
            key={tab}
            type="button"
            role="tab"
            aria-selected={activeTab === tab}
            aria-controls={`${bootstrap.inputId}-panel-${tab}`}
            id={`${bootstrap.inputId}-tab-${tab}`}
            data-testid={`tab-${tab}`}
            className="craftai-tab"
            onClick={() => setActiveTab(tab)}
          >
            {TAB_LABELS[tab]}
          </button>
        ))}
      </div>

      {availableTabs.map((tab) => (
        <div
          key={tab}
          role="tabpanel"
          id={`${bootstrap.inputId}-panel-${tab}`}
          aria-labelledby={`${bootstrap.inputId}-tab-${tab}`}
          hidden={activeTab !== tab}
          data-testid={`panel-${tab}`}
        >
          {tab === "prompt" ? (
            <PromptTab
              chatUrls={bootstrap.chat}
              fieldHandle={bootstrap.fieldHandle}
              fieldName={bootstrap.fieldName}
              fieldId={bootstrap.fieldId}
              element={bootstrap.element}
              values={values}
              agentSessionId={values.agentSessionId}
              onSessionMinted={setSessionId}
              csrfTokenName={csrfTokenName}
              csrfTokenValue={csrfTokenValue}
            />
          ) : (
            <CodeEditor
              language={tab as CodeLanguage}
              value={values[tab as CodeLanguage]}
              onChange={(next) => setCodeValue(tab as CodeLanguage, next)}
            />
          )}
        </div>
      ))}
    </div>
  );
}

/**
 * Fire the `input` + `change` event pair Craft's CP element-editor listens
 * for so it marks the entry dirty and (if it's running on a provisional
 * draft) flushes an autosave. Both events bubble so the editor's delegated
 * listener at the form level picks them up. Without this, mutations done
 * via `input.value = …` are invisible to autosave and the session pointer
 * would only persist when the user explicitly saved the entry.
 */
function notifyCraftOfChange(input: HTMLInputElement): void {
  input.dispatchEvent(new Event("input", { bubbles: true }));
  input.dispatchEvent(new Event("change", { bubbles: true }));
}
