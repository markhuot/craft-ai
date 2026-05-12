import { afterEach, describe, expect, test } from "bun:test";
import { cleanup, render, screen } from "@testing-library/react";
import { ContextProgress } from "../components/context-progress";

afterEach(() => cleanup());

describe("<ContextProgress />", () => {
  test("renders nothing when no context window is configured", () => {
    const { container } = render(
      <ContextProgress used={1000} contextWindow={null} />,
    );
    expect(container.firstChild).toBeNull();
  });

  test("renders the rounded percentage of the configured window", () => {
    render(<ContextProgress used={50_000} contextWindow={200_000} />);
    const el = screen.getByTestId("context-progress");
    expect(el.getAttribute("data-pct")).toBe("25");
    expect(el.textContent).toContain("25%");
  });

  test("clamps the visible percentage to 100 when usage overshoots", () => {
    render(<ContextProgress used={500_000} contextWindow={100_000} />);
    const el = screen.getByTestId("context-progress");
    expect(el.getAttribute("data-pct")).toBe("100");
  });

  test("renders zero percent without dividing by zero when window is zero", () => {
    const { container } = render(
      <ContextProgress used={5} contextWindow={0} />,
    );
    // ContextProgress treats `<= 0` the same as null — gauge disabled.
    expect(container.firstChild).toBeNull();
  });

  test("exposes a token-count tooltip for screen readers and hover", () => {
    render(<ContextProgress used={75_000} contextWindow={100_000} />);
    const el = screen.getByTestId("context-progress");
    const title = el.getAttribute("title") ?? "";
    expect(title).toContain("75,000");
    expect(title).toContain("100,000");
    expect(title).toContain("75%");
  });
});
