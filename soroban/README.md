# LokaFi Invoice Registry

LokaFi Invoice Registry is a small Soroban contract that records privacy-safe invoice references and verified settlement receipts. The Laravel backend remains responsible for verifying the underlying Stellar payment before an administrator records the settlement on-chain.

This deployment is for Stellar Testnet only and does not process real money.

## Testnet Deployment

- Contract ID: `CBKQDSBN66VQ4QNYSVK73H4YXG3O4ZEBANJPS76XHB6K46ICLUQSSM2W`
- Alias: `lokafi_invoice_registry`
- Version: `lokafi_invoice_registry_v1`
- Administrator: `GDDG45RVBJSICPWIS5UV45S3X23UAX64FILXAT7A4C2HSNHONH5S4COI`
- WASM SHA-256: `7e52def42add4f453b0587a1b80d9805330d787b24bc99793163ce714bc1a059`

No secret keys, mnemonics, or recovery phrases are committed. The `lokafi-deployer` identity is stored by Stellar CLI in the current user's configuration directory outside this repository.

## Data Model

Each `InvoiceRecord` contains only:

- a 32-byte invoice hash
- merchant public address
- positive integer amount in stroops
- a 32-byte memo hash
- optional 32-byte payment transaction hash
- optional payer public address
- pending or paid status
- created and paid ledger/timestamp metadata

The contract does not store customer names, email addresses, descriptions, bank details, credentials, access tokens, or wallet secrets.

## Public Functions

- `initialize(admin)` sets the administrator once and requires that address to authorize the call.
- `register_invoice(invoice_hash, merchant, amount, memo_hash)` creates a pending record and emits `invoice_registered` data through the `invoice/register` event topics.
- `mark_invoice_paid(invoice_hash, transaction_hash, payer)` records a settlement once and emits `invoice_paid` data through the `invoice/paid` event topics.
- `get_invoice(invoice_hash)` returns an invoice record when it exists.
- `invoice_exists(invoice_hash)` checks invoice registration.
- `is_transaction_used(transaction_hash)` checks settlement hash reuse.
- `version()` returns `lokafi_invoice_registry_v1`.

Amounts are integers. LokaFi documents the unit as stroops, where 10,000,000 stroops equals 1 XLM.

## Authorization And Errors

All state-changing invoice operations require the stored administrator's authorization. Initialization can occur only once. Contract errors cover an uninitialized contract, duplicate invoice, non-positive amount, unknown invoice, already-paid invoice, and reused transaction hash.

The contract does not verify Horizon transaction details itself and does not custody tokens. The Laravel backend must verify the recipient, exact amount, native XLM asset, memo, Testnet network, success status, and unique transaction hash before invoking `mark_invoice_paid`.

## Test And Build

From this directory:

```powershell
cargo test --locked
stellar contract build --locked
stellar contract info interface --wasm target\wasm32v1-none\release\lokafi_invoice_registry.wasm
```

The generated WASM path is:

```text
target\wasm32v1-none\release\lokafi_invoice_registry.wasm
```

## Deploy And Verify

```powershell
stellar contract deploy `
  --wasm target\wasm32v1-none\release\lokafi_invoice_registry.wasm `
  --source-account lokafi-deployer `
  --network testnet `
  --alias lokafi_invoice_registry

stellar contract info interface `
  --id lokafi_invoice_registry `
  --network testnet

stellar contract invoke `
  --id lokafi_invoice_registry `
  --source-account lokafi-deployer `
  --network testnet `
  -- version
```

The verified version output is:

```text
"lokafi_invoice_registry_v1"
```

The deployed contract was initialized with the administrator public address above. No demo invoice data was written during deployment.

## Security Limitations

- Testnet only; no Mainnet deployment is provided.
- The contract is an additional registry, not a replacement for backend payment verification.
- Administrator key security and contract storage TTL management remain operational responsibilities.
- The contract is not upgradeable and does not implement token custody, lending, trading, or settlement execution.
