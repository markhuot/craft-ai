import CodeMirror from "@uiw/react-codemirror";
import { html } from "@codemirror/lang-html";
import { css } from "@codemirror/lang-css";
import { javascript } from "@codemirror/lang-javascript";
import { useMemo } from "react";

export type CodeLanguage = "twig" | "css" | "js";

interface CodeEditorProps {
  language: CodeLanguage;
  value: string;
  onChange: (value: string) => void;
  /** Visually de-emphasize and disable editing. We still render the value so
   * read-only viewers (or tests) can inspect it. */
  readOnly?: boolean;
}

export function CodeEditor({ language, value, onChange, readOnly = false }: CodeEditorProps) {
  // `@codemirror/lang-html` accepts plain HTML, Twig template tags, and
  // arbitrary mustache-ish syntax without choking, so it doubles as a
  // serviceable Twig highlighter without an extra dependency. Future
  // upgrade: swap in a dedicated Twig grammar if/when one we trust ships.
  const extensions = useMemo(() => {
    switch (language) {
      case "twig":
        return [html()];
      case "css":
        return [css()];
      case "js":
        return [javascript()];
    }
  }, [language]);

  return (
    <div className="craftai-code-editor" data-testid={`code-editor-${language}`}>
      <CodeMirror
        value={value}
        readOnly={readOnly}
        editable={!readOnly}
        height="280px"
        extensions={extensions}
        onChange={(next) => onChange(next)}
        // Match Craft CP's input chrome — sans-serif elsewhere, but a
        // dialed-in monospace stack inside the editor is what users expect
        // from a code field.
        basicSetup={{ lineNumbers: true, highlightActiveLineGutter: true, foldGutter: false }}
      />
    </div>
  );
}
