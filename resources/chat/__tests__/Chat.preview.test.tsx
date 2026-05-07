import { afterEach, describe, expect, test } from "bun:test";
import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { Chat } from "../Chat";
import { ChatApi } from "../api";
import type {
  ChatBootstrap,
  ChatMessage,
  MessagesResponse,
  PreviewRequest,
} from "../types";

afterEach(() => {
  cleanup();
  window.localStorage.clear();
});

function bootstrap(): ChatBootstrap {
  return {
    sessionId: "session-preview",
    messagesUrl: "http://localhost/messages",
    sendUrl: "http://localhost/send",
    sessionsUrl: "http://localhost/sessions/data",
    newSessionUrl: "http://localhost/sessions/new",
    sessionsIndexUrl: "http://localhost/sessions",
    assetsInfoUrl: "http://localhost/assets/info",
    previewRespondUrl: "http://localhost/preview/respond",
    csrfTokenName: "CRAFT_CSRF",
    csrfTokenValue: "tok",
    initialMessages: [] as ChatMessage[],
    initialSessions: [],
  };
}

function envelope(
  messages: ChatMessage[],
  previewRequest: PreviewRequest | null = null,
  lastPreviewUrl: string | null = null,
): MessagesResponse {
  return { messages, previewRequest, lastPreviewUrl };
}

interface ApiHandlers {
  fetchMessagesAfter: (lastId: number) => Promise<MessagesResponse>;
  respondToPreviewRequest?: (
    id: number,
    status: "completed" | "errored",
    result: Record<string, unknown>,
  ) => Promise<void>;
}

function makeApi(h: ApiHandlers): ChatApi {
  const api = new ChatApi({
    messagesUrl: "http://localhost/messages",
    sendUrl: "http://localhost/send",
    assetsInfoUrl: "http://localhost/assets/info",
    previewRespondUrl: "http://localhost/preview/respond",
    sessionId: "session-preview",
    csrfTokenName: "CRAFT_CSRF",
    csrfTokenValue: "tok",
    fetchImpl: async () => new Response("{}", { status: 200 }),
  });
  api.fetchMessagesAfter = h.fetchMessagesAfter;
  if (h.respondToPreviewRequest) api.respondToPreviewRequest = h.respondToPreviewRequest;
  return api;
}

describe("<Chat /> preview pane integration", () => {
  test("renders the iframe in peek mode when an open request arrives", async () => {
    let polls = 0;
    const api = makeApi({
      fetchMessagesAfter: async () => {
        polls += 1;
        if (polls === 1) {
          return envelope([], {
            id: 11,
            type: "open",
            status: "pending",
            input: { url: "https://example.com" },
          });
        }
        return envelope([]);
      },
      respondToPreviewRequest: async () => {},
    });

    render(<Chat bootstrap={bootstrap()} api={api} pollIntervalMs={1_000_000} />);

    await waitFor(() => expect(screen.getByTestId("preview-pane")).toBeTruthy());
    const layout = screen.getByTestId("chat-with-preview");
    expect(layout.dataset.previewMode).toBe("peek");
    const iframe = screen.getByTestId("preview-iframe") as HTMLIFrameElement;
    expect(iframe.src).toContain("https://example.com");
  });

  test("resolves the open request as completed when the iframe finishes loading", async () => {
    const responses: Array<{ id: number; status: string; result: Record<string, unknown> }> = [];
    let polls = 0;
    const api = makeApi({
      fetchMessagesAfter: async () => {
        polls += 1;
        if (polls === 1) {
          return envelope([], {
            id: 21,
            type: "open",
            status: "pending",
            input: { url: "https://example.com" },
          });
        }
        return envelope([]);
      },
      respondToPreviewRequest: async (id, status, result) => {
        responses.push({ id, status, result });
      },
    });

    render(<Chat bootstrap={bootstrap()} api={api} pollIntervalMs={1_000_000} />);
    await waitFor(() => screen.getByTestId("preview-iframe"));

    fireEvent.load(screen.getByTestId("preview-iframe"));

    await waitFor(() => expect(responses.length).toBe(1));
    expect(responses[0]?.id).toBe(21);
    expect(responses[0]?.status).toBe("completed");
    expect(responses[0]?.result).toMatchObject({ finalUrl: expect.any(String) });
  });

  test("a repeat open_preview for the same URL still resolves the new request", async () => {
    // Regression: without the reload-key bump, setting previewUrl to the
    // value React already has is a no-op, the iframe's src= attribute
    // doesn't change, no navigation happens, and the second request hangs
    // until the agent's waitFor() times out.
    const responses: Array<{ id: number; status: string }> = [];
    let pollCount = 0;
    const api = makeApi({
      fetchMessagesAfter: async () => {
        pollCount += 1;
        if (pollCount === 1) {
          return envelope([], {
            id: 130,
            type: "open",
            status: "pending",
            input: { url: "https://example.com" },
          });
        }
        if (pollCount === 2) {
          return envelope([], {
            id: 131,
            type: "open",
            status: "pending",
            input: { url: "https://example.com" },
          });
        }
        return envelope([]);
      },
      respondToPreviewRequest: async (id, status) => {
        responses.push({ id, status });
      },
    });

    render(<Chat bootstrap={bootstrap()} api={api} pollIntervalMs={20} />);

    // First request: load and ack.
    await waitFor(() => screen.getByTestId("preview-iframe"));
    fireEvent.load(screen.getByTestId("preview-iframe"));
    await waitFor(() => expect(responses.length).toBe(1));
    expect(responses[0]).toMatchObject({ id: 130, status: "completed" });

    // Wait for the second poll to deliver request 131. The reload-key bump
    // remounts the iframe with the same URL — fire load on the fresh
    // element to ack the new request.
    await waitFor(() => expect(pollCount).toBeGreaterThanOrEqual(2));
    // Give React a tick to commit the remount triggered by the second poll.
    await new Promise((r) => setTimeout(r, 20));
    fireEvent.load(screen.getByTestId("preview-iframe"));
    await waitFor(() => expect(responses.length).toBe(2));
    expect(responses[1]).toMatchObject({ id: 131, status: "completed" });
  });

  test("does not double-handle the same request when polling returns it twice", async () => {
    // A get_preview without an open iframe resolves immediately by posting
    // an error. If the dedup set in Chat works, the same request returned
    // from multiple polls only triggers one respond — without it we'd see
    // a flood of identical respond() calls.
    let responds = 0;
    const api = makeApi({
      fetchMessagesAfter: async () =>
        envelope([], {
          id: 33,
          type: "get",
          status: "pending",
          input: { fullHtml: false },
        }),
      respondToPreviewRequest: async () => {
        responds += 1;
      },
    });

    render(<Chat bootstrap={bootstrap()} api={api} pollIntervalMs={20} />);

    await waitFor(() => expect(responds).toBe(1));
    // Let a few more poll cycles run to confirm we don't keep replying.
    await new Promise((r) => setTimeout(r, 100));
    expect(responds).toBe(1);
  });

  test("expanding flips the layout to fixed-overlay 1/3 + 2/3 split", async () => {
    const api = makeApi({
      fetchMessagesAfter: async () =>
        envelope([], {
          id: 44,
          type: "open",
          status: "pending",
          input: { url: "https://example.com" },
        }),
      respondToPreviewRequest: async () => {},
    });

    render(<Chat bootstrap={bootstrap()} api={api} pollIntervalMs={1_000_000} />);
    await waitFor(() => screen.getByTestId("preview-pane"));

    fireEvent.click(screen.getByTestId("preview-expand"));

    const layout = screen.getByTestId("chat-with-preview");
    expect(layout.dataset.previewMode).toBe("expanded");
    // The shrink (X) control replaces expand once we're full-screen.
    expect(screen.getByTestId("preview-shrink")).toBeTruthy();
  });

  test("a follow-up open_preview keeps the user's expanded view instead of yanking back to peek", async () => {
    // Real-world flow: user expands the preview, then asks the agent for
    // an edit. The agent re-runs open_preview to refresh the URL after
    // the change. The pane URL should swap, but the expand state must
    // survive — only the user's X click can return to peek.
    let pollCount = 0;
    const api = makeApi({
      fetchMessagesAfter: async () => {
        pollCount += 1;
        if (pollCount === 1) {
          return envelope([], {
            id: 70,
            type: "open",
            status: "pending",
            input: { url: "https://example.com/v1" },
          });
        }
        if (pollCount === 2) {
          return envelope([], {
            id: 71,
            type: "open",
            status: "pending",
            input: { url: "https://example.com/v2" },
          });
        }
        return envelope([]);
      },
      respondToPreviewRequest: async () => {},
    });

    render(<Chat bootstrap={bootstrap()} api={api} pollIntervalMs={20} />);
    await waitFor(() => screen.getByTestId("preview-pane"));

    fireEvent.click(screen.getByTestId("preview-expand"));
    expect(screen.getByTestId("chat-with-preview").dataset.previewMode).toBe("expanded");

    // Wait for the second open_preview poll to be picked up — we'll know
    // because the iframe src updates to /v2 even though the mode stays.
    await waitFor(() => {
      const iframe = screen.getByTestId("preview-iframe") as HTMLIFrameElement;
      expect(iframe.src).toContain("/v2");
    });
    expect(screen.getByTestId("chat-with-preview").dataset.previewMode).toBe("expanded");
    expect(screen.getByTestId("preview-shrink")).toBeTruthy();
  });

  test("close errors any in-flight open request so the agent loop unblocks", async () => {
    const responses: Array<{ id: number; status: string }> = [];
    const api = makeApi({
      fetchMessagesAfter: async () =>
        envelope([], {
          id: 55,
          type: "open",
          status: "pending",
          input: { url: "https://example.com" },
        }),
      respondToPreviewRequest: async (id, status) => {
        responses.push({ id, status });
      },
    });

    render(<Chat bootstrap={bootstrap()} api={api} pollIntervalMs={1_000_000} />);
    await waitFor(() => screen.getByTestId("preview-pane"));

    fireEvent.click(screen.getByTestId("preview-close"));

    await waitFor(() => expect(responses.length).toBe(1));
    expect(responses[0]?.id).toBe(55);
    expect(responses[0]?.status).toBe("errored");
    // Pane should be torn down too so a future request mounts a fresh one.
    expect(screen.queryByTestId("preview-pane")).toBeNull();
  });

  test("renders the toolbar globe once the server reports a lastPreviewUrl", async () => {
    const api = makeApi({
      fetchMessagesAfter: async () =>
        envelope([], null, "https://example.com/last"),
      respondToPreviewRequest: async () => {},
    });

    render(<Chat bootstrap={bootstrap()} api={api} pollIntervalMs={1_000_000} />);

    await waitFor(() => expect(screen.getByTestId("preview-toggle")).toBeTruthy());
    // No iframe yet — the user hasn't clicked the globe.
    expect(screen.queryByTestId("preview-iframe")).toBeNull();
  });

  test("clicking the globe re-mounts the iframe at the last URL after a reload", async () => {
    const api = makeApi({
      fetchMessagesAfter: async () =>
        envelope([], null, "https://example.com/last"),
      respondToPreviewRequest: async () => {},
    });

    render(<Chat bootstrap={bootstrap()} api={api} pollIntervalMs={1_000_000} />);

    fireEvent.click(await screen.findByTestId("preview-toggle"));

    const iframe = (await screen.findByTestId("preview-iframe")) as HTMLIFrameElement;
    expect(iframe.src).toContain("https://example.com/last");
    // Once the iframe is mounted, the toolbar globe steps aside — the X on
    // the preview pane header is the close affordance from here on.
    expect(screen.queryByTestId("preview-toggle")).toBeNull();
  });

  test("closing via the pane X brings the globe back so the user can reopen", async () => {
    const api = makeApi({
      fetchMessagesAfter: async () =>
        envelope([], null, "https://example.com/last"),
      respondToPreviewRequest: async () => {},
    });

    render(<Chat bootstrap={bootstrap()} api={api} pollIntervalMs={1_000_000} />);

    fireEvent.click(await screen.findByTestId("preview-toggle"));
    await waitFor(() => screen.getByTestId("preview-iframe"));

    fireEvent.click(screen.getByTestId("preview-close"));

    await waitFor(() => expect(screen.queryByTestId("preview-iframe")).toBeNull());
    // Globe is back so the user has a way to reopen.
    expect(screen.getByTestId("preview-toggle")).toBeTruthy();
  });

  test("does not render the globe when the session has never had a preview", () => {
    const api = makeApi({
      fetchMessagesAfter: async () => envelope([]),
      respondToPreviewRequest: async () => {},
    });

    render(<Chat bootstrap={bootstrap()} api={api} pollIntervalMs={1_000_000} />);

    expect(screen.queryByTestId("preview-toggle")).toBeNull();
  });

  test("an in-iframe navigation rides along on the next message as page context", async () => {
    const sends: Array<{ context?: unknown }> = [];
    let pollCount = 0;
    const api = makeApi({
      fetchMessagesAfter: async () => {
        pollCount += 1;
        if (pollCount === 1) {
          return envelope([], {
            id: 80,
            type: "open",
            status: "pending",
            input: { url: "https://example.com/v1" },
          });
        }
        return envelope([]);
      },
      respondToPreviewRequest: async () => {},
    });
    api.sendMessage = async (_msg, _ids, context) => {
      sends.push({ context });
    };

    const data = new Map<string, string>();
    const storage = {
      getItem: (k: string) => data.get(k) ?? null,
      setItem: (k: string, v: string) => {
        data.set(k, v);
      },
      removeItem: (k: string) => {
        data.delete(k);
      },
    };

    render(
      <Chat
        bootstrap={bootstrap()}
        api={api}
        pollIntervalMs={1_000_000}
        storage={storage}
      />,
    );
    await waitFor(() => screen.getByTestId("preview-iframe"));

    const iframe = screen.getByTestId("preview-iframe") as HTMLIFrameElement;
    // Initial load resolves the OpenPreview at /v1.
    fireEvent.load(iframe);

    // User clicks a link inside the iframe and the frame navigates to /v2.
    Object.defineProperty(iframe, "contentWindow", {
      configurable: true,
      get: () => ({ location: { href: "https://example.com/v2" } }),
    });
    fireEvent.load(iframe);

    fireEvent.change(screen.getByPlaceholderText("Send a message…"), {
      target: { value: "what does this say" },
    });
    fireEvent.click(screen.getByRole("button", { name: /send/i }));

    await waitFor(() => expect(sends.length).toBe(1));
    // The page-context payload reflects the URL the user is actually
    // looking at after the in-iframe navigation, not the URL the agent
    // originally requested.
    expect(sends[0]?.context).toEqual({ url: "https://example.com/v2" });
    expect(data.get("craftai-widget:context-fp:session-preview")).toBe(
      "preview:https://example.com/v2",
    );
  });

  test("the same preview URL is not re-sent as context on the next message", async () => {
    const sends: Array<{ context?: unknown }> = [];
    let pollCount = 0;
    const api = makeApi({
      fetchMessagesAfter: async () => {
        pollCount += 1;
        if (pollCount === 1) {
          return envelope([], {
            id: 81,
            type: "open",
            status: "pending",
            input: { url: "https://example.com/v1" },
          });
        }
        // Flip status back to idle so the second send isn't blocked.
        if (pollCount === 2) {
          return envelope([
            { id: 1, role: "assistant", content: [{ type: "text", text: "ok" }] },
          ]);
        }
        return envelope([]);
      },
      respondToPreviewRequest: async () => {},
    });
    api.sendMessage = async (_msg, _ids, context) => {
      sends.push({ context });
    };

    const data = new Map<string, string>();
    const storage = {
      getItem: (k: string) => data.get(k) ?? null,
      setItem: (k: string, v: string) => {
        data.set(k, v);
      },
      removeItem: (k: string) => {
        data.delete(k);
      },
    };

    render(
      <Chat
        bootstrap={bootstrap()}
        api={api}
        pollIntervalMs={1_000_000}
        storage={storage}
      />,
    );
    await waitFor(() => screen.getByTestId("preview-iframe"));
    const iframe = screen.getByTestId("preview-iframe") as HTMLIFrameElement;
    Object.defineProperty(iframe, "contentWindow", {
      configurable: true,
      get: () => ({ location: { href: "https://example.com/v1" } }),
    });
    fireEvent.load(iframe);

    const textarea = screen.getByPlaceholderText("Send a message…");
    fireEvent.change(textarea, { target: { value: "first" } });
    fireEvent.click(screen.getByRole("button", { name: /send/i }));
    await waitFor(() => expect(sends.length).toBe(1));
    await waitFor(() => expect(screen.queryByText("ok")).toBeTruthy());

    fireEvent.change(textarea, { target: { value: "second" } });
    fireEvent.click(screen.getByRole("button", { name: /send/i }));
    await waitFor(() => expect(sends.length).toBe(2));

    expect(sends[0]?.context).toEqual({ url: "https://example.com/v1" });
    // Same URL, fingerprint already cached → omit the payload.
    expect(sends[1]?.context).toBeUndefined();
  });

  test("a navigation does not double-resolve the original OpenPreview request", async () => {
    const responses: Array<{ id: number; status: string }> = [];
    const api = makeApi({
      fetchMessagesAfter: async () =>
        envelope([], {
          id: 90,
          type: "open",
          status: "pending",
          input: { url: "https://example.com/v1" },
        }),
      respondToPreviewRequest: async (id, status) => {
        responses.push({ id, status });
      },
    });

    render(<Chat bootstrap={bootstrap()} api={api} pollIntervalMs={1_000_000} />);
    await waitFor(() => screen.getByTestId("preview-iframe"));

    const iframe = screen.getByTestId("preview-iframe") as HTMLIFrameElement;
    fireEvent.load(iframe);

    Object.defineProperty(iframe, "contentWindow", {
      configurable: true,
      get: () => ({ location: { href: "https://example.com/v2" } }),
    });
    fireEvent.load(iframe);

    // Let any straggling responds settle.
    await new Promise((r) => setTimeout(r, 50));
    expect(responses.length).toBe(1);
    expect(responses[0]?.id).toBe(90);
    expect(responses[0]?.status).toBe("completed");
  });

  test("renders a resize divider in peek mode that sits between the transcript and preview", async () => {
    const api = makeApi({
      fetchMessagesAfter: async () =>
        envelope([], {
          id: 100,
          type: "open",
          status: "pending",
          input: { url: "https://example.com" },
        }),
      respondToPreviewRequest: async () => {},
    });

    render(<Chat bootstrap={bootstrap()} api={api} pollIntervalMs={1_000_000} />);
    await waitFor(() => screen.getByTestId("preview-pane"));

    const layout = screen.getByTestId("chat-with-preview");
    expect(layout.dataset.previewMode).toBe("peek");
    expect(screen.getByTestId("preview-resize")).toBeTruthy();

    // Default peek split: 67% transcript / 33% preview, applied as an inline
    // width on the transcript pane. The preview pane fills the remainder via
    // flex-1, so we don't need to assert its width directly.
    const transcript = screen.getByTestId("chat-transcript-pane");
    expect(transcript.style.width).toBe("67%");
  });

  test("renders a resize divider in expanded mode and respects the saved expanded percent", async () => {
    window.localStorage.setItem("craftai-chat:transcript-pct:expanded", "45");
    const api = makeApi({
      fetchMessagesAfter: async () =>
        envelope([], {
          id: 101,
          type: "open",
          status: "pending",
          input: { url: "https://example.com" },
        }),
      respondToPreviewRequest: async () => {},
    });

    render(<Chat bootstrap={bootstrap()} api={api} pollIntervalMs={1_000_000} />);
    await waitFor(() => screen.getByTestId("preview-pane"));

    fireEvent.click(screen.getByTestId("preview-expand"));
    expect(screen.getByTestId("chat-with-preview").dataset.previewMode).toBe("expanded");
    expect(screen.getByTestId("preview-resize")).toBeTruthy();
    expect(screen.getByTestId("chat-transcript-pane").style.width).toBe("45%");
  });

  test("hydrates the peek transcript width from localStorage on first render", async () => {
    window.localStorage.setItem("craftai-chat:transcript-pct:peek", "55");
    const api = makeApi({
      fetchMessagesAfter: async () =>
        envelope([], {
          id: 102,
          type: "open",
          status: "pending",
          input: { url: "https://example.com" },
        }),
      respondToPreviewRequest: async () => {},
    });

    render(<Chat bootstrap={bootstrap()} api={api} pollIntervalMs={1_000_000} />);
    await waitFor(() => screen.getByTestId("preview-pane"));

    expect(screen.getByTestId("chat-transcript-pane").style.width).toBe("55%");
  });

  test("dragging the divider updates the transcript width and persists it", async () => {
    const api = makeApi({
      fetchMessagesAfter: async () =>
        envelope([], {
          id: 103,
          type: "open",
          status: "pending",
          input: { url: "https://example.com" },
        }),
      respondToPreviewRequest: async () => {},
    });

    render(<Chat bootstrap={bootstrap()} api={api} pollIntervalMs={1_000_000} />);
    await waitFor(() => screen.getByTestId("preview-pane"));

    const layout = screen.getByTestId("chat-with-preview");
    // happy-dom doesn't lay anything out, so pin a known container rect.
    layout.getBoundingClientRect = () =>
      ({
        left: 0,
        top: 0,
        right: 1000,
        bottom: 0,
        width: 1000,
        height: 0,
        x: 0,
        y: 0,
        toJSON() {},
      }) as DOMRect;

    const divider = screen.getByTestId("preview-resize");
    fireEvent.pointerDown(divider, { clientX: 670 });
    fireEvent.pointerMove(window, { clientX: 400 });
    fireEvent.pointerUp(window);

    // 400 / 1000 = 40%, clamped to the [20, 80] band.
    await waitFor(() =>
      expect(screen.getByTestId("chat-transcript-pane").style.width).toBe("40%"),
    );
    expect(window.localStorage.getItem("craftai-chat:transcript-pct:peek")).toBe("40");
  });

  test("dragging far past the right edge clamps the transcript to 80%", async () => {
    const api = makeApi({
      fetchMessagesAfter: async () =>
        envelope([], {
          id: 104,
          type: "open",
          status: "pending",
          input: { url: "https://example.com" },
        }),
      respondToPreviewRequest: async () => {},
    });

    render(<Chat bootstrap={bootstrap()} api={api} pollIntervalMs={1_000_000} />);
    await waitFor(() => screen.getByTestId("preview-pane"));

    const layout = screen.getByTestId("chat-with-preview");
    layout.getBoundingClientRect = () =>
      ({
        left: 0,
        top: 0,
        right: 1000,
        bottom: 0,
        width: 1000,
        height: 0,
        x: 0,
        y: 0,
        toJSON() {},
      }) as DOMRect;

    fireEvent.pointerDown(screen.getByTestId("preview-resize"), { clientX: 670 });
    fireEvent.pointerMove(window, { clientX: 5000 });
    fireEvent.pointerUp(window);

    await waitFor(() =>
      expect(screen.getByTestId("chat-transcript-pane").style.width).toBe("80%"),
    );
  });

  test("notifies the host when peek opens, expands, and closes", async () => {
    const peekStates: boolean[] = [];
    let pollCount = 0;
    const api = makeApi({
      fetchMessagesAfter: async () => {
        pollCount += 1;
        if (pollCount === 1) {
          return envelope([], {
            id: 110,
            type: "open",
            status: "pending",
            input: { url: "https://example.com" },
          });
        }
        return envelope([]);
      },
      respondToPreviewRequest: async () => {},
    });

    render(
      <Chat
        bootstrap={bootstrap()}
        api={api}
        pollIntervalMs={1_000_000}
        onPreviewPeekChange={(peek) => peekStates.push(peek)}
      />,
    );

    // Initial state: peek=false (no preview yet).
    await waitFor(() => expect(peekStates[0]).toBe(false));
    // After the OpenPreview poll, we transition to peek=true.
    await waitFor(() => expect(peekStates).toContain(true));

    // Expanding the preview takes us out of peek mode for the host's purposes.
    fireEvent.click(screen.getByTestId("preview-expand"));
    await waitFor(() => expect(peekStates[peekStates.length - 1]).toBe(false));

    // Collapsing back puts us in peek again.
    fireEvent.click(screen.getByTestId("preview-shrink"));
    await waitFor(() => expect(peekStates[peekStates.length - 1]).toBe(true));

    // Closing the preview entirely returns to peek=false.
    fireEvent.click(screen.getByTestId("preview-close"));
    await waitFor(() => expect(peekStates[peekStates.length - 1]).toBe(false));
  });

  test("a get_preview request without an open iframe is rejected with an error", async () => {
    const responses: Array<{ id: number; status: string; result: Record<string, unknown> }> = [];
    const api = makeApi({
      fetchMessagesAfter: async () =>
        envelope([], {
          id: 66,
          type: "get",
          status: "pending",
          input: { fullHtml: false },
        }),
      respondToPreviewRequest: async (id, status, result) => {
        responses.push({ id, status, result });
      },
    });

    render(<Chat bootstrap={bootstrap()} api={api} pollIntervalMs={1_000_000} />);

    await waitFor(() => expect(responses.length).toBe(1));
    expect(responses[0]?.status).toBe("errored");
    expect(String(responses[0]?.result.error)).toContain("No preview is open");
  });
});
