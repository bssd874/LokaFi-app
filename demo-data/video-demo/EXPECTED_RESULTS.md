# Expected Video Demo Results

Angka berikut dihitung dari transaksi yang dibuat command dan isi tiga CSV pada paket ini dengan reference date `2026-07-15`. Gunakan filter `2026-07-01` sampai `2026-07-31` agar hasil dapat dibandingkan secara deterministik.

## Setelah `lokafi:demo:prepare`

| Metric | Expected |
| --- | ---: |
| Seeded transactions | 32 |
| Current-period transactions | 14 |
| Total income | Rp 7.500.000 |
| Total expense | Rp 2.460.000 |
| Net cashflow | Rp 5.040.000 |
| Savings rate | 67,20% |
| Previous-period income | Rp 6.900.000 |
| Previous-period expense | Rp 2.835.000 |
| Previous-period net cashflow | Rp 4.065.000 |

Seed data juga mengandung dua transaksi `Nusantara Bento Lab` senilai Rp 180.000 dalam selang 10 menit dan histori lima transaksi Shopping. Keduanya sengaja disiapkan untuk duplicate-like dan unusual-amount analysis.

## Hasil Import

| File | Imported | Duplicate | Invalid | Failed |
| --- | ---: | ---: | ---: | ---: |
| bank_statement_video_demo.csv | 12 | 0 | 0 | 0 |
| ewallet_statement_video_demo.csv | 10 | 0 | 0 | 0 |
| bank_statement_duplicate_demo.csv | 1 | 1 | 0 | 0 |

Duplicate pada file ketiga menggunakan `VIDEO-DEMO-BANK-0010`, yaitu external ID yang sudah ada pada bank CSV utama. Baris baru menggunakan `VIDEO-DEMO-BANK-0013`.

## Setelah Bank dan E-Wallet CSV Utama

| Metric | Expected |
| --- | ---: |
| Current-period transactions | 36 |
| Total income | Rp 10.700.000 |
| Total expense | Rp 6.906.000 |
| Net cashflow | Rp 3.794.000 |
| Savings rate | 35,46% |
| Food and Beverage budget usage | 82,70% (Rp 951.000 / Rp 1.150.000) |
| Transportation budget usage | 25,71% (Rp 180.000 / Rp 700.000) |
| Shopping budget usage | 97,86% (Rp 2.740.000 / Rp 2.800.000) |
| Entertainment budget usage | 25,71% (Rp 180.000 / Rp 700.000) |

`Shopping` adalah kategori pengeluaran terbesar dan meningkat dibanding periode sebelumnya. Transaksi `PIXELNEST SUPPLIES BULK ORDER` sebesar Rp 1.250.000 harus tersedia sebagai kandidat unusual amount. `NOVA HUB SERVICE 0726` senilai Rp 155.000 tetap uncategorized/review required.

## Setelah Duplicate Demo CSV

| Metric | Expected |
| --- | ---: |
| Current-period transactions | 37 |
| Total income | Rp 10.700.000 |
| Total expense | Rp 6.942.000 |
| Net cashflow | Rp 3.758.000 |
| Savings rate | 35,12% |
| Food and Beverage budget usage | 85,83% (Rp 987.000 / Rp 1.150.000) |

Final expense breakdown:

| Category | Expected expense |
| --- | ---: |
| Shopping | Rp 2.740.000 |
| Business Operations | Rp 1.345.000 |
| Food and Beverage | Rp 987.000 |
| Bills | Rp 855.000 |
| Education | Rp 500.000 |
| Transportation | Rp 180.000 |
| Entertainment | Rp 180.000 |
| Uncategorized (`NOVA HUB SERVICE 0726`) | Rp 155.000 |

Tidak ada expected Stellar payment. Invoice hanya dibuat dalam status `pending` ketika `LOKAFI_DEMO_STELLAR_PUBLIC_KEY` valid; status `paid` hanya boleh muncul setelah transaksi Testnet nyata diverifikasi backend.
