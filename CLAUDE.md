## Tentang proyek

Aplikasi kasir F and B multi-outlet, offline-capable (PWA), dipakai di laptop, tablet, dan HP.
Pengguna: kasir, manajer outlet, owner, staf dapur, staf gudang.

## Aturan kode

- Semua file PHP diawali declare(strict_types=1).
- Nama tabel, kolom, class, variabel, dan komentar dalam bahasa Inggris. Semua teks yang dilihat pengguna dalam bahasa Indonesia lewat file lang/id, tidak pernah di-hardcode di Blade.
- Uang disimpan sebagai integer rupiah penuh (bigint), tidak pernah float. Kuantitas bahan decimal(12,3).
- Primary key memakai ULID di semua tabel domain (syarat mode offline).
- Struktur folder per domain: app/Domain/{Sales,Catalog,Inventory,Purchasing,Identity,Sync}. Komponen Livewire di resources/views/pages dan resources/views/components sesuai konvensi Livewire 4.
- Logika bisnis di Action/Service class, bukan di komponen Livewire dan bukan di controller.
- Validasi lewat Form Request atau rules() Livewire, tidak pernah percaya input mentah.
- Setiap query yang menampilkan daftar wajib pakai eager loading. AppServiceProvider mengaktifkan Model::preventLazyLoading() saat environment local.
- Semua master data pakai soft delete. Baris transaksi menyimpan snapshot nama dan harga.

## Aturan kualitas (wajib sebelum sebuah langkah dinyatakan selesai)

- php artisan test hijau.
- npm run build tidak ada error.
- Tidak ada error di console browser dan tidak ada request gagal di tab Network.
- Setiap hitungan uang, pajak, diskon, dan stok punya test Pest sendiri.
- Tidak ada TODO tersisa di kode yang ditulis pada langkah itu.

## Aturan antarmuka

- Target sentuh minimum 44x44 px. Ukuran teks minimum 15px di layar kasir.
- Semua angka pakai tabular-nums dan rata kanan.
- Setiap animasi wajib dinonaktifkan otomatis saat prefers-reduced-motion aktif.
- Mode terang dan gelap keduanya wajib jalan; warna selalu lewat token CSS, tidak pernah hex langsung di komponen.

## Cara kerja

- Kerjakan satu langkah saja per sesi. Jangan mengerjakan fitur langkah berikutnya.
- Kalau ada keputusan yang ambigu, TANYA dulu, jangan menebak.
- Akhiri setiap langkah dengan git commit berpesan jelas.
