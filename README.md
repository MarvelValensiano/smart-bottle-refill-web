# Smart Water Bottle Refill Dispenser — IoT System
**Projek Akhir Internet of Things (IoT) — Semester 5**

Sistem dispenser pengisian ulang air botol minum otomatis berbasis Internet of Things (IoT) dengan modul **ESP32**, sensor **Load Cell HX711**, modul **Relay & Pompa**, indikator **LED & LCD**, broker **MQTT (EMQX TLS)**, dan backend cerdas **Laravel (PHP)** beserta antarmuka pemantauan **Web Dashboard**.

---

## 📖 Dokumentasi Lengkap
Dokumentasi teknis lengkap mengenai arsitektur sistem, alur data (flow data), format protokol MQTT, tabel pin hardware, logika bisnis, dan panduan troubleshooting dapat dilihat pada file:
👉 **[DOKUMENTASI_SISTEM.md](DOKUMENTASI_SISTEM.md)**

---

## ⚡ Fitur Utama
- **Otomatisasi Penuh**: Pompa otomatis menyala saat botol ditaruh dan air $\le 30\%$, berhenti otomatis saat air mencapai $80\%$ ($579\text{ g}$).
- **Server-Side Intelligence**: Logika perhitungan berat, persentase air, dan keputusan kontrol dijalankan terpusat di server backend.
- **Safety Cutoff**: Pompa otomatis mati seketika jika botol diangkat dari dudukan dispenser.
- **Auto-Tare**: Mengeset titik nol secara otomatis saat perangkat dinyalakan.
- **Real-Time Web Dashboard**: Pemantauan volume air, status pompa, status botol, penghitung jumlah pengisian (hari ini & kumulatif), dan tombol pengujian manual.

---

## 🔌 Pinout Hardware ESP32
| Komponen | Pin ESP32 | Keterangan |
|---|---|---|
| **HX711 DT** | `GPIO 32` | Data timbangan |
| **HX711 SCK** | `GPIO 33` | Clock timbangan |
| **LCD SDA** | `GPIO 21` | I2C Data |
| **LCD SCL** | `GPIO 22` | I2C Clock |
| **LED Merah** | `GPIO 18` | Air rendah / Berkedip saat isi |
| **LED Hijau** | `GPIO 19` | Botol terdeteksi di tatakan |
| **Relay Pompa** | `GPIO 25` & `GPIO 27` | Active LOW (Pemicu Pompa) |
| **Active Buzzer** | `GPIO 26` | Alarm & notifikasi |

---

## 🚀 Cara Menjalankan

### 1. Backend Web Server & Database
Pastikan Apache & MySQL berjalan di XAMPP, lalu jalankan server:
```bash
php artisan serve
```
Buka browser di: `http://localhost:8000` atau `http://localhost:8000/monitor`

### 2. MQTT Listener Daemon
Buka terminal kedua dan jalankan daemon pendengar telemetri:
```bash
php artisan mqtt:listen
```

### 3. ESP32 Firmware
Buka file `firmware/smart_refill_esp32/smart_refill_esp32.ino` di Arduino IDE, pastikan SSID/Password Wi-Fi sesuai, lalu upload ke ESP32. Pastikan tatakan kosong saat pertama kali dinyalakan agar proses Auto-Tare membaca $0.0\text{ g}$.
