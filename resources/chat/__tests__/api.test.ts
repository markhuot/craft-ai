import { describe, expect, test } from "bun:test";
import { ChatApi, type FetchLike } from "../api";

function makeApi(fetchImpl: FetchLike) {
  return new ChatApi({
    messagesUrl: "http://localhost/actions/craft-ai/messages",
    sendUrl: "http://localhost/actions/craft-ai/sessions/send",
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
  });

  test("throws on non-ok response", async () => {
    const api = makeApi(async () => new Response("bad", { status: 422 }));
    await expect(api.sendMessage("x")).rejects.toThrow(/422/);
  });
});
