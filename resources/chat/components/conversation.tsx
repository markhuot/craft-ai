import { type HTMLAttributes, type ReactNode, useEffect, useRef, useState } from "react";
import { ChevronDown } from "lucide-react";
import { cn } from "../lib/utils";

interface ConversationProps extends HTMLAttributes<HTMLDivElement> {
  children: ReactNode;
}

export function Conversation({ className, children, ...rest }: ConversationProps) {
  return (
    <div
      data-slot="conversation"
      className={cn("ai:relative ai:flex ai:h-full ai:min-h-0 ai:flex-1 ai:flex-col ai:overflow-hidden", className)}
      {...rest}
    >
      {children}
    </div>
  );
}

interface ConversationContentProps extends HTMLAttributes<HTMLDivElement> {
  children: ReactNode;
}

export function ConversationContent({ className, children, ...rest }: ConversationContentProps) {
  const ref = useRef<HTMLDivElement | null>(null);
  const [isAtBottom, setIsAtBottom] = useState(true);

  useEffect(() => {
    const el = ref.current;
    if (!el) return;
    const onScroll = () => {
      const distance = el.scrollHeight - el.scrollTop - el.clientHeight;
      setIsAtBottom(distance < 32);
    };
    el.addEventListener("scroll", onScroll, { passive: true });
    return () => el.removeEventListener("scroll", onScroll);
  }, []);

  useEffect(() => {
    const el = ref.current;
    if (!el || !isAtBottom) return;
    el.scrollTop = el.scrollHeight;
  });

  return (
    <div
      ref={ref}
      data-slot="conversation-content"
      data-at-bottom={isAtBottom}
      className={cn("ai:flex ai:flex-1 ai:flex-col ai:gap-3 ai:overflow-y-auto ai:px-1 ai:py-2", className)}
      {...rest}
    >
      {children}
    </div>
  );
}

interface ConversationScrollButtonProps extends HTMLAttributes<HTMLButtonElement> {
  targetRef?: React.RefObject<HTMLDivElement | null>;
}

export function ConversationScrollButton({ className, ...rest }: ConversationScrollButtonProps) {
  return (
    <button
      type="button"
      aria-label="Scroll to latest"
      data-slot="conversation-scroll-button"
      className={cn(
        "ai:absolute ai:bottom-3 ai:right-3 ai:inline-flex ai:h-9 ai:w-9 ai:items-center ai:justify-center ai:rounded-full ai:border ai:border-craftai-border ai:bg-white ai:text-craftai-muted ai:shadow-sm ai:transition ai:hover:bg-craftai-user",
        className,
      )}
      {...rest}
    >
      <ChevronDown className="ai:h-4 ai:w-4" aria-hidden />
    </button>
  );
}
