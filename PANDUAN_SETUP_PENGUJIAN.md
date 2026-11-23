# 📖 Panduan Setup & Pengujian Sistem Smart Bottle Refill Dispenser

Panduan ini ditujukan bagi rekan tim / penguji yang ingin menjalankan dan menguji proyek ini di laptop masing-masing dari awal (*fresh install*).

---

## 1. Prasyarat Sistem (*Prerequisites*)

Sebelum memulai, pastikan perangkat lunak berikut telah terpasang di laptop:
- **Git** (versi terbaru)
- **Web Server & Database:** XAMPP atau Laragon (Apache & MySQL dalam status aktif/Running)
- **PHP:** Minimal versi **8.2** (pastikan ekstensi `curl`, `pdo_mysql`, `mbstring`, `openssl` aktif)
- **Composer:** Dependency manager PHP
- **Node.js & npm:** Minimal Node.js v18 LTS
- **Arduino IDE** *(hanya jika Anda ingin meng-upload sketch ke board ESP32)*

---

## 2. Clone Repository & Instalasi Dependensi

Buka Terminal / Git Bash di direktori kerja Anda:

```bash
# 1. Clone repository
git clone https://github.com/khol217/projek_akhir_iot2.git
cd projek_akhir_iot2

# 2. Install dependensi Backend PHP
composer install

# 3. Install dan build aset Frontend
npm install
npm run build
```

---

## 3. Konfigurasi Environment (`.env`)

1. Salin template `.env.example` menjadi file `.env`:
   ```bash
   cp .env.example .env
   php artisan key:generate
   ```

2. Buka file `.env` menggunakan teks editor (VS Code, Notepad, dll), lalu pastikan pengaturan berikut sesuai:

### A. Database MySQL
Buat terlebih dahulu database kosong bernama **`smart_bottle_refill`** di phpMyAdmin (`http://localhost/phpmyadmin`), lalu sesuaikan bagian database di `.env`:
```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=smart_bottle_refill
DB_USERNAME=root
DB_PASSWORD=
```
*(Sesuaikan `DB_USERNAME` dan `DB_PASSWORD` jika MySQL Anda memiliki kata sandi).*

### B. Konfigurasi Broker MQTT EMQX
Tambahkan atau pastikan baris berikut ada di bagian paling bawah file `.env`:
```env
MQTT_HOST=i61e46ae.ala.asia-southeast1.emqxsl.com
MQTT_PORT=8883
MQTT_USERNAME=esp32-rack01
MQTT_PASSWORD=ESP32
MQTT_CLIENT_ID=smart-refill-laravel-test
```

> **Catatan Sertifikat TLS:** File sertifikat Root CA EMQX sudah tersedia di dalam proyek pada path `storage/app/certs/emqxsl-ca.crt` sehingga koneksi TLS port 8883 akan langsung terverifikasi secara otomatis.

---

## 4. Migrasi Database & Data Awal (*Seeding*)

Jalankan perintah berikut di terminal untuk membuat tabel dan mengisi data awal (perangkat `rack01`, profil atlet, botol, dan kalibrasi timbangan):

```bash
php artisan migrate --seed --seeder=SmartRefillSeeder
```

---

## 5. Konfigurasi Firmware ESP32 *(Jika Melakukan Upload ke Alat)*

Jika ESP32 terhubung ke laptop Anda dan perlu di-flash ulang:
1. Buka Arduino IDE.
2. Buka file sketsa: `firmware/smart_refill_esp32/smart_refill_esp32.ino`.
3. Pastikan library Arduino berikut sudah terpasang via *Library Manager*:
   - `PubSubClient` (oleh Nick O'Leary)
   - `HX711` (oleh bogde)
   - `LiquidCrystal_I2C` (oleh Frank de Brabander / marcus sathler)
4. Sesuaikan baris **16–17** dengan kredensial Wi-Fi / Hotspot yang Anda gunakan:
   ```cpp
   const char* WIFI_SSID     = "NAMA_WIFI_ANDA";    // Wajib frekuensi 2.4 GHz
   const char* WIFI_PASSWORD = "PASSWORD_WIFI_ANDA";
   ```
5. Pilih board **ESP32 Dev Module** dan Port COM yang sesuai, lalu lakukan **Upload**.

---

## 6. Menjalankan Sistem Saat Pengujian (*Running*)

Untuk menjalankan pengujian secara penuh dan *real-time*, buka **2 jendela terminal**:

### 🌐 Terminal 1: Web Server Dashboard
```bash
php artisan serve
```
- Buka browser dan akses: **`http://localhost:8000`**
- Halaman dashboard pemantauan, riwayat isi ulang, dan kontrol perangkat akan tampil.

### 🧠 Terminal 2: Background Listener MQTT (Otak Sistem)
```bash
php artisan mqtt:listen
```
- **Wajib Dijalankan!** Perintah ini bertindak sebagai jembatan komunikasi backend dengan ESP32.
- Terminal ini menerima data pembacaan berat timbangan, menghitung persentase air secara dinamis, mengontrol pompa otomatis, mendeteksi sumber air macet, serta memperbarui tampilan LCD di dispenser.

---

## 7. Verifikasi Pengujian

| Pengujian | Aksi Fisik | Hasil yang Diharapkan |
| :--- | :--- | :--- |
| **Koneksi** | Colok ESP32 ke power / USB | Terminal 2 menampilkan log telemetry masuk, status dashboard menjadi `ONLINE`. |
| **Deteksi Botol** | Letakkan botol di atas timbangan | LCD menampilkan berat & persentase air. Status web menjadi `TERDETEKSI`. |
| **Refill Otomatis** | Botol diletakkan dengan isi $\le 30\%$ | Pompa menyala, LCD menampilkan `MENGISI`, LED merah berkedip. |
| **Jeda Refill** | Angkat botol saat pompa sedang mengisi | Pompa otomatis mati, LCD menampilkan `REFILL DIJEDA`. Saat ditaruh lagi, pompa lanjut mengisi. |
| **Target Tercapai** | Pengisian mencapai target (misal $80\%$) | Pompa mati, LCD menampilkan `Pengisian Sukses`, buzzer berbunyi bip selesai. |
| **Botol Diambil** | Angkat botol setelah selesai refill | Teks sukses beralih ke standby `ISI CUKUP`, status dashboard menjadi `BOTOL DIANGKAT`. |
| **Sumber Kosong** | Pompa aktif namun berat air tidak bertambah $\ge 5$ detik | Pompa dimatikan paksa, buzzer menyala 10 detik, LCD menampilkan `SUMBER KOSONG`. |
