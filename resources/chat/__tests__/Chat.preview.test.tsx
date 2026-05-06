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

afterEach(() => cleanup());

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
): MessagesResponse {
  return { messages, previewRequest };
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
