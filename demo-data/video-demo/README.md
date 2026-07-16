# LokaFi Video Demo Data

Paket ini berisi data sintetis khusus rekaman demo hackathon. Semua nama merchant, akun, customer, dan transaksi bersifat fiktif. Paket tidak berisi credential bank, token, PIN, OTP, secret key Stellar, mnemonic, atau recovery phrase.

## Persiapan

Jalankan dari folder `LokaFi-architect-api` dengan environment `APP_ENV=local`:

```bash
php artisan migrate
php artisan lokafi:demo:prepare --reset --show-credentials
```

Password dibaca dari `LOKAFI_DEMO_PASSWORD`. Jika variabel itu kosong, command membuat password acak dan hanya menampilkannya saat `--show-credentials` diberikan. Jangan memasukkan password nyata ke repository.

Nilai default:

- Demo run: `LOKAFI-VIDEO-DEMO-2026`
- Email: `demo.video@lokafi.local`
- Reference date: `2026-07-15`
- Dataset marker: `lokafi_video_demo_v1`

Override yang tersedia:

```bash
php artisan lokafi:demo:prepare --email=demo.video@lokafi.local --reference-date=2026-07-15 --reset --show-credentials
```

Command hanya aktif pada environment `local` dan `testing`. Tanpa `--reset`, command berhenti jika user demo sudah ada. `--reset` hanya dapat menghapus user dengan email pilihan dan nama persis `Boni Steven Demo`; email milik user non-demo akan ditolak.

## Stellar Testnet Opsional

Untuk membuat state koneksi Freighter Testnet dan satu invoice berstatus `pending`, set public key milik akun Testnet:

```env
LOKAFI_DEMO_STELLAR_PUBLIC_KEY=G...
```

Jika variabel tidak tersedia atau invalid, command melewati Stellar wallet dan invoice. Command tidak membuat Stellar payment sukses, transaction hash, income Stellar, secret key, atau recovery phrase. Pembayaran tetap harus dilakukan dan diverifikasi melalui alur Testnet asli.

## Urutan Import untuk Video

1. Login sebagai user demo.
2. Buka `Accounts` lalu bagian `Bank & E-Wallet` dan pilih import statement.
3. Import `bank_statement_video_demo.csv` ke account `BCA Statement Import` dengan source `bank_csv`.
4. Pastikan mapping bank sesuai tabel di bawah lalu preview dan commit.
5. Import `ewallet_statement_video_demo.csv` ke account `GoPay Statement Import` dengan source `ewallet_csv`.
6. Pastikan kolom `reference` dipakai sebagai `external_transaction_id`, lalu preview dan commit.
7. Import `bank_statement_duplicate_demo.csv` ke `BCA Statement Import` untuk mendemokan satu duplicate dan satu transaksi baru.
8. Buka review queue. `NOVA HUB SERVICE 0726` harus tetap `review_required`; transaksi lain menggunakan rule atau mapping yang sudah disiapkan.

### Mapping Bank CSV

| Field LokaFi | Kolom CSV |
| --- | --- |
| happened_at | transaction_date |
| description | description |
| type | transaction_type |
| amount | amount |
| external_transaction_id | external_id |

### Mapping E-Wallet CSV

| Field LokaFi | Kolom CSV |
| --- | --- |
| happened_at | date_time |
| merchant | merchant |
| type | direction |
| amount | total |
| reference_code | reference |
| external_transaction_id | reference |

## Perilaku Kategorisasi

- `KOPI LANGIT SELATAN` menggunakan verified historical mapping ke `Food and Beverage`.
- `SAGARA INTERNET` dan merchant demo lain menggunakan deterministic user rule.
- `NOVA HUB SERVICE 0726` sengaja tidak memiliki mapping atau rule sehingga masuk review queue dan dapat dipakai untuk demo AI fallback.
- Tidak ada hasil AI yang dipra-set sebagai accepted.

Lihat `EXPECTED_RESULTS.md` untuk angka deterministik dan `VIDEO_DEMO_CHECKLIST.md` untuk urutan rekaman.

## Additional Judge Validation File

`bank_statement_invalid_demo.csv` is separate from the recorded demo flow. It
contains one valid row and three intentionally malformed rows (date, amount,
and transaction type). With the standard bank mapping, the expected result is
1 imported, 0 duplicate, 3 invalid, and 0 failed. The malformed rows must not
create finance transactions.

See the root `TESTING.md` for the complete hosted and local judge flow.
