# Progress Meja POS

Aturan: fase berikutnya hanya setelah test, build, cek browser, dan commit fase aktif selesai. Catatan audit dan lokasi query harian ditambah pada Fase 0.

## Fase 0 — Audit
- [x] Baca CLAUDE.md dan audit semua temuan awal
- [x] Audit service worker, draft offline, SyncDraftOrder, DeviceRegistry, nomor bill
- [x] Audit kekosongan offline: pembayaran/shift, cache katalog, badge antrean, diagnosa sync
- [x] Audit refund, waste, impor menu, gateway/QRIS
- [x] Audit business_date dan semua query/preset/ekspor/laporan/dashboard
- [x] Jalankan baseline test dan build; catat bug atau kode setengah jadi
- [x] Pilihan gateway: Xendit; pakai fake driver sampai kredensial sandbox tersedia

## Fase 1 — Offline
- [ ] Outbox per perangkat berurutan: ULID, idempotency key, sequence, waktu perangkat
- [ ] Perintah: order.saved, payment.recorded, order.line.voided, shift.opened, shift.closed, cash.moved
- [ ] Tunai offline, cetak struk; non-tunai dinonaktifkan dengan arahan tunai
- [ ] Shift offline, rekap final terkunci hingga sinkron
- [ ] Cache IndexedDB katalog, harga, modifier, meja, pajak, staf; status waktu pembaruan
- [ ] Delta stok server, stok minus, daftar tinjauan manajer
- [ ] Badge antrean/header dan peringatan perintah >24 jam
- [ ] Diagnosa sync manajer: gagal per perangkat, retry, tandai selesai beralasan/audit
- [ ] Gagal menahan perintah setelahnya pada perangkat sama
- [ ] Test: 15 tunai/reload, replay payment, stok minus 2 perangkat, antrean tertahan, non-tunai offline
- [ ] Validasi test/build/browser dan commit `feat: pembayaran tunai, shift, dan cache katalog saat offline`

## Fase 2 — Refund, waste, impor menu
- [ ] Refund penuh/sebagian, alasan, restock, peminta, penyetuju, waktu
- [ ] PIN supervisor, order paid immutable, audit, struk dan riwayat refund
- [ ] Restock movement dan business date refund
- [ ] Layar waste, movement bernilai, daftar/filter dan total terpisah dari HPP penjualan
- [ ] Pasang maatwebsite/excel
- [ ] Template/impor/pratinjau/laporan baris rusak/opsi lewati atomik
- [ ] Test refund, PIN, restock, tanggal, waste, impor
- [ ] Validasi dan commit `feat: refund dengan persetujuan, log waste, dan impor menu`

## Fase 3 — QRIS dan gateway
- [ ] PaymentGateway, driver gateway pilihan dan fake driver
- [ ] QRIS dinamis, Reverb, cek/batal, split payment
- [ ] Webhook signature, raw payload, idempoten, queue
- [ ] QRIS statis dengan bukti dan rekonsiliasi manajer
- [ ] Rekonsiliasi settlement; refund QRIS; nonaktif offline; README tunnel
- [ ] Test webhook, signature, expiry, split, refund
- [ ] Validasi dan commit `feat: pembayaran QRIS dengan webhook idempoten dan rekonsiliasi`

## Fase 4 — Hari operasional dan ringkasan
- [ ] Catat semua query tanggal di bawah ini setelah audit
- [ ] Migration/backfill business date, timezone dan jam awal hari
- [ ] BusinessDate terpusat dan DateRange business date
- [ ] Semua laporan/dashboard filter business_date
- [ ] daily_sales_summaries dan job pembaruan
- [ ] Rebuild summaries idempoten dan setting outlet
- [ ] Test batas hari/timezone/offline/total/rebuild
- [ ] Validasi dan commit `fix: laporan berbasis hari operasional dan tabel ringkasan harian`

## Fase 5 — Dashboard
- [ ] Widget KPI dan pembanding minggu lalu
- [ ] Chart.js Vite + Alpine reusable, dark mode, destroy, tabel aksesibel
- [ ] Grafik 7 hari, heatmap CSS, stok/waste, status sistem, owner outlet compare
- [ ] Animasi/reduced motion/responsif/performa
- [ ] Validasi dan commit `feat: dashboard lengkap dengan grafik 7 hari, jam sibuk, dan stok kritis`

## Fase 6 — Laporan dan ekspor
- [ ] Filter/preset/rentang/URL/preferensi/perbandingan
- [ ] Semua laporan business date
- [ ] PDF/Excel per laporan/transaksi, CSV tetap opsional
- [ ] Export jobs queue/expiry/audit/otorisasi/online only
- [ ] Test preset, akses outlet, angka Excel, queue, kasir ditolak, dashboard sama
- [ ] Validasi dan commit `feat: laporan lengkap, mode perbandingan, dan ekspor PDF/Excel`

## Fase 7 — Polish dan rilis
- [ ] Motion One terbatas; daftar animasi; reduced motion
- [ ] Aksesibilitas kontras, keyboard, focus trap/Esc, label pembaca layar
- [ ] N+1, uang, business date, hardcode/hex/TODO/otorisasi, viewport
- [ ] E2E migrate fresh seluruh alur
- [ ] UI kit local-only/hapus, README, backup/restore, deploy VPS, setting owner
- [ ] Validasi dan commit `chore: penghalusan animasi, aksesibilitas, perbaikan bug, dan persiapan rilis`

## Query tanggal yang harus dimigrasikan pada Fase 4
- `app/Domain/Reporting/DateRange.php`: batas rentang, previous, dates memakai hari kalender.
- `app/Domain/Reporting/Enums/Preset.php`: Today, Yesterday, Week, Month memakai hari kalender.
- `app/Domain/Reporting/SalesReport.php`: `date(paid_at)` untuk grup harian; `paid_at` dan `closed_at` untuk filter.
- `resources/views/pages/⚡dashboard.blade.php`: memakai `DateRange` dan `SalesReport` tersebut.

## Catatan sesi
- Fase 0 selesai. Fase aktif: 1 — outbox perintah offline.
- Commit sebelum prompt: `d5b56a8 fix: pertahankan draft offline tertahan`.
- Baseline 2026-09-22: `php artisan test --compact` hijau: 270 passed, 640 assertions, 135.42s. `npm run build` hijau.
- Untracked sebelum audit: `Claude outputs/`; tidak disentuh.