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
- [x] Outbox per perangkat berurutan: ULID, idempotency key, sequence, waktu perangkat
- [x] Perintah: order.saved, payment.recorded, shift.opened, shift.closed, cash.moved (void baris offline = simpan ulang bill tanpa baris itu, sebelum dibayar)
- [x] Tunai offline, cetak struk; non-tunai dinonaktifkan dengan arahan tunai
- [x] Shift offline, rekap final terkunci hingga sinkron
- [x] Cache IndexedDB katalog, harga, modifier, meja, pajak, kasir yang sedang login; status waktu pembaruan (login PIN offline untuk staf lain belum ada)
- [x] Delta stok server, stok minus, daftar tinjauan manajer
- [x] Badge antrean/header dan peringatan perintah >24 jam
- [x] Diagnosa sync manajer: gagal per perangkat, retry, tandai selesai beralasan/audit
- [x] Gagal menahan perintah setelahnya pada perangkat sama
- [x] Test: 15 tunai/reload, replay payment, stok minus 2 perangkat, antrean tertahan, non-tunai offline
- [x] Validasi test/build/browser dan commit `feat: pembayaran tunai, shift, dan cache katalog saat offline`

## Fase 2 — Refund, waste, impor menu
- [x] Refund penuh/sebagian, alasan, restock, peminta, penyetuju, waktu
- [x] PIN supervisor, order paid immutable, audit, struk dan riwayat refund
- [x] Restock movement (business date refund menyusul di Fase 4 bersama kolom business_date)
- [x] Layar waste, movement bernilai, daftar/filter dan total terpisah dari HPP penjualan
- [x] ~~Pasang maatwebsite/excel~~ diganti penulis/pembaca XLSX bawaan (`app/Support/Spreadsheet`), tanpa dependensi
- [x] Template/impor/pratinjau/laporan baris rusak/opsi lewati atomik
- [x] Test refund, PIN, restock, tanggal, waste, impor
- [x] Validasi dan commit `feat: refund dengan persetujuan, log waste, dan impor menu`

## Fase 3 — QRIS dan gateway
- [x] PaymentGateway, driver Xendit (API QR Codes 2022-07-31) dan fake driver
- [x] QRIS dinamis, Reverb, cek/batal, split payment
- [x] Webhook signature, raw payload, idempoten, queue
- [x] QRIS statis dengan bukti dan rekonsiliasi manajer
- [x] Rekonsiliasi settlement; refund QRIS; nonaktif offline; README tunnel
- [x] Test webhook, signature, expiry, split, refund
- [x] Validasi dan commit `feat: pembayaran QRIS dengan webhook idempoten dan rekonsiliasi`

## Fase 4 — Hari operasional dan ringkasan
- [x] Catat semua query tanggal di bawah ini setelah audit
- [x] Migration/backfill business date, timezone dan jam awal hari
- [x] BusinessDate terpusat (`BusinessCalendar`) dan DateRange business date
- [x] Semua laporan/dashboard filter business_date
- [x] daily_sales_summaries dan pembaruan (setelah commit, sinkron; tanpa worker)
- [x] Rebuild summaries idempoten (`meja:rebuild-summaries`, opsi `--redate`) dan setting outlet (`/outlet/settings`)
- [x] Test batas hari/timezone/offline/total/rebuild
- [x] Validasi dan commit `fix: laporan berbasis hari operasional dan tabel ringkasan harian`

## Fase 5 — Dashboard
- [x] Widget KPI dan pembanding minggu lalu (hari yang sama, sampai menit yang sama)
- [x] Chart.js Vite + Alpine reusable, dark mode, destroy, tabel aksesibel
- [x] Grafik 7 hari, heatmap CSS, stok/waste, status sistem, owner outlet compare
- [x] Animasi/reduced motion/responsif/performa
- [x] Validasi dan commit `feat: dashboard lengkap dengan grafik 7 hari, jam sibuk, dan stok kritis`

## Fase 6 — Laporan dan ekspor
- [x] Filter/preset/rentang/URL/preferensi/perbandingan
- [x] Semua laporan business date
- [x] PDF/Excel per laporan/transaksi, CSV tetap opsional
- [x] Export jobs queue/expiry/audit/otorisasi/online only
- [x] Test preset, akses outlet, angka Excel, queue, kasir ditolak, dashboard sama
- [x] Validasi dan commit `feat: laporan lengkap, mode perbandingan, dan ekspor PDF/Excel`

## Fase 7 — Polish dan rilis
- [x] ~~Motion One terbatas~~ tetap CSS saja (tanpa skrip tambahan untuk kasir offline); daftar animasi di README; reduced motion
- [x] Aksesibilitas kontras, keyboard, focus trap/Esc, label pembaca layar
- [x] N+1, uang, business date, hardcode/hex/TODO/otorisasi, viewport
- [x] E2E migrate fresh seluruh alur
- [x] UI kit local-only/hapus, README, backup/restore, deploy VPS, setting owner
- [x] Validasi dan commit `chore: penghalusan animasi, aksesibilitas, perbaikan bug, dan persiapan rilis`

## Query tanggal (dimigrasikan pada Fase 4)
- `app/Domain/Reporting/DateRange.php`: sekarang rentang hari operasional (`fromDate()`/`toDate()`).
- `app/Domain/Reporting/Enums/Preset.php`: dihitung dari hari operasional outlet (`BusinessCalendar::today`).
- `app/Domain/Reporting/SalesReport.php`: ringkasan dan harian dari `daily_sales_summaries`; produk, metode bayar, dan shift memfilter `business_date`.
- `resources/views/pages/⚡dashboard.blade.php`: preset memakai hari operasional outlet.
- `app/Domain/Inventory/LogWaste.php` dan `/stock/waste`: filter `business_date`.
- Tetap hari kalender, disengaja: rekonsiliasi settlement gateway (laporan gateway dipotong tengah malam), nomor bill, hitungan "hari ini" di halaman sinkronisasi.

## Catatan sesi
- Fase 0 selesai. Fase 1 selesai 2026-09-26 (dikerjakan Claude Cowork).
- Fase 1: kasir offline di /pos/offline (Alpine + IndexedDB, dilayani service worker saat offline), outbox berurutan ke POST /sync/operations, diagnosa manajer di /sync.
- Ditemukan & diperbaiki di Fase 1: stok bahan belum pernah terpotong saat penjualan (sekarang DeductSaleStock saat bill lunas); cache menu gagal dibaca ulang karena cache.serializable_classes=false (layar kasir error di muatan kedua) — kelas menu sekarang di-allow-list + test penjaga.
- Hitungan total JS (resources/js/offline/totals.js) dicek silang dengan CalculateTotals PHP pada 3.000 keranjang acak: identik.
- Fase 2 selesai 2026-09-26. Refund di layar struk (/pos/{order}/receipt), waste di /stock/waste (dapur juga boleh), impor menu dari tombol di /catalog.
- Ditemukan & diperbaiki di Fase 2: token warna `--color-base` bentrok dengan utilitas ukuran `text-base` Tailwind, sehingga semua `flux:heading size="lg"` berwarna hampir putih di mode terang. Token diganti `--color-canvas` (`bg-canvas`).
- Ditemukan & diperbaiki di Fase 2: Livewire 4 tidak memuat ulang relasi model publik di request berikutnya; layar struk sekarang memuat ulang relasinya di `hydrate()`.
- Fase 3 selesai 2026-09-28. QRIS dinamis di layar bayar, QRIS statis dengan foto bukti, halaman manajer /payments/review, webhook POST /webhooks/payments/{provider}, perintah `meja:reconcile-settlements` (terjadwal 06.10). Driver Xendit ditulis dari dokumentasi dan diuji dengan Http::fake; daftar cek sandbox ada di README.
- Fase 3: `phpstan analyse` (level 7) sekarang bersih, termasuk temuan lama di Sync, DeductSaleStock, dan XlsxReader.
- Fase 3: dependensi npm baru `qrcode-generator` (MIT, tanpa dependensi) untuk menggambar kode QRIS. Jalankan `npm install`.
- Fase 4 selesai 2026-09-28. Hari operasional per outlet (zona waktu + jam mulai, bawaan WIB 04.00) dicap ke orders, refunds, stock_movements, cashier_shifts. Ringkasan harian dibangun ulang setelah setiap bayar/refund/waste, dan dibangun sendiri saat laporan meminta hari yang belum ada.
- Fase 5 selesai. Dashboard baru di /dashboard (halaman awal setelah login); laporan lama pindah ke /reports. Chart.js (npm `chart.js`) dimuat hanya di dashboard lewat import dinamis (chunk terpisah, kasir tidak mengunduhnya). Angka diperbarui tiap 60 detik selama tab terlihat.
- Fase 6 selesai. Laporan per tab di /reports (ringkasan, produk, laba kotor, arus kas) dari satu sumber (`ReportCatalog` → `ReportTable`) yang sama untuk layar, Excel, dan PDF. Daftar bill di /orders. Excel memakai penulis XLSX bawaan; PDF memakai dompdf bila terpasang (`composer require dompdf/dompdf`), selain itu halaman siap cetak. Ekspor besar lewat antrean dan disimpan 24 jam.
- Catatan untuk Fase 7: PIN supervisor yang sama dipakai dua orang membuat penyetuju tercatat sebagai orang pertama yang cocok. Seeder memakai 123456 untuk semua; pertimbangkan PIN unik per outlet. (Selesai di Fase 7.)
- Fase 7 selesai 2026-10-10. Baru: halaman Staf (/staff) dengan PIN unik per rekan kerja, PIN sendiri di Pengaturan > PIN, akun nonaktif langsung keluar dari sesinya, PIN supervisor ganda ditolak dengan pesan jelas; Pengaturan outlet ditambah profil/struk (manajer) dan pajak/servis/pembulatan (owner); Metode pembayaran (/payment-methods, owner); `meja:backup` terjadwal 04.30 dengan rotasi; README akun demo, cadangan/pemulihan, deploy Laragon dan VPS, daftar animasi.
- Ditemukan & diperbaiki di Fase 7 (lewat E2E browser): ketuk menu di layar kasir online tidak pernah memperbarui keranjang atau membuka lembar modifier di browser, karena grid menu dan keranjang dibungkus `@island` Livewire (aksi di dalam island hanya merender island itu). Island dihapus, ada test penjaga. Test Livewire sebelumnya tidak menangkapnya karena memeriksa state, bukan DOM.
- Ditemukan & diperbaiki di Fase 7: lembar modifier tetap tampil kosong setelah "Masukkan keranjang" (computed `modifierProduct` tersimpan selama request); hitungan keranjang selalu "Belum ada item" (format `trans_choice` salah); pesan validasi, login, dan reset kata sandi masih bahasa Inggris (sekarang `lang/id/validation.php`, `auth.php`, `passwords.php`, `pagination.php`); tabel laporan, katalog, dan stok melebar di HP; jam KDS "hampir telat" dan ikon peringatan dashboard kurang kontras di mode terang.
- Fase 7: kelas warna bawaan starter kit (zinc/red/green) diganti token aplikasi; hex hanya tersisa di `app.css` (definisi token), `exports/_styles.blade.php` (PDF dompdf), dan meta `theme-color` (tag meta tidak bisa membaca variabel CSS).
- Fase 7: halaman /design-system hanya terbuka di environment local dan testing.
- E2E 2026-10-10 di database `migrate:fresh --seed`: owner tambah staf, PIN ganda ditolak, ubah fee metode bayar, catatan kaki struk, PIN sendiri, serah terima kasir lewat kunci layar, buka shift, pesan dengan modifier, bayar tunai, struk, Esc menutup lembar refund, refund dengan PIN owner, laporan + Excel/PDF, daftar bill + Excel; 21 halaman di 390px dan 1280px, terang dan gelap: tanpa overflow, tanpa error console, tanpa request gagal (KDS butuh Reverb hidup untuk websocket).
- Commit sebelum prompt: `d5b56a8 fix: pertahankan draft offline tertahan`.
- Baseline 2026-09-22: `php artisan test --compact` hijau: 270 passed, 640 assertions, 135.42s. `npm run build` hijau.
- Untracked sebelum audit: `Claude outputs/`; tidak disentuh.