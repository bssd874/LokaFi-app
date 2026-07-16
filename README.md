# LokaFi

LokaFi is a hackathon adaptation of an existing university personal finance application. It keeps the original PI finance features - authentication, wallet CRUD, transactions, categories, budgets, dashboard, bank simulation, and reports - while adding Stellar Testnet invoice payments through Freighter.

The MVP flow is: merchant connects Freighter, creates an IDR invoice with a demo XLM equivalent, customer pays native Testnet XLM through Freighter, backend verifies the transaction on Stellar Testnet, invoice becomes paid, and an income transaction appears in the existing finance dashboard.

## Live Demo

- Application: https://lokafi-app-bssd874.vercel.app
- API health check: https://lokafi-api-bssd874.vercel.app/up
- Demo email: `demo@lokafi.test`
- Demo password: `password`

The hosted MVP uses Stellar Testnet only and does not process real money.

## Judge Testing

Judges can test the hosted application without running a local environment:

1. Open https://lokafi-app-bssd874.vercel.app.
2. Sign in with `demo@lokafi.test` / `password` for a product tour, or register
   a fresh account when validating exact import counts and payment records.
3. Open **Dashboard**, **Accounts**, **Transactions**, **Budgets**, and
   **Financial Analytics** to verify the existing finance workflows.
4. In **Accounts**, add a Bank Account and an E-Wallet, then use **Import CSV**
   with the synthetic files in [`demo-data/video-demo`](demo-data/video-demo).
5. Preview and map the CSV columns before confirming the import. Imported,
   duplicate, invalid, and failed rows are reported separately.
6. Open **Transactions** to review or correct categories, then verify that the
   dashboard, budgets, and analytics reflect the imported records.

Expected CSV results for a fresh account:

| Test file | Imported | Duplicate | Invalid | Failed |
| --- | ---: | ---: | ---: | ---: |
| `bank_statement_video_demo.csv` | 12 | 0 | 0 | 0 |
| `ewallet_statement_video_demo.csv` | 10 | 0 | 0 | 0 |
| `bank_statement_duplicate_demo.csv` | 1 | 1 | 0 | 0 |
| `bank_statement_invalid_demo.csv` | 1 | 0 | 3 | 0 |

For the Stellar payment test, install Freighter, select **Testnet**, and use
two funded Testnet accounts. Connect the merchant account under
**Accounts > Stellar**, create an invoice, open its public link in a separate
browser profile, and approve the native XLM payment with the customer account.
The invoice becomes paid only after backend verification and must create
exactly one Stellar payment record and one income transaction.

See the complete [`TESTING.md`](TESTING.md) guide for column mappings, the
deterministic local dataset, automated verification commands, Soroban checks,
idempotency scenarios, and troubleshooting notes. All packaged CSV data is
synthetic and contains no bank credentials or wallet secrets.

## Stellar Smart Contract

- Contract: LokaFi Invoice Registry
- Network: Stellar Testnet
- Contract Address: `CBKQDSBN66VQ4QNYSVK73H4YXG3O4ZEBANJPS76XHB6K46ICLUQSSM2W`
- Alias: `lokafi_invoice_registry`
- Contract Version: `lokafi_invoice_registry_v1`

The contract stores safe on-chain invoice references and verified settlement receipts while preventing duplicate invoice settlement and reused transaction hashes. It exposes:

- `initialize`
- `register_invoice`
- `mark_invoice_paid`
- `get_invoice`
- `invoice_exists`
- `is_transaction_used`
- `version`

The Laravel backend verifies the Stellar payment network, recipient, asset, amount, memo, status, and transaction hash. After verification, the Soroban contract provides an additional on-chain registry for invoice references and verified settlement receipts. This deployment uses Stellar Testnet only and does not process real money.

Build and inspect the exact deployed contract source:

```powershell
cd soroban
cargo test --locked
stellar contract build --locked
stellar contract info interface --wasm target\wasm32v1-none\release\lokafi_invoice_registry.wasm
```

Deploy and verify with the local Testnet identity alias (the identity secret remains outside the repository):

```powershell
stellar contract deploy `
  --wasm target\wasm32v1-none\release\lokafi_invoice_registry.wasm `
  --source-account lokafi-deployer `
  --network testnet `
  --alias lokafi_invoice_registry

stellar contract invoke `
  --id lokafi_invoice_registry `
  --source-account lokafi-deployer `
  --network testnet `
  -- version
```

## Architecture

- Backend: Laravel REST API in `LokaFi-architect-api`.
- Frontend: React + Vite in `LokaFi-architect-web`.
- Database: PostgreSQL for local app data.
- Auth: Laravel Sanctum bearer tokens.
- Stellar: Testnet only, Freighter signs in the browser, backend verifies with Horizon Testnet.
- Finance integration: verified Stellar payments create ordinary `income` records in the existing `transactions` table.

## Prerequisites

- PHP 8.3+
- Composer
- Node.js 20+
- PostgreSQL 14+
- Freighter browser extension
- A browser profile with Freighter set to Testnet

## Backend Setup

```bash
cd LokaFi-architect-api
composer install
copy .env.example .env
php artisan key:generate
php artisan migrate
php artisan db:seed --class=LokaFiDemoSeeder
php artisan serve
```

The API runs at `http://127.0.0.1:8000` by default.

## Frontend Setup

```bash
cd LokaFi-architect-web
npm install
copy .env.example .env
npm run dev
```

Vite uses basic SSL so Freighter can run in a secure browser context. Open `https://localhost:5173`, not plain HTTP.

## PostgreSQL Setup

Create a database and user that match the backend `.env`.

```sql
CREATE DATABASE lokafi;
```

Recommended backend variables:

```env
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=lokafi
DB_USERNAME=postgres
DB_PASSWORD=
```

## Environment Variables

Backend:

```env
APP_URL=http://127.0.0.1:8000
FRONTEND_URL=http://localhost:5173
FRONTEND_HTTPS_URL=https://localhost:5173
LOKAFI_DEMO_MERCHANT_PUBLIC_KEY=
LOKAFI_DEMO_CUSTOMER_PUBLIC_KEY=
```

Frontend:

```env
VITE_API_BASE_URL=http://127.0.0.1:8000/api
```

Never put Stellar secret keys, mnemonics, or recovery phrases in `.env`, seeders, localStorage, logs, or the database.

## Freighter Testnet Setup

1. Install Freighter.
2. Switch Freighter network to Testnet.
3. Create or import a Testnet account.
4. Fund the account with Friendbot from Freighter or Stellar Laboratory.
5. Use one Testnet account as merchant and another as customer for the cleanest demo.

## Migrations and Seed Data

```bash
cd LokaFi-architect-api
php artisan migrate
php artisan db:seed --class=LokaFiDemoSeeder
```

Demo login:

- Email: `demo@lokafi.test`
- Password: `password`

The demo seeder stores public keys only. It does not include wallet secrets. If `LOKAFI_DEMO_MERCHANT_PUBLIC_KEY` is empty, the seed uses placeholder public-key-shaped values for UI walkthroughs. For live payment demos, connect a real funded Freighter Testnet wallet and create a new invoice.

## Testing Commands

Backend:

```bash
cd LokaFi-architect-api
php artisan test
```

Frontend:

```bash
cd LokaFi-architect-web
npm run lint
npm run build
```

## Demo Flow

1. Login as merchant.
2. Open Stellar Wallet and connect Freighter on Testnet.
3. Confirm the page shows `Stellar Testnet - no real money`.
4. Create an invoice.
5. Copy the public invoice link.
6. Open the public page in a customer browser profile.
7. Connect customer Freighter on Testnet.
8. Pay native XLM and approve in Freighter.
9. Watch the page move through submitted, verifying, and paid states.
10. Open the Testnet explorer link.
11. Return to merchant invoices and Stellar Payments.
12. Show the new income transaction in the dashboard/transactions.

## API Summary

Authenticated:

- `GET /api/stellar/wallet`
- `POST /api/stellar/wallet`
- `DELETE /api/stellar/wallet`
- `GET /api/stellar/payments`
- `GET /api/invoices`
- `POST /api/invoices`
- `GET /api/invoices/{invoice}`
- `PATCH /api/invoices/{invoice}`
- `DELETE /api/invoices/{invoice}`
- `POST /api/invoices/{invoice}/verify-payment`

Public:

- `GET /api/public/invoices/{uuid}`
- `POST /api/public/invoices/{uuid}/verify-payment`

## Current MVP Limitations

- LokaFi includes a Soroban smart contract for registering invoice references and verified settlement receipts on-chain.
- The Soroban contract does not custody funds or independently transfer user assets.
- Stellar payments currently operate on Stellar Testnet only.
- Bank and e-wallet activity is imported through CSV statements, not live account synchronization.
- Direct banking movement is not supported.
- Fiat on-ramp and off-ramp are not included.
- Production-grade KYC and AML verification are not included.
- The MVP does not process real-money settlement.
- The current deployment is intended for hackathon demonstration and evaluation.
