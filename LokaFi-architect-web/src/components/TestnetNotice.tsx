import { ShieldCheck } from "lucide-react";

export const STELLAR_TESTNET_NOTICE = "Stellar Testnet \u2014 no real money.";

type TestnetNoticeProps = {
    compact?: boolean;
};

export function TestnetNotice({ compact = false }: TestnetNoticeProps) {
    return (
        <div
            className={[
                "inline-flex items-center gap-2 rounded-2xl bg-blue-50 font-bold text-blue-700 ring-1 ring-blue-100",
                compact ? "px-3 py-2 text-xs" : "px-5 py-4 text-sm",
            ].join(" ")}
        >
            <ShieldCheck size={compact ? 15 : 18} />
            {STELLAR_TESTNET_NOTICE}
        </div>
    );
}
