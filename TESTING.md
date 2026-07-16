# LokaFi Judge Testing Guide

This guide covers the hosted smoke test, deterministic local test data, CSV
import scenarios, automated checks, and the Stellar Testnet payment flow.

## Safety and Scope

- Stellar is Testnet only. No real money is used.
- Freighter signs transactions in the browser. LokaFi never asks for or stores
  a secret key, mnemonic, or recovery phrase.
- Bank and e-wallet data are imported from CSV statements. There is no live
  bank or e-wallet synchronization.
- All packaged CSV data is synthetic.
- The Soroban contract is a non-custodial invoice and settlement receipt
  registry. It does not hold or transfer user funds.

## Hosted Smoke Test

- Application: https://lokafi-app-bssd874.vercel.app
- API health: https://lokafi-api-bssd874.vercel.app/up
- Shared demo email: `demo@lokafi.test`
- Shared demo password: `password`

The shared account is useful for a read-only product tour, but its data may
change when several reviewers use it. Register a fresh account when testing
CSV counts, category corrections, invoice creation, or Stellar payments.

### 1. Core Finance Flow

1. Register a fresh user or sign in with the shared demo account.
2. Open **Dashboard** and confirm the summary widgets load.
3. Open **Accounts** and confirm Cash, Bank & E-Wallet, and Stellar sections
   are available.
4. Open **Transactions**, **Budgets**, and **Categories** and confirm existing
   manual finance workflows remain available.

### 2. CSV Import Flow

For exact counts, use a fresh user.

1. In **Accounts**, add a Bank Account named `BCA Statement Import`.
2. Add an E-Wallet named `GoPay Statement Import`.
3. Open **Bank & E-Wallet**, then select **Import CSV**.
4. Upload `demo-data/video-demo/bank_statement_video_demo.csv` as `bank_csv`.
5. Apply the bank mapping below, preview, and confirm the import.
6. Upload `demo-data/video-demo/ewallet_statement_video_demo.csv` as
   `ewallet_csv`, apply the e-wallet mapping, preview, and confirm.
7. Upload `demo-data/video-demo/bank_statement_duplicate_demo.csv` to the same
   bank account to verify row-level duplicate prevention.
8. Upload `demo-data/video-demo/bank_statement_invalid_demo.csv` to verify that
   valid rows are imported while malformed rows are reported and skipped.

#### Bank Mapping

| LokaFi field | CSV column |
| --- | --- |
| `happened_at` | `transaction_date` |
| `description` | `description` |
| `type` | `transaction_type` |
| `amount` | `amount` |
| `external_transaction_id` | `external_id` |

#### E-Wallet Mapping

| LokaFi field | CSV column |
| --- | --- |
| `happened_at` | `date_time` |
| `merchant` | `merchant` |
| `type` | `direction` |
| `amount` | `total` |
| `reference_code` | `reference` |
| `external_transaction_id` | `reference` |

#### Expected Import Results

| File | Imported | Duplicate | Invalid | Failed |
| --- | ---: | ---: | ---: | ---: |
| `bank_statement_video_demo.csv` | 12 | 0 | 0 | 0 |
| `ewallet_statement_video_demo.csv` | 10 | 0 | 0 | 0 |
| `bank_statement_duplicate_demo.csv` | 1 | 1 | 0 | 0 |
| `bank_statement_invalid_demo.csv` | 1 | 0 | 3 | 0 |

Uploading an identical file again should return the existing batch rather than
create a second batch. Committing an already imported batch is idempotent and
must not create duplicate finance transactions or change the balance twice.

### 3. Categorization and Analytics

1. Open **Transactions** and select the review-required filter.
2. Confirm or correct a transaction category.
3. Import a later transaction with a similar normalized merchant description.
4. Confirm the verified mapping or rule is reused for the new transaction.
5. Open **Budgets** and choose July 2026.
6. Open **Financial Analytics**, set `2026-07-01` through `2026-07-31`, and
   confirm cashflow, spending trend, source distribution, budget progress, and
   anomaly sections load.

Categorization remains functional without an AI provider. AI output is always
a suggestion and cannot invent numerical finance metrics or categories.

### 4. Stellar Testnet Invoice Payment

Prerequisites: Freighter installed, two funded Testnet accounts, and Freighter
set to Testnet.

1. Sign in as the merchant and open **Accounts > Stellar**.
2. Connect the merchant Freighter account and confirm the page says
   `Stellar Testnet - no real money`.
3. Create an invoice from **Invoices** using the connected merchant account.
4. Copy the public invoice URL and open it in a separate browser profile.
5. Connect the customer Freighter Testnet account.
6. Approve the native XLM payment in Freighter.
7. Wait for backend verification. The UI must not mark the invoice paid based
   only on frontend submission.
8. Confirm the paid invoice shows a transaction hash and Testnet explorer link.
9. Confirm **Stellar Payments** contains one verified payment.
10. Confirm **Transactions** and **Dashboard** contain exactly one new income
    entry for the invoice.
11. Repeat verification for the same hash and confirm no duplicate payment or
    income transaction is created.

### 5. Soroban Registry Verification

- Network: Stellar Testnet
- Contract: LokaFi Invoice Registry
- Contract address:
  `CBKQDSBN66VQ4QNYSVK73H4YXG3O4ZEBANJPS76XHB6K46ICLUQSSM2W`

The Laravel backend verifies the payment first. The Soroban contract provides
an additional on-chain invoice and verified settlement receipt registry.

## Deterministic Local Setup

### Backend

```bash
cd LokaFi-architect-api
composer install
copy .env.example .env
php artisan key:generate
php artisan migrate
php artisan lokafi:demo:prepare --reset --show-credentials
php artisan serve
```

The preparation command is blocked outside `local` and `testing`. It creates a
dedicated synthetic user, wallets, categories, rules, mappings, budgets, and
two periods of transactions. Without `LOKAFI_DEMO_PASSWORD`, it generates a
temporary password and prints it only when `--show-credentials` is used.

Optional local variables:

```env
LOKAFI_DEMO_PASSWORD=
LOKAFI_DEMO_REFERENCE_DATE=2026-07-15
LOKAFI_DEMO_STELLAR_PUBLIC_KEY=
```

Only a Testnet public key may be supplied. Do not add wallet secrets.

### Frontend

```bash
cd LokaFi-architect-web
npm install
copy .env.example .env
npm run dev
```

Open `https://localhost:5173`. HTTPS is required for Freighter.

## Automated Verification

Backend:

```bash
cd LokaFi-architect-api
php artisan test
```

Focused demo package test:

```bash
php artisan test --filter=VideoDemoDataCommandTest
```

Frontend:

```bash
cd LokaFi-architect-web
npm run lint
npm run build
```

Soroban:

```bash
cd soroban
cargo test --locked
```

## Expected Local Analytics

After `lokafi:demo:prepare` and before importing CSV files, use the July 2026
date range:

| Metric | Expected |
| --- | ---: |
| Total income | Rp 7.500.000 |
| Total expense | Rp 2.460.000 |
| Net cashflow | Rp 5.040.000 |
| Savings rate | 67.20% |

Detailed deterministic results are documented in
`demo-data/video-demo/EXPECTED_RESULTS.md`.

## Troubleshooting

- **Freighter unavailable:** install/unlock Freighter and use an HTTPS page.
- **Wrong network:** switch Freighter to Testnet and reconnect.
- **CSV preview validation error:** verify source type, destination account, and
  the mapping tables above.
- **CSV duplicate:** use a fresh account for exact counts or treat the duplicate
  response as the expected idempotency behavior.
- **AI provider unavailable:** deterministic rules, manual categorization, and
  financial analytics should continue working.
- **Shared demo data differs:** use a fresh hosted account or run the local
  deterministic preparation command.
