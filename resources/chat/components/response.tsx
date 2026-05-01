import ReactMarkdown from "react-markdown";
import remarkGfm from "remark-gfm";
import { cn } from "../lib/utils";

interface ResponseProps {
  className?: string;
  children: string;
}

export function Response({ className, children }: ResponseProps) {
  return (
    <div
      data-slot="response"
      className={cn(
        "ai:prose ai:prose-sm ai:max-w-none ai:[&>*:first-child]:mt-0 ai:[&>*:last-child]:mb-0",
        "ai:[&_pre]:overflow-x-auto ai:[&_pre]:rounded-md ai:[&_pre]:bg-slate-100 ai:[&_pre]:p-2 ai:[&_pre]:text-xs",
        "ai:[&_code]:rounded ai:[&_code]:bg-slate-100 ai:[&_code]:px-1 ai:[&_code]:py-0.5 ai:[&_code]:text-xs",
        "ai:[&_pre_code]:bg-transparent ai:[&_pre_code]:p-0",
        className,
      )}
    >
      <ReactMarkdown remarkPlugins={[remarkGfm]}>{children}</ReactMarkdown>
    </div>
  );
}
