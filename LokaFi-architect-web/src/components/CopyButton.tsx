import { useEffect, useState } from "react";
import { Check, Copy } from "lucide-react";

type CopyButtonProps = {
    value: string;
    label?: string;
    copiedLabel?: string;
    className?: string;
    disabled?: boolean;
};

export function CopyButton({
    value,
    label = "Copy",
    copiedLabel = "Copied",
    className = "",
    disabled = false,
}: CopyButtonProps) {
    const [copied, setCopied] = useState(false);
    const [failed, setFailed] = useState(false);

    async function handleCopy() {
        if (!value || disabled) return;

        try {
            await navigator.clipboard.writeText(value);
            setCopied(true);
            setFailed(false);
        } catch {
            setCopied(false);
            setFailed(true);
        }
    }

    useEffect(() => {
        if (!copied && !failed) return;

        const timeout = window.setTimeout(() => {
            setCopied(false);
            setFailed(false);
        }, 2200);

        return () => window.clearTimeout(timeout);
    }, [copied, failed]);

    return (
        <button
            type="button"
            onClick={handleCopy}
            disabled={disabled || !value}
            className={[
                "inline-flex items-center justify-center gap-2 rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50",
                failed ? "border-red-100 text-red-600" : "",
                copied ? "border-emerald-100 text-emerald-700" : "",
                className,
            ].join(" ")}
        >
            {copied ? <Check size={16} /> : <Copy size={16} />}
            {failed ? "Copy failed" : copied ? copiedLabel : label}
        </button>
    );
}
