# Video Demo Checklist

## Sebelum Rekam

- [ ] Backend dan frontend berjalan.
- [ ] Database sudah dimigrasikan.
- [ ] Jalankan `php artisan lokafi:demo:prepare --reset --show-credentials`.
- [ ] Simpan credential sementara hanya untuk sesi rekaman; jangan commit.
- [ ] Browser memakai periode analitik `2026-07-01` sampai `2026-07-31`.
- [ ] Freighter berada di Stellar Testnet jika bagian pembayaran akan direkam.
- [ ] `LOKAFI_DEMO_STELLAR_PUBLIC_KEY` diisi dengan public key Testnet yang benar jika invoice demo diperlukan.

## Alur Rekaman

- [ ] Login sebagai `Boni Steven Demo`.
- [ ] Tunjukkan Accounts: Cash Wallet, BCA statement import, dan GoPay statement import.
- [ ] Tunjukkan dashboard awal dengan transaksi manual dua periode.
- [ ] Upload dan preview `bank_statement_video_demo.csv`.
- [ ] Commit: hasil harus 12 imported, 0 duplicate, 0 invalid, 0 failed.
- [ ] Upload dan preview `ewallet_statement_video_demo.csv`.
- [ ] Map `reference` ke `external_transaction_id` dan commit.
- [ ] Hasil e-wallet harus 10 imported, 0 duplicate, 0 invalid, 0 failed.
- [ ] Tunjukkan transaksi `SAGARA INTERNET` memakai user rule.
- [ ] Tunjukkan `KOPI LANGIT SELATAN` memakai verified mapping/history.
- [ ] Tunjukkan `NOVA HUB SERVICE 0726` berstatus review required.
- [ ] Upload `bank_statement_duplicate_demo.csv`.
- [ ] Commit: hasil harus 1 imported dan 1 duplicate.
- [ ] Buka Financial Analytics untuk menunjukkan cashflow, tren, budget, dan anomaly.
- [ ] Jika public key Testnet tersedia, tunjukkan invoice `pending`, lalu lakukan pembayaran melalui Freighter dan verifikasi backend asli.
- [ ] Tunjukkan explorer link hanya setelah transaksi Testnet benar-benar terkonfirmasi.

## Pemeriksaan Keamanan

- [ ] Tidak ada secret key, mnemonic, recovery phrase, credential bank, PIN, OTP, password, atau token dalam layar/file.
- [ ] Tidak mengklaim adanya direct bank/e-wallet synchronization.
- [ ] Tidak menampilkan transaksi Stellar sukses palsu.
- [ ] Label `Stellar Testnet - no real money` tetap terlihat pada layar Stellar.

## Reset Setelah Percobaan

```bash
php artisan lokafi:demo:prepare --reset --show-credentials
```

Reset mengganti user demo beserta data miliknya. User non-demo tidak disentuh.
