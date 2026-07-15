import {
    getAddress,
    getNetwork,
    isConnected,
    requestAccess,
    signTransaction,
} from "@stellar/freighter-api";
import {
    Asset,
    BASE_FEE,
    Horizon,
    Memo,
    Networks,
    Operation,
    TransactionBuilder,
} from "@stellar/stellar-sdk";
import type {
    StellarBalance,
    StellarNetwork,
    StellarWalletProvider,
} from "../../types/stellar";

export const STELLAR_TESTNET_NETWORK: StellarNetwork = "testnet";
export const STELLAR_WALLET_PROVIDER: StellarWalletProvider = "freighter";
export const STELLAR_TESTNET_HORIZON_URL =
    "https://horizon-testnet.stellar.org";
const FREIGHTER_TESTNET_NAME = "TESTNET";

type FreighterAvailability = {
    available: boolean;
    message: string;
};

type FreighterTestnetInfo = {
    network: StellarNetwork;
    networkName: string;
    networkPassphrase: string;
};

type ConnectedFreighterWallet = {
    publicKey: string;
    network: StellarNetwork;
    walletProvider: StellarWalletProvider;
    networkName: string;
    networkPassphrase: string;
};

type SubmitNativeXlmPaymentArgs = {
    recipientPublicKey: string;
    xlmAmount: string | number;
    paymentMemo: string;
};

type SubmitNativeXlmPaymentCallbacks = {
    onAwaitingWalletApproval?: () => void;
    onSubmitting?: () => void;
};

export type SubmittedNativeXlmPayment = {
    customerPublicKey: string;
    transactionHash: string;
    ledger: number;
    successful: boolean;
    horizonUrl: string;
};

type HorizonErrorShape = {
    response?: {
        status?: number;
        data?: unknown;
    };
};

function logStellarDiagnostic(label: string, payload: Record<string, unknown>) {
    if (!import.meta.env.DEV) return;

    console.debug(`[stellar] ${label}`, payload);
}

function sanitizeFreighterError(error: unknown) {
    if (!error || typeof error !== "object") return null;

    return {
        code: "code" in error ? (error as { code?: unknown }).code : undefined,
        message: "message" in error ? (error as { message?: unknown }).message : undefined,
    };
}

function getAddressPreview(address: string) {
    if (!address) return "";

    return `${address.slice(0, 6)}...${address.slice(-6)}`;
}

function hasFreighterInjection() {
    if (typeof window === "undefined") return false;

    const freighterWindow = window as Window & {
        freighter?: boolean | object;
        freighterApi?: unknown;
    };

    return Boolean(freighterWindow.freighter || freighterWindow.freighterApi);
}

function getFreighterErrorMessage(error: unknown, fallback: string) {
    if (error && typeof error === "object" && "message" in error) {
        const message = (error as { message?: unknown }).message;

        if (typeof message === "string" && message.trim()) {
            return message;
        }
    }

    return fallback;
}

function isHorizonNotFound(error: unknown) {
    if (!error || typeof error !== "object" || !("response" in error)) {
        return false;
    }

    const response = (error as HorizonErrorShape).response;
    return response?.status === 404;
}

function normalizeXlmAmount(value: string | number) {
    const amount = String(value).trim();

    if (!/^\d+(\.\d+)?$/.test(amount)) {
        throw new Error("Nominal XLM invoice tidak valid.");
    }

    const [integerPart, fractionPart = ""] = amount.split(".");
    const extraPrecision = fractionPart.slice(7);

    if (extraPrecision && extraPrecision.replace(/0/g, "") !== "") {
        throw new Error("Nominal XLM tidak boleh lebih dari 7 angka desimal.");
    }

    return `${integerPart.replace(/^0+(?=\d)/, "")}.${fractionPart
        .slice(0, 7)
        .padEnd(7, "0")}`;
}

function ensureMemoFitsStellarTextMemo(paymentMemo: string) {
    const memoBytes = new TextEncoder().encode(paymentMemo).length;

    if (memoBytes > 28) {
        throw new Error("Payment memo invoice melebihi batas 28 byte Stellar.");
    }
}

function getHorizonErrorMessage(error: unknown, fallback: string) {
    if (!error || typeof error !== "object" || !("response" in error)) {
        return fallback;
    }

    const response = (error as HorizonErrorShape).response;

    return `Horizon Testnet menolak transaksi${response?.status ? ` (${response.status})` : ""}.`;
}

export async function detectFreighterAvailability(): Promise<FreighterAvailability> {
    try {
        const connectionStatus = await isConnected();

        logStellarDiagnostic("isConnected result", {
            isConnected: connectionStatus.isConnected,
            error: sanitizeFreighterError(connectionStatus.error),
        });

        if (connectionStatus.error) {
            return {
                available: false,
                message: getFreighterErrorMessage(
                    connectionStatus.error,
                    "Freighter tidak tersedia di browser ini.",
                ),
            };
        }

        if (connectionStatus.isConnected || hasFreighterInjection()) {
            return {
                available: true,
                message: "Freighter tersedia.",
            };
        }
    } catch (error: unknown) {
        return {
            available: false,
            message: getFreighterErrorMessage(
                error,
                "Freighter tidak tersedia di browser ini.",
            ),
        };
    }

    return {
        available: false,
        message: "Freighter tidak tersedia. Install atau aktifkan extension Freighter dulu.",
    };
}

export async function ensureFreighterTestnet(): Promise<FreighterTestnetInfo> {
    const networkResponse = await getNetwork();

    logStellarDiagnostic("getNetwork result", {
        network: networkResponse.network,
        networkPassphrase: networkResponse.networkPassphrase,
        error: sanitizeFreighterError(networkResponse.error),
    });

    if (networkResponse.error) {
        throw new Error(
            getFreighterErrorMessage(
                networkResponse.error,
                "Gagal membaca network aktif dari Freighter.",
            ),
        );
    }

    const networkName = networkResponse.network || "";
    const networkPassphrase = networkResponse.networkPassphrase || "";
    const isTestnet =
        networkName.toUpperCase() === FREIGHTER_TESTNET_NAME ||
        networkPassphrase === Networks.TESTNET;

    if (!isTestnet) {
        throw new Error(
            "Freighter harus aktif di Stellar Testnet. Ganti network ke Testnet lalu coba lagi.",
        );
    }

    return {
        network: STELLAR_TESTNET_NETWORK,
        networkName: networkName || "Testnet",
        networkPassphrase,
    };
}

export async function getFreighterPublicKey() {
    const addressResponse = await getAddress();

    if (addressResponse.error) {
        throw new Error(
            getFreighterErrorMessage(
                addressResponse.error,
                "Gagal membaca public key dari Freighter.",
            ),
        );
    }

    if (!addressResponse.address) {
        throw new Error("Public key Freighter belum tersedia. Klik Connect untuk memberi akses.");
    }

    return addressResponse.address;
}

export async function connectFreighterWallet(): Promise<ConnectedFreighterWallet> {
    const availability = await detectFreighterAvailability();

    if (!availability.available) {
        throw new Error(availability.message);
    }

    await ensureFreighterTestnet();

    const accessResponse = await requestAccess();

    logStellarDiagnostic("requestAccess result", {
        hasAddress: Boolean(accessResponse.address),
        addressPreview: getAddressPreview(accessResponse.address),
        error: sanitizeFreighterError(accessResponse.error),
    });

    if (accessResponse.error) {
        throw new Error(
            getFreighterErrorMessage(
                accessResponse.error,
                "Akses Freighter ditolak atau gagal.",
            ),
        );
    }

    if (!accessResponse.address) {
        throw new Error("Freighter tidak mengembalikan public key.");
    }

    const networkInfo = await ensureFreighterTestnet();

    return {
        publicKey: accessResponse.address,
        network: STELLAR_TESTNET_NETWORK,
        walletProvider: STELLAR_WALLET_PROVIDER,
        networkName: networkInfo.networkName,
        networkPassphrase: networkInfo.networkPassphrase,
    };
}

export async function disconnectFreighterLocalSession() {
    return {
        connected: false,
        publicKey: null,
    };
}

export async function getNativeTestnetXlmBalance(
    publicKey: string,
): Promise<StellarBalance> {
    const server = new Horizon.Server(STELLAR_TESTNET_HORIZON_URL);

    try {
        const account = await server.loadAccount(publicKey);
        const nativeBalance = account.balances.find(
            (balance) => balance.asset_type === "native",
        );

        return {
            asset_code: "XLM",
            balance: nativeBalance?.balance ?? "0",
            is_funded: true,
            horizon_url: STELLAR_TESTNET_HORIZON_URL,
        };
    } catch (error: unknown) {
        if (isHorizonNotFound(error)) {
            return {
                asset_code: "XLM",
                balance: "0",
                is_funded: false,
                horizon_url: STELLAR_TESTNET_HORIZON_URL,
            };
        }

        throw new Error("Gagal mengambil saldo native XLM dari Stellar Testnet.", {
            cause: error,
        });
    }
}

export async function submitNativeTestnetXlmPayment(
    args: SubmitNativeXlmPaymentArgs,
    callbacks: SubmitNativeXlmPaymentCallbacks = {},
): Promise<SubmittedNativeXlmPayment> {
    ensureMemoFitsStellarTextMemo(args.paymentMemo);

    const amount = normalizeXlmAmount(args.xlmAmount);
    const wallet = await connectFreighterWallet();
    const server = new Horizon.Server(STELLAR_TESTNET_HORIZON_URL);
    const sourceAccount = await server.loadAccount(wallet.publicKey);

    const transaction = new TransactionBuilder(sourceAccount, {
        fee: BASE_FEE,
        networkPassphrase: Networks.TESTNET,
    })
        .addOperation(
            Operation.payment({
                destination: args.recipientPublicKey,
                asset: Asset.native(),
                amount,
            }),
        )
        .addMemo(Memo.text(args.paymentMemo))
        .setTimeout(180)
        .build();

    callbacks.onAwaitingWalletApproval?.();

    const signResponse = await signTransaction(transaction.toXDR(), {
        networkPassphrase: Networks.TESTNET,
        address: wallet.publicKey,
    });

    logStellarDiagnostic("signTransaction result", {
        hasSignedTxXdr: Boolean(signResponse.signedTxXdr),
        signerAddressPreview: getAddressPreview(signResponse.signerAddress),
        error: sanitizeFreighterError(signResponse.error),
    });

    if (signResponse.error) {
        throw new Error(
            getFreighterErrorMessage(
                signResponse.error,
                "Freighter menolak atau gagal menandatangani transaksi.",
            ),
        );
    }

    if (!signResponse.signedTxXdr) {
        throw new Error("Freighter tidak mengembalikan transaksi yang sudah ditandatangani.");
    }

    const signedTransaction = TransactionBuilder.fromXDR(
        signResponse.signedTxXdr,
        Networks.TESTNET,
    );

    callbacks.onSubmitting?.();

    try {
        const submitResponse = await server.submitTransaction(signedTransaction);

        logStellarDiagnostic("submitTransaction result", {
            transactionHash: submitResponse.hash,
            ledger: submitResponse.ledger,
            successful: submitResponse.successful,
        });

        if (!submitResponse.hash) {
            throw new Error("Horizon Testnet tidak mengembalikan transaction hash.");
        }

        return {
            customerPublicKey: wallet.publicKey,
            transactionHash: submitResponse.hash,
            ledger: submitResponse.ledger,
            successful: submitResponse.successful,
            horizonUrl: STELLAR_TESTNET_HORIZON_URL,
        };
    } catch (error: unknown) {
        throw new Error(
            getHorizonErrorMessage(
                error,
                "Gagal submit transaksi ke Stellar Testnet.",
            ),
            { cause: error },
        );
    }
}
