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
      return new Response(JSON.stringify([{ id: 7, role: "assistant", content: [] }]), {
        status: 200,
        headers: { "Content-Type": "application/json" },
      });
    });

    const messages = await api.fetchMessagesAfter(3);
    expect(capturedUrl).toContain("sessionId=abc-123");
    expect(capturedUrl).toContain("after=3");
    expect(messages).toHaveLength(1);
    expect(messages[0]?.id).toBe(7);
  });

  test("returns empty array on non-array response", async () => {
    const api = makeApi(async () =>
      new Response(JSON.stringify({ unexpected: true }), { status: 200 }),
    );
    const messages = await api.fetchMessagesAfter(0);
    expect(messages).toEqual([]);
  });

  test("throws on non-ok response", async () => {
    const api = makeApi(async () => new Response("nope", { status: 500 }));
    await expect(api.fetchMessagesAfter(0)).rejects.toThrow(/500/);
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
