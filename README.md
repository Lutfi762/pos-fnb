# meja

POS untuk kafe dan restoran kecil di Indonesia. Laravel 13, Livewire 4, Flux,
MySQL. Bahasa antarmuka: Indonesia.

## Menjalankan di Laragon

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
npm run dev
```

Buka `http://meja.test`.

## Layar dapur (KDS)

Papan dapur di `/kitchen` memakai websocket lewat Laravel Reverb. Reverb adalah
proses tersendiri, jadi Laragon perlu dua terminal yang jalan berbarengan:

```bash
# terminal 1 - server websocket, biarkan terbuka selama jam buka
php artisan reverb:start

# terminal 2 - vite
npm run dev
```

Kunci yang dipakai ada di `.env`:

```
BROADCAST_CONNECTION=reverb
REVERB_HOST="localhost"
REVERB_PORT=8080
```

Kalau Reverb mati, papan tidak ikut mati: layar menampilkan peringatan koneksi
dan tetap menyegarkan diri tiap 10 detik lewat polling. Pesanan tidak pernah
hilang, hanya telat muncul beberapa detik.

Untuk produksi, jalankan `php artisan reverb:start` di bawah supervisor
(`supervisord`, `systemd`, atau Task Scheduler di Windows) supaya proses naik
lagi sendiri setelah reboot.

### Outlet tanpa layar

Outlet dengan `outlets.has_kitchen_display = false` tidak memakai papan. Begitu
kasir menyimpan pesanan, aplikasi langsung membuka struk dapur (`/kitchen/{order}/docket`)
dan dicetak lewat printer termal yang sama dengan struk pelanggan.

### Rute stasiun

Stasiun (`bar` atau `kitchen`) dan target waktu saji diatur per kategori di
`categories.station` dan `categories.prep_minutes`. Satu produk boleh menyimpang
lewat `products.station`. Nilai itu disalin ke baris pesanan saat dikirim, jadi
mengubah kategori tidak memindahkan tiket yang sudah jalan.

## QRIS

Ada dua jenis QRIS di layar pembayaran:

- **QRIS** (dinamis, `payment_methods.mode = gateway_qris`): server meminta kode
  baru ke gateway untuk nominal yang diketik kasir, lalu menunggu konfirmasi.
  Uang baru masuk bill setelah gateway bilang lunas, lewat webhook atau lewat
  pengecekan status tiap beberapa detik. Bisa dipakai untuk sebagian bill
  (split dengan tunai).
- **QRIS Statis** (`static_qris`): stiker QRIS di meja kasir. Kasir mengisi
  referensi dari layar tamu dan boleh memotret buktinya. Manajer mencocokkan
  dengan mutasi rekening di **Cek pembayaran** (`/payments/review`).

Migrasi Fase 3 mengubah metode QRIS lama (`type = qris`) menjadi QRIS statis,
karena tanpa gateway memang begitulah cara kerjanya. Setelah gateway siap,
ubah mode metode QRIS ke `gateway_qris` (atau jalankan ulang
`php artisan db:seed --class=PaymentMethodSeeder`).

QRIS dinamis butuh internet. Kasir offline (`/pos/offline`) hanya menerima tunai.

### Mode uji tanpa akun (bawaan)

```
PAYMENT_GATEWAY=fake
```

Gateway palsu menerbitkan kode yang tampak seperti QRIS. Di layar pembayaran ada
tombol **Simulasikan tamu membayar** (hanya di luar produksi) yang mengirim
webhook bertanda tangan lewat jalur yang sama dengan gateway sungguhan.

### QRIS dengan Xendit

1. Buat akun Xendit, aktifkan mode **Test**, lalu buat *Secret API Key* dengan
   izin tulis untuk QR Codes dan izin baca untuk Transactions.
2. Isi `.env`:

   ```
   PAYMENT_GATEWAY=xendit
   XENDIT_SECRET_KEY=xnd_development_...
   XENDIT_WEBHOOK_TOKEN=...   # Settings > Webhooks > Verification token
   QRIS_EXPIRY_MINUTES=10
   ```

3. Di dashboard Xendit, arahkan webhook **QR Code paid** ke
   `https://<domain-anda>/webhooks/payments/xendit`.
4. Jalankan antrean supaya webhook diproses: `php artisan queue:work`.
   Kalau antrean mati, pembayaran tetap terkonfirmasi lewat pengecekan status
   dari layar kasir; hanya baris webhook yang tertinggal berstatus `received`.
5. Jalankan scheduler (`php artisan schedule:work`, atau Task Scheduler di
   Windows) untuk rekonsiliasi settlement otomatis tiap pagi pukul 06.10.
   Manual: `php artisan meja:reconcile-settlements 2026-09-27`.

Daftar cek sebelum shift pertama dengan Xendit (kode ini ditulis dari dokumentasi
API `2022-07-31`, belum diuji dengan akun sandbox sungguhan):

- Buat kode Rp1.500, bayar lewat simulator Xendit, pastikan bill lunas dan baris
  `gateway_webhooks` berstatus `processed`.
- Kirim ulang webhook yang sama dari dashboard: tidak boleh ada pembayaran ganda.
- Biarkan satu kode kedaluwarsa, lalu refund sebagian satu pembayaran QRIS dari
  layar struk.
- Jalankan `meja:reconcile-settlements` untuk hari itu dan periksa hasilnya di
  **Cek pembayaran**.

### Webhook di laptop (Laragon)

Xendit tidak bisa menjangkau `http://meja.test`. Untuk mencoba webhook dari
laptop, buka terowongan HTTPS dan pakai alamatnya di dashboard Xendit:

```bash
# pilih salah satu
cloudflared tunnel --url http://meja.test --http-host-header meja.test
ngrok http --host-header=meja.test 80
```

Tanpa terowongan pun QRIS tetap jalan: layar pembayaran menanyakan status ke
Xendit tiap `QRIS_STATUS_CHECK_SECONDS` (bawaan 10 detik). Webhook hanya
membuatnya lebih cepat.

Kalau `BROADCAST_CONNECTION=reverb` dan Reverb berjalan, layar pembayaran juga
langsung pindah ke struk begitu webhook masuk.

## Laporan dan ekspor

- `/dashboard`: hari ini dibanding hari yang sama minggu lalu, grafik 7 hari,
  jam sibuk, stok kritis, dan status sistem.
- `/reports`: ringkasan, penjualan per produk, laba kotor, dan arus kas untuk
  rentang tanggal bebas (semuanya per hari operasional outlet). Kasir hanya
  melihat ringkasan dan produk, tanpa harga pokok.
- `/orders`: semua bill dengan filter; tiap bill bisa diunduh sebagai PDF atau
  Excel oleh siapa pun di outlet itu.

Ekspor laporan (Excel/PDF) hanya untuk manajer dan owner, dan dicatat di audit
log. Laporan lebih dari `REPORT_QUEUE_THRESHOLD` baris (bawaan 2000) disiapkan
lewat antrean (`php artisan queue:work`) lalu muncul di "Ekspor terakhir";
filenya dihapus otomatis setelah 24 jam (`meja:prune-exports`, terjadwal tiap
jam).

PDF langsung butuh dompdf:

```bash
composer require dompdf/dompdf
```

Tanpa dompdf, tombol PDF membuka halaman siap cetak yang langsung memunculkan
dialog cetak; pilih "Simpan sebagai PDF".

## Gerbang sebelum commit

```bash
php artisan test
vendor/bin/pint
vendor/bin/phpstan analyse
npm run build
```
