import {
  forwardRef,
  type ButtonHTMLAttributes,
  type FormHTMLAttributes,
  type ReactNode,
  type TextareaHTMLAttributes,
} from "react";
import { Loader2, Paperclip, Send, X } from "lucide-react";
import { cn } from "../lib/utils";
import type { Attachment } from "../types";

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
      className={cn("ai:flex ai:items-center ai:justify-between ai:gap-2", className)}
    >
      {children}
    </div>
  );
}

interface PromptInputUploadProps extends ButtonHTMLAttributes<HTMLButtonElement> {}

export function PromptInputUpload({ className, children, ...rest }: PromptInputUploadProps) {
  return (
    <button
      type="button"
      data-slot="prompt-input-upload"
      className={cn(
        "ai:inline-flex ai:items-center ai:gap-1.5 ai:rounded-md ai:border ai:border-craftai-border ai:bg-white ai:px-3 ai:py-1.5 ai:text-xs ai:font-medium ai:text-craftai-fg ai:transition hover:ai:bg-craftai-border/20 ai:disabled:opacity-50 ai:disabled:cursor-not-allowed",
        className,
      )}
      {...rest}
    >
      <Paperclip className="ai:h-3.5 ai:w-3.5" aria-hidden />
      {children ?? "Upload"}
    </button>
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
        "ai:inline-flex ai:items-center ai:gap-1.5 ai:rounded-md ai:bg-slate-900 ai:px-3 ai:py-1.5 ai:text-xs ai:font-medium ai:text-white ai:transition hover:ai:bg-slate-700 ai:disabled:opacity-50 ai:disabled:hover:bg-slate-900",
        className,
      )}
      {...rest}
    >
      {busy ? <Loader2 className="ai:h-3.5 ai:w-3.5 ai:animate-spin" aria-hidden /> : <Send className="ai:h-3.5 ai:w-3.5" aria-hidden />}
      {children ?? (busy ? "Sending…" : "Send")}
    </button>
  );
}

interface PromptInputAttachmentsProps {
  attachments: Attachment[];
  onRemove?: (id: number) => void;
  className?: string;
}

export function PromptInputAttachments({ attachments, onRemove, className }: PromptInputAttachmentsProps) {
  if (attachments.length === 0) return null;
  return (
    <div
      data-slot="prompt-input-attachments"
      className={cn("ai:flex ai:flex-wrap ai:gap-2 ai:px-1", className)}
    >
      {attachments.map((a) => (
        <AttachmentChip key={a.id} attachment={a} onRemove={onRemove} />
      ))}
    </div>
  );
}

interface AttachmentChipProps {
  attachment: Attachment;
  onRemove?: (id: number) => void;
}

export function AttachmentChip({ attachment, onRemove }: AttachmentChipProps) {
  return (
    <div
      data-slot="attachment-chip"
      data-attachment-id={attachment.id}
      title={attachment.filename ?? attachment.label}
      className="ai:relative ai:overflow-hidden ai:rounded ai:border ai:border-craftai-border ai:bg-white"
    >
      {attachment.thumbUrl ? (
        <img
          src={attachment.thumbUrl}
          alt={attachment.label}
          className="ai:block ai:h-12 ai:w-12 ai:object-cover"
        />
      ) : (
        <div className="ai:flex ai:h-12 ai:w-12 ai:items-center ai:justify-center ai:bg-slate-100 ai:text-[10px] ai:uppercase ai:tracking-wide ai:text-slate-500">
          {attachment.kind ?? "file"}
        </div>
      )}
      {onRemove && (
        <button
          type="button"
          data-slot="attachment-remove"
          aria-label={`Remove ${attachment.label}`}
          onClick={() => onRemove(attachment.id)}
          className="ai:absolute ai:top-0 ai:right-0 ai:flex ai:h-4 ai:w-4 ai:items-center ai:justify-center ai:rounded-bl ai:bg-black/60 ai:text-white hover:ai:bg-black/80"
        >
          <X className="ai:h-3 ai:w-3" aria-hidden />
        </button>
      )}
    </div>
  );
}
