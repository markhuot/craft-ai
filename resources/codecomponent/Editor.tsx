import { useCallback, useMemo, useState } from "react";
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

export function Editor({ bootstrap, hiddenInputs, csrfTokenName, csrfTokenValue }: EditorProps) {
  const [values, setValues] = useState<FieldValues>(bootstrap.values);

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
      if (input) input.value = next;
    },
    [hiddenInputs],
  );

  const setSessionId = useCallback(
    (next: string | null) => {
      setValues((prev) => ({ ...prev, agentSessionId: next }));
      const input = hiddenInputs.agentSessionId;
      if (input) input.value = next ?? "";
    },
    [hiddenInputs],
  );

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
