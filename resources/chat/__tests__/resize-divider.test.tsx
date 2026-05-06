import { afterEach, describe, expect, test } from "bun:test";
import { cleanup, fireEvent, render, screen } from "@testing-library/react";
import { useRef } from "react";
import { ResizeDivider } from "../components/resize-divider";

afterEach(() => cleanup());

interface HarnessProps {
  ariaValueNow: number;
  onResize: (percent: number) => void;
  containerWidth?: number;
}

function Harness({ ariaValueNow, onResize, containerWidth = 1000 }: HarnessProps) {
  const ref = useRef<HTMLDivElement | null>(null);
  return (
    <div
      ref={(el) => {
        ref.current = el;
        if (el) {
          // happy-dom returns 0-width rects by default; pin a known width so
          // the percentage math is deterministic without needing a real layout.
          el.getBoundingClientRect = () =>
            ({
              left: 0,
              top: 0,
              right: containerWidth,
              bottom: 0,
              width: containerWidth,
              height: 0,
              x: 0,
              y: 0,
              toJSON() {},
            }) as DOMRect;
        }
      }}
      data-testid="container"
      style={{ width: containerWidth }}
    >
      <ResizeDivider
        containerRef={ref}
        onResize={onResize}
        ariaValueNow={ariaValueNow}
      />
    </div>
  );
}

describe("<ResizeDivider />", () => {
  test("renders with separator role and the supplied aria-valuenow", () => {
    render(<Harness ariaValueNow={67} onResize={() => {}} />);
    const divider = screen.getByTestId("preview-resize");
    expect(divider.getAttribute("role")).toBe("separator");
    expect(divider.getAttribute("aria-orientation")).toBe("vertical");
    expect(divider.getAttribute("aria-valuenow")).toBe("67");
    expect(divider.getAttribute("aria-valuemin")).toBe("20");
    expect(divider.getAttribute("aria-valuemax")).toBe("80");
  });

  test("rounds fractional aria-valuenow", () => {
    render(<Harness ariaValueNow={42.6} onResize={() => {}} />);
    expect(screen.getByTestId("preview-resize").getAttribute("aria-valuenow")).toBe("43");
  });

  test("pointer down + move reports percentages computed against the container", () => {
    const calls: number[] = [];
    render(
      <Harness ariaValueNow={50} onResize={(p) => calls.push(p)} containerWidth={800} />,
    );
    const divider = screen.getByTestId("preview-resize");

    // Drag-start arms the listeners but doesn't itself emit a percentage.
    fireEvent.pointerDown(divider, { clientX: 400 });
    expect(divider.getAttribute("data-dragging")).toBe("true");
    expect(calls).toEqual([]);

    // 200 / 800 = 25%
    fireEvent.pointerMove(window, { clientX: 200 });
    // 600 / 800 = 75%
    fireEvent.pointerMove(window, { clientX: 600 });

    expect(calls).toEqual([25, 75]);
  });

  test("pointer up clears the dragging state and stops emitting", () => {
    const calls: number[] = [];
    render(<Harness ariaValueNow={50} onResize={(p) => calls.push(p)} />);
    const divider = screen.getByTestId("preview-resize");

    fireEvent.pointerDown(divider, { clientX: 500 });
    fireEvent.pointerMove(window, { clientX: 100 });
    expect(calls.length).toBe(1);

    fireEvent.pointerUp(window);
    expect(divider.getAttribute("data-dragging")).toBe("false");

    fireEvent.pointerMove(window, { clientX: 900 });
    // No additional call after pointerup — the listener is gone.
    expect(calls.length).toBe(1);
  });

  test("pointer cancel is treated like pointer up", () => {
    const calls: number[] = [];
    render(<Harness ariaValueNow={50} onResize={(p) => calls.push(p)} />);
    const divider = screen.getByTestId("preview-resize");

    fireEvent.pointerDown(divider, { clientX: 500 });
    fireEvent.pointerCancel(window);
    expect(divider.getAttribute("data-dragging")).toBe("false");

    fireEvent.pointerMove(window, { clientX: 200 });
    expect(calls.length).toBe(0);
  });
});
