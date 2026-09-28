<?php

declare(strict_types=1);

return [
    'title' => 'Laporan',
    'subtitle' => 'Penjualan :outlet',

    'preset' => [
        'today' => 'Hari ini',
        'yesterday' => 'Kemarin',
        'week' => '7 hari',
        'month' => 'Bulan ini',
    ],

    'from' => 'Dari tanggal',
    'to' => 'Sampai tanggal',
    'export' => 'Unduh CSV',

    'net_sales' => 'Penjualan bersih',
    'gross' => 'Total tagihan',
    'gross_profit' => 'Laba kotor',
    'cost' => 'Harga pokok',
    'margin' => 'Margin',
    'orders' => 'Jumlah bill',
    'guests' => 'Jumlah tamu',
    'average_order' => 'Rata-rata per bill',
    'average_guest' => 'Rata-rata per tamu',
    'discounts' => 'Diskon',
    'tax' => 'Pajak',
    'service_charge' => 'Servis',
    'date' => 'Tanggal',

    'net_sales_hint' => 'Tagihan dikurangi pajak, servis, dan pembulatan.',
    'vs_previous' => 'Dibanding :days hari sebelumnya',
    'up' => 'Naik :percent',
    'down' => 'Turun :percent',
    'flat' => 'Setara',

    'daily' => 'Penjualan harian',
    'top_products' => 'Produk terlaris',
    'payment_methods' => 'Metode pembayaran',
    'shifts' => 'Rekap shift',

    'product' => 'Produk',
    'sold' => 'Terjual',
    'method' => 'Metode',
    'tenders' => 'Transaksi',
    'fee' => 'Biaya',
    'total' => 'Total',

    'empty_heading' => 'Belum ada penjualan',
    'empty_description' => 'Pilih rentang tanggal lain atau mulai transaksi di kasir.',

    'refunds' => 'Refund',
    'refunded' => 'Dikembalikan',
    'net_after_refunds' => 'Bersih setelah refund',
    'waste' => 'Waste',
    'business_day_hint' => 'Hari operasional mulai pukul :hour. Penjualan sebelum jam itu masuk hari sebelumnya.',

    'rebuild' => [
        'done' => ':days hari ringkasan dibangun ulang (:range).',
        'redated' => ':outlet: :count transaksi pindah tanggal operasional.',
        'unknown_outlet' => 'Outlet dengan kode :code tidak ditemukan.',
    ],
];
