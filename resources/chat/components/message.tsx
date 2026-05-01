import type { HTMLAttributes, ReactNode } from "react";
import { cn } from "../lib/utils";
import type { Role } from "../types";

interface MessageProps extends HTMLAttributes<HTMLDivElement> {
  from: Role;
  children: ReactNode;
}

export function Message({ from, className, children, ...rest }: MessageProps) {
  return (
    <div
      data-slot="message"
      data-from={from}
      className={cn(
        "ai:flex ai:w-full ai:gap-2",
        from === "user" ? "ai:justify-end" : "ai:justify-start",
        className,
      )}
      {...rest}
    >
      {children}
    </div>
  );
}

interface MessageContentProps extends HTMLAttributes<HTMLDivElement> {
  from?: Role;
  children: ReactNode;
}

export function MessageContent({ from = "assistant", className, children, ...rest }: MessageContentProps) {
  return (
    <div
      data-slot="message-content"
      className={cn(
        "ai:max-w-[85%] ai:rounded-lg ai:border ai:px-3 ai:py-2 ai:text-sm ai:leading-relaxed",
        from === "user"
          ? "ai:border-craftai-border ai:bg-craftai-user ai:text-slate-900"
          : "ai:border-craftai-border ai:bg-white ai:text-slate-900",
        className,
      )}
      {...rest}
    >
      {children}
    </div>
  );
}
