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
    element: {
      type: "entry",
      id: 100,
      title: "Page",
      sectionHandle: "pages",
      isDraft: false,
      isProvisionalDraft: false,
      draftId: null,
      canonicalId: null,
      ownerId: null,
    },
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
    persist: {
      stateUrl: "/code-component/state",
      persistSessionUrl: "/code-component/persist-session",
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

    // Prompt is the default per the tab order; the code tabs are hidden
    // until the user picks one.
    expect(screen.getByTestId("panel-prompt").hidden).toBe(false);
    expect(screen.getByTestId("panel-css").hidden).toBe(true);
    expect(screen.getByTestId("panel-twig").hidden).toBe(true);

    fireEvent.click(screen.getByTestId("tab-css"));

    expect(screen.getByTestId("panel-css").hidden).toBe(false);
    expect(screen.getByTestId("panel-prompt").hidden).toBe(true);
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

  test("pulls fresh server-side tab values into the editor on its initial poll", async () => {
    const inputs = makeHiddenInputs();

    const originalFetch = global.fetch;
    global.fetch = (async (url: RequestInfo | URL) => {
      const u = typeof url === "string" ? url : url.toString();
      if (u.includes("/code-component/state")) {
        // Simulate the agent having just written through the
        // update_code_component tool, so the persisted Twig is now ahead
        // of the React state.
        return new Response(
          JSON.stringify({
            twig: "<h1>AGENT WROTE THIS</h1>",
            css: "CSS",
            js: "JS",
            agentSessionId: null,
          }),
          { status: 200, headers: { "Content-Type": "application/json" } },
        );
      }
      return new Response("", { status: 404 });
    }) as typeof fetch;

    try {
      render(
        <Editor
          bootstrap={bootstrap()}
          hiddenInputs={inputs}
          csrfTokenName="CRAFT_CSRF"
          csrfTokenValue="tok"
        />,
      );

      // Switch to the Twig tab and wait for the poll to land.
      fireEvent.click(screen.getByTestId("tab-twig"));

      await new Promise<void>((resolve) => setTimeout(resolve, 50));
      await new Promise<void>((resolve) => setTimeout(resolve, 0));

      // The polled value should have replaced the bootstrap value in both
      // the visible editor and the hidden input that the form will
      // serialize on save.
      expect(inputs.twig.value).toBe("<h1>AGENT WROTE THIS</h1>");
    } finally {
      global.fetch = originalFetch;
    }
  });

  test("skips the polling loop entirely when the element hasn't been saved yet", async () => {
    // A fresh entry that hasn't been saved has no `element` snapshot in
    // the bootstrap, so the editor has nothing to poll for. The loop
    // must short-circuit instead of firing requests against a null id.
    const inputs = makeHiddenInputs();
    let calls = 0;
    const originalFetch = global.fetch;
    global.fetch = (async () => {
      calls++;
      return new Response("{}", { status: 200, headers: { "Content-Type": "application/json" } });
    }) as typeof fetch;

    try {
      render(
        <Editor
          bootstrap={bootstrap({ element: null })}
          hiddenInputs={inputs}
          csrfTokenName="CRAFT_CSRF"
          csrfTokenValue="tok"
        />,
      );

      await new Promise<void>((resolve) => setTimeout(resolve, 50));
      expect(calls).toBe(0);
    } finally {
      global.fetch = originalFetch;
    }
  });

  test("dispatches input + change on the hidden agentSessionId input after the chat creates a session", async () => {
    const inputs = makeHiddenInputs();
    const events: string[] = [];
    inputs.agentSessionId.addEventListener("input", () => events.push("input"));
    inputs.agentSessionId.addEventListener("change", () => events.push("change"));

    // Stub fetch so the PromptTab's "Start chatting" button gets back a
    // real session id and writes it through to the hidden input.
    const originalFetch = global.fetch;
    global.fetch = (async () =>
      new Response(JSON.stringify({ sessionId: "abc-123" }), {
        status: 200,
        headers: { "Content-Type": "application/json" },
      })) as typeof fetch;

    try {
      render(
        <Editor
          bootstrap={bootstrap({
            permissions: { twig: false, css: false, js: false, prompt: true },
          })}
          hiddenInputs={inputs}
          csrfTokenName="CRAFT_CSRF"
          csrfTokenValue="tok"
        />,
      );

      const startBtn = await screen.findByRole("button", { name: /start chatting/i });
      fireEvent.click(startBtn);

      // Wait for the async fetch + setSessionId to settle.
      await screen.findByTestId("prompt-shell");

      expect(inputs.agentSessionId.value).toBe("abc-123");
      // Craft listens for either event — both are dispatched so the
      // element-editor flushes an autosave for the surrounding entry.
      expect(events).toContain("input");
      expect(events).toContain("change");
    } finally {
      global.fetch = originalFetch;
    }
  });

  test("fingerprint changes when any tab value changes", async () => {
    const { fingerprint } = await import("../PromptTab");
    const base = {
      element: null,
      fieldAuthor: {
        kind: "code-component-field",
        fieldHandle: "component",
        fieldName: "Component",
        fieldId: 42,
        currentValues: { twig: "", css: "", js: "" },
      },
    };
    const a = fingerprint(base);
    const b = fingerprint({
      ...base,
      fieldAuthor: {
        ...base.fieldAuthor,
        currentValues: { twig: "<h1></h1>", css: "", js: "" },
      },
    });
    expect(a).not.toBe(b);
    expect(a.length).toBeGreaterThan(0);
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
