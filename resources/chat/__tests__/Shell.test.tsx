import { afterEach, describe, expect, test } from "bun:test";
import { cleanup, fireEvent, render, screen } from "@testing-library/react";
import { Shell } from "../Shell";
import type { ChatBootstrap, SessionListItem } from "../types";

afterEach(() => cleanup());

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
