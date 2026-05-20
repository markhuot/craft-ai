import { afterEach, describe, expect, test } from "bun:test";
import { act, cleanup, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { Widget } from "../Widget";
import { WidgetApi } from "../api";
import type { WidgetBootstrap } from "../types";
import type { SessionListItem } from "../../chat/types";

afterEach(() => cleanup());

function bootstrap(): WidgetBootstrap {
  return {
    jsUrl: "/cpresources/widget.js",
    cssUrl: "/cpresources/widget.css",
    sessionsUrl: "http://localhost/sessions/data",
    newSessionUrl: "http://localhost/sessions/new",
    sessionsIndexUrl: "http://localhost/cp/ai/sessions",
    messagesUrl: "http://localhost/messages",
    sendUrl: "http://localhost/sessions/send",
    previewRespondUrl: "http://localhost/preview/respond",
    toolModeUrl: "http://localhost/tool-mode",
    updateToolModeUrl: "http://localhost/update-tool-mode",
    csrfTokenName: "CRAFT_CSRF",
    csrfTokenValue: "tok",
    context: {
      surface: "site",
      url: null,
      path: null,
      query: {},
      siteHandle: null,
      template: null,
      element: null,
    },
    contextFingerprint: "",
  };
}

interface FakeApiOpts {
  fetchSessions?: () => Promise<SessionListItem[]>;
  createSession?: () => Promise<string>;
}

function makeApi(opts: FakeApiOpts = {}): WidgetApi {
  const api = new WidgetApi({
    bootstrap: bootstrap(),
    fetchImpl: async () => new Response("{}", { status: 200 }),
  });
  if (opts.fetchSessions) {
    api.fetchSessions = opts.fetchSessions;
  }
  if (opts.createSession) {
    api.createSession = opts.createSession;
  }
  return api;
}

function makeStorage(initial: Record<string, string> = {}) {
  const data = new Map<string, string>(Object.entries(initial));
  return {
    getItem: (k: string) => data.get(k) ?? null,
    setItem: (k: string, v: string) => {
      data.set(k, v);
    },
    removeItem: (k: string) => {
      data.delete(k);
    },
    snapshot: () => Object.fromEntries(data),
  };
}

const sampleSessions: SessionListItem[] = [
  {
    sessionId: "session-recent",
    url: "http://localhost/cp/ai/session/session-recent",
    title: "Most recent",
    active: false,
    messageCount: 3,
    firstMessage: "1m ago",
    lastMessage: "1m ago",
  },
  {
    sessionId: "session-old",
    url: "http://localhost/cp/ai/session/session-old",
    title: "Older one",
    active: false,
    messageCount: 1,
    firstMessage: "yesterday",
    lastMessage: "yesterday",
  },
];

describe("<Widget />", () => {
  test("renders the FAB bubble when closed", () => {
    render(<Widget bootstrap={bootstrap()} api={makeApi()} storage={makeStorage()} />);
    const bubble = screen.getByTestId("widget-bubble");
    expect(bubble).toBeTruthy();
    expect(screen.getByTestId("widget-root").getAttribute("data-view")).toBe("closed");
  });

  test("clicking the bubble opens to the stored session when it still exists", async () => {
    const storage = makeStorage({ "craftai-widget:active-session": "session-old" });
    const api = makeApi({
      fetchSessions: async () => sampleSessions,
    });

    render(<Widget bootstrap={bootstrap()} api={api} storage={storage} />);
    await act(async () => {
      fireEvent.click(screen.getByTestId("widget-bubble"));
    });

    await waitFor(() => {
      expect(screen.getByTestId("widget-root").getAttribute("data-view")).toBe("chat");
    });
    expect(screen.getByTestId("widget-panel")).toBeTruthy();
    expect(storage.snapshot()["craftai-widget:active-session"]).toBe("session-old");
  });

  test("falls back to the most-recent server session when no preference is stored", async () => {
    const storage = makeStorage();
    const api = makeApi({
      fetchSessions: async () => sampleSessions,
    });

    render(<Widget bootstrap={bootstrap()} api={api} storage={storage} />);
    await act(async () => {
      fireEvent.click(screen.getByTestId("widget-bubble"));
    });

    await waitFor(() => {
      expect(storage.snapshot()["craftai-widget:active-session"]).toBe("session-recent");
    });
    expect(screen.getByTestId("widget-root").getAttribute("data-view")).toBe("chat");
  });

  test("creates a brand-new session when the user has none", async () => {
    let created = 0;
    const storage = makeStorage();
    const api = makeApi({
      fetchSessions: async () => [],
      createSession: async () => {
        created += 1;
        return "session-new";
      },
    });

    render(<Widget bootstrap={bootstrap()} api={api} storage={storage} />);
    await act(async () => {
      fireEvent.click(screen.getByTestId("widget-bubble"));
    });

    await waitFor(() => {
      expect(created).toBe(1);
      expect(storage.snapshot()["craftai-widget:active-session"]).toBe("session-new");
    });
  });

  test("back button switches to the sessions list view", async () => {
    const storage = makeStorage({ "craftai-widget:active-session": "session-recent" });
    const api = makeApi({
      fetchSessions: async () => sampleSessions,
    });

    render(<Widget bootstrap={bootstrap()} api={api} storage={storage} />);
    await act(async () => {
      fireEvent.click(screen.getByTestId("widget-bubble"));
    });
    await waitFor(() =>
      expect(screen.getByTestId("widget-root").getAttribute("data-view")).toBe("chat"),
    );

    await act(async () => {
      fireEvent.click(screen.getByTestId("widget-back"));
    });

    await waitFor(() =>
      expect(screen.getByTestId("widget-root").getAttribute("data-view")).toBe("sessions"),
    );
    expect(screen.getByTestId("widget-sessions")).toBeTruthy();
    expect(screen.getByText("Most recent")).toBeTruthy();
    expect(screen.getByText("Older one")).toBeTruthy();
  });

  test("back button is hidden while the sessions list is showing", async () => {
    const storage = makeStorage({ "craftai-widget:active-session": "session-recent" });
    const api = makeApi({
      fetchSessions: async () => sampleSessions,
    });

    render(<Widget bootstrap={bootstrap()} api={api} storage={storage} />);
    await act(async () => {
      fireEvent.click(screen.getByTestId("widget-bubble"));
    });
    await waitFor(() => screen.getByTestId("widget-back"));

    await act(async () => {
      fireEvent.click(screen.getByTestId("widget-back"));
    });

    await waitFor(() =>
      expect(screen.getByTestId("widget-root").getAttribute("data-view")).toBe("sessions"),
    );
    expect(screen.queryByTestId("widget-back")).toBeNull();
  });

  test("selecting a session from the picker swaps the active id and returns to chat", async () => {
    const storage = makeStorage({ "craftai-widget:active-session": "session-recent" });
    const api = makeApi({
      fetchSessions: async () => sampleSessions,
    });

    render(<Widget bootstrap={bootstrap()} api={api} storage={storage} />);
    await act(async () => {
      fireEvent.click(screen.getByTestId("widget-bubble"));
    });
    await waitFor(() =>
      expect(screen.getByTestId("widget-root").getAttribute("data-view")).toBe("chat"),
    );

    await act(async () => {
      fireEvent.click(screen.getByTestId("widget-back"));
    });
    await waitFor(() => screen.getByTestId("widget-sessions"));

    await act(async () => {
      fireEvent.click(screen.getByRole("button", { name: /Older one/i }));
    });

    await waitFor(() => {
      expect(screen.getByTestId("widget-root").getAttribute("data-view")).toBe("chat");
      expect(storage.snapshot()["craftai-widget:active-session"]).toBe("session-old");
    });
  });

  test("close button collapses back to the bubble", async () => {
    const storage = makeStorage({ "craftai-widget:active-session": "session-recent" });
    const api = makeApi({
      fetchSessions: async () => sampleSessions,
    });

    render(<Widget bootstrap={bootstrap()} api={api} storage={storage} />);
    await act(async () => {
      fireEvent.click(screen.getByTestId("widget-bubble"));
    });
    await waitFor(() =>
      expect(screen.getByTestId("widget-root").getAttribute("data-view")).toBe("chat"),
    );

    await act(async () => {
      fireEvent.click(screen.getByTestId("widget-close"));
    });

    expect(screen.getByTestId("widget-root").getAttribute("data-view")).toBe("closed");
    expect(screen.getByTestId("widget-bubble")).toBeTruthy();
  });

  test("'New session' button inside the picker creates and selects a session", async () => {
    const storage = makeStorage();
    let createdId = "session-fresh";
    const api = makeApi({
      fetchSessions: async () => sampleSessions,
      createSession: async () => createdId,
    });

    render(<Widget bootstrap={bootstrap()} api={api} storage={storage} />);
    await act(async () => {
      fireEvent.click(screen.getByTestId("widget-bubble"));
    });
    await waitFor(() =>
      expect(screen.getByTestId("widget-root").getAttribute("data-view")).toBe("chat"),
    );

    await act(async () => {
      fireEvent.click(screen.getByTestId("widget-back"));
    });
    await waitFor(() => screen.getByTestId("widget-sessions"));

    createdId = "session-fresh";
    await act(async () => {
      fireEvent.click(screen.getByTestId("widget-new-session"));
    });

    await waitFor(() => {
      expect(storage.snapshot()["craftai-widget:active-session"]).toBe("session-fresh");
      expect(screen.getByTestId("widget-root").getAttribute("data-view")).toBe("chat");
    });
  });

  test("restores the open state from localStorage on mount", async () => {
    const storage = makeStorage({
      "craftai-widget:open": "true",
      "craftai-widget:active-session": "session-recent",
    });
    const api = makeApi({
      fetchSessions: async () => sampleSessions,
    });

    await act(async () => {
      render(<Widget bootstrap={bootstrap()} api={api} storage={storage} />);
    });

    await waitFor(() => {
      expect(screen.getByTestId("widget-root").getAttribute("data-view")).toBe("chat");
    });
    expect(screen.getByTestId("widget-panel")).toBeTruthy();
    expect(screen.queryByTestId("widget-bubble")).toBeNull();
  });

  test("stays closed on mount when no open state is persisted", () => {
    const storage = makeStorage();
    render(<Widget bootstrap={bootstrap()} api={makeApi()} storage={storage} />);
    expect(screen.getByTestId("widget-root").getAttribute("data-view")).toBe("closed");
    expect(screen.getByTestId("widget-bubble")).toBeTruthy();
  });

  test("persists open=true to localStorage when the bubble is clicked", async () => {
    const storage = makeStorage();
    const api = makeApi({ fetchSessions: async () => sampleSessions });

    render(<Widget bootstrap={bootstrap()} api={api} storage={storage} />);
    await act(async () => {
      fireEvent.click(screen.getByTestId("widget-bubble"));
    });

    await waitFor(() => {
      expect(storage.snapshot()["craftai-widget:open"]).toBe("true");
    });
  });

  test("clears the open flag when the widget is closed", async () => {
    const storage = makeStorage({
      "craftai-widget:open": "true",
      "craftai-widget:active-session": "session-recent",
    });
    const api = makeApi({ fetchSessions: async () => sampleSessions });

    await act(async () => {
      render(<Widget bootstrap={bootstrap()} api={api} storage={storage} />);
    });
    await waitFor(() =>
      expect(screen.getByTestId("widget-root").getAttribute("data-view")).toBe("chat"),
    );

    await act(async () => {
      fireEvent.click(screen.getByTestId("widget-close"));
    });

    expect(storage.snapshot()["craftai-widget:open"]).toBeUndefined();
    expect(screen.getByTestId("widget-root").getAttribute("data-view")).toBe("closed");
  });

  test("surfaces an error message when sessions fail to load", async () => {
    const api = makeApi({
      fetchSessions: async () => {
        throw new Error("network down");
      },
      createSession: async () => {
        throw new Error("network down");
      },
    });

    render(<Widget bootstrap={bootstrap()} api={api} storage={makeStorage()} />);
    await act(async () => {
      fireEvent.click(screen.getByTestId("widget-bubble"));
    });

    await waitFor(() => {
      expect(screen.getByTestId("widget-error").textContent).toContain("network down");
    });
  });

  test("opens the comment composer when craftai:start-comment fires", async () => {
    const api = makeApi({ createSession: async () => "ignored" });
    api.fetchToolMode = async () => ({
      toolMode: "full",
      enabledTools: null,
      availableTools: [],
    });
    api.fetchAssetInfo = async () => [];
    render(<Widget bootstrap={bootstrap()} api={api} storage={makeStorage()} />);

    await act(async () => {
      document.dispatchEvent(
        new CustomEvent("craftai:start-comment", {
          detail: {
            elementId: 42,
            isDraft: false,
            fieldHandle: "bodyContent",
            referenceId: "ref-1",
            selectionText: "the third paragraph",
          },
        }),
      );
    });

    await waitFor(() => {
      expect(screen.getByTestId("widget-compose-comment")).toBeTruthy();
    });
    expect(screen.getByText(/bodyContent/)).toBeTruthy();
    expect(screen.getByText(/the third paragraph/)).toBeTruthy();
    expect(screen.getByTestId("widget-compose-comment-textarea")).toBeTruthy();
  });

  test("submitting the composer posts the comment and dispatches craftai:comment-created", async () => {
    const postLog: { body: string; referenceId: string; sessionId?: string }[] = [];
    const api = makeApi({ createSession: async () => "pre-created-session" });
    api.fetchToolMode = async () => ({
      toolMode: "full",
      enabledTools: null,
      availableTools: [],
    });
    api.fetchAssetInfo = async () => [];
    api.createComment = async (draft, body, opts) => {
      postLog.push({ body, referenceId: draft.referenceId, sessionId: opts?.sessionId });
      return {
        id: 7,
        referenceId: draft.referenceId,
        sessionId: opts?.sessionId ?? "fresh-session",
      };
    };

    const events: CustomEvent[] = [];
    const listener = (e: Event) => events.push(e as CustomEvent);
    document.addEventListener("craftai:comment-created", listener);

    try {
      render(<Widget bootstrap={bootstrap()} api={api} storage={makeStorage()} />);

      await act(async () => {
        document.dispatchEvent(
          new CustomEvent("craftai:start-comment", {
            detail: {
              elementId: 9,
              isDraft: false,
              fieldHandle: "summary",
              referenceId: "ref-xyz",
              selectionText: "hello",
            },
          }),
        );
      });

      await waitFor(() =>
        expect(screen.getByTestId("widget-compose-comment-textarea")).toBeTruthy(),
      );

      const ta = screen.getByTestId("widget-compose-comment-textarea") as HTMLTextAreaElement;
      await act(async () => {
        fireEvent.change(ta, { target: { value: "rewrite this section" } });
      });

      await act(async () => {
        fireEvent.click(screen.getByTestId("widget-compose-comment-submit"));
      });

      await waitFor(() => {
        expect(postLog.length).toBe(1);
      });
      expect(postLog[0]?.body).toBe("rewrite this section");
      expect(postLog[0]?.referenceId).toBe("ref-xyz");
      // The composer pre-creates a session on mount and reuses it
      // for the post — sessionId should match the one createSession
      // returned (not a server-minted one).
      expect(postLog[0]?.sessionId).toBe("pre-created-session");
      expect(events.length).toBe(1);
      expect(events[0]?.detail).toMatchObject({
        commentId: 7,
        referenceId: "ref-xyz",
        elementId: 9,
        fieldHandle: "summary",
        sessionId: "pre-created-session",
      });
      // Post-submit, the widget should hand the user off to the
      // comment's own session — not back to whatever was active
      // before — so they can continue the conversation in place.
      await waitFor(() =>
        expect(screen.getByTestId("widget-root").getAttribute("data-view")).toBe("chat"),
      );
    } finally {
      document.removeEventListener("craftai:comment-created", listener);
    }
  });

  test("cancel button exits the composer without posting", async () => {
    let postCount = 0;
    const api = makeApi({ createSession: async () => "ignored" });
    api.fetchToolMode = async () => ({
      toolMode: "full",
      enabledTools: null,
      availableTools: [],
    });
    api.fetchAssetInfo = async () => [];
    api.createComment = async () => {
      postCount += 1;
      return { id: 1, referenceId: "ref", sessionId: "s" };
    };

    render(<Widget bootstrap={bootstrap()} api={api} storage={makeStorage()} />);

    await act(async () => {
      document.dispatchEvent(
        new CustomEvent("craftai:start-comment", {
          detail: {
            elementId: 1,
            isDraft: false,
            fieldHandle: "f",
            referenceId: "ref",
            selectionText: "",
          },
        }),
      );
    });

    await waitFor(() => screen.getByTestId("widget-compose-comment"));

    await act(async () => {
      fireEvent.click(screen.getByText("Cancel"));
    });

    expect(screen.queryByTestId("widget-compose-comment")).toBeNull();
    expect(postCount).toBe(0);
  });
});
