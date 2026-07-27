# Buku Saku Sales: Tindak Lanjut

## Import spreadsheet lama

Import spreadsheet Buku Saku Sales lama bersifat opsional dan belum disertakan. Jika dibutuhkan, format sumber, pemetaan kolom, aturan duplikasi, dan validasi pemilik data perlu disepakati sebelum proses migrasi dibuat.

## Tautan konsumen

Data konsumen saat ini belum memiliki foreign key lokal yang stabil untuk ditautkan dari lead Buku Saku Sales. Kolom `linked_consumer_reference` hanya boleh diisi sebagai referensi manual yang aman.

Sistem tidak menautkan konsumen otomatis berdasarkan nama. Nama dapat sama, berubah, atau salah ketik sehingga pencocokan tersebut berisiko menghubungkan lead ke konsumen yang keliru dan membuka data kepada pengguna yang tidak berhak.
