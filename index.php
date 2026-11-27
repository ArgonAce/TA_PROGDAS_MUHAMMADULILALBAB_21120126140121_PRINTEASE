<?php
session_set_cookie_params(["httponly" => true, "samesite" => "Lax"]); //buat mengurangi risiko pencurian cookie lewat xss, dan risiko CSRF
session_start();
date_default_timezone_set("Asia/Jakarta");
$_SESSION["csrf"] ??= bin2hex(random_bytes(16)); //buat nyimpen token CSRF

// buat membuat folder dan nyimpen data
const FILE_ORDERS = __DIR__ . "/data/orders.json";
const FILE_LOGS = __DIR__ . "/data/logs.json";
const DIR_UPLOADS = __DIR__ . "/uploads";
const MAX_UPLOAD = 10 * 1024 * 1024; // int, 10 MB

const ADMIN_USER = "admin";
const ADMIN_PASS = "admin123";

const EKSTENSI_IZIN = ["pdf", "doc", "docx", "ppt", "pptx", "txt"];

// Tarif: [label, nilai, keterangan]. Satu sumber untuk PHP dan kalkulator JS.
const TARIF_UKURAN = ["A4" => ["A4 Standar", 1, "210 × 297 mm"], "F4" => ["F4 Folio", 2, "215 × 330 mm, harga ×2"], "A3" => ["A3 Poster", 3, "297 × 420 mm, harga ×2"]];
const TARIF_WARNA = ["hitam" => ["Hitam putih", 500, "Rp 500 / halaman"], "warna" => ["Berwarna", 1000, "Rp 1.000 / halaman"]];
const TARIF_JILID = ["tanpa" => ["Tanpa jilid", 0, "Gratis"], "lakban" => ["Jilid lakban", 3000, "Rp 3.000 / salinan"], "spiral" => ["Jilid spiral", 7000, "Rp 7.000 / salinan"]];
const STATUS_LIST = ["Menunggu", "Diproses", "Siap Diambil", "Diambil"];
const STATUS_UI = ["Menunggu" => "bg-amber-100 text-amber-900", "Diproses" => "bg-blue-100 text-blue-900", "Siap Diambil" => "bg-emerald-100 text-emerald-900", "Diambil" => "bg-slate-200 text-slate-700"];

foreach ([dirname(FILE_ORDERS), DIR_UPLOADS] as $folder) {
    is_dir($folder) || mkdir($folder, 0755, true);
    is_file("$folder/.htaccess") || file_put_contents("$folder/.htaccess", "Require all denied\n");
}

// abstract
abstract class PesananCetak
{
    // Enkapsulasi: semua properti private (promosi konstruktor), dibaca lewat __get, status diubah lewat setStatus().
    public function __construct(
        private string $id,
        private string $nomor,
        private string $nama,
        private string $nim,
        private string $ukuran,
        private int $halaman,
        private int $salinan,
        private string $file,
        private string $nama_file,
        private string $tanggal,
        private string $jilid = "tanpa",
        private string $catatan = "",
        private string $status = "Menunggu",
        mixed ...$abaikan
    ) {
        if (!in_array($this->status, STATUS_LIST, true))
            $this->status = "Menunggu";
    }

    public function __get(string $nama): mixed
    {
        return $this->$nama;
    }

    public function setStatus(string $status): void
    {
        if (in_array($status, STATUS_LIST, true))
            $this->status = $status;
    }

    // polymorphism
    abstract public function kodeWarna(): string;
    public function tarifPerHalaman(): int
    {
        return TARIF_WARNA[$this->kodeWarna()][1];
    }
    public function jenisCetak(): string
    {
        return TARIF_WARNA[$this->kodeWarna()][0];
    }

    public function hitungTotal(): int
    {
        $pengali = TARIF_UKURAN[$this->ukuran][1] ?? 1;
        return $this->halaman * $this->salinan * $this->tarifPerHalaman() * $pengali
            + $this->salinan * (TARIF_JILID[$this->jilid][1] ?? 0);
    }

    public function ringkasan(): string
    {
        $jilid = $this->jilid === "tanpa" ? "" : ", " . strtolower(TARIF_JILID[$this->jilid][0]);
        return "$this->ukuran, " . strtolower($this->jenisCetak()) . ", $this->halaman halaman × $this->salinan salinan$jilid";
    }

    public function biayaPerLembar(): float
    {
        return $this->hitungTotal() / max(1, $this->halaman * $this->salinan);
    }

    // switch case
    public function pesanStatus(): string
    {
        switch ($this->status) {
            case "Menunggu":
                return "Pesananmu menunggu giliran dicetak.";
            case "Diproses":
                return "Dokumenmu sedang dicetak.";
            case "Siap Diambil":
                return "Sudah selesai. Ambil di loket dan bayar di sana.";
            default:
                return "Pesanan sudah diambil.";
        }
    }

    public function label(): string
    {
        return "$this->nomor ($this->nama)";
    }

    public function toArray(): array
    {
        return get_object_vars($this) + ["warna" => $this->kodeWarna(), "total" => $this->hitungTotal()];
    }

    // Factory: memilih subclass dari data tersimpan.
    public static function dariArray(array $d): self
    {
        return ($d["warna"] ?? "hitam") === "warna" ? new CetakBerwarna(...$d) : new CetakHitamPutih(...$d);
    }
}

// Inheritance
class CetakHitamPutih extends PesananCetak
{
    public function kodeWarna(): string
    {
        return "hitam";
    }
}
class CetakBerwarna extends PesananCetak
{
    public function kodeWarna(): string
    {
        return "warna";
    }
    public function label(): string
    {
        return parent::label() . ", cetak warna";
    }
}
// Interface buat queue dan stack
interface StrukturData
{
    public function isEmpty(): bool;
    public function size(): int;
}
class AntreanCetakQueue implements StrukturData
{
    private array $items = [];
    public function enqueue(PesananCetak $p): void
    {
        $this->items[] = $p;
    }
    public function dequeue(): ?PesananCetak
    {
        return array_shift($this->items);
    }
    public function peek(): ?PesananCetak
    {
        return $this->items[0] ?? null;
    }
    public function size(): int
    {
        return count($this->items);
    }
    public function isEmpty(): bool
    {
        return !$this->items;
    }
}

class RiwayatAktivitasStack implements StrukturData
{
    public function __construct(private array $items = [], private int $kapasitas = 20)
    {
    }
    public function push(string $judul, string $detail): void
    {
        $this->items[] = ["waktu" => date("H:i"), "judul" => $judul, "detail" => $detail];
        while (count($this->items) > $this->kapasitas)
            array_shift($this->items);
    }
    public function pop(): ?array
    {
        return array_pop($this->items);
    }
    public function isEmpty(): bool
    {
        return !$this->items;
    }
    public function size(): int
    {
        return count($this->items);
    }
    public function dariTeratas(): array
    {
        return array_reverse($this->items);
    }
    public function toArray(): array
    {
        return $this->items;
    }
}

// buat penyimpanan
function aman($teks): string
{
    return htmlspecialchars((string) $teks, ENT_QUOTES, "UTF-8");
}
function rupiah(int $n): string
{
    return "Rp " . number_format($n, 0, ",", ".");
}
function bacaJson(string $path): array
{
    return json_decode((string) @file_get_contents($path), true) ?: [];
}

// buat simpen data pembeli biar ga saling timpa
function ubahJson(string $path, callable $fn): void
{
    $fp = fopen($path, "c+");
    flock($fp, LOCK_EX);
    $baru = $fn(json_decode(stream_get_contents($fp), true) ?: []);
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($baru, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    fclose($fp);
}

function semuaPesanan(): array
{
    return array_map([PesananCetak::class, "dariArray"], bacaJson(FILE_ORDERS));
}
function cari(string $id): ?PesananCetak
{
    foreach (semuaPesanan() as $p)
        if ($p->id === $id)
            return $p;
    return null;
}

function ubahPesanan(callable $fn): void
{
    ubahJson(FILE_ORDERS, fn(array $d) => array_map(fn($p) => $p->toArray(), $fn(array_map([PesananCetak::class, "dariArray"], $d))));
}

function ubahLog(callable $fn): void
{
    ubahJson(FILE_LOGS, function (array $d) use ($fn): array {
        $s = new RiwayatAktivitasStack($d);
        $fn($s);
        return $s->toArray();
    });
}
function catatLog(string $judul, string $detail): void
{
    ubahLog(fn($s) => $s->push($judul, $detail));
}

// Field tersembunyi token CSRF, aksi, id, dan filter halaman.
function kunciCsrf(string $aksi, string $id = ""): string
{
    return "<input type='hidden' name='csrf' value='{$_SESSION["csrf"]}'><input type='hidden' name='aksi' value='$aksi'>"
        . "<input type='hidden' name='id' value='" . aman($id) . "'><input type='hidden' name='qs' value='" . aman($_SERVER["QUERY_STRING"] ?? "") . "'>";
}

function kembali(string $url, ?string $pesan = null, string $tipe = "ok"): never
{
    if ($pesan !== null)
        $_SESSION["flash"] = [$tipe, $pesan];
    header("Location: $url");
    exit;
}

function renderRadioCard(string $nama, array $opsi, string $terpilih): void
{
    foreach ($opsi as $kode => [$label, , $ket]) {
        $cek = $kode === $terpilih ? " checked" : "";
        echo "<label class='cursor-pointer'><input type='radio' name='$nama' value='$kode' class='peer sr-only'$cek>"
            . "<span class='opt'><span class='block text-sm font-semibold'>$label</span><span class='block text-xs text-muted'>$ket</span></span></label>";
    }
}

// Pengujian mandiri: buka index.php?selftest
function jalankanSelfTest(): never
{
    $buat = fn(string $uk, string $wr, string $jl, int $h, int $s) => PesananCetak::dariArray([
        "id" => "t",
        "nomor" => "T-$h",
        "nama" => "Uji",
        "nim" => "1",
        "ukuran" => $uk,
        "warna" => $wr,
        "jilid" => $jl,
        "halaman" => $h,
        "salinan" => $s,
        "file" => "a.pdf",
        "nama_file" => "a.pdf",
        "tanggal" => "",
    ]);
    [$a, $b] = [$buat("A4", "hitam", "lakban", 10, 2), $buat("A3", "warna", "tanpa", 5, 1)];
    $q = new AntreanCetakQueue();
    $q->enqueue($a);
    $q->enqueue($b);
    $s = new RiwayatAktivitasStack();
    $s->push("satu", "");
    $s->push("dua", "");
    $k = new RiwayatAktivitasStack([], 2);
    foreach (["a", "b", "c"] as $x)
        $k->push($x, "");
    header("Content-Type: text/plain; charset=UTF-8");
    foreach ([
        "Polimorfisme: A4 hitam putih + jilid lakban = Rp 12.000" => $a instanceof CetakHitamPutih && $a->hitungTotal() === 12000,
        "Polimorfisme: A3 berwarna = Rp 10.000" => $b instanceof CetakBerwarna && $b->hitungTotal() === 10000,
        "Queue FIFO: masuk pertama keluar pertama" => $q->dequeue()?->nomor === "T-10",
        "Stack LIFO: masuk terakhir keluar pertama" => $s->pop()["judul"] === "dua",
        "Stack kapasitas 2 (while): tiga push menyisakan 2 entri" => $k->size() === 2,
        "Float: biaya per lembar = 600.0" => $a->biayaPerLembar() === 600.0,
        "Override parent::: label berwarna menambah keterangan" => str_contains($b->label(), "cetak warna") && !str_contains($a->label(), "cetak warna"),
    ] as $nama => $lulus)
        echo ($lulus ? "LULUS  " : "GAGAL  ") . "$nama\n";
    exit;
}
if (isset($_GET["selftest"]))
    jalankanSelfTest();

// request
$isAdmin = !empty($_SESSION["admin"]);

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!hash_equals($_SESSION["csrf"], (string) ($_POST["csrf"] ?? ""))) {
        kembali("index.php", "Sesi formulir kedaluwarsa. Silakan ulangi.", "err");
    }
    $aksi = (string) ($_POST["aksi"] ?? "");
    $id = (string) ($_POST["id"] ?? "");

    if ($aksi === "login") {
        if (hash_equals(ADMIN_USER, (string) ($_POST["user"] ?? "")) && hash_equals(ADMIN_PASS, (string) ($_POST["pass"] ?? ""))) {
            session_regenerate_id(true);
            $_SESSION["admin"] = true;
            catatLog("Operator masuk", "Konsol dibuka");
            kembali("index.php?admin");
        }
        kembali("index.php?admin", "Username atau password tidak cocok.", "err");
    }

    if ($aksi === "logout") {
        unset($_SESSION["admin"]);
        session_regenerate_id(true);
        kembali("index.php");
    }

    if ($aksi === "pesan") {
        $in = fn(string $k): string => trim((string) ($_POST[$k] ?? ""));
        $nama = mb_substr($in("nama"), 0, 80);
        $nim = mb_substr($in("nim"), 0, 30);
        [$uk, $wr, $jl] = [$in("ukuran"), $in("warna"), $in("jilid")];
        [$hal, $sal] = [(int) $in("halaman"), (int) $in("salinan")];
        $berkas = $_FILES["dokumen"] ?? ["error" => UPLOAD_ERR_NO_FILE];
        $ext = strtolower(pathinfo($berkas["name"] ?? "", PATHINFO_EXTENSION));

        $err = match (true) {
            $nama === "" || $nim === "" => "Nama dan NIM wajib diisi.",
            !array_key_exists($uk, TARIF_UKURAN) || !array_key_exists($wr, TARIF_WARNA) || !array_key_exists($jl, TARIF_JILID) => "Spesifikasi cetakan tidak valid.",
            $hal < 1 || $hal > 500 || $sal < 1 || $sal > 100 => "Halaman harus 1–500 dan salinan 1–100.",
            $berkas["error"] !== UPLOAD_ERR_OK => "Dokumen wajib diunggah (maksimal 10 MB).",
            $berkas["size"] > MAX_UPLOAD => "Ukuran file melebihi 10 MB.",
            !in_array($ext, EKSTENSI_IZIN, true) => "Format file tidak didukung.",
            default => "",
        };

        $namaSimpan = bin2hex(random_bytes(12)) . ".$ext";
        if ($err === "" && !move_uploaded_file($berkas["tmp_name"], DIR_UPLOADS . "/$namaSimpan"))
            $err = "Gagal menyimpan file ke server.";
        if ($err !== "")
            kembali("index.php#form-pesan", $err, "err");

        $dipakai = array_map(fn($x) => $x->nomor, semuaPesanan());
        do {
            $nomor = "PE-" . date("ymd") . "-" . strtoupper(bin2hex(random_bytes(3)));
        } while (in_array($nomor, $dipakai, true));
        $p = PesananCetak::dariArray([
            "id" => bin2hex(random_bytes(8)),
            "nomor" => $nomor,
            "nama" => $nama,
            "nim" => $nim,
            "ukuran" => $uk,
            "warna" => $wr,
            "jilid" => $jl,
            "halaman" => $hal,
            "salinan" => $sal,
            "catatan" => mb_substr($in("catatan"), 0, 200),
            "file" => $namaSimpan,
            "nama_file" => mb_substr(basename($berkas["name"]), 0, 120),
            "tanggal" => date("d-m-Y H:i"),
        ]);
        ubahPesanan(fn(array $ps) => [...$ps, $p]);
        catatLog("Pesanan masuk", "$nomor, $nama");
        kembali("index.php?nomor=" . urlencode($nomor) . "&nim=" . urlencode($nim) . "#panel-lacak", "Pesanan masuk antrean. Simpan kode $nomor.");
    }

    // Dequeue
    if ($isAdmin && $aksi === "dequeue") {
        $diproses = null;
        ubahPesanan(function (array $ps) use (&$diproses): array {
            $q = new AntreanCetakQueue();
            foreach ($ps as $p)
                if ($p->status === "Menunggu")
                    $q->enqueue($p);
            $diproses = $q->dequeue();
            $diproses?->setStatus("Diproses");
            return $ps;
        });
        if ($diproses) {
            catatLog("Antrean diproses", $diproses->label());
            kembali("index.php?admin", "Mulai diproses: {$diproses->label()}.");
        }
        kembali("index.php?admin", "Tidak ada pesanan yang menunggu.", "err");
    }

    if ($isAdmin && $aksi === "status" && in_array($_POST["status"] ?? "", STATUS_LIST, true) && ($p = cari($id))) {
        $st = $_POST["status"];
        ubahPesanan(function (array $ps) use ($id, $st): array {
            foreach ($ps as $x)
                if ($x->id === $id)
                    $x->setStatus($st);
            return $ps;
        });
        catatLog("Status diubah", "{$p->nomor} menjadi $st");
    }

    if ($isAdmin && $aksi === "hapus" && ($p = cari($id))) {
        @unlink(DIR_UPLOADS . "/" . basename($p->file));
        ubahPesanan(fn(array $ps) => array_values(array_filter($ps, fn($x) => $x->id !== $id)));
        catatLog("Pesanan dihapus", $p->nomor);
    }

    // Pop
    if ($isAdmin && $aksi === "pop_log") {
        $log = null;
        ubahLog(function (RiwayatAktivitasStack $s) use (&$log): void {
            $log = $s->pop(); });
        kembali("index.php?admin", $log ? "Log “{$log['judul']}” dihapus dari riwayat." : "Riwayat sudah kosong.", $log ? "ok" : "err");
    }

    kembali("index.php?" . ($_POST["qs"] ?? ""));
}

// unduh dokumen hanya admin
if ($isAdmin && isset($_GET["unduh"]) && ($p = cari((string) $_GET["unduh"])) && is_file($path = DIR_UPLOADS . "/" . basename($p->file))) {
    header("X-Content-Type-Options: nosniff");
    header("Content-Type: application/octet-stream");
    header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($p->nama_file));
    header("Content-Length: " . filesize($path));
    readfile($path);
    exit;
}

// tampilan
$semua = semuaPesanan();
$antrean = new AntreanCetakQueue();
$urutan = [];
$hitung = array_fill_keys(STATUS_LIST, 0);
$pendapatan = 0;
foreach ($semua as $p) {
    $hitung[$p->status]++;
    if ($p->status === "Menunggu") {
        $antrean->enqueue($p);
        $urutan[$p->id] = $antrean->size();
    }
    if ($p->status === "Diambil")
        $pendapatan += $p->hitungTotal();
}
$riwayat = new RiwayatAktivitasStack(bacaJson(FILE_LOGS));

$halAdmin = isset($_GET["admin"]);
[$flashTipe, $flashPesan] = $_SESSION["flash"] ?? [null, null];
unset($_SESSION["flash"]);

// lacak pesanan
$sedangLacak = isset($_GET["nomor"]);
$hasil = null;
foreach ($semua as $p) {
    if ($sedangLacak && strcasecmp($p->nomor, trim((string) $_GET["nomor"])) === 0 && strcasecmp($p->nim, trim((string) ($_GET["nim"] ?? ""))) === 0)
        $hasil = $p;
}

$filter = (string) ($_GET["s"] ?? "");
$kata = strtolower(trim((string) ($_GET["q"] ?? "")));
$daftar = array_filter($semua, fn($p) => ($filter === "" || $p->status === $filter) && str_contains(strtolower("$p->nomor $p->nama $p->nim"), $kata));
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>PrintEase: cetak dokumen tanpa antre</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,600..800&family=Instrument+Sans:wght@400..700&display=swap"
        rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: { kertas: "#F5F6F8", ink: "#18203A", pulpen: "#2340CC", hl: "#FFE53B", garis: "#DDE1EA", muted: "#5B6478" },
                    fontFamily: { sans: ["Instrument Sans", "system-ui", "sans-serif"], display: ["Bricolage Grotesque", "Instrument Sans", "sans-serif"] }
                }
            }
        };
    </script>
    <style type="text/tailwindcss">
        @layer base { h1, h2, h3 { @apply font-display tracking-tight; } }
@layer components {
    .field { @apply w-full rounded-lg border border-garis bg-white px-3.5 py-2.5 text-[15px] transition placeholder:text-slate-500 focus:border-pulpen focus:outline-none focus:ring-2 focus:ring-pulpen/25; }
    .label { @apply mb-1.5 block text-sm font-medium; }
    .opt   { @apply block rounded-lg border border-garis bg-white px-3 py-2.5 transition hover:border-pulpen/60 peer-checked:border-pulpen peer-checked:bg-pulpen/5 peer-checked:ring-1 peer-checked:ring-pulpen peer-focus-visible:ring-2 peer-focus-visible:ring-pulpen; }
    .btn   { @apply inline-flex items-center justify-center gap-2 rounded-full bg-pulpen px-6 py-3 text-sm font-semibold text-white transition hover:bg-ink disabled:cursor-not-allowed disabled:opacity-50; }
    .btn-o { @apply inline-flex items-center justify-center rounded-full border border-garis bg-white px-4 py-2 text-sm font-medium transition hover:border-ink disabled:cursor-not-allowed disabled:opacity-40; }
}
</style>
    <style>
        :focus-visible {
            outline: 3px solid #2340CC;
            outline-offset: 2px;
        }

        /* Tepi bergerigi di bawah struk biaya */
        .struk {
            -webkit-mask: conic-gradient(from -45deg at bottom, #0000, #000 1deg 89deg, #0000 90deg) 50% / 20px 100%;
            mask: conic-gradient(from -45deg at bottom, #0000, #000 1deg 89deg, #0000 90deg) 50% / 20px 100%;
        }
    </style>
</head>

<body class="min-h-screen bg-kertas font-sans text-ink antialiased selection:bg-hl">

    <header class="sticky top-0 z-30 border-b border-garis bg-kertas/90 backdrop-blur">
        <div class="mx-auto flex max-w-7xl items-center justify-between gap-3 px-4 py-3 sm:px-6">
            <a href="index.php" class="flex items-center gap-2.5 font-display text-xl font-extrabold">
                <span class="grid h-8 w-8 place-items-center rounded-lg bg-pulpen" aria-hidden="true"><i
                        class="h-1.5 w-4 rounded-full bg-hl"></i></span>
                PrintEase
            </a>
            <div class="flex items-center gap-2">
                <?php if ($isAdmin): ?>
                    <a href="index.php" class="btn-o">Beranda</a>
                    <a href="?admin" class="btn-o">Konsol</a>
                    <form method="post"><?= kunciCsrf("logout") ?><button class="btn-o">Keluar</button></form>
                <?php else: ?>
                    <a href="index.php#panel-lacak" class="btn-o">Lacak</a>
                    <a href="?admin" class="btn-o">Operator</a>
                <?php endif ?>
            </div>
        </div>
    </header>

    <main class="mx-auto max-w-7xl px-4 py-8 sm:px-6">

        <?php if ($flashPesan !== null): ?>
            <div role="<?= $flashTipe === "err" ? "alert" : "status" ?>"
                class="sticky top-[4.25rem] z-20 mb-6 flex items-center justify-between gap-4 rounded-xl border px-4 py-3 text-sm font-medium shadow-lg <?= $flashTipe === "err" ? "border-red-300 bg-red-50 text-red-900" : "border-emerald-300 bg-emerald-50 text-emerald-950" ?>">
                <span><?= aman($flashPesan) ?></span>
                <button type="button" onclick="this.parentElement.remove()"
                    class="text-lg leading-none opacity-60 hover:opacity-100" aria-label="Tutup pesan">×</button>
            </div>
        <?php endif ?>

        <?php if ($halAdmin && !$isAdmin): ?>
            <section class="mx-auto my-12 max-w-md rounded-2xl border border-garis bg-white p-8">
                <h1 class="text-3xl font-extrabold">Masuk operator</h1>
                <p class="mt-2 text-muted">Akun demo sudah terisi. Tinggal klik masuk.</p>
                <form method="post" class="mt-6 space-y-4">
                    <?= kunciCsrf("login") ?>
                    <div><label class="label" for="user">Username</label><input class="field" id="user" name="user"
                            value="<?= ADMIN_USER ?>" required></div>
                    <div><label class="label" for="pass">Password</label><input class="field" id="pass" name="pass"
                            type="password" value="<?= ADMIN_PASS ?>" required></div>
                    <button class="btn w-full">Masuk sebagai admin demo</button>
                </form>
            </section>

        <?php elseif ($halAdmin): ?>
            <section class="flex flex-wrap items-end justify-between gap-4 border-b border-garis pb-6">
                <div>
                    <h1 class="text-3xl font-extrabold sm:text-4xl">Daftar Pesanan</h1>
                    <p class="mt-1 text-muted">Melayani pesanan sesuai antrean.</p>
                </div>
                <form method="post">
                    <?= kunciCsrf("dequeue") ?>
                    <button class="btn" <?= $antrean->isEmpty() ? "disabled" : "" ?>>
                        Proses antrean berikutnya
                        <?php if (!$antrean->isEmpty()): ?><span
                                class="rounded-full bg-white/20 px-2 py-0.5 text-xs tabular-nums"><?= aman($antrean->peek()->nomor) ?></span><?php endif ?>
                    </button>
                </form>
            </section>

            <dl
                class="mt-6 grid grid-cols-2 gap-px overflow-hidden rounded-2xl border border-garis bg-garis lg:grid-cols-4">
                <?php foreach ([
                    ["Menunggu", $hitung["Menunggu"], "belum diproses"],
                    ["Diproses", $hitung["Diproses"], "sedang dikerjakan"],
                    ["Siap diambil", $hitung["Siap Diambil"], "menunggu di loket"],
                    ["Pendapatan", rupiah($pendapatan), "dari {$hitung['Diambil']} pesanan diambil"],
                ] as [$judul, $angka, $ket]): ?>
                    <div class="bg-white p-5">
                        <dt class="text-sm text-muted"><?= $judul ?></dt>
                        <dd class="mt-1 font-display text-3xl font-bold tabular-nums"><?= $angka ?></dd>
                        <p class="text-xs text-muted"><?= $ket ?></p>
                    </div>
                <?php endforeach ?>
            </dl>

            <div class="mt-8 grid items-start gap-6 xl:grid-cols-[1.65fr_0.85fr]">

                <section class="rounded-2xl border border-garis bg-white">
                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-garis p-4">
                        <div class="flex flex-wrap gap-1.5" role="group" aria-label="Filter status">
                            <?php foreach (["", ...STATUS_LIST] as $st): ?>
                                <a href="?admin&s=<?= urlencode($st) ?>" <?= $filter === $st ? "aria-current='true'" : "" ?>
                                    class="rounded-full px-3.5 py-1.5 text-sm font-medium transition <?= $filter === $st ? "bg-ink text-white" : "bg-kertas text-muted hover:text-ink" ?>">
                                    <?= $st ?: "Semua" ?> <span
                                        class="tabular-nums opacity-70"><?= $st !== "" ? $hitung[$st] : count($semua) ?></span>
                                </a>
                            <?php endforeach ?>
                        </div>
                        <form class="w-full sm:w-64">
                            <input type="hidden" name="admin" value="1"><input type="hidden" name="s"
                                value="<?= aman($filter) ?>">
                            <input class="field !py-2 !text-sm" type="search" name="q" value="<?= aman($_GET["q"] ?? "") ?>"
                                placeholder="Cari kode, nama, atau NIM" aria-label="Cari pesanan">
                        </form>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[760px] text-left text-sm">
                            <thead class="border-b border-garis text-muted">
                                <tr>
                                    <th class="p-4 font-medium">Pesanan</th>
                                    <th class="p-4 font-medium">Mahasiswa</th>
                                    <th class="p-4 font-medium">Cetakan</th>
                                    <th class="p-4 font-medium">Total</th>
                                    <th class="p-4 font-medium">Status</th>
                                    <th class="p-4"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-garis">
                                <?php foreach ($daftar as $p):
                                    $ke = $urutan[$p->id] ?? null; ?>
                                    <tr class="align-top <?= $ke === 1 ? "bg-hl/20" : "" ?>">
                                        <td class="p-4">
                                            <span class="font-semibold tabular-nums"><?= aman($p->nomor) ?></span>
                                            <?php if ($ke !== null): ?><span
                                                    class="ml-1.5 rounded-full px-2 py-0.5 text-xs font-semibold <?= $ke === 1 ? "bg-hl" : "bg-kertas" ?>">antrean
                                                    <?= $ke ?></span><?php endif ?>
                                            <p class="text-xs text-muted tabular-nums"><?= aman($p->tanggal) ?></p>
                                        </td>
                                        <td class="p-4"><span class="font-medium"><?= aman($p->nama) ?></span>
                                            <p class="text-xs text-muted"><?= aman($p->nim) ?></p>
                                        </td>
                                        <td class="max-w-xs p-4">
                                            <?= aman($p->ringkasan()) ?>
                                            <?php if ($p->catatan !== ""): ?>
                                                <p class="mt-1 text-xs italic text-muted">“<?= aman($p->catatan) ?>”</p>
                                            <?php endif ?>
                                            <a href="?unduh=<?= urlencode($p->id) ?>"
                                                class="mt-1 block truncate font-medium text-pulpen underline"><?= aman($p->nama_file) ?></a>
                                        </td>
                                        <td class="p-4 font-semibold tabular-nums"><?= rupiah($p->hitungTotal()) ?></td>
                                        <td class="p-4">
                                            <form method="post">
                                                <?= kunciCsrf("status", $p->id) ?>
                                                <select name="status" onchange="this.form.submit()"
                                                    aria-label="Status <?= aman($p->nomor) ?>"
                                                    class="cursor-pointer rounded-full border-0 py-1.5 pl-3 pr-8 text-xs font-semibold <?= STATUS_UI[$p->status] ?>">
                                                    <?php foreach (STATUS_LIST as $st): ?>
                                                        <option <?= $st === $p->status ? "selected" : "" ?>><?= $st ?></option>
                                                    <?php endforeach ?>
                                                </select>
                                            </form>
                                        </td>
                                        <td class="p-4 text-right">
                                            <form method="post"
                                                onsubmit="return confirm('Hapus pesanan <?= aman($p->nomor) ?> beserta filenya?')">
                                                <?= kunciCsrf("hapus", $p->id) ?>
                                                <button class="font-medium text-red-700 hover:underline"
                                                    aria-label="Hapus <?= aman($p->nomor) ?>">Hapus</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach ?>
                                <?php if (!$daftar): ?>
                                    <tr>
                                        <td colspan="6" class="p-10 text-center text-muted">Belum ada pesanan.</td>
                                    </tr><?php endif ?>
                            </tbody>
                        </table>
                    </div>
                </section>

                <aside class="rounded-2xl border border-garis bg-white p-5">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <h2 class="text-lg font-bold">Riwayat aktivitas</h2>
                            <p class="text-xs text-muted"><?= $riwayat->size() ?> entri, yang terbaru di atas (LIFO)</p>
                        </div>
                        <form method="post">
                            <?= kunciCsrf("pop_log") ?>
                            <button class="btn-o !px-3 !py-1.5" <?= $riwayat->isEmpty() ? "disabled" : "" ?>>Hapus aktivitas</button>
                        </form>
                    </div>
                    <?php if ($riwayat->isEmpty()): ?>
                        <p class="py-8 text-center text-sm text-muted">Belum ada aktivitas.</p>
                    <?php else: ?>
                        <ol class="mt-4 space-y-2">
                            <?php foreach ($riwayat->dariTeratas() as $i => $log): ?>
                                <li class="rounded-lg px-3 py-2.5 <?= $i === 0 ? "bg-hl/30" : "bg-kertas/70" ?>">
                                    <div class="flex justify-between gap-2 text-sm">
                                        <span
                                            class="font-semibold"><?= aman($log["judul"]) ?><?= $i === 0 ? " (terbaru)" : "" ?></span>
                                        <span class="tabular-nums text-muted"><?= aman($log["waktu"]) ?></span>
                                    </div>
                                    <p class="text-xs text-muted"><?= aman($log["detail"]) ?></p>
                                </li>
                            <?php endforeach ?>
                        </ol>
                    <?php endif ?>
                </aside>
            </div>

        <?php else: ?>

            <section class="grid items-center gap-10 pb-10 pt-4 md:grid-cols-[1.4fr_1fr] md:pb-14 md:pt-10">
                <div>
                    <h1 class="text-4xl font-extrabold leading-[1.05] sm:text-6xl">Kirim dokumen kapan saja, ambil saat selesai.</h1>
                    <p class="mt-4 max-w-lg text-lg text-muted">Unggah file, tentukan spesifikasi, dan bayar saat pesanan siap diambil.</p>
                    <a class="btn mt-7" href="#form-pesan">Mulai pesan</a>
                </div>

                <div class="relative w-full max-w-xs rounded-2xl bg-pulpen text-white md:justify-self-end">
                    <div class="p-6">
                        <p class="text-sm text-white/85">Jumlah antrean</p>
                        <p class="font-display text-8xl font-extrabold leading-none tabular-nums text-hl">
                            <?= $antrean->size() ?></p>
                        <p class="mt-1 text-sm text-white/85">pesanan</p>
                    </div>
                    <div class="relative border-t border-dashed border-white/40 p-6 text-sm">
                        <span class="absolute -left-3 -top-3 h-6 w-6 rounded-full bg-kertas" aria-hidden="true"></span>
                        <span class="absolute -right-3 -top-3 h-6 w-6 rounded-full bg-kertas" aria-hidden="true"></span>
                        <?php if ($antrean->isEmpty()): ?>
                            <p class="text-white/85">Antrean kosong saat ini.</p>
                        <?php else: ?>
                            <p class="text-white/85">Giliran berikutnya</p>
                            <p class="font-semibold tabular-nums"><?= aman($antrean->peek()->nomor) ?></p>
                        <?php endif ?>
                    </div>
                </div>
            </section>

            <div class="grid items-start gap-8 lg:grid-cols-[1.35fr_0.85fr]">

                <form id="form-pesan" method="post" enctype="multipart/form-data"
                    class="scroll-mt-24 divide-y divide-garis rounded-2xl border border-garis bg-white">
                    <?= kunciCsrf("pesan") ?>

                    <div class="p-6 sm:p-8">
                        <h2 class="text-xl font-bold">Data pemesan</h2>
                        <div class="mt-4 grid gap-4 sm:grid-cols-2">
                            <div><label class="label" for="nama">Nama lengkap</label><input class="field" id="nama"
                                    name="nama" maxlength="80" autocomplete="name" placeholder="Sesuai kartu mahasiswa"
                                    required></div>
                            <div><label class="label" for="nim">NIM</label><input class="field" id="nim" name="nim"
                                    maxlength="30" placeholder="21120126120001" required></div>
                        </div>
                    </div>

                    <div class="space-y-5 p-6 sm:p-8">
                        <h2 class="text-xl font-bold">Spesifikasi cetak</h2>
                        <fieldset>
                            <legend class="label">Ukuran kertas</legend>
                            <div class="grid grid-cols-3 gap-2.5"><?php renderRadioCard("ukuran", TARIF_UKURAN, "A4") ?>
                            </div>
                        </fieldset>
                        <fieldset>
                            <legend class="label">Jenis cetakan</legend>
                            <div class="grid grid-cols-2 gap-2.5"><?php renderRadioCard("warna", TARIF_WARNA, "hitam") ?>
                            </div>
                        </fieldset>
                        <fieldset>
                            <legend class="label">Jilid</legend>
                            <div class="grid grid-cols-3 gap-2.5"><?php renderRadioCard("jilid", TARIF_JILID, "tanpa") ?>
                            </div>
                        </fieldset>
                        <div class="grid grid-cols-2 gap-4">
                            <div><label class="label" for="halaman">Jumlah halaman</label><input class="field" id="halaman"
                                    name="halaman" type="number" min="1" max="500" value="1" required></div>
                            <div><label class="label" for="salinan">Jumlah salinan</label><input class="field" id="salinan"
                                    name="salinan" type="number" min="1" max="100" value="1" required></div>
                        </div>
                    </div>

                    <div class="space-y-5 p-6 sm:p-8">
                        <h2 class="text-xl font-bold">Dokumen</h2>
                        <div>
                            <label class="label" for="dokumen">File dokumen</label>
                            <input id="dokumen" type="file" name="dokumen" required
                                accept=".<?= implode(",.", EKSTENSI_IZIN) ?>"
                                class="block w-full cursor-pointer rounded-xl border-2 border-dashed border-garis bg-kertas/60 p-4 text-sm transition hover:border-pulpen file:mr-4 file:rounded-full file:border-0 file:bg-ink file:px-4 file:py-2 file:text-sm file:font-semibold file:text-white">
                            <p class="mt-1.5 text-xs text-muted"><?= strtoupper(implode(", ", EKSTENSI_IZIN)) ?>. Maksimal
                                10 MB.</p>
                        </div>
                        <div>
                            <label class="label" for="catatan">Catatan <span
                                    class="font-normal text-muted">(opsional)</span></label>
                            <textarea class="field" id="catatan" name="catatan" rows="2" maxlength="200"
                                placeholder="Misalnya: cetak halaman 1–12 saja"></textarea>
                        </div>
                        <button id="btn-kirim" class="btn w-full !py-3.5 text-base">Kirim pesanan</button>
                    </div>
                </form>

                <div class="space-y-10 lg:sticky lg:top-20">

                    <!-- Struk biaya: angka total ditandai stabilo -->
                    <aside
                        class="[filter:drop-shadow(0_0_.5px_rgba(24,32,58,.45))_drop-shadow(0_8px_14px_rgba(24,32,58,.06))]">
                        <div class="struk bg-white px-6 pb-9 pt-6">
                            <h2 class="text-lg font-bold">Rincian biaya</h2>
                            <dl class="mt-4 space-y-2.5 text-sm">
                                <?php foreach (["ukuran" => "Kertas", "warna" => "Tinta", "volume" => "Volume", "cetak" => "Biaya cetak", "jilid" => "Biaya jilid"] as $k => $label): ?>
                                    <div class="flex justify-between gap-4">
                                        <dt class="text-muted"><?= $label ?></dt>
                                        <dd class="text-right font-medium tabular-nums" data-bind="<?= $k ?>"></dd>
                                    </div>
                                <?php endforeach ?>
                            </dl>
                            <div class="my-4 border-t border-dashed border-garis"></div>
                            <p class="text-sm text-muted">Total bayar</p>
                            <p class="font-display text-4xl font-extrabold tabular-nums"><span
                                    class="[background:linear-gradient(transparent_55%,#FFE53B_55%)]" data-bind="total"
                                    aria-live="polite"></span></p>
                            <p class="mt-3 text-xs text-muted">Pembayaran dilakukan di loket saat mengambil dokumen.</p>
                        </div>
                    </aside>

                    <section id="panel-lacak" class="scroll-mt-24">
                        <h2 class="text-xl font-bold">Lacak pesanan</h2>
                        <p class="mt-1 text-sm text-muted">Masukkan kode pesanan dan NIM yang kamu pakai saat memesan.</p>
                        <form method="get" action="index.php#panel-lacak" class="mt-4 space-y-3">
                            <input class="field uppercase" name="nomor" value="<?= aman($_GET["nomor"] ?? "") ?>"
                                placeholder="PE-261125-A1B2C3" aria-label="Kode pesanan" required>
                            <div class="flex gap-2">
                                <input class="field" name="nim" value="<?= aman($_GET["nim"] ?? "") ?>"
                                    placeholder="NIM pemesan" aria-label="NIM pemesan" required>
                                <button class="btn shrink-0 px-5">Cek</button>
                            </div>
                        </form>

                        <?php if ($hasil): $pos = array_search($hasil->status, STATUS_LIST, true);
                            $ke = $urutan[$hasil->id] ?? null; ?>
                            <div class="mt-5 rounded-xl border border-garis bg-white p-4">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <div class="flex items-center gap-2">
                                        <span
                                            class="font-display text-lg font-bold tabular-nums"><?= aman($hasil->nomor) ?></span>
                                    </div>
                                    <span
                                        class="rounded-full px-3 py-1 text-xs font-semibold <?= STATUS_UI[$hasil->status] ?>"><?= aman($hasil->status) ?></span>
                                </div>
                                <p class="mt-2 text-sm text-muted"><strong
                                        class="text-ink"><?= aman($hasil->nama_file) ?></strong><br><?= aman($hasil->ringkasan()) ?>.
                                    Total <strong class="text-ink"><?= rupiah($hasil->hitungTotal()) ?></strong>, rata-rata Rp
                                    <?= number_format($hasil->biayaPerLembar(), 2, ",", ".") ?> per lembar.</p>
                                <p class="mt-2 text-sm"><?= aman($hasil->pesanStatus()) ?></p>
                                <?php if ($ke !== null): ?>
                                    <p class="mt-2 text-sm font-semibold">Kamu di antrean ke-<?= $ke ?>.</p><?php endif ?>
                                <div class="mt-4 grid grid-cols-4 gap-1.5">
                                    <?php for ($i = 0; $i < count(STATUS_LIST); $i++): ?>
                                        <div>
                                            <div class="h-1.5 rounded-full <?= $i <= $pos ? "bg-pulpen" : "bg-garis" ?>"></div>
                                            <p class="mt-1.5 text-xs <?= $i === $pos ? "font-semibold" : "text-muted" ?>">
                                                <?= STATUS_LIST[$i] ?></p>
                                        </div>
                                    <?php endfor ?>
                                </div>
                            </div>
                        <?php elseif ($sedangLacak): ?>
                            <p class="mt-4 rounded-lg border border-red-200 bg-red-50 px-3.5 py-2.5 text-sm font-medium text-red-900"
                                role="alert">Pesanan tidak ditemukan. Periksa lagi kode dan NIM-nya.</p>
                        <?php endif ?>
                    </section>
                </div>
            </div>

            <script>
                const TARIF = <?= json_encode(["u" => TARIF_UKURAN, "w" => TARIF_WARNA, "j" => TARIF_JILID]) ?>,
                    form = document.getElementById("form-pesan"),
                    rp = n => "Rp " + n.toLocaleString("id-ID");

                function hitung() {
                    const [uk, wr, jl] = ["ukuran", "warna", "jilid"].map(k => form.elements[k].value),
                        hal = Math.max(0, +form.elements.halaman.value || 0),
                        sal = Math.max(0, +form.elements.salinan.value || 0),
                        cetak = hal * sal * TARIF.w[wr][1] * TARIF.u[uk][1],
                        jilid = sal * TARIF.j[jl][1],
                        v = {
                            ukuran: TARIF.u[uk][0], warna: TARIF.w[wr][0], volume: `${hal} hal × ${sal} salinan`,
                            cetak: rp(cetak), jilid: jilid ? rp(jilid) : "Gratis", total: rp(cetak + jilid)
                        };
                    document.querySelectorAll("[data-bind]").forEach(el => el.textContent = v[el.dataset.bind]);
                }
                form.addEventListener("input", hitung);
                form.addEventListener("submit", () => form.querySelector("#btn-kirim").disabled = true);
                hitung();
            </script>

        <?php endif ?>

    </main>

    <footer class="py-10 text-center text-sm text-muted">PrintEase © 2026</footer>

</body>

</html>