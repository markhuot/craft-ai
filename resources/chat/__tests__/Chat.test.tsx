import { afterEach, describe, expect, test } from "bun:test";
import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { Chat } from "../Chat";
import { ChatApi } from "../api";
import type { ChatBootstrap, ChatMessage } from "../types";

afterEach(() => cleanup());

function bootstrap(initialMessages: ChatMessage[] = []): ChatBootstrap {
  return {
    sessionId: "session-1",
    messagesUrl: "http://localhost/messages",
    sendUrl: "http://localhost/send",
    csrfTokenName: "CRAFT_CSRF",
    csrfTokenValue: "tok",
    initialMessages,
  };
}

function makeApi(overrides: Partial<{
  fetchMessagesAfter: (lastId: number) => Promise<ChatMessage[]>;
  sendMessage: (msg: string) => Promise<void>;
}> = {}) {
  const api = new ChatApi({
    messagesUrl: "http://localhost/messages",
    sendUrl: "http://localhost/send",
    sessionId: "session-1",
    csrfTokenName: "CRAFT_CSRF",
    csrfTokenValue: "tok",
    fetchImpl: async () => new Response("[]", { status: 200 }),
  });
  if (overrides.fetchMessagesAfter) {
    api.fetchMessagesAfter = overrides.fetchMessagesAfter;
  }
  if (overrides.sendMessage) {
    api.sendMessage = overrides.sendMessage;
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
      fetchMessagesAfter: async () => [],
    });

    render(<Chat bootstrap={bootstrap()} api={api} pollIntervalMs={1_000_000} />);

    const textarea = screen.getByPlaceholderText("Send a message…") as HTMLTextAreaElement;
    fireEvent.change(textarea, { target: { value: "ping" } });
    const submit = screen.getByRole("button");
    fireEvent.click(submit);

    await waitFor(() => expect(sent).toBe("ping"));
    await waitFor(() => expect(textarea.value).toBe(""));
  });

  test("submit is disabled when the draft is empty", () => {
    render(<Chat bootstrap={bootstrap()} api={makeApi()} pollIntervalMs={1_000_000} />);
    const submit = screen.getByRole("button") as HTMLButtonElement;
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
    fireEvent.click(screen.getByRole("button"));
    await waitFor(() => expect(screen.getByRole("alert").textContent).toContain("network down"));
  });

  test("polling appends new messages", async () => {
    let calls = 0;
    const api = makeApi({
      fetchMessagesAfter: async () => {
        calls += 1;
        if (calls === 1) {
          return [
            { id: 5, role: "assistant", content: [{ type: "text", text: "later" }] },
          ];
        }
        return [];
      },
    });
    render(<Chat bootstrap={bootstrap()} api={api} pollIntervalMs={20} />);
    await waitFor(() => expect(screen.getByText("later")).toBeTruthy());
  });
});
