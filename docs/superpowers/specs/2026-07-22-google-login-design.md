# Google Login Design

## Tujuan

Memungkinkan pengguna masuk atau mendaftar ke Spekta menggunakan akun Google.

## Ruang lingkup

- Integrasi Google OAuth menggunakan Laravel Socialite.
- Tombol Google yang sudah ada pada halaman autentikasi menjadi aktif.
- Pengguna Google yang sebelumnya sudah tertaut masuk ke akun yang sama.
- Pengguna Google baru otomatis mendapat user, workspace, paket free, dan kredit awal—setara dengan registrasi email/password.
- Pengguna akun email/password dapat menautkan Google dari Pengaturan setelah login.
- Menampilkan pesan berbahasa Indonesia saat pengguna membatalkan atau OAuth gagal.

## Arsitektur

1. Tambahkan `laravel/socialite` sebagai dependensi aplikasi.
2. Tambahkan provider Google pada `config/services.php`. Konfigurasi berasal dari `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI`, dan `GOOGLE_LINK_REDIRECT_URI`; nilai rahasia tidak disimpan dalam kode atau repository.
3. Tambahkan endpoint `GET /auth/google` untuk mengarahkan pengguna ke consent screen Google dan endpoint callback untuk menyelesaikan autentikasi.
4. Tambahkan `google_id` unik dan nullable pada user untuk mengikat akun Google secara stabil.
5. Callback login membaca profil Google dengan email terverifikasi, lalu hanya mencari user berdasarkan `google_id`.
6. Jika user sudah tertaut, autentikasi user tersebut. Jika tidak ada user dengan `google_id` dan email belum dipakai akun lokal, buat user baru dan panggil layanan bootstrap yang sama dengan registrasi biasa untuk membuat workspace, subscription free, credit awal, dan default rate card.
7. Jika email Google sudah dipakai akun lokal yang belum tertaut, callback menolak login dan mengarahkan pengguna untuk masuk dengan password lalu menautkan Google dari Pengaturan.
8. Halaman Pengaturan Profil menyediakan aksi “Hubungkan Google”; callback khusus link hanya menerima user yang telah terautentikasi, memverifikasi email Google, dan menyimpan `google_id` bila belum dipakai user lain.

## Alur pengguna

1. Pengguna memilih tombol “Lanjutkan dengan Google”.
2. Aplikasi mengarahkan ke Google dan pengguna memberi persetujuan.
3. Google memanggil callback aplikasi.
4. Aplikasi mengautentikasi user yang telah tertaut, atau membuat user baru bila email belum dipakai.
5. Pengguna diarahkan ke dashboard.
6. Jika consent dibatalkan atau callback gagal, pengguna kembali ke halaman login dengan notifikasi kegagalan yang aman dan jelas.

## Keputusan dan batasan

- Email Google harus terverifikasi untuk dapat digunakan.
- Email Google yang sama tidak otomatis menautkan akun lokal; pengguna harus login dengan password lalu melakukan link dari Pengaturan. Ini mencegah account takeover dari email yang dipraregistrasi penyerang.
- User Google baru tidak memerlukan password lokal.
- Tidak ada unlinking atau provider OAuth lain dalam perubahan ini.
- Redirect URI harus didaftarkan di Google Cloud Console sesuai lingkungan aplikasi dan harus cocok **persis** dengan URI callback yang dikirim aplikasi.

## Pengujian

- Endpoint awal mengarahkan ke provider Google.
- Callback untuk user yang ada melakukan login tanpa membuat user/workspace baru.
- Callback untuk user baru membuat user dan seluruh bootstrap registrasi normal.
- Callback login menolak email yang sudah dipakai akun lokal tetapi belum tertaut.
- Callback link hanya bekerja untuk user yang telah login, menolak Google ID yang sudah dipakai user lain, dan tidak mengganti tautan yang sudah ada.
- Callback gagal atau dibatalkan mengembalikan pengguna ke login dengan pesan aman.
- Validasi memastikan kredensial OAuth hanya dibaca dari environment.

## Setup Google Cloud

1. Buat OAuth client type **Web application** pada Google Cloud Console.
2. Tambahkan **kedua** authorized redirect URI berikut untuk setiap lingkungan. Google mengharuskan kecocokan URI secara **persis**—protokol, host, port, path, dan trailing slash harus sama dengan nilai yang digunakan aplikasi:

   | Alur | Lokal | Produksi |
   | --- | --- | --- |
   | Login | `http://localhost/auth/google/callback` | `https://<deployment-host>/auth/google/callback` |
   | Hubungkan akun setelah login | `http://localhost/settings/profile/google/callback` | `https://<deployment-host>/settings/profile/google/callback` |

3. Atur environment untuk pasangan URI yang sama:

   ```dotenv
   GOOGLE_REDIRECT_URI="${APP_URL}/auth/google/callback"
   GOOGLE_LINK_REDIRECT_URI="${APP_URL}/settings/profile/google/callback"
   ```

4. Salin Client ID dan Client Secret ke environment lokal/deployment.
5. Jangan commit secret atau membagikannya melalui chat.
