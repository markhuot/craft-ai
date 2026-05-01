import { afterEach, describe, expect, test } from "bun:test";
import { cleanup, fireEvent, render, screen } from "@testing-library/react";
import { Message, MessageContent } from "../components/message";
import {
  PromptInput,
  PromptInputSubmit,
  PromptInputTextarea,
  PromptInputToolbar,
} from "../components/prompt-input";
import { Tool, ToolContent, ToolHeader, ToolInput, ToolOutput } from "../components/tool";
import { Response as MarkdownResponse } from "../components/response";

afterEach(() => cleanup());

describe("<Message />", () => {
  test("aligns user messages to the right via data-from", () => {
    const { container } = render(
      <Message from="user">
        <MessageContent from="user">hi</MessageContent>
      </Message>,
    );
    const el = container.querySelector('[data-slot="message"]') as HTMLElement;
    expect(el.dataset.from).toBe("user");
    expect(el.className).toContain("justify-end");
  });

  test("aligns assistant messages to the left", () => {
    const { container } = render(
      <Message from="assistant">
        <MessageContent>hello</MessageContent>
      </Message>,
    );
    const el = container.querySelector('[data-slot="message"]') as HTMLElement;
    expect(el.className).toContain("justify-start");
  });
});

describe("<PromptInput />", () => {
  test("submit button reflects status prop", () => {
    render(
      <PromptInput onSubmit={(e) => e.preventDefault()}>
        <PromptInputTextarea />
        <PromptInputToolbar>
          <PromptInputSubmit status="submitting" />
        </PromptInputToolbar>
      </PromptInput>,
    );
    const button = screen.getByRole("button") as HTMLButtonElement;
    expect(button.dataset.status).toBe("submitting");
    expect(button.disabled).toBe(true);
  });

  test("calls onSubmit when the form is submitted", () => {
    let called = 0;
    render(
      <PromptInput
        onSubmit={(e) => {
          e.preventDefault();
          called += 1;
        }}
      >
        <PromptInputTextarea defaultValue="hi" />
        <PromptInputToolbar>
          <PromptInputSubmit />
        </PromptInputToolbar>
      </PromptInput>,
    );
    fireEvent.click(screen.getByRole("button"));
    expect(called).toBe(1);
  });
});

describe("<Tool />", () => {
  test("toggles content open/closed", () => {
    const { container } = render(
      <Tool>
        <ToolHeader name="search" />
        <ToolContent>
          <ToolInput input={{ q: "craft" }} />
        </ToolContent>
      </Tool>,
    );
    const toolEl = container.querySelector('[data-slot="tool"]') as HTMLElement;
    expect(toolEl.dataset.state).toBe("closed");
    expect(container.querySelector('[data-slot="tool-content"]')).toBeNull();

    fireEvent.click(screen.getByRole("button"));
    expect(toolEl.dataset.state).toBe("open");
    expect(container.querySelector('[data-slot="tool-content"]')).not.toBeNull();
  });

  test("ToolOutput renders error styling when isError", () => {
    const { container } = render(
      <Tool defaultOpen>
        <ToolHeader name="x" status="error" />
        <ToolContent>
          <ToolOutput output="kaboom" isError />
        </ToolContent>
      </Tool>,
    );
    const pre = container.querySelector('[data-slot="tool-output"] pre') as HTMLElement;
    expect(pre.className).toContain("bg-craftai-error-bg");
  });
});

describe("<Response />", () => {
  test("renders inline code", () => {
    render(<MarkdownResponse>Use `craft up` to migrate.</MarkdownResponse>);
    const code = screen.getByText("craft up");
    expect(code.tagName.toLowerCase()).toBe("code");
  });

  test("renders fenced code blocks", () => {
    const md = "```\nhello\n```";
    const { container } = render(<MarkdownResponse>{md}</MarkdownResponse>);
    const pre = container.querySelector("pre");
    expect(pre).not.toBeNull();
    expect(pre?.textContent).toContain("hello");
  });
});
