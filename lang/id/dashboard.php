<?php

declare(strict_types=1);

return [
    'nav' => 'Dashboard',
    'title' => 'Dashboard',
    'greeting' => [
        'morning' => 'Selamat pagi, :name',
        'midday' => 'Selamat siang, :name',
        'afternoon' => 'Selamat sore, :name',
        'evening' => 'Selamat malam, :name',
    ],
    'business_day' => ':outlet · Hari operasional :date (mulai :hour)',
    'updated' => 'Diperbarui :time',
    'open_pos' => 'Buka kasir',
    'open_reports' => 'Laporan lengkap',

    'kpi' => [
        'net_sales' => 'Penjualan bersih',
        'orders' => 'Transaksi',
        'average' => 'Rata-rata per bill',
        'gross_profit' => 'Laba kotor',
        'margin' => 'margin :percent',
        'refunds' => 'Refund',
        'refunds_note' => ':count refund',
        'waste' => 'Waste',
        'waste_note' => 'dicatat terpisah dari HPP',
        'guests' => ':count tamu',
        'up' => 'naik :percent',
        'down' => 'turun :percent',
        'flat' => 'sama',
        'new' => 'minggu lalu belum ada',
        'vs' => 'vs :day lalu di jam yang sama',
    ],

    'week' => [
        'title' => '7 hari terakhir',
        'subtitle' => 'Penjualan bersih per hari operasional, dibanding minggu sebelumnya.',
        'this_week' => 'Minggu ini',
        'last_week' => 'Minggu lalu',
        'today' => 'hari ini, masih berjalan',
        'show_table' => 'Lihat angka',
        'day' => 'Hari',
        'chart_label' => 'Grafik penjualan bersih 7 hari terakhir. Total :total, hari terbaik :best.',
    ],

    'heatmap' => [
        'title' => 'Jam sibuk',
        'subtitle' => 'Jumlah bill per jam, 4 minggu terakhir.',
        'peak' => 'Paling ramai: :day pukul :hour (:orders bill).',
        'empty' => 'Belum cukup transaksi untuk melihat pola jam sibuk.',
        'cell' => ':day pukul :hour: :orders bill',
        'less' => 'Sepi',
        'more' => 'Ramai',
    ],

    'products' => [
        'title' => 'Produk terlaris hari ini',
        'empty' => 'Belum ada produk terjual hari ini.',
        'sold' => ':quantity terjual',
    ],

    'methods' => [
        'title' => 'Cara bayar hari ini',
        'empty' => 'Belum ada pembayaran hari ini.',
    ],

    'stock' => [
        'title' => 'Stok kritis',
        'empty' => 'Semua bahan di atas titik pesan ulang.',
        'left' => 'sisa :quantity :unit',
        'reorder' => 'pesan ulang di :quantity',
        'negative' => 'minus, perlu dihitung',
        'open' => 'Buka stok',
    ],

    'waste' => [
        'title' => 'Waste hari ini',
        'empty' => 'Belum ada waste hari ini.',
        'open' => 'Catat waste',
    ],

    'cashiers' => [
        'title' => 'Kasir hari ini',
        'empty' => 'Belum ada bill yang lunas hari ini.',
        'orders' => ':count bill',
    ],

    'status' => [
        'title' => 'Status sistem',
        'all_good' => 'Semua berjalan normal.',
        'sync_failed' => ':count perintah sinkronisasi gagal',
        'devices_stale' => ':count perangkat belum sinkron lebih dari sehari',
        'static_qris' => ':count QRIS statis menunggu dicek',
        'qris_unapplied' => ':count QRIS dibayar tapi tidak masuk bill',
        'webhooks_failed' => ':count webhook gateway gagal diproses minggu ini',
        'negative_stock' => ':count bahan stoknya minus',
        'last_settlement' => 'Settlement gateway terakhir dicocokkan: :date',
        'no_settlement' => 'Settlement gateway belum pernah dicocokkan',
    ],

    'outlets' => [
        'title' => 'Semua outlet hari ini',
        'outlet' => 'Outlet',
        'net' => 'Penjualan bersih',
        'orders' => 'Transaksi',
        'change' => 'vs minggu lalu',
    ],
];
