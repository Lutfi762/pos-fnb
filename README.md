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

### Akun demo

Seeder membuat satu akun per peran di outlet pertama. Kata sandi semuanya
`password`; PIN dibedakan karena supervisor dikenali dari PIN-nya saja.

| Peran | Email | PIN |
| --- | --- | --- |
| Owner | `owner@meja.test` | `246810` |
| Manajer | `manajer@meja.test` | `135790` |
| Kasir | `kasir@meja.test` | `102030` |
| Dapur | `dapur@meja.test` | `112233` |
| Gudang | `gudang@meja.test` | `445566` |

Ganti semuanya sebelum dipakai sungguhan, atau buat akun baru dari menu **Staf**.

## Staf, PIN, dan pengaturan owner

- **Staf** (`/staff`, owner dan manajer): tambah orang, pilih peran, isi kata
  sandi awal dan PIN 6 angka, nonaktifkan. Manajer hanya mengatur kasir, dapur,
  dan gudang di outletnya sendiri; owner mengatur semua peran dan boleh
  menempatkan satu orang di beberapa outlet. Akun yang dinonaktifkan langsung
  keluar dari sesi yang sedang berjalan.
- PIN dipakai untuk membuka kunci layar dan, bagi manajer/owner, menyetujui
  diskon besar, refund, dan selisih kas. Aplikasi menolak PIN yang sudah dipakai
  rekan satu outlet (owner dihitung rekan di semua outlet) dan PIN yang mudah
  ditebak (`000000`, `123456`). Setiap orang bisa mengganti PIN-nya sendiri di
  **Pengaturan > PIN**.
- **Pengaturan outlet** (`/outlet/settings`): nama, alamat, judul dan catatan
  kaki struk, dan apakah dapur memakai layar (manajer dan owner). Pajak, biaya
  layanan, harga termasuk pajak, dan pembulatan hanya bisa diubah owner, dan
  berlaku mulai bill berikutnya.
- **Metode pembayaran** (`/payment-methods`, owner): aktif/nonaktif, potongan
  penyedia, urutan di layar kasir, QRIS dinamis atau statis, dan metode manual
  baru (EDC, dompet digital). Tunai tidak bisa dimatikan karena kasir offline
  hanya menerima tunai.

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

## Cadangan dan pemulihan

```bash
php artisan meja:backup            # satu cadangan sekarang
php artisan meja:backup --keep=30  # simpan 30 terakhir
```

Cadangan ditulis ke `BACKUP_PATH` (bawaan `storage/app/backups`) dengan nama
`meja-YYYYMMDD-HHMMSS.sql.gz`, dan yang lebih lama dari `BACKUP_KEEP` (bawaan 14)
dihapus. Scheduler menjalankannya tiap hari pukul 04.30. MySQL disalin dengan
`mysqldump --single-transaction`, jadi kasir tetap bisa berjualan selama proses.
Di Laragon, kalau `mysqldump` tidak ditemukan oleh Task Scheduler, isi
`BACKUP_MYSQLDUMP=C:\laragon\bin\mysql\mysql-8.x\bin\mysqldump.exe`.

Arahkan `BACKUP_PATH` ke disk lain atau folder yang tersinkron (Google Drive,
OneDrive): cadangan di disk yang sama ikut hilang kalau disknya rusak.

Memulihkan (hentikan dulu antrean dan Reverb, lalu):

```bash
# MySQL / MariaDB
gunzip -k meja-20261010-043000.sql.gz
mysql -u root -p meja < meja-20261010-043000.sql

# SQLite
gunzip -c meja-20261010-043000.sqlite.gz > database/database.sqlite

php artisan migrate            # bila cadangan lebih lama dari kode
php artisan meja:rebuild-summaries
```

Coba pulihkan ke database terpisah sebulan sekali; cadangan yang belum pernah
dicoba dipulihkan belum tentu bisa dipakai.

## Deploy

### Satu outlet dengan Laragon (laptop kasir)

1. `.env`: `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=http://meja.test`
   (atau IP laptop bila tablet ikut memakai), `DB_CONNECTION=mysql`.
2. `composer install --no-dev --optimize-autoloader`, `npm ci`, `npm run build`,
   `php artisan migrate --force`, `php artisan optimize`.
3. Task Scheduler Windows, tiap menit: `php C:\laragon\www\meja\artisan schedule:run`.
4. Dua proses yang harus hidup sejak login Windows (Task Scheduler "At log on"):
   `php artisan queue:work --tries=3` dan `php artisan reverb:start`.

### VPS (beberapa outlet)

Ubuntu 24.04, Nginx, PHP 8.4-FPM (ekstensi `intl`, `zip`, `gd`, `pdo_mysql`,
`bcmath`), MySQL 8, Node 22 hanya untuk build.

```bash
git clone <repo> /var/www/meja && cd /var/www/meja
composer install --no-dev --optimize-autoloader
npm ci && npm run build
cp .env.example .env && php artisan key:generate
php artisan migrate --force
php artisan storage:link
php artisan optimize
chown -R www-data:www-data storage bootstrap/cache
```

Daftar cek `.env` produksi:

- `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://...` (HTTPS wajib:
  kasir offline memakai service worker, yang hanya jalan di HTTPS).
- `DB_*`, `QUEUE_CONNECTION=database`, `CACHE_STORE=database` atau `redis`.
- `SESSION_SECURE_COOKIE=true`.
- `BROADCAST_CONNECTION=reverb` dan `REVERB_*`; pasang Nginx sebagai proxy
  websocket ke port Reverb.
- `PAYMENT_GATEWAY=xendit`, `XENDIT_SECRET_KEY`, `XENDIT_WEBHOOK_TOKEN`.
- `BACKUP_PATH` di luar folder aplikasi, `MAIL_*` untuk reset kata sandi.

Cron (`crontab -u www-data -e`):

```
* * * * * cd /var/www/meja && php artisan schedule:run >> /dev/null 2>&1
```

Supervisor (`/etc/supervisor/conf.d/meja.conf`):

```ini
[program:meja-queue]
command=php /var/www/meja/artisan queue:work --tries=3 --max-time=3600
user=www-data
autostart=true
autorestart=true
numprocs=1

[program:meja-reverb]
command=php /var/www/meja/artisan reverb:start --host=127.0.0.1 --port=8080
user=www-data
autostart=true
autorestart=true
```

Setiap rilis: `git pull`, `composer install --no-dev -o`, `npm ci && npm run build`,
`php artisan migrate --force`, `php artisan optimize`, lalu
`supervisorctl restart meja-queue meja-reverb`.

## Animasi

Semua animasi CSS (tanpa pustaka animasi; Motion One tidak dipakai supaya kasir
offline tidak mengunduh skrip tambahan) dan hanya berjalan saat
`prefers-reduced-motion` tidak aktif:

- `motion-rise`: kartu dan keadaan kosong muncul naik pelan.
- `motion-draw`, `meja-grow`: garis dan batang grafik dashboard tumbuh sekali.
- Angka KPI dashboard menghitung naik (`countUp`); dilewati bila reduced motion.
- `motion-pulse-soft`, `motion-shimmer`: penanda menunggu dan kerangka muat.
- Lembar bawah POS (`meja-sheet-up`), tombol bayar, kartu tiket KDS
  (`kds-edge` untuk tiket terlambat), badge koneksi offline.

Dengan reduced motion, `app.css` memotong semua durasi menjadi 1 ms, dan grafik
digambar tanpa animasi.

## Gerbang sebelum commit

```bash
php artisan test
vendor/bin/pint
vendor/bin/phpstan analyse
npm run build
```
