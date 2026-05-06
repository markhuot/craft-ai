import { describe, expect, test } from "bun:test";
import { ChatApi, type FetchLike } from "../api";

function makeApi(fetchImpl: FetchLike) {
  return new ChatApi({
    messagesUrl: "http://localhost/actions/craft-ai/messages",
    sendUrl: "http://localhost/actions/craft-ai/sessions/send",
    assetsInfoUrl: "http://localhost/actions/craft-ai/assets/info",
    sessionId: "abc-123",
    csrfTokenName: "CRAFT_CSRF",
    csrfTokenValue: "token-value",
    fetchImpl,
  });
}

describe("ChatApi.fetchMessagesAfter", () => {
  test("appends sessionId and after query params", async () => {
    let capturedUrl = "";
    const api = makeApi(async (input) => {
      capturedUrl = String(input);
      return new Response(
        JSON.stringify({
          messages: [{ id: 7, role: "assistant", content: [] }],
          previewRequest: null,
        }),
        {
          status: 200,
          headers: { "Content-Type": "application/json" },
        },
      );
    });

    const result = await api.fetchMessagesAfter(3);
    expect(capturedUrl).toContain("sessionId=abc-123");
    expect(capturedUrl).toContain("after=3");
    expect(result.messages).toHaveLength(1);
    expect(result.messages[0]?.id).toBe(7);
    expect(result.previewRequest).toBeNull();
  });

  test("tolerates a legacy bare-array response", async () => {
    const api = makeApi(async () =>
      new Response(JSON.stringify([{ id: 1, role: "user", content: [] }]), { status: 200 }),
    );
    const result = await api.fetchMessagesAfter(0);
    expect(result.messages).toHaveLength(1);
    expect(result.previewRequest).toBeNull();
  });

  test("returns empty messages on unexpected response shape", async () => {
    const api = makeApi(async () =>
      new Response(JSON.stringify({ unexpected: true }), { status: 200 }),
    );
    const result = await api.fetchMessagesAfter(0);
    expect(result.messages).toEqual([]);
    expect(result.previewRequest).toBeNull();
  });

  test("surfaces a previewRequest envelope when present", async () => {
    const api = makeApi(async () =>
      new Response(
        JSON.stringify({
          messages: [],
          previewRequest: {
            id: 42,
            type: "open",
            status: "pending",
            input: { url: "https://example.com" },
          },
        }),
        { status: 200 },
      ),
    );
    const result = await api.fetchMessagesAfter(0);
    expect(result.previewRequest).toEqual({
      id: 42,
      type: "open",
      status: "pending",
      input: { url: "https://example.com" },
    });
  });

  test("surfaces lastPreviewUrl when the server pins one", async () => {
    const api = makeApi(async () =>
      new Response(
        JSON.stringify({
          messages: [],
          previewRequest: null,
          lastPreviewUrl: "https://example.com/last",
        }),
        { status: 200 },
      ),
    );
    const result = await api.fetchMessagesAfter(0);
    expect(result.lastPreviewUrl).toBe("https://example.com/last");
  });

  test("treats an empty-string lastPreviewUrl as null", async () => {
    const api = makeApi(async () =>
      new Response(
        JSON.stringify({ messages: [], previewRequest: null, lastPreviewUrl: "" }),
        { status: 200 },
      ),
    );
    const result = await api.fetchMessagesAfter(0);
    expect(result.lastPreviewUrl).toBeNull();
  });

  test("drops a previewRequest with an unknown type", async () => {
    const api = makeApi(async () =>
      new Response(
        JSON.stringify({
          messages: [],
          previewRequest: { id: 1, type: "garbage", status: "pending", input: {} },
        }),
        { status: 200 },
      ),
    );
    const result = await api.fetchMessagesAfter(0);
    expect(result.previewRequest).toBeNull();
  });

  test("throws on non-ok response", async () => {
    const api = makeApi(async () => new Response("nope", { status: 500 }));
    await expect(api.fetchMessagesAfter(0)).rejects.toThrow(/500/);
  });
});

describe("ChatApi.respondToPreviewRequest", () => {
  test("posts id, status, and JSON-encoded result", async () => {
    let captured: { url: string; init: RequestInit | undefined } = { url: "", init: undefined };
    const api = new ChatApi({
      messagesUrl: "http://localhost/actions/craft-ai/messages",
      sendUrl: "http://localhost/actions/craft-ai/sessions/send",
      assetsInfoUrl: "http://localhost/actions/craft-ai/assets/info",
      previewRespondUrl: "http://localhost/actions/craft-ai/preview/respond",
      sessionId: "abc-123",
      csrfTokenName: "CRAFT_CSRF",
      csrfTokenValue: "token-value",
      fetchImpl: async (input, init) => {
        captured = { url: String(input), init };
        return new Response("{}", { status: 200 });
      },
    });

    await api.respondToPreviewRequest(7, "completed", { content: "hi" });

    expect(captured.url).toBe("http://localhost/actions/craft-ai/preview/respond");
    const body = captured.init?.body as FormData;
    expect(body.get("id")).toBe("7");
    expect(body.get("status")).toBe("completed");
    expect(JSON.parse(body.get("result") as string)).toEqual({ content: "hi" });
  });

  test("throws when no respond url is configured", async () => {
    const api = makeApi(async () => new Response("{}", { status: 200 }));
    await expect(
      api.respondToPreviewRequest(1, "completed", {}),
    ).rejects.toThrow(/not configured/);
  });
});

describe("ChatApi.sendMessage", () => {
  test("posts FormData with csrf token, sessionId, and message", async () => {
    let captured: { url: string; init: RequestInit | undefined } = { url: "", init: undefined };
    const api = makeApi(async (input, init) => {
      captured = { url: String(input), init };
      return new Response("{}", { status: 200 });
    });

    await api.sendMessage("hello world");

    expect(captured.url).toBe("http://localhost/actions/craft-ai/sessions/send");
    expect(captured.init?.method).toBe("POST");
    const body = captured.init?.body as FormData;
    expect(body).toBeInstanceOf(FormData);
    expect(body.get("sessionId")).toBe("abc-123");
    expect(body.get("message")).toBe("hello world");
    expect(body.get("CRAFT_CSRF")).toBe("token-value");
    expect(body.get("assetIds")).toBeNull();
  });

  test("appends a JSON-encoded assetIds field when provided", async () => {
    let captured: { url: string; init: RequestInit | undefined } = { url: "", init: undefined };
    const api = makeApi(async (input, init) => {
      captured = { url: String(input), init };
      return new Response("{}", { status: 200 });
    });

    await api.sendMessage("hello", [12, 34]);

    const body = captured.init?.body as FormData;
    expect(body.get("assetIds")).toBe("[12,34]");
  });

  test("attaches a JSON-encoded context payload when one is provided", async () => {
    let captured: { url: string; init: RequestInit | undefined } = { url: "", init: undefined };
    const api = makeApi(async (input, init) => {
      captured = { url: String(input), init };
      return new Response("{}", { status: 200 });
    });

    const ctx = { url: "https://x.test/foo", element: { type: "entry", id: 1 } };
    await api.sendMessage("hello", [], ctx);

    const body = captured.init?.body as FormData;
    const sent = body.get("context");
    expect(typeof sent).toBe("string");
    expect(JSON.parse(sent as string)).toEqual(ctx);
  });

  test("omits the context field when no payload is supplied", async () => {
    let captured: { url: string; init: RequestInit | undefined } = { url: "", init: undefined };
    const api = makeApi(async (input, init) => {
      captured = { url: String(input), init };
      return new Response("{}", { status: 200 });
    });

    await api.sendMessage("hello");

    const body = captured.init?.body as FormData;
    expect(body.get("context")).toBeNull();
  });

  test("throws on non-ok response", async () => {
    const api = makeApi(async () => new Response("bad", { status: 422 }));
    await expect(api.sendMessage("x")).rejects.toThrow(/422/);
  });
});

describe("ChatApi.fetchAssetInfo", () => {
  test("returns an empty array immediately when no ids are passed", async () => {
    let called = false;
    const api = makeApi(async () => {
      called = true;
      return new Response("{}", { status: 200 });
    });

    const out = await api.fetchAssetInfo([]);
    expect(out).toEqual([]);
    expect(called).toBe(false);
  });

  test("requests asset info with a JSON-encoded ids query param", async () => {
    let capturedUrl = "";
    const api = makeApi(async (input) => {
      capturedUrl = String(input);
      return new Response(
        JSON.stringify({
          assets: [
            {
              id: 7,
              label: "photo",
              filename: "photo.jpg",
              kind: "image",
              mimeType: "image/jpeg",
              thumbUrl: "/thumb.jpg",
            },
          ],
        }),
        { status: 200, headers: { "Content-Type": "application/json" } },
      );
    });

    const out = await api.fetchAssetInfo([7]);
    expect(capturedUrl).toContain("ids=%5B7%5D");
    expect(out).toHaveLength(1);
    expect(out[0]?.thumbUrl).toBe("/thumb.jpg");
  });

  test("returns an empty array when the response shape is unexpected", async () => {
    const api = makeApi(async () =>
      new Response(JSON.stringify({ unexpected: true }), { status: 200 }),
    );

    const out = await api.fetchAssetInfo([1]);
    expect(out).toEqual([]);
  });

  test("throws on non-ok response", async () => {
    const api = makeApi(async () => new Response("bad", { status: 500 }));
    await expect(api.fetchAssetInfo([1])).rejects.toThrow(/500/);
  });
});
