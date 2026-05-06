import { afterEach, describe, expect, test } from "bun:test";
import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { Chat } from "../Chat";
import { ChatApi } from "../api";
import type {
  Attachment,
  ChatBootstrap,
  ChatMessage,
  MessagesResponse,
  PreviewRequest,
} from "../types";

afterEach(() => cleanup());

function bootstrap(initialMessages: ChatMessage[] = []): ChatBootstrap {
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
    initialMessages,
    initialSessions: [],
  };
}

function envelope(messages: ChatMessage[], previewRequest: PreviewRequest | null = null): MessagesResponse {
  return { messages, previewRequest };
}

function makeApi(overrides: Partial<{
  fetchMessagesAfter: (lastId: number) => Promise<MessagesResponse>;
  sendMessage: (msg: string, assetIds?: number[], context?: unknown) => Promise<void>;
  fetchAssetInfo: (ids: number[]) => Promise<Attachment[]>;
  respondToPreviewRequest: (id: number, status: "completed" | "errored", result: Record<string, unknown>) => Promise<void>;
}> = {}) {
  const api = new ChatApi({
    messagesUrl: "http://localhost/messages",
    sendUrl: "http://localhost/send",
    assetsInfoUrl: "http://localhost/assets/info",
    previewRespondUrl: "http://localhost/preview/respond",
    sessionId: "session-1",
    csrfTokenName: "CRAFT_CSRF",
    csrfTokenValue: "tok",
    fetchImpl: async () =>
      new Response(JSON.stringify({ messages: [], previewRequest: null }), { status: 200 }),
  });
  if (overrides.fetchMessagesAfter) {
    api.fetchMessagesAfter = overrides.fetchMessagesAfter;
  }
  if (overrides.sendMessage) {
    api.sendMessage = overrides.sendMessage;
  }
  if (overrides.fetchAssetInfo) {
    api.fetchAssetInfo = overrides.fetchAssetInfo;
  }
  if (overrides.respondToPreviewRequest) {
    api.respondToPreviewRequest = overrides.respondToPreviewRequest;
  }
  return api;
}

describe("<Chat />", () => {
  test("renders empty state when there are no messages", () => {
    render(<Chat bootstrap={bootstrap()} api={makeApi()} pollIntervalMs={1_000_000} />);
    expect(screen.getByTestId("chat-empty")).toBeTruthy();
  });

  test("renders text blocks via markdown", () => {
    const messages: ChatMessage[] = [
      { id: 1, role: "user", content: [{ type: "text", text: "Hello **world**" }] },
    ];
    render(<Chat bootstrap={bootstrap(messages)} api={makeApi()} pollIntervalMs={1_000_000} />);
    const strong = screen.getByText("world");
    expect(strong.tagName.toLowerCase()).toBe("strong");
  });

  test("renders tool_use blocks with name and input", () => {
    const messages: ChatMessage[] = [
      {
        id: 1,
        role: "assistant",
        content: [
          { type: "tool_use", name: "list_entries", input: { section: "news" } },
        ],
      },
    ];
    render(<Chat bootstrap={bootstrap(messages)} api={makeApi()} pollIntervalMs={1_000_000} />);
    expect(screen.getByText("list_entries")).toBeTruthy();
  });

  test("renders tool_result blocks with error styling", () => {
    const messages: ChatMessage[] = [
      {
        id: 1,
        role: "assistant",
        content: [{ type: "tool_result", content: "boom", is_error: true }],
      },
    ];
    render(<Chat bootstrap={bootstrap(messages)} api={makeApi()} pollIntervalMs={1_000_000} />);
    expect(screen.getByText("error")).toBeTruthy();
    expect(screen.getByText("boom")).toBeTruthy();
  });

  test("submitting the form calls sendMessage and clears the textarea", async () => {
    let sent = "";
    const api = makeApi({
      sendMessage: async (msg) => {
        sent = msg;
      },
      fetchMessagesAfter: async () => envelope([]),
    });

    render(<Chat bootstrap={bootstrap()} api={api} pollIntervalMs={1_000_000} />);

    const textarea = screen.getByPlaceholderText("Send a message…") as HTMLTextAreaElement;
    fireEvent.change(textarea, { target: { value: "ping" } });
    const submit = screen.getByRole("button", { name: /send/i });
    fireEvent.click(submit);

    await waitFor(() => expect(sent).toBe("ping"));
    await waitFor(() => expect(textarea.value).toBe(""));
  });

  test("submit is disabled when the draft is empty and there are no attachments", () => {
    render(<Chat bootstrap={bootstrap()} api={makeApi()} pollIntervalMs={1_000_000} />);
    const submit = screen.getByRole("button", { name: /send/i }) as HTMLButtonElement;
    expect(submit.disabled).toBe(true);
  });

  test("displays an error when sendMessage rejects", async () => {
    const api = makeApi({
      sendMessage: async () => {
        throw new Error("network down");
      },
    });
    render(<Chat bootstrap={bootstrap()} api={api} pollIntervalMs={1_000_000} />);
    fireEvent.change(screen.getByPlaceholderText("Send a message…"), { target: { value: "x" } });
    fireEvent.click(screen.getByRole("button", { name: /send/i }));
    await waitFor(() => expect(screen.getByRole("alert").textContent).toContain("network down"));
  });

  test("polling appends new messages", async () => {
    let calls = 0;
    const api = makeApi({
      fetchMessagesAfter: async () => {
        calls += 1;
        if (calls === 1) {
          return envelope([
            { id: 5, role: "assistant", content: [{ type: "text", text: "later" }] },
          ]);
        }
        return envelope([]);
      },
    });
    render(<Chat bootstrap={bootstrap()} api={api} pollIntervalMs={20} />);
    await waitFor(() => expect(screen.getByText("later")).toBeTruthy());
  });

  test("clicking Upload opens the asset selector and renders a thumbnail chip", async () => {
    const fetched: Attachment[] = [
      {
        id: 42,
        label: "photo",
        filename: "photo.jpg",
        kind: "image",
        mimeType: "image/jpeg",
        thumbUrl: "/cpresources/photo-thumb.jpg",
      },
    ];
    const api = makeApi({
      fetchAssetInfo: async () => fetched,
    });
    render(
      <Chat
        bootstrap={bootstrap()}
        api={api}
        pollIntervalMs={1_000_000}
        openAssetSelector={async () => [42]}
      />,
    );

    fireEvent.click(screen.getByRole("button", { name: /upload/i }));

    await waitFor(() => {
      const img = screen.getByAltText("photo") as HTMLImageElement;
      expect(img.src).toContain("photo-thumb.jpg");
    });
  });

  test("the X on a chip removes the attachment from the pending list", async () => {
    const api = makeApi({
      fetchAssetInfo: async () => [
        {
          id: 7,
          label: "doc",
          filename: "doc.pdf",
          kind: "pdf",
          mimeType: "application/pdf",
          thumbUrl: null,
        },
      ],
    });
    render(
      <Chat
        bootstrap={bootstrap()}
        api={api}
        pollIntervalMs={1_000_000}
        openAssetSelector={async () => [7]}
      />,
    );

    fireEvent.click(screen.getByRole("button", { name: /upload/i }));
    await waitFor(() => screen.getByRole("button", { name: /remove doc/i }));
    fireEvent.click(screen.getByRole("button", { name: /remove doc/i }));

    await waitFor(() =>
      expect(screen.queryByRole("button", { name: /remove doc/i })).toBeNull(),
    );
  });

  test("submit is enabled when only attachments are present and forwards their ids", async () => {
    let sent: { msg: string; ids?: number[] } | null = null;
    const api = makeApi({
      sendMessage: async (msg, ids) => {
        sent = { msg, ids };
      },
      fetchAssetInfo: async () => [
        {
          id: 99,
          label: "graph",
          filename: "graph.png",
          kind: "image",
          mimeType: "image/png",
          thumbUrl: "/g.png",
        },
      ],
    });
    render(
      <Chat
        bootstrap={bootstrap()}
        api={api}
        pollIntervalMs={1_000_000}
        openAssetSelector={async () => [99]}
      />,
    );

    fireEvent.click(screen.getByRole("button", { name: /upload/i }));
    await waitFor(() => screen.getByAltText("graph"));

    const submit = screen.getByRole("button", { name: /send/i }) as HTMLButtonElement;
    expect(submit.disabled).toBe(false);

    fireEvent.click(submit);
    await waitFor(() => {
      expect(sent).not.toBeNull();
      expect(sent?.msg).toBe("");
      expect(sent?.ids).toEqual([99]);
    });
  });

  test("attaches context on the first send and skips it on the second when the fingerprint matches", async () => {
    const sends: Array<{ msg: string; assetIds?: number[]; context?: unknown }> = [];
    let pollCalls = 0;
    const api = makeApi({
      sendMessage: async (msg, assetIds, context) => {
        sends.push({ msg, assetIds, context });
      },
      fetchMessagesAfter: async () => {
        // Chat fires an immediate poll on mount (call #1) and another after
        // each send (call #2 = post-first-send). Returning an assistant turn
        // on call #2 flips status back to "idle" so the second send isn't
        // rejected by the busy guard in onSubmit.
        pollCalls += 1;
        if (pollCalls === 2) {
          return envelope([
            { id: 1, role: "assistant", content: [{ type: "text", text: "ack" }] },
          ]);
        }
        return envelope([]);
      },
    });

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

    const boot: ChatBootstrap = {
      ...bootstrap(),
      context: { url: "https://x.test/about", element: null },
      contextFingerprint: "fp-abc",
    };

    render(
      <Chat bootstrap={boot} api={api} pollIntervalMs={1_000_000} storage={storage} />,
    );

    const textarea = screen.getByPlaceholderText("Send a message…") as HTMLTextAreaElement;
    fireEvent.change(textarea, { target: { value: "first" } });
    fireEvent.click(screen.getByRole("button", { name: /send/i }));
    await waitFor(() => expect(sends.length).toBe(1));
    // Wait for the simulated assistant turn so Chat returns to idle.
    await waitFor(() => expect(screen.queryByText("ack")).toBeTruthy());

    fireEvent.change(textarea, { target: { value: "second" } });
    fireEvent.click(screen.getByRole("button", { name: /send/i }));
    await waitFor(() => expect(sends.length).toBe(2));

    expect(sends[0]?.context).toEqual({ url: "https://x.test/about", element: null });
    // After a successful first send, the fingerprint is cached and the
    // second send should skip the context payload.
    expect(sends[1]?.context).toBeUndefined();
    expect(data.get("craftai-widget:context-fp:session-1")).toBe("fp-abc");
  });

  test("re-attaches context when the bootstrap's fingerprint changes", async () => {
    const sends: Array<{ context?: unknown }> = [];
    const api = makeApi({
      sendMessage: async (_msg, _ids, context) => {
        sends.push({ context });
      },
      fetchMessagesAfter: async () => envelope([]),
    });

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

    // Pretend the user previously sent on this session with fp-old; the
    // current page's fingerprint is fp-new.
    data.set("craftai-widget:context-fp:session-1", "fp-old");

    const boot: ChatBootstrap = {
      ...bootstrap(),
      context: { url: "https://x.test/contact" },
      contextFingerprint: "fp-new",
    };

    render(
      <Chat bootstrap={boot} api={api} pollIntervalMs={1_000_000} storage={storage} />,
    );

    fireEvent.change(screen.getByPlaceholderText("Send a message…"), {
      target: { value: "hi" },
    });
    fireEvent.click(screen.getByRole("button", { name: /send/i }));
    await waitFor(() => expect(sends.length).toBe(1));

    expect(sends[0]?.context).toEqual({ url: "https://x.test/contact" });
    expect(data.get("craftai-widget:context-fp:session-1")).toBe("fp-new");
  });

  test("does not update the cached fingerprint when the send fails", async () => {
    const api = makeApi({
      sendMessage: async () => {
        throw new Error("network down");
      },
    });

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

    const boot: ChatBootstrap = {
      ...bootstrap(),
      context: { url: "https://x.test/about" },
      contextFingerprint: "fp-abc",
    };

    render(
      <Chat bootstrap={boot} api={api} pollIntervalMs={1_000_000} storage={storage} />,
    );

    fireEvent.change(screen.getByPlaceholderText("Send a message…"), {
      target: { value: "x" },
    });
    fireEvent.click(screen.getByRole("button", { name: /send/i }));

    await waitFor(() =>
      expect(screen.getByRole("alert").textContent).toContain("network down"),
    );

    expect(data.get("craftai-widget:context-fp:session-1")).toBeUndefined();
  });

  test("renders system messages with a distinct 'Page context' panel", () => {
    const messages: ChatMessage[] = [
      {
        id: 1,
        role: "system",
        content: [{ type: "text", text: "URL: https://x.test/about" }],
      },
    ];
    render(<Chat bootstrap={bootstrap(messages)} api={makeApi()} pollIntervalMs={1_000_000} />);

    const panel = screen.getByTestId("message-system");
    expect(panel.textContent).toContain("Page context");
    expect(panel.textContent).toContain("URL: https://x.test/about");
  });

  test("renders attachments on past messages", () => {
    const messages: ChatMessage[] = [
      {
        id: 1,
        role: "user",
        content: [{ type: "text", text: "look at this" }],
        attachments: [
          {
            id: 5,
            label: "photo",
            filename: "photo.jpg",
            kind: "image",
            mimeType: "image/jpeg",
            thumbUrl: "/p.jpg",
          },
        ],
      },
    ];
    render(<Chat bootstrap={bootstrap(messages)} api={makeApi()} pollIntervalMs={1_000_000} />);

    const region = screen.getByTestId("message-attachments");
    expect(region).toBeTruthy();
    const img = region.querySelector("img") as HTMLImageElement;
    expect(img.src).toContain("p.jpg");
  });
});
