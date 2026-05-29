import ReactMarkdown from "react-markdown";
import remarkGfm from "remark-gfm";

interface MarkdownProps {
  children: string;
}

/**
 * Minimal markdown renderer for the narrative panel. Mirrors the chat
 * bundle's `Response` component (react-markdown + remark-gfm with the same
 * `ai:prose` styling), copied rather than imported because each bundle ships
 * independently. Kept small — the narrative is short prose, not code.
 */
export function Markdown({ children }: MarkdownProps) {
  return (
    <div
      data-slot="narrative-markdown"
      className={[
        "ai:prose ai:prose-sm ai:max-w-none ai:[&>*:first-child]:mt-0 ai:[&>*:last-child]:mb-0",
        "ai:[&_pre]:overflow-x-auto ai:[&_pre]:rounded-md ai:[&_pre]:bg-slate-100 ai:[&_pre]:p-2 ai:[&_pre]:text-xs",
        "ai:[&_code]:rounded ai:[&_code]:bg-slate-100 ai:[&_code]:px-1 ai:[&_code]:py-0.5 ai:[&_code]:text-xs",
        "ai:[&_pre_code]:bg-transparent ai:[&_pre_code]:p-0",
      ].join(" ")}
    >
      <ReactMarkdown remarkPlugins={[remarkGfm]}>{children}</ReactMarkdown>
    </div>
  );
}
