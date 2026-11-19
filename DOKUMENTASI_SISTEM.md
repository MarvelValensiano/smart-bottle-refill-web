# Dokumentasi Sistem Smart Water Bottle Refill Dispenser
**Projek Akhir Internet of Things (IoT) — Semester 5**

---

## 1. Ikhtisar Projek (Project Overview)
**Smart Water Bottle Refill Dispenser** adalah sistem dispenser air pintar berbasis Internet of Things (IoT) yang berfungsi melakukan pengisian air minum secara otomatis ke botol pengguna ketika botol diletakkan di atas tatakan timbangan.

Arsitektur sistem ini mengadopsi prinsip **Server-Side Intelligence & Control**, di mana modul mikrokontroler (ESP32) bertindak sebagai *edge client* (pembaca sensor dan eksekutor aktuator), sedangkan seluruh logika analitik, evaluasi ambang batas, aturan pengisian otomatis, dan pencatatan riwayat siklus diproses secara terpusat di server backend **PHP (Laravel)** melalui komunikasi aman broker **MQTT (EMQX TLS)** dan basis data **MySQL**.

---

## 2. Fitur Utama Sistem

1. **Auto-Refill Cerdas Berbasis Persentase Air**:
   - Pompa air otomatis menyala ketika botol berada di dudukan dan persentase air **$\le 30\%$**.
   - Pengisian air otomatis berhenti seketika saat volume air mencapai target **$80\%$** ($579\,\text{g}$ total berat).
2. **Auto-Tare Tatakan Otomatis (Auto-Zeroing)**:
   - Saat ESP32 dinyalakan pertama kali, firmware secara otomatis mengambil 15 sampel untuk menolkan titik acuan berat ($0.0\,\text{g}$).
   - Tersedia tombol *Tare* instan di Web Dashboard untuk mengeset ulang nol jika tatakan bergeser.
3. **Deteksi Botol dengan Histeresis Anti-Bouncing**:
   - Botol terdeteksi ada jika berat $\ge 14.0\,\text{g}$.
   - Botol dianggap diangkat jika berat $\le 9.0\,\text{g}$.
4. **Safety Interlock & Cutoff Keamanan**:
   - **Emergency Cutoff Botol Diangkat**: Jika botol diangkat saat pompa sedang aktif mengisi air, pompa langsung dimatikan seketika untuk mencegah air tumpah.
   - **Timeout Lease Safety**: Masa sewa pompa dibatasi maksimum 30–60 detik per siklus untuk mencegah luapan air akibat malfungsi sensor.
5. **Indikator Visual & Audio Multi-Kondisi**:
   - **LED Hijau (Pin 19)**: Menyala saat botol berada di dudukan dispenser.
   - **LED Merah (Pin 18)**: Menyala diam jika air rendah ($\le 30\%$), dan **berkedip** setiap 500 ms selama pompa aktif mengisi air.
   - **Buzzer (Pin 26)**: Berbunyi bip berkala saat air rendah dan bunyi konfirmasi saat pengisian selesai.
   - **Layar LCD 16x2 (I2C)**: Menampilkan persentase air, berat gram terkini, dan status operasional sistem.
6. **Pemantauan Real-Time & Riwayat di Web Dashboard**:
   - Animasi indikator level air dinamis.
   - Status pompa (`MENYALA / MATI`) dan botol (`ADA / TANPA BOTOL`).
   - Penghitung siklus pengisian air: **Refill Hari Ini** dan **Total Refill Kumulatif**.
   - Tombol pengujian cepat: Uji nyala pompa 4 detik dan Tare timbangan.
   - Tabel riwayat log pengisian lengkap dengan waktu, berat awal, berat akhir, dan volume air yang ditambahkan.

---

## 3. Arsitektur & Alur Aliran Data (Data Flow)

Komunikasi data dua arah berjalan secara asinkronus menggunakan protokol MQTT melalui EMQX Cloud dengan enkripsi TLS (Port 8883).

### Diagram Alur Komunikasi Sistem

```mermaid
flowchart TD
    subgraph HARDWARE["Perangkat Keras (Dispenser Fisik)"]
        Sensor["Sensor Load Cell + HX711"] -->|Baca Nilai Mentah ADC| ESP32["ESP32 Microcontroller"]
        ESP32 -->|Kendalikan Saklar (Pin 25/27)| Relay["Modul Relay 2-Channel"]
        Relay -->|Daya 5V| Pompa["Pompa Mini Submersible"]
        ESP32 -->|Visual Status| LED["LED Merah & Hijau"]
        ESP32 -->|Audio Peringatan| Buzzer["Active Buzzer"]
        ESP32 -->|Teks Status| LCD["LCD 16x2 I2C"]
    end

    subgraph BROKER["MQTT Broker (EMQX Cloud - Port 8883 TLS)"]
        T_UP["smartrefill/rack01/telemetry"]
        T_DOWN_PUMP["smartrefill/rack01/refill/cmd"]
        T_DOWN_STATE["smartrefill/rack01/display/state"]
        T_DOWN_BUZZ["smartrefill/rack01/buzzer/cmd"]
    end

    subgraph BACKEND["Server Backend (Laravel PHP)"]
        Listener["MQTT Listener Daemon<br/>(php artisan mqtt:listen)"]
        TelemService["TelemetryService<br/>(Hitung Berat & Persen Air)"]
        ControlService["RefillControlService<br/>(Evaluasi Ambang Batas 30% & 80%)"]
    end

    subgraph DATABASE["Basis Data (MySQL)"]
        DB[(smart_refill Database)]
    end

    subgraph FRONTEND["Frontend Web Dashboard"]
        WebUI["Web Dashboard<br/>(Laravel Blade + AJAX Live Polling)"]
    end

    %% Jalur Telemetri Naik (Uplink)
    ESP32 -->|1. Publish JSON Telemetri (tiap 500ms)| T_UP
    T_UP -->|2. Terima Pesan Sensor| Listener
    Listener -->|3. Teruskan Payload| TelemService
    TelemService -->|4. Simpan Telemetri & Update Terakhir| DB
    TelemService -->|5. Minta Evaluasi Kontrol| ControlService

    %% Jalur Keputusan & Perintah Turun (Downlink)
    ControlService -->|6. Jika Air <= 30%, Buat Siklus & Perintah Pompa| DB
    ControlService -.->|7. Dispatch Perintah Aktuator| Listener
    Listener -->|8a. Publish Perintah Pompa ON/OFF| T_DOWN_PUMP
    Listener -->|8b. Publish Status Display Terkini| T_DOWN_STATE
    Listener -->|8c. Publish Perintah Alarm Buzzer| T_DOWN_BUZZ

    T_DOWN_PUMP -->|9. Eksekusi Relays| ESP32
    T_DOWN_STATE -->|10. Tampilkan di LCD & LED| ESP32

    %% Pemantauan Frontend
    WebUI <-->|Polling JSON Status (tiap 2.5s)| Listener
    WebUI -->|Kueri Riwayat Refill & Rekap| DB
    WebUI -->|Kirim Perintah Uji Manual / Tare| DB
```

---

## 4. Rincian Format Protokol Data MQTT

### A. Telemetri Sensor: ESP32 $\rightarrow$ Backend
- **Topik**: `smartrefill/rack01/telemetry`
- **Frekuensi**: Setiap 500 ms
- **Contoh Payload**:
```json
{
  "device_id": "rack01",
  "boot_id": "5f3a2c89-8d76-4c31-9a7e-123456789abc",
  "sequence": 1420,
  "uptime_ms": 710000,
  "sensor_ready": true,
  "raw_weight": 62780,
  "pump_on": false,
  "simulated": false
}
```

### B. Perintah Kendali Pompa: Backend $\rightarrow$ ESP32
- **Topik**: `smartrefill/rack01/refill/cmd`
- **Contoh Payload Menyalakan**:
```json
{
  "pump": true,
  "lease_ms": 30000
}
```
- **Contoh Payload Mematikan**:
```json
{
  "pump": false
}
```

### C. Sinkronisasi Tampilan Layar & Indikator: Backend $\rightarrow$ ESP32
- **Topik**: `smartrefill/rack01/display/state`
- **Contoh Payload**:
```json
{
  "status": "MENGISI",
  "percent": 24.5,
  "weight": 190.2,
  "bottle": true,
  "pump": true,
  "alarm": false
}
```

---

## 5. Logika Bisnis & Perhitungan Kalibrasi

### A. Perhitungan Berat & Persentase Air
Dihitung secara terpusat pada `TelemetryService.php`:
$$\text{Total Berat (g)} = \frac{\text{Raw ADC} - \text{Tare Offset}}{\text{Faktor Kalibrasi}}$$
$$\text{Berat Air (g)} = \text{Total Berat (g)} - \text{Berat Kosong Botol (19.0 g)}$$
$$\text{Persentase Air (\%)} = \left(\frac{\text{Berat Air (g)}}{\text{Kapasitas Botol (700.0 g)}}\right) \times 100\%$$

*Nilai persentase dibatasi pada rentang $0\%$ hingga $100\%$.*

### B. Ambang Batas Operasional
| Parameter | Nilai Acuan | Keterangan |
|---|---|---|
| **Faktor Kalibrasi** | `1070.682373` | Nilai pembagi sensor load cell 1 kg |
| **Berat Kosong Botol** | `19.0 g` | Bobot botol uji plastik Aquviva |
| **Kapasitas Air Botol** | `700.0 mL / g` | Volume botol terisi penuh |
| **Batas Botol Terdeteksi** | $\ge 14.0\text{ g}$ | Histeresis batas atas deteksi botol |
| **Batas Botol Diangkat** | $\le 9.0\text{ g}$ | Histeresis batas bawah botol dilepas |
| **Ambang Mulai Refill** | $\le 30.0\%$ | Batas memicu pompa menyala otomatis |
| **Ambang Target Stop** | $\ge 80.0\%$ ($579\text{ g}$) | Batas pengisian selesai dan pompa dimatikan |
| **Maksimum Waktu Pompa ($T_{\text{max}}$)** | $60\text{ detik}$ | Batas waktu darurat pengaman jika sensor macet |

---

## 6. Wiring & Pinout Hardware ESP32

| Komponen Hardware | Pin ESP32 | Tipe Pin | Fungsi |
|---|---|---|---|
| **HX711 DT (Data)** | `GPIO 32` | Input | Pembacaan bit data timbangan load cell |
| **HX711 SCK (Clock)** | `GPIO 33` | Output | Clock pulsa pembacaan HX711 |
| **LCD 16x2 SDA** | `GPIO 21` | I2C Data | Jalur data tampilan I2C |
| **LCD 16x2 SCL** | `GPIO 22` | I2C Clock | Jalur clock tampilan I2C |
| **LED Merah** | `GPIO 18` | Output | Peringatan air rendah / Berkedip saat isi |
| **LED Hijau** | `GPIO 19` | Output | Indikator keberadaan botol di dudukan |
| **Relay Pompa (IN1)** | `GPIO 25` | Output (Active LOW) | Saklar sakelar daya pompa mini |
| **Relay Pompa (IN2)** | `GPIO 27` | Output (Active LOW) | Pemicu paralel channel 2 relay |
| **Active Buzzer** | `GPIO 26` | Output (PWM / Tone) | Audio alert dan notifikasi status |

> [!IMPORTANT]
> **Tegangan Modul Relay**: Pastikan pin **VCC modul relay** dihubungkan ke pin **`VIN` (5V dari USB)** pada ESP32, bukan pin `3V3`. Relay 5V memerlukan tegangan minimal 5V untuk menarik koil mekanis saklar.

---

## 7. Struktur Basis Data (Database Schema)

Sistem menggunakan basis data MySQL `smart_refill` dengan tabel-tabel utama:

1. **`devices`**: Menyimpan identitas stasiun dispenser (`code`: `rack01`).
2. **`device_settings`**: Menyimpan parameter kalibrasi (`tare_offset`, `calibration_factor`, `start_percent`, `stop_percent`).
3. **`bottles`**: Master data botol (`empty_weight_g = 19.0`, `water_capacity_g = 700.0`).
4. **`athletes`**: Master data pengguna/atlet pemilik botol.
5. **`telemetries`**: Log penyimpanan deret waktu pengukuran sensor mentah dan hasil kalkulasi berat/persen air.
6. **`refills`**: Catatan riwayat siklus pengisian air (waktu mulai, waktu selesai, berat awal, berat akhir, jumlah air terisi, dan alasan selesai).
7. **`device_commands`**: Antrean perintah aktuator dari server yang akan dikirim via MQTT ke ESP32 (`pending` $\rightarrow$ `published` $\rightarrow$ `executed`).
8. **`device_events`**: Catatan log peringatan insiden sistem (botol diangkat, timeout pengisian, dll).

---

## 8. Panduan Menjalankan Sistem (Setup & Execution)

### A. Persyaratan Sistem
- **PHP** $\ge 8.2$ (Ekstensi `pdo_mysql`, `openssl`, `curl` aktif).
- **MySQL Database Server** (XAMPP / standalone).
- **Composer** untuk dependensi Laravel.
- **Arduino IDE** dengan Board Package ESP32 dan pustaka:
  - `HX711 by Bogde`
  - `LiquidCrystal_I2C`
  - `PubSubClient by Nick O'Leary`

### B. Langkah Menjalankan Backend (Laravel)
1. **Konfigurasi Database**:
   Pastikan MySQL berjalan dan file `.env` telah terkonfigurasi:
   ```env
   DB_CONNECTION=mysql
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_DATABASE=smart_refill
   DB_USERNAME=root
   DB_PASSWORD=
   ```
2. **Jalankan Web Server**:
   Buka terminal di root direktori projek:
   ```bash
   php artisan serve
   ```
   Akses dashboard melalui browser: `http://localhost:8000` atau `http://localhost:8000/monitor`.
3. **Jalankan MQTT Listener**:
   Buka terminal kedua dan jalankan daemon pendengar pesan MQTT:
   ```bash
   php artisan mqtt:listen
   ```

### C. Langkah Flash Firmware ESP32
1. Buka file `firmware/smart_refill_esp32/smart_refill_esp32.ino` di Arduino IDE.
2. Periksa kredensial Wi-Fi:
   ```cpp
   const char* WIFI_SSID     = "IF24classH";
   const char* WIFI_PASSWORD = "InterH2024";
   ```
3. Hubungkan ESP32 via kabel USB (pilih Board: *ESP32 Dev Module* dan Port COM yang sesuai).
4. Tekan tombol **Upload**.
5. **PENTING**: Saat alat baru menyala pertama kali, pastikan tatakan timbangan dalam keadaan kosong agar proses auto-tare membaca acuan $0\,\text{g}$.

---

## 9. Penanganan Masalah (Troubleshooting)

1. **LED Tidak Menyala Saat Botol Ditaruh**:
   - Periksa apakah tatakan timbangan terbaca negatif atau di bawah $14\,\text{g}$.
   - Solusi: Kosongkan tatakan lalu klik tombol **`⚖ Tare / Nolkan Timbangan Sekarang`** di web dashboard, atau tekan tombol RST pada ESP32 saat tatakan kosong.
2. **Pompa Tidak Menyala / Tidak Ada Bunyi Klik**:
   - Pastikan modul relay terhubung ke pin `VIN` (5V), bukan `3V3`.
   - Gunakan tombol **`⚡ Test Nyalakan Pompa (4 Detik)`** di web dashboard untuk memastikan koneksi relay secara independen.
3. **Pesan "WiFi Terputus" pada LCD**:
   - Pastikan hotspot/Wi-Fi menggunakan frekuensi **2.4 GHz** (ESP32 tidak mendukung Wi-Fi 5 GHz).
   - Pastikan SSID dan password di sketch Arduino sesuai.
4. **Data Web Dashboard Tidak Berubah**:
   - Pastikan proses `php artisan mqtt:listen` aktif berjalan di terminal backend.
