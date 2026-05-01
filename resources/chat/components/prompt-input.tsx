import {
  forwardRef,
  type ButtonHTMLAttributes,
  type FormHTMLAttributes,
  type ReactNode,
  type TextareaHTMLAttributes,
} from "react";
import { Loader2, Send } from "lucide-react";
import { cn } from "../lib/utils";

interface PromptInputProps extends FormHTMLAttributes<HTMLFormElement> {
  children: ReactNode;
}

export function PromptInput({ className, children, ...rest }: PromptInputProps) {
  return (
    <form
      data-slot="prompt-input"
      className={cn(
        "ai:flex ai:flex-col ai:gap-2 ai:rounded-lg ai:border ai:border-craftai-border ai:bg-white ai:p-2 ai:shadow-sm",
        className,
      )}
      {...rest}
    >
      {children}
    </form>
  );
}

interface PromptInputTextareaProps extends TextareaHTMLAttributes<HTMLTextAreaElement> {}

export const PromptInputTextarea = forwardRef<HTMLTextAreaElement, PromptInputTextareaProps>(
  ({ className, rows = 3, ...rest }, ref) => (
    <textarea
      ref={ref}
      rows={rows}
      data-slot="prompt-input-textarea"
      className={cn(
        "ai:block ai:w-full ai:resize-y ai:rounded-md ai:border-0 ai:bg-transparent ai:px-2 ai:py-1 ai:text-sm ai:focus:outline-none ai:focus:ring-0",
        className,
      )}
      {...rest}
    />
  ),
);
PromptInputTextarea.displayName = "PromptInputTextarea";

interface PromptInputToolbarProps {
  className?: string;
  children: ReactNode;
}

export function PromptInputToolbar({ className, children }: PromptInputToolbarProps) {
  return (
    <div
      data-slot="prompt-input-toolbar"
      className={cn("ai:flex ai:items-center ai:justify-end ai:gap-2", className)}
    >
      {children}
    </div>
  );
}

interface PromptInputSubmitProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  status?: "idle" | "submitting" | "streaming";
}

export function PromptInputSubmit({
  className,
  status = "idle",
  disabled,
  children,
  ...rest
}: PromptInputSubmitProps) {
  const busy = status !== "idle";
  return (
    <button
      type="submit"
      data-slot="prompt-input-submit"
      data-status={status}
      disabled={disabled || busy}
      className={cn(
        "ai:inline-flex ai:items-center ai:gap-1.5 ai:rounded-md ai:bg-slate-900 ai:px-3 ai:py-1.5 ai:text-xs ai:font-medium ai:text-white ai:transition ai:disabled:opacity-50",
        className,
      )}
      {...rest}
    >
      {busy ? <Loader2 className="ai:h-3.5 ai:w-3.5 ai:animate-spin" aria-hidden /> : <Send className="ai:h-3.5 ai:w-3.5" aria-hidden />}
      {children ?? (busy ? "Sending…" : "Send")}
    </button>
  );
}
