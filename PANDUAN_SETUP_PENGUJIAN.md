# 📘 Panduan Setup & Pengujian Sistem Smart Bottle Refill IoT
Panduan ini ditujukan untuk rekan tim / penguji yang ingin menjalankan dan menguji sistem IoT Smart Bottle Refill di laptop baru dari awal.

---

## 1. Prasyarat Sistem
Pastikan perangkat lunak berikut telah terpasang di laptop:
- **Git**
- **XAMPP / Laragon** (Apache & MySQL)
- **PHP** (minimal versi 8.2) & **Composer**
- **Node.js** (minimal LTS v18+) & **npm**
- *(Opsional jika upload firmware)* **Arduino IDE 2.x** dengan ESP32 Board Package & library:
  - `HX711` by bogde
  - `LiquidCrystal_I2C` by Frank de Brabander / marcus sathler
  - `PubSubClient` by Nick O'Leary

---

## 2. Clone Repository & Instalasi Dependensi
Buka Terminal / Command Prompt / Git Bash di folder kerja Anda:

```bash
# 1. Clone repository
git clone https://github.com/khol217/projek_akhir_iot2.git
cd projek_akhir_iot2

# 2. Install dependensi PHP (Laravel)
composer install

# 3. Install dependensi antarmuka frontend (Tailwind/Vite)
npm install
npm run build
```

---

## 3. Konfigurasi Environment (`.env`)
1. Salin file template `.env.example` menjadi `.env`:
   ```bash
   cp .env.example .env
   php artisan key:generate
   ```

2. Buka file `.env` dan pastikan konfigurasi berikut terisi:

   ### A. Database MySQL
   *(Buat database baru bernama `smart_bottle_refill` di phpMyAdmin)*
   ```env
   DB_CONNECTION=mysql
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_DATABASE=smart_bottle_refill
   DB_USERNAME=root
   DB_PASSWORD=
   ```

   ### B. Broker MQTT EMQX (Cloud SSL/TLS)
   Tambahkan baris berikut di bagian paling bawah file `.env`:
   ```env
   MQTT_HOST=i61e46ae.ala.asia-southeast1.emqxsl.com
   MQTT_PORT=8883
   MQTT_USERNAME=esp32-rack01
   MQTT_PASSWORD=ESP32
   MQTT_CLIENT_ID=smart-refill-laravel-test
   ```
   > **Catatan:** File sertifikat CA SSL sudah otomatis tersedia di dalam repository pada direktori `storage/app/certs/emqxsl-ca.crt`.

---

## 4. Migrasi Database & Data Awal (Seeding)
Jalankan perintah ini di terminal untuk membuat tabel dan mengisi data awal (perangkat `rack01`, profil atlet, data botol, batas kalibrasi timbangan, dan profil dispenser):

```bash
php artisan migrate --seed --seeder=SmartRefillSeeder
```

---

## 5. Konfigurasi Wi-Fi ESP32 *(Jika mengunggah sketch ulang)*
Jika ESP32 dihubungkan ke laptop penguji dan perlu upload sketch:
1. Buka file sketch: `firmware/smart_refill_esp32/smart_refill_esp32.ino` di Arduino IDE.
2. Ubah baris **16–17** sesuai Wi-Fi / Hotspot yang digunakan:
   ```cpp
   const char* WIFI_SSID     = "NAMA_WIFI_ANDA";    // Wajib 2.4 GHz
   const char* WIFI_PASSWORD = "PASSWORD_WIFI";
   ```
3. Pilih board **ESP32 Dev Module** dan port COM yang sesuai, lalu lakukan **Upload**.

---

## 6. Menjalankan Sistem Saat Pengujian
Buka **2 jendela terminal terpisah** di folder proyek:

### 🔹 Terminal 1 — Web Server (Dashboard)
```bash
php artisan serve
```
Buka browser dan akses: **`http://localhost:8000`**

### 🔹 Terminal 2 — MQTT Listener (Otak Otomatisasi & Kontrol Pompa)
```bash
php artisan mqtt:listen
```
> ⚠️ **PENTING:** Terminal 2 ini **WAJIB TETAP BERJALAN** selama pengujian. Perintah ini bertindak sebagai otak backend yang menerima data timbangan secara real-time, mendeteksi botol, menghitung persentase air resmi, mengontrol pompa ON/OFF, mendeteksi air macet, serta memperbarui tampilan layar LCD dispenser.

---

## 7. Checklist Alur Pengujian Dispenser
1. **Standby:** Pasang botol kosong ke rak/timbangan $\rightarrow$ Status di Web: `AIR RENDAH`, Pompa otomatis mulai mengisi air. Layar LCD: `MENGISI`.
2. **Jeda Pengisian:** Angkat botol saat pompa sedang mengisi $\rightarrow$ Pompa otomatis mati seketika. Layar LCD: `REFILL DIJEDA`. Dashboard Web: `BOTOL DIANGKAT`.
3. **Lanjut Pengisian:** Letakkan kembali botol ke tatakan $\rightarrow$ Pompa otomatis menyala kembali melanjutkan pengisian.
4. **Target Tercapai:** Ketika persentase air mencapai target $\rightarrow$ Pompa mati. Layar LCD menampilkan: `Pengisian Sukses`.
5. **Ambil Botol Setelah Refill:** Angkat botol yang sudah penuh $\rightarrow$ LCD beralih menampilkan: `ISI CUKUP` ketika botol diletakkan kembali.
6. **Air Habis / Macet:** Jika pompa menyala tetapi air tidak bertambah $\rightarrow$ Pompa mati demi keamanan, buzzer berbunyi peringatan, LED merah berkedip, dan LCD menampilkan: `SUMBER KOSONG`.
