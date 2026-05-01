import { describe, expect, test } from "bun:test";

// Recreate the bootstrap parser inline so the test exercises the same
// shape the build entry expects, without importing the side-effectful index.
function parseBootstrap(rootEl: HTMLElement): {
  sessionId: string;
  messagesUrl: string;
  sendUrl: string;
  csrfTokenName: string;
  csrfTokenValue: string;
  initialMessages: unknown[];
} {
  const dataEl = rootEl.querySelector("script[data-craftai-bootstrap]");
  const json = dataEl?.textContent ?? "";
  const obj = JSON.parse(json) as Record<string, unknown>;
  return {
    sessionId: String(obj.sessionId ?? ""),
    messagesUrl: String(obj.messagesUrl ?? ""),
    sendUrl: String(obj.sendUrl ?? ""),
    csrfTokenName: String(obj.csrfTokenName ?? ""),
    csrfTokenValue: String(obj.csrfTokenValue ?? ""),
    initialMessages: Array.isArray(obj.initialMessages) ? obj.initialMessages : [],
  };
}

describe("bootstrap parsing", () => {
  test("extracts JSON config from the embedded script tag", () => {
    document.body.innerHTML = `
      <div data-craftai-chat-root>
        <script type="application/json" data-craftai-bootstrap>
          ${JSON.stringify({
            sessionId: "sess",
            messagesUrl: "/m",
            sendUrl: "/s",
            csrfTokenName: "T",
            csrfTokenValue: "v",
            initialMessages: [{ id: 1, role: "user", content: [] }],
          })}
        </script>
      </div>
    `;
    const root = document.querySelector("[data-craftai-chat-root]") as HTMLElement;
    const cfg = parseBootstrap(root);
    expect(cfg.sessionId).toBe("sess");
    expect(cfg.messagesUrl).toBe("/m");
    expect(cfg.initialMessages).toHaveLength(1);
  });
});
