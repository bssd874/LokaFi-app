export type StellarNetwork = "testnet";
export type StellarWalletProvider = "freighter";

export type StellarWallet = {
  id: number;
  user_id: number;
  public_key: string;
  network: StellarNetwork;
  wallet_provider: StellarWalletProvider;
  connected_at: string;
};

export type CreateStellarWalletPayload = {
  public_key: string;
  network: StellarNetwork;
  wallet_provider: StellarWalletProvider;
};

export type StellarWalletResponse = {
  message: string;
  data: StellarWallet;
};

export type NullableStellarWalletResponse = {
  message: string;
  data: StellarWallet | null;
};

export type StellarBalance = {
  asset_code: "XLM";
  balance: string;
  is_funded: boolean;
  horizon_url: string;
};
