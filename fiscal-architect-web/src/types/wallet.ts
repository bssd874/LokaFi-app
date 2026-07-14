export type WalletType = "cash" | "bank" | "ewallet" | "investment_cash";
export type WalletConnectionStatus =
  | "manual"
  | "connected"
  | "expired"
  | "failed"
  | "revoked";
export type WalletSyncSource =
  | "manual"
  | "brankas"
  | "open_banking_simulator"
  | "open_banking_provider"
  | "portfolio_simulator";

export type Wallet = {
  id: number;
  user_id: number;
  name: string;
  type: WalletType;
  currency: string;
  opening_balance: string;
  current_balance: string;
  is_active: boolean;
  provider_code?: string | null;
  account_number_masked?: string | null;
  connection_status?: WalletConnectionStatus | null;
  sync_source?: WalletSyncSource | null;
  last_synced_at?: string | null;
  created_at: string;
  updated_at: string;
};

export type CreateWalletPayload = {
  name: string;
  type: WalletType;
  currency: string;
  opening_balance: number;
};

export type WalletResponse = {
  message: string;
  data: Wallet;
};

export type WalletListResponse = {
  message: string;
  data: Wallet[];
};
