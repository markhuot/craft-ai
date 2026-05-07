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
});
