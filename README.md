# PrintEase

**PrintEase** adalah Website untuk pemesanan cetak dokumen dan banner dengan kalkulasi tarif otomatis (**OOP**), pengelolaan antrean cetak (**Queue**), dan pencatatan riwayat aktivitas (**Stack**).

## Fitur Utama

* **Pemesanan & Unggah Berkas:** Mendukung unggah dokumen (`.pdf`, `.doc`, `.docx`, `.ppt`, `.pptx`, `.txt` maks. 10 MB) beserta pilihan ukuran kertas (A4, F4, A3 Poster), jenis tinta, dan jilid.
* **Kalkulasi Tarif Otomatis (OOP):** Perhitungan harga real-time di sisi klien dan server menggunakan konsep *Inheritance* & *Polimorfisme* (`CetakHitamPutih` dan `CetakBerwarna`).
* **Lacak Pesanan:** Cek progres cetakan secara mandiri menggunakan kode pesanan dan NIM.
* **Antrean Cetak (Queue - FIFO):** Operator dapat memproses pesanan yang masuk secara berurutan dari antrean paling awal.
* **Riwayat Aktivitas (Stack - LIFO):** Mencatat hingga 20 aktivitas terbaru dengan urutan paling baru di atas.
* **Tanpa Database Eksternal:** Data disimpan dalam berkas JSON (`data/orders.json` dan `data/logs.json`) yang dibuat otomatis.

## Materi yang Diterapkan

| Materi | Implementasi dalam Kode |
| :--- | :--- |
| **Variabel & Tipe Data** | Konstanta (`MAX_UPLOAD`, `EKSTENSI_IZIN`), tipe `string`, `int`, `float` (`biayaPerLembar()`), `bool`, dan `array`. |
| **Array** | Array asosiatif & multidimensi untuk daftar tarif (`TARIF_UKURAN`, `TARIF_WARNA`, `TARIF_JILID`) dan badge status. |
| **Pengkondisian** | `if-else`, `switch-case` pada `pesanStatus()`, serta ekspresi `match` untuk validasi input formulir pesanan. |
| **Perulangan** | `foreach` (daftar pesanan & opsi kartu), `while` (batas kapasitas log), `do-while` (kode unik `PE-...`), dan `for` (progres status). |
| **Function** | Fungsi modular (`rupiah()`, `aman()`, `ubahJson()`, `catatLog()`, `kunciCsrf()`) serta *arrow function* (`fn`). |
| **OOP I (Enkapsulasi)** | Kelas `PesananCetak` dengan properti `private`, pembacaan atribut lewat `__get()`, dan perubahan status lewat `setStatus()`. |
| **OOP II (Polimorfisme & Interface)** | `abstract class PesananCetak`, pewarisan & *override* pada `CetakHitamPutih` dan `CetakBerwarna`, serta `interface StrukturData`. |
| **Struktur Data (Queue & Stack)** | `AntreanCetakQueue` (**FIFO**: `enqueue`, `dequeue`, `peek`) untuk antrean cetak dan `RiwayatAktivitasStack` (**LIFO**: `push`, `pop`) untuk riwayat aktivitas. |
| **GUI (Antarmuka Web)** | Antarmuka responsif menggunakan HTML5, Tailwind CSS, dan kalkulator biaya otomatis real-time berbasis JavaScript. |

## Cara Menjalankan

1. Pastikan **PHP 8.1+** sudah terpasang.
2. Jalankan server lokal di folder proyek:
   ```bash
   php -S localhost:8000
   ```
3. Buka di browser:
   * **Halaman Pemesan:** `http://localhost:8000/index.php`
   * **Halaman Operator:** `http://localhost:8000/index.php?admin` (Login: `admin` / `admin123`)
   * **Pengujian Mandiri:** `http://localhost:8000/index.php?selftest`

## Struktur Proyek

```text
printease/
├── index.php    # Kode utama (UI, logika OOP, Queue, Stack)
├── data/        # Penyimpanan JSON pesanan & log (otomatis)
└── uploads/     # Penyimpanan dokumen unggahan (otomatis)
```