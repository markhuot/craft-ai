import { describe, expect, test } from "bun:test";
import { WidgetApi } from "../api";
import type { WidgetBootstrap } from "../types";

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

describe("WidgetApi", () => {
  test("fetchSessions returns the sessions array on a successful response", async () => {
    const sessions = [
      {
        sessionId: "abc",
        url: "/cp/ai/session/abc",
        title: null,
        active: false,
        messageCount: 0,
        firstMessage: "",
        lastMessage: "",
      },
    ];
    const api = new WidgetApi({
      bootstrap: bootstrap(),
      fetchImpl: async () =>
        new Response(JSON.stringify({ sessions }), {
          status: 200,
          headers: { "Content-Type": "application/json" },
        }),
    });

    const result = await api.fetchSessions();
    expect(result).toEqual(sessions);
  });

  test("fetchSessions returns [] when payload is malformed", async () => {
    const api = new WidgetApi({
      bootstrap: bootstrap(),
      fetchImpl: async () =>
        new Response(JSON.stringify({ unrelated: true }), {
          status: 200,
          headers: { "Content-Type": "application/json" },
        }),
    });

    const result = await api.fetchSessions();
    expect(result).toEqual([]);
  });

  test("fetchSessions throws on a non-OK status", async () => {
    const api = new WidgetApi({
      bootstrap: bootstrap(),
      fetchImpl: async () => new Response("nope", { status: 500 }),
    });

    let caught: unknown = null;
    try {
      await api.fetchSessions();
    } catch (err) {
      caught = err;
    }
    expect(caught).toBeInstanceOf(Error);
  });

  test("createSession posts a CSRF token and returns the new sessionId", async () => {
    let lastInit: RequestInit | undefined;
    const api = new WidgetApi({
      bootstrap: bootstrap(),
      fetchImpl: async (_input, init) => {
        lastInit = init;
        return new Response(JSON.stringify({ sessionId: "session-new" }), {
          status: 200,
          headers: { "Content-Type": "application/json" },
        });
      },
    });

    const id = await api.createSession();
    expect(id).toBe("session-new");
    expect(lastInit?.method).toBe("POST");

    const body = lastInit?.body as FormData;
    expect(body.get("CRAFT_CSRF")).toBe("tok");
  });

  test("createSession throws when the response is missing the sessionId", async () => {
    const api = new WidgetApi({
      bootstrap: bootstrap(),
      fetchImpl: async () =>
        new Response(JSON.stringify({}), {
          status: 200,
          headers: { "Content-Type": "application/json" },
        }),
    });

    let caught: unknown = null;
    try {
      await api.createSession();
    } catch (err) {
      caught = err;
    }
    expect(caught).toBeInstanceOf(Error);
  });
});
