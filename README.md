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

## Gerbang sebelum commit

```bash
php artisan test
vendor/bin/pint
vendor/bin/phpstan analyse
npm run build
```
