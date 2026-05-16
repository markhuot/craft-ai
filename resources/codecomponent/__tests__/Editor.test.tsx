import { afterEach, describe, expect, test } from "bun:test";
import { cleanup, fireEvent, render, screen } from "@testing-library/react";
import { Editor } from "../Editor";
import type { FieldBootstrap } from "../types";

afterEach(() => cleanup());

function bootstrap(overrides: Partial<FieldBootstrap> = {}): FieldBootstrap {
  return {
    inputId: "fields-component",
    fieldId: 42,
    fieldHandle: "component",
    fieldName: "Component",
    inputName: "fields[component]",
    permissions: { twig: true, css: true, js: true, prompt: true },
    values: { twig: "TWIG", css: "CSS", js: "JS", agentSessionId: null },
    element: { type: "entry", id: 100, title: "Page", sectionHandle: "pages" },
    chat: {
      messagesUrl: "/messages",
      sendUrl: "/send",
      sessionsUrl: "/sessions",
      newSessionUrl: "/sessions/new",
      sessionsIndexUrl: "/ai/sessions",
      previewRespondUrl: "/preview/respond",
      toolModeUrl: "/tool-mode",
      updateToolModeUrl: "/update-tool-mode",
    },
    ...overrides,
  };
}

function makeHiddenInputs() {
  const twig = document.createElement("input");
  const css = document.createElement("input");
  const js = document.createElement("input");
  const agentSessionId = document.createElement("input");
  return { twig, css, js, agentSessionId };
}

describe("CodeComponent editor", () => {
  test("renders one tab per permitted area", () => {
    render(
      <Editor
        bootstrap={bootstrap()}
        hiddenInputs={makeHiddenInputs()}
        csrfTokenName="CRAFT_CSRF"
        csrfTokenValue="tok"
      />,
    );

    expect(screen.getByTestId("tab-twig")).toBeDefined();
    expect(screen.getByTestId("tab-css")).toBeDefined();
    expect(screen.getByTestId("tab-js")).toBeDefined();
    expect(screen.getByTestId("tab-prompt")).toBeDefined();
  });

  test("hides tabs the user lacks permission for", () => {
    render(
      <Editor
        bootstrap={bootstrap({
          permissions: { twig: true, css: false, js: false, prompt: true },
        })}
        hiddenInputs={makeHiddenInputs()}
        csrfTokenName="CRAFT_CSRF"
        csrfTokenValue="tok"
      />,
    );

    expect(screen.getByTestId("tab-twig")).toBeDefined();
    expect(screen.queryByTestId("tab-css")).toBeNull();
    expect(screen.queryByTestId("tab-js")).toBeNull();
    expect(screen.getByTestId("tab-prompt")).toBeDefined();
  });

  test("renders a deny message when no tabs are permitted", () => {
    render(
      <Editor
        bootstrap={bootstrap({
          permissions: { twig: false, css: false, js: false, prompt: false },
        })}
        hiddenInputs={makeHiddenInputs()}
        csrfTokenName="CRAFT_CSRF"
        csrfTokenValue="tok"
      />,
    );

    expect(screen.getByText(/do not have permission/i)).toBeDefined();
  });

  test("clicking a tab switches the visible panel", () => {
    render(
      <Editor
        bootstrap={bootstrap()}
        hiddenInputs={makeHiddenInputs()}
        csrfTokenName="CRAFT_CSRF"
        csrfTokenValue="tok"
      />,
    );

    // Twig panel is the default per the tab order; CSS is hidden until clicked.
    const cssPanel = screen.getByTestId("panel-css");
    expect(cssPanel.hidden).toBe(true);

    fireEvent.click(screen.getByTestId("tab-css"));

    expect(screen.getByTestId("panel-css").hidden).toBe(false);
    expect(screen.getByTestId("panel-twig").hidden).toBe(true);
  });

  test("opening the Prompt tab without a session shows the Start chatting affordance", () => {
    render(
      <Editor
        bootstrap={bootstrap()}
        hiddenInputs={makeHiddenInputs()}
        csrfTokenName="CRAFT_CSRF"
        csrfTokenValue="tok"
      />,
    );

    fireEvent.click(screen.getByTestId("tab-prompt"));
    expect(screen.getByRole("button", { name: /start chatting/i })).toBeDefined();
  });

  test("does not show the Start affordance once an agentSessionId is already present", () => {
    render(
      <Editor
        bootstrap={bootstrap({
          values: { twig: "", css: "", js: "", agentSessionId: "session-uuid" },
        })}
        hiddenInputs={makeHiddenInputs()}
        csrfTokenName="CRAFT_CSRF"
        csrfTokenValue="tok"
      />,
    );

    fireEvent.click(screen.getByTestId("tab-prompt"));
    // The mounted Chat surface should appear; the empty-state CTA should not.
    expect(screen.queryByRole("button", { name: /start chatting/i })).toBeNull();
    expect(screen.getByTestId("prompt-shell")).toBeDefined();
  });
});
