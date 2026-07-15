# LokaFi

LokaFi is a hackathon adaptation of an existing university personal finance application. It keeps the original PI finance features - authentication, wallet CRUD, transactions, categories, budgets, dashboard, bank simulation, and reports - while adding Stellar Testnet invoice payments through Freighter.

The MVP flow is: merchant connects Freighter, creates an IDR invoice with a demo XLM equivalent, customer pays native Testnet XLM through Freighter, backend verifies the transaction on Stellar Testnet, invoice becomes paid, and an income transaction appears in the existing finance dashboard.

## Architecture

- Backend: Laravel REST API in `fiscal-architect-api`.
- Frontend: React + Vite in `fiscal-architect-web`.
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
cd fiscal-architect-api
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
cd fiscal-architect-web
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
cd fiscal-architect-api
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
cd fiscal-architect-api
php artisan test
```

Frontend:

```bash
cd fiscal-architect-web
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

## Known Limitations

- Stellar Mainnet is intentionally unsupported.
- XLM/IDR conversion is demo data, not a real market rate.
- No custodial wallet is implemented.
- Freighter must sign in the browser.
- Backend tests mock Horizon responses; live payment testing is manual.
- No smart contracts, real banking movement, fiat on-ramp, KYC, or real settlement is included.
