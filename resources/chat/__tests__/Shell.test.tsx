import { afterEach, describe, expect, test } from "bun:test";
import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { SessionsSidebar, Shell } from "../Shell";
import type { ChatBootstrap, MessagesResponse, SessionListItem } from "../types";

const originalFetch = globalThis.fetch;

afterEach(() => {
  cleanup();
  window.localStorage.clear();
  globalThis.fetch = originalFetch;
});

function mockFetch(handler: (url: string) => unknown) {
  globalThis.fetch = ((input: string | URL | Request) => {
    const url =
      typeof input === "string"
        ? input
        : input instanceof URL
          ? input.href
          : input.url;
    const body = handler(url);
    return Promise.resolve(
      new Response(typeof body === "string" ? body : JSON.stringify(body ?? {}), {
        status: 200,
      }),
    );
  }) as typeof fetch;
}

function bootstrap(
  overrides: Partial<ChatBootstrap> & { sessions?: SessionListItem[] } = {},
): ChatBootstrap {
  const { sessions, ...rest } = overrides;
  return {
    sessionId: "session-1",
    messagesUrl: "http://localhost/messages",
    sendUrl: "http://localhost/send",
    sessionsUrl: "http://localhost/sessions/data",
    newSessionUrl: "http://localhost/sessions/new",
    sessionsIndexUrl: "http://localhost/sessions",
    assetsInfoUrl: "http://localhost/assets/info",
    previewRespondUrl: "http://localhost/preview/respond",
    csrfTokenName: "CRAFT_CSRF",
    csrfTokenValue: "tok",
    initialMessages: [],
    initialSessions: sessions ?? [
      {
        sessionId: "session-1",
        title: "First",
        url: "http://localhost/sessions/session-1",
        active: false,
        messageCount: 0,
        firstMessage: "",
        lastMessage: "",
      },
    ],
    ...rest,
  };
}

describe("<Shell />", () => {
  test("sidebar starts closed on mobile (data-open=false)", () => {
    render(<Shell bootstrap={bootstrap()} pollIntervalMs={1_000_000} />);
    const sidebar = screen.getByTestId("sessions-sidebar");
    expect(sidebar.getAttribute("data-open")).toBe("false");
  });

  test("clicking the toggle opens the sidebar", () => {
    render(<Shell bootstrap={bootstrap()} pollIntervalMs={1_000_000} />);
    const toggle = screen.getByTestId("sidebar-toggle");
    expect(toggle.getAttribute("aria-expanded")).toBe("false");

    fireEvent.click(toggle);

    expect(toggle.getAttribute("aria-expanded")).toBe("true");
    expect(screen.getByTestId("sessions-sidebar").getAttribute("data-open")).toBe("true");
  });

  test("clicking the backdrop closes the sidebar", () => {
    render(<Shell bootstrap={bootstrap()} pollIntervalMs={1_000_000} />);
    fireEvent.click(screen.getByTestId("sidebar-toggle"));
    expect(screen.getByTestId("sessions-sidebar").getAttribute("data-open")).toBe("true");

    fireEvent.click(screen.getByTestId("sidebar-backdrop"));

    expect(screen.getByTestId("sessions-sidebar").getAttribute("data-open")).toBe("false");
  });

  test("clicking the close button inside the drawer closes the sidebar", () => {
    render(<Shell bootstrap={bootstrap()} pollIntervalMs={1_000_000} />);
    fireEvent.click(screen.getByTestId("sidebar-toggle"));

    fireEvent.click(screen.getByRole("button", { name: /close sessions/i }));

    expect(screen.getByTestId("sessions-sidebar").getAttribute("data-open")).toBe("false");
  });

  test("pressing Escape closes the sidebar when open", () => {
    render(<Shell bootstrap={bootstrap()} pollIntervalMs={1_000_000} />);
    fireEvent.click(screen.getByTestId("sidebar-toggle"));
    expect(screen.getByTestId("sessions-sidebar").getAttribute("data-open")).toBe("true");

    fireEvent.keyDown(document, { key: "Escape" });

    expect(screen.getByTestId("sessions-sidebar").getAttribute("data-open")).toBe("false");
  });

  test("clicking a session link closes the sidebar so chat is visible on mobile", () => {
    render(<Shell bootstrap={bootstrap()} pollIntervalMs={1_000_000} />);
    fireEvent.click(screen.getByTestId("sidebar-toggle"));
    expect(screen.getByTestId("sessions-sidebar").getAttribute("data-open")).toBe("true");

    const link = screen.getByRole("link", { name: /First/i });
    fireEvent.click(link);

    expect(screen.getByTestId("sessions-sidebar").getAttribute("data-open")).toBe("false");
  });
});

describe("<SessionsSidebar /> desktop collapse states", () => {
  function defaultProps() {
    return {
      sessions: [
        {
          sessionId: "s1",
          title: "S1",
          url: "http://localhost/sessions/s1",
          active: false,
          messageCount: 0,
          firstMessage: "",
          lastMessage: "",
        },
      ] as SessionListItem[],
      currentSessionId: "s1",
      newSessionUrl: "http://localhost/sessions/new",
      csrfTokenName: "CRAFT_CSRF",
      csrfTokenValue: "tok",
      isOpen: false,
      onClose: () => {},
    };
  }

  test("not collapsed and no onDesktopCollapse → no expand or collapse buttons", () => {
    render(
      <SessionsSidebar
        {...defaultProps()}
        desktopCollapsed={false}
        onDesktopExpand={() => {}}
      />,
    );
    expect(screen.queryByTestId("sessions-collapsed")).toBeNull();
    expect(screen.queryByTestId("sessions-expand")).toBeNull();
    expect(screen.queryByTestId("sessions-collapse")).toBeNull();
    expect(screen.getByTestId("sessions-sidebar").dataset.desktopCollapsed).toBe("false");
  });

  test("not collapsed but onDesktopCollapse provided → renders collapse toggle", () => {
    let collapsed = 0;
    render(
      <SessionsSidebar
        {...defaultProps()}
        desktopCollapsed={false}
        onDesktopExpand={() => {}}
        onDesktopCollapse={() => {
          collapsed += 1;
        }}
      />,
    );
    const btn = screen.getByTestId("sessions-collapse");
    fireEvent.click(btn);
    expect(collapsed).toBe(1);
  });

  test("collapsed → hides the sidebar list and renders the expand strip", () => {
    let expanded = 0;
    render(
      <SessionsSidebar
        {...defaultProps()}
        desktopCollapsed
        onDesktopExpand={() => {
          expanded += 1;
        }}
      />,
    );
    const sidebar = screen.getByTestId("sessions-sidebar");
    expect(sidebar.dataset.desktopCollapsed).toBe("true");
    // The collapsed strip is the only way back to the full sidebar.
    const strip = screen.getByTestId("sessions-collapsed");
    expect(strip).toBeTruthy();
    fireEvent.click(screen.getByTestId("sessions-expand"));
    expect(expanded).toBe(1);
  });
});

describe("<Shell /> sidebar collapse driven by chat preview state", () => {
  function shellBootstrap(): ChatBootstrap {
    return {
      sessionId: "session-1",
      messagesUrl: "http://localhost/messages",
      sendUrl: "http://localhost/send",
      sessionsUrl: "http://localhost/sessions/data",
      newSessionUrl: "http://localhost/sessions/new",
      sessionsIndexUrl: "http://localhost/sessions",
      assetsInfoUrl: "http://localhost/assets/info",
      previewRespondUrl: "http://localhost/preview/respond",
      csrfTokenName: "CRAFT_CSRF",
      csrfTokenValue: "tok",
      initialMessages: [],
      initialSessions: [
        {
          sessionId: "session-1",
          title: "First",
          url: "http://localhost/sessions/session-1",
          active: false,
          messageCount: 0,
          firstMessage: "",
          lastMessage: "",
        },
      ],
    };
  }

  function setupPreviewFetch() {
    let pollCount = 0;
    mockFetch((url) => {
      if (url.includes("/messages")) {
        pollCount += 1;
        const envelope: MessagesResponse = {
          messages: [],
          previewRequest:
            pollCount === 1
              ? {
                  id: 1,
                  type: "open",
                  status: "pending",
                  input: { url: "https://example.com" },
                }
              : null,
          lastPreviewUrl: null,
        };
        return envelope;
      }
      if (url.includes("/sessions/data")) return { sessions: [] };
      return {};
    });
  }

  test("starts with the sidebar visible and no expand strip", async () => {
    mockFetch(() => ({ messages: [], previewRequest: null, lastPreviewUrl: null }));
    render(<Shell bootstrap={shellBootstrap()} pollIntervalMs={1_000_000} />);
    expect(screen.getByTestId("sessions-sidebar").dataset.desktopCollapsed).toBe("false");
    expect(screen.queryByTestId("sessions-collapsed")).toBeNull();
    // Collapse toggle is always rendered now, not just while peek is active.
    expect(screen.getByTestId("sessions-collapse")).toBeTruthy();
  });

  test("clicking the collapse toggle hides the sidebar even without a preview open", async () => {
    mockFetch(() => ({ messages: [], previewRequest: null, lastPreviewUrl: null }));
    render(<Shell bootstrap={shellBootstrap()} pollIntervalMs={1_000_000} />);

    fireEvent.click(screen.getByTestId("sessions-collapse"));

    expect(screen.getByTestId("sessions-sidebar").dataset.desktopCollapsed).toBe("true");
    expect(screen.getByTestId("sessions-expand")).toBeTruthy();
  });

  test("collapses the sidebar when chat enters peek mode and shows the expand button", async () => {
    setupPreviewFetch();
    render(<Shell bootstrap={shellBootstrap()} pollIntervalMs={20} />);

    await waitFor(() =>
      expect(screen.getByTestId("sessions-sidebar").dataset.desktopCollapsed).toBe("true"),
    );
    expect(screen.getByTestId("sessions-collapsed")).toBeTruthy();
    expect(screen.getByTestId("sessions-expand")).toBeTruthy();
  });

  test("clicking the expand button restores the sidebar and reveals the collapse toggle", async () => {
    setupPreviewFetch();
    render(<Shell bootstrap={shellBootstrap()} pollIntervalMs={20} />);

    await waitFor(() => screen.getByTestId("sessions-expand"));
    fireEvent.click(screen.getByTestId("sessions-expand"));

    await waitFor(() =>
      expect(screen.getByTestId("sessions-sidebar").dataset.desktopCollapsed).toBe("false"),
    );
    // Collapse toggle is always rendered, so the user has a way to
    // recollapse without closing the preview.
    expect(screen.getByTestId("sessions-collapse")).toBeTruthy();

    fireEvent.click(screen.getByTestId("sessions-collapse"));
    await waitFor(() =>
      expect(screen.getByTestId("sessions-sidebar").dataset.desktopCollapsed).toBe("true"),
    );
  });
});
