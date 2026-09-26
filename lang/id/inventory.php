<?php

declare(strict_types=1);

return [
    'title' => 'Stok',
    'subtitle' => 'Persediaan di :outlet',
    'item' => 'Bahan',
    'unit' => 'Satuan',
    'on_hand' => 'Sisa',
    'reorder_point' => 'Batas pesan ulang',
    'average_cost' => 'Harga rata-rata',
    'value' => 'Nilai',
    'counted_at' => 'Terakhir dihitung',
    'never_counted' => 'Belum pernah',
    'search' => 'Cari bahan',
    'search_placeholder' => 'Nama atau SKU',
    'low_only' => 'Hanya yang menipis',
    'low' => 'Menipis',

    'adjust' => 'Sesuaikan',
    'adjust_title' => 'Sesuaikan stok :item',
    'quantity' => 'Jumlah',
    'quantity_hint' => 'Isi minus untuk mengurangi.',
    'counted_quantity' => 'Hasil hitung fisik',
    'note' => 'Catatan',
    'note_placeholder' => 'Contoh: susu tumpah saat penerimaan',
    'save' => 'Simpan',
    'count' => 'Opname',
    'count_title' => 'Opname :item',
    'count_hint' => 'Isi jumlah yang benar-benar ada di rak.',

    'adjusted' => 'Stok :item kini :quantity.',
    'counted' => 'Opname :item disimpan.',
    'count_matched' => 'Hitungan sudah cocok, tidak ada perubahan.',
    'quantity_required' => 'Jumlah tidak boleh nol.',

    'history' => 'Riwayat',
    'history_title' => 'Riwayat :item',
    'history_empty' => 'Belum ada pergerakan',
    'moved_at' => 'Waktu',
    'balance_after' => 'Sisa',
    'by' => 'Oleh',

    'empty_heading' => 'Belum ada bahan',
    'empty_description' => 'Tambahkan bahan lewat data master, lalu catat stok masuk.',
    'no_results_heading' => 'Tidak ada yang cocok',
    'no_results_description' => 'Coba kata kunci lain atau matikan filter.',

    'movement' => [
        'purchase' => 'Pembelian',
        'sale' => 'Penjualan',
        'waste' => 'Rusak',
        'adjustment' => 'Penyesuaian',
        'transfer' => 'Transfer',
        'count' => 'Opname',
    ],

    'review_heading' => 'Stok minus, perlu dihitung ulang',
    'review_description' => 'Penjualan tetap tercatat, tetapi buku stok berada di bawah nol. Biasanya terjadi saat kasir offline atau barang masuk belum dicatat. Hitung fisik untuk membetulkan.',
    'review_last_sold' => 'Terakhir terjual :time',
    'count_now' => 'Hitung sekarang',
];
