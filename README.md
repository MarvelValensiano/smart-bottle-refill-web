# Smart Water Bottle Refill Dispenser — IoT System

**Proyek Akhir Internet of Things (IoT) — Semester 5**

Sistem dispenser pengisian ulang air botol minum otomatis berbasis Internet of Things (IoT) menggunakan **ESP32**, sensor **Load Cell HX711**, modul **Relay & Pompa**, indikator **LED & LCD**, broker **MQTT (EMQX TLS)**, serta backend **Laravel (PHP)** dengan antarmuka pemantauan berbasis **Web Dashboard**.

---

## Media & Project Showcase

Pelajari lebih lanjut mengenai proses pengembangan, implementasi, dan demonstrasi sistem melalui media berikut:

- **YouTube – Project Demonstration:** [Watch Video](https://youtu.be/-4Obj1mcdU8)
- **Medium – Project Documentation:** [SmartFill: Dari Sensor Berat hingga Pengisian Air Otomatis](https://medium.com/@gracerianty2/smartfill-dari-sensor-berat-hingga-pengisian-air-otomatis-3cce26554db0)

---

## Dokumentasi Lengkap

Dokumentasi teknis lengkap mengenai arsitektur sistem, alur data (*flow data*), format protokol MQTT, tabel pin hardware, logika bisnis, dan panduan troubleshooting dapat dilihat pada file berikut:

**[DOKUMENTASI_SISTEM.md](DOKUMENTASI_SISTEM.md)**

---

## Fitur Utama

- **Otomatisasi Penuh**: Pompa otomatis menyala saat botol diletakkan dan air ≤ 30%, kemudian berhenti otomatis saat air mencapai 80% (579 g).
- **Server-Side Intelligence**: Logika perhitungan berat, persentase air, dan keputusan kontrol dijalankan secara terpusat di server backend.
- **Safety Cutoff**: Pompa otomatis mati seketika jika botol diangkat dari dudukan dispenser.
- **Auto-Tare**: Mengatur titik nol secara otomatis saat perangkat dinyalakan.
- **Real-Time Web Dashboard**: Pemantauan volume air, status pompa, status botol, penghitung jumlah pengisian (harian dan kumulatif), serta tombol pengujian manual.

---

## Pinout Hardware ESP32

| Komponen | Pin ESP32 | Keterangan |
|---|---|---|
| **HX711 DT** | `GPIO 32` | Data timbangan |
| **HX711 SCK** | `GPIO 33` | Clock timbangan |
| **LCD SDA** | `GPIO 21` | I2C Data |
| **LCD SCL** | `GPIO 22` | I2C Clock |
| **LED Merah** | `GPIO 18` | Air rendah / Berkedip saat pengisian |
| **LED Hijau** | `GPIO 19` | Botol terdeteksi di tatakan |
| **Relay Pompa** | `GPIO 25` & `GPIO 27` | Active LOW (Pemicu Pompa) |
| **Active Buzzer** | `GPIO 26` | Alarm dan notifikasi |

---

## Cara Menjalankan

### 1. Backend Web Server & Database

Pastikan Apache dan MySQL berjalan di XAMPP, kemudian jalankan server:

```bash
php artisan serve
```

Buka browser melalui alamat berikut:

- `http://localhost:8000`
- `http://localhost:8000/monitor`

### 2. MQTT Listener Daemon

Buka terminal kedua dan jalankan daemon pendengar telemetri:

```bash
php artisan mqtt:listen
```

### 3. ESP32 Firmware

Buka file `firmware/smart_refill_esp32/smart_refill_esp32.ino` menggunakan Arduino IDE. Pastikan SSID dan password Wi-Fi sudah sesuai, kemudian upload program ke ESP32.

**Catatan:** Pastikan tatakan dalam kondisi kosong saat pertama kali perangkat dinyalakan agar proses Auto-Tare dapat membaca titik nol (0.0 g).
