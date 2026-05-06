import { afterEach, beforeEach, describe, expect, test } from "bun:test";
import { act, cleanup, render } from "@testing-library/react";
import { useResizableSplit } from "../lib/useResizableSplit";

afterEach(() => {
  cleanup();
  window.localStorage.clear();
});

beforeEach(() => {
  window.localStorage.clear();
});

interface ProbeProps {
  storageKey: string;
  defaultPercent: number;
  onSetter?: (set: (next: number) => void) => void;
  onValue?: (value: number) => void;
}

function Probe({ storageKey, defaultPercent, onSetter, onValue }: ProbeProps) {
  const [value, setValue] = useResizableSplit(storageKey, defaultPercent);
  onValue?.(value);
  onSetter?.(setValue);
  return <div data-testid="probe" data-value={String(value)} />;
}

// Wrapper objects sidestep TypeScript narrowing in callback-set values —
// without them, TS thinks `captured` is still its initial type at the
// expect() call site since the assignment happens inside a closure it
// can't trace.
function valueRef() {
  return { current: NaN as number };
}
function setterRef() {
  return { current: null as ((next: number) => void) | null };
}

describe("useResizableSplit", () => {
  test("returns the default when localStorage is empty", () => {
    const captured = valueRef();
    render(
      <Probe
        storageKey="key-empty"
        defaultPercent={50}
        onValue={(v) => {
          captured.current = v;
        }}
      />,
    );
    expect(captured.current).toBe(50);
  });

  test("clamps the default into [20, 80] when an out-of-range default is supplied", () => {
    const high = valueRef();
    render(
      <Probe
        storageKey="key-high"
        defaultPercent={120}
        onValue={(v) => {
          high.current = v;
        }}
      />,
    );
    expect(high.current).toBe(80);

    cleanup();

    const low = valueRef();
    render(
      <Probe
        storageKey="key-low"
        defaultPercent={5}
        onValue={(v) => {
          low.current = v;
        }}
      />,
    );
    expect(low.current).toBe(20);
  });

  test("hydrates from localStorage when a previously-saved value exists", () => {
    window.localStorage.setItem("key-saved", "42.5");
    const captured = valueRef();
    render(
      <Probe
        storageKey="key-saved"
        defaultPercent={50}
        onValue={(v) => {
          captured.current = v;
        }}
      />,
    );
    expect(captured.current).toBe(42.5);
  });

  test("clamps a stored value that's outside the allowed range", () => {
    window.localStorage.setItem("key-stale", "99");
    const captured = valueRef();
    render(
      <Probe
        storageKey="key-stale"
        defaultPercent={50}
        onValue={(v) => {
          captured.current = v;
        }}
      />,
    );
    expect(captured.current).toBe(80);
  });

  test("falls back to the default when the stored value is unparseable", () => {
    window.localStorage.setItem("key-junk", "not-a-number");
    const captured = valueRef();
    render(
      <Probe
        storageKey="key-junk"
        defaultPercent={50}
        onValue={(v) => {
          captured.current = v;
        }}
      />,
    );
    expect(captured.current).toBe(50);
  });

  test("persists updates to localStorage", () => {
    const setter = setterRef();
    render(
      <Probe
        storageKey="key-persist"
        defaultPercent={50}
        onSetter={(s) => {
          setter.current = s;
        }}
      />,
    );
    expect(setter.current).not.toBeNull();
    act(() => setter.current!(35));
    expect(window.localStorage.getItem("key-persist")).toBe("35");
  });

  test("clamps writes that exceed the allowed range", () => {
    const setter = setterRef();
    const latest = valueRef();
    render(
      <Probe
        storageKey="key-clamp"
        defaultPercent={50}
        onSetter={(s) => {
          setter.current = s;
        }}
        onValue={(v) => {
          latest.current = v;
        }}
      />,
    );
    act(() => setter.current!(120));
    expect(latest.current).toBe(80);
    expect(window.localStorage.getItem("key-clamp")).toBe("80");
    act(() => setter.current!(-50));
    expect(latest.current).toBe(20);
    expect(window.localStorage.getItem("key-clamp")).toBe("20");
  });
});
