<?php

declare(strict_types=1);

return [
    'select_title' => 'Pilih outlet',
    'select_description' => 'Pilih outlet tempat Anda bekerja hari ini.',
    'none_heading' => 'Belum ada outlet',
    'none_description' => 'Akun Anda belum ditugaskan ke outlet mana pun. Hubungi manajer Anda.',
    'switch' => 'Ganti outlet',
    'current' => 'Outlet aktif',

    'settings' => [
        'nav' => 'Pengaturan outlet',
        'title' => 'Pengaturan outlet',
        'subtitle' => 'Jam dan hari operasional :outlet.',
        'timezone' => 'Zona waktu',
        'day_starts_at' => 'Hari operasional mulai pukul',
        'day_starts_hint' => 'Penjualan sebelum jam ini dihitung ke hari sebelumnya. Contoh: mulai 04.00, bill lunas pukul 01.30 masuk laporan kemarin malam.',
        'applies_hint' => 'Berlaku untuk transaksi berikutnya. Hari yang sudah lewat tidak berubah.',
        'example' => 'Hari ini (:date) berjalan dari :from sampai :to.',
        'save' => 'Simpan',
        'saved' => 'Pengaturan outlet disimpan.',
        'zones' => [
            'Asia/Jakarta' => 'WIB (Jakarta, Sumatra, Jawa, Kalimantan Barat dan Tengah)',
            'Asia/Makassar' => 'WITA (Bali, NTB, NTT, Sulawesi, Kalimantan Timur dan Selatan)',
            'Asia/Jayapura' => 'WIT (Maluku, Papua)',
        ],
    ],
    'timezone_invalid' => 'Pilih zona waktu Indonesia yang tersedia.',
    'day_start_invalid' => 'Jam mulai hari harus antara 00.00 dan 08.00.',
];
