# Google Login Design

## Tujuan

Memungkinkan pengguna masuk atau mendaftar ke Spekta menggunakan akun Google.

## Ruang lingkup

- Integrasi Google OAuth menggunakan Laravel Socialite.
- Tombol Google yang sudah ada pada halaman autentikasi menjadi aktif.
- Pengguna Google dengan email yang sudah terdaftar masuk ke akun yang sama.
- Pengguna Google baru otomatis mendapat user, workspace, paket free, dan kredit awal—setara dengan registrasi email/password.
- Menampilkan pesan berbahasa Indonesia saat pengguna membatalkan atau OAuth gagal.

## Arsitektur

1. Tambahkan `laravel/socialite` sebagai dependensi aplikasi.
2. Tambahkan provider Google pada `config/services.php`. Konfigurasi berasal dari `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, dan `GOOGLE_REDIRECT_URI`; nilai rahasia tidak disimpan dalam kode atau repository.
3. Tambahkan endpoint `GET /auth/google` untuk mengarahkan pengguna ke consent screen Google dan endpoint callback untuk menyelesaikan autentikasi.
4. Tambahkan `google_id` unik dan nullable pada user untuk mengikat akun Google secara stabil.
5. Callback membaca profil Google terverifikasi, lalu mencari user dengan `google_id` atau email.
6. Jika user ada, autentikasi user tersebut. Jika tidak, buat user baru dan panggil layanan bootstrap yang sama dengan registrasi biasa untuk membuat workspace, subscription free, credit awal, dan default rate card.

## Alur pengguna

1. Pengguna memilih tombol “Lanjutkan dengan Google”.
2. Aplikasi mengarahkan ke Google dan pengguna memberi persetujuan.
3. Google memanggil callback aplikasi.
4. Aplikasi mengautentikasi user lama atau membuat user baru.
5. Pengguna diarahkan ke dashboard.
6. Jika consent dibatalkan atau callback gagal, pengguna kembali ke halaman login dengan notifikasi kegagalan yang aman dan jelas.

## Keputusan dan batasan

- Email Google harus terverifikasi untuk dapat digunakan.
- Email adalah cara migrasi aman bagi akun lokal yang sudah ada; `google_id` menjadi identitas tautan setelah login Google berhasil.
- User Google baru tidak memerlukan password lokal.
- Tidak ada pengelolaan akun Google, unlinking, atau provider OAuth lain dalam perubahan ini.
- Redirect URI harus didaftarkan di Google Cloud Console sesuai lingkungan aplikasi.

## Pengujian

- Endpoint awal mengarahkan ke provider Google.
- Callback untuk user yang ada melakukan login tanpa membuat user/workspace baru.
- Callback untuk user baru membuat user dan seluruh bootstrap registrasi normal.
- Callback gagal atau dibatalkan mengembalikan pengguna ke login dengan pesan aman.
- Validasi memastikan kredensial OAuth hanya dibaca dari environment.

## Setup Google Cloud

1. Buat OAuth client type **Web application** pada Google Cloud Console.
2. Tambahkan redirect URI aplikasi, misalnya `http://localhost/auth/google/callback` untuk lokal.
3. Salin Client ID dan Client Secret ke environment lokal/deployment.
4. Jangan commit secret atau membagikannya melalui chat.
