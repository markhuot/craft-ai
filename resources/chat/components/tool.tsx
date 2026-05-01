import { type ReactNode, useState } from "react";
import { ChevronRight, Wrench } from "lucide-react";
import { cn } from "../lib/utils";

interface ToolProps {
  className?: string;
  defaultOpen?: boolean;
  children: ReactNode;
}

interface ToolContextValue {
  open: boolean;
  setOpen: (open: boolean) => void;
}

import { createContext, useContext } from "react";

const ToolContext = createContext<ToolContextValue | null>(null);

function useTool(): ToolContextValue {
  const ctx = useContext(ToolContext);
  if (!ctx) throw new Error("Tool subcomponents must be used inside <Tool>");
  return ctx;
}

export function Tool({ className, defaultOpen = false, children }: ToolProps) {
  const [open, setOpen] = useState(defaultOpen);
  return (
    <ToolContext.Provider value={{ open, setOpen }}>
      <div
        data-slot="tool"
        data-state={open ? "open" : "closed"}
        className={cn(
          "ai:rounded-md ai:border ai:border-craftai-border ai:bg-white ai:text-xs",
          className,
        )}
      >
        {children}
      </div>
    </ToolContext.Provider>
  );
}

interface ToolHeaderProps {
  name: string;
  status?: "running" | "complete" | "error";
  className?: string;
}

export function ToolHeader({ name, status = "complete", className }: ToolHeaderProps) {
  const { open, setOpen } = useTool();
  return (
    <button
      type="button"
      data-slot="tool-header"
      data-status={status}
      onClick={() => setOpen(!open)}
      aria-expanded={open}
      className={cn(
        "ai:flex ai:w-full ai:items-center ai:gap-2 ai:px-3 ai:py-2 ai:text-left",
        className,
      )}
    >
      <ChevronRight
        className={cn("ai:h-3.5 ai:w-3.5 ai:transition-transform", open && "ai:rotate-90")}
        aria-hidden
      />
      <Wrench className="ai:h-3.5 ai:w-3.5 ai:text-craftai-muted" aria-hidden />
      <span className="ai:font-medium">{name}</span>
      <span
        className={cn(
          "ai:ml-auto ai:rounded-full ai:px-2 ai:py-0.5 ai:text-[10px] ai:uppercase ai:tracking-wide",
          status === "error" && "ai:bg-red-100 ai:text-red-700",
          status === "running" && "ai:bg-amber-100 ai:text-amber-700",
          status === "complete" && "ai:bg-emerald-100 ai:text-emerald-700",
        )}
      >
        {status}
      </span>
    </button>
  );
}

interface ToolContentProps {
  className?: string;
  children: ReactNode;
}

export function ToolContent({ className, children }: ToolContentProps) {
  const { open } = useTool();
  if (!open) return null;
  return (
    <div
      data-slot="tool-content"
      className={cn("ai:border-t ai:border-craftai-border ai:px-3 ai:py-2", className)}
    >
      {children}
    </div>
  );
}

interface ToolInputProps {
  input: unknown;
  className?: string;
}

export function ToolInput({ input, className }: ToolInputProps) {
  return (
    <div data-slot="tool-input" className={cn("ai:space-y-1", className)}>
      <div className="ai:text-[10px] ai:font-medium ai:uppercase ai:tracking-wide ai:text-craftai-muted">
        Input
      </div>
      <pre className="ai:overflow-x-auto ai:rounded ai:bg-slate-50 ai:p-2 ai:text-[11px]">
        {JSON.stringify(input, null, 2)}
      </pre>
    </div>
  );
}

interface ToolOutputProps {
  output: string;
  isError?: boolean;
  className?: string;
}

export function ToolOutput({ output, isError, className }: ToolOutputProps) {
  return (
    <div data-slot="tool-output" className={cn("ai:mt-2 ai:space-y-1", className)}>
      <div className="ai:text-[10px] ai:font-medium ai:uppercase ai:tracking-wide ai:text-craftai-muted">
        {isError ? "Error" : "Output"}
      </div>
      <pre
        className={cn(
          "ai:overflow-x-auto ai:rounded ai:p-2 ai:text-[11px]",
          isError ? "ai:bg-craftai-error-bg" : "ai:bg-slate-50",
        )}
      >
        {output}
      </pre>
    </div>
  );
}
