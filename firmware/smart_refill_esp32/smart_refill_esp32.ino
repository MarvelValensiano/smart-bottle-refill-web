#include <Wire.h>
#include <LiquidCrystal_I2C.h>
#include "HX711.h"
#include <esp_system.h>
#include <WiFi.h>
#include <WiFiClientSecure.h>
#include <PubSubClient.h>
#include <time.h>

// ==============================================================
// 1. KONFIGURASI DISPENSER
// ==============================================================
const char* DEVICE_ID     = "rack01"; 

// Wi-Fi (Pastikan jaringan 2.4 GHz)
const char* WIFI_SSID     = "POCO M3";
const char* WIFI_PASSWORD = "aiueoppp";

// EMQX MQTT Broker (TLS Port 8883)
const char* MQTT_HOST     = "i61e46ae.ala.asia-southeast1.emqxsl.com";
const int   MQTT_PORT     = 8883;
const char* MQTT_USERNAME = "esp32-rack01";
const char* MQTT_PASSWORD = "ESP32";

// ==============================================================
// 2. PIN HARDWARE (PERSIS SESUAI TES.INO)
// ==============================================================
const uint8_t HX_DT       = 32;
const uint8_t HX_SCK      = 33;
const uint8_t LCD_SDA     = 21;
const uint8_t LCD_SCL     = 22;
const uint8_t LED_MERAH   = 18;
const uint8_t LED_HIJAU   = 19;
const uint8_t RELAY_POMPA = 25;
const uint8_t RELAY_DUA   = 27; // Relay channel 2 (dinyalakan bersamaan agar pasti aktif)
const uint8_t BUZZER_PIN  = 26;

// Relay Active-LOW (Modul relay umum: LOW = ON / Kontak Tertutup, HIGH = OFF)
const uint8_t RELAY_ON    = LOW;
const uint8_t RELAY_OFF   = HIGH;

// Parameter Karakteristik Botol Aquviva & Timbangan (Disamakan persis dengan Web)
float faktorKalibrasi          = 1138.0; // Faktor kalibrasi disamakan persis dengan Web Settings (1138)
const float BERAT_BOTOL_KOSONG = 19.0;
const float KAPASITAS_AIR      = 700.0;
const float BATAS_BOTOL_ADA    = 14.0;
const float BATAS_BOTOL_LEPAS  = 9.0;

// Root CA DigiCert Global Root G2 untuk EMQX TLS
static const char ROOT_CA[] = R"EOF(
-----BEGIN CERTIFICATE-----
MIIDjjCCAnagAwIBAgIQAzrx5qcRqaC7KGSxHQn65TANBgkqhkiG9w0BAQsFADBh
MQswCQYDVQQGEwJVUzEVMBMGA1UEChMMRGlnaUNlcnQgSW5jMRkwFwYDVQQLExB3
d3cuZGlnaWNlcnQuY29tMSAwHgYDVQQDExdEaWdpQ2VydCBHbG9iYWwgUm9vdCBH
MjAeFw0xMzA4MDExMjAwMDBaFw0zODAxMTUxMjAwMDBaMGExCzAJBgNVBAYTAlVT
MRUwEwYDVQQKEwxEaWdpQ2VydCBJbmMxGTAXBgNVBAsTEHd3dy5kaWdpY2VydC5j
b20xIDAeBgNVBAMTF0RpZ2lDZXJ0IEdsb2JhbCBSb290IEcyMIIBIjANBgkqhkiG
9w0BAQEFAAOCAQ8AMIIBCgKCAQEAuzfNNNx7a8myaJCtSnX/RrohCgiN9RlUyfuI
2/Ou8jqJkTx65qsGGmvPrC3oXgkkRLpimn7Wo6h+4FR1IAWsULecYxpsMNzaHxmx
1x7e/dfgy5SDN67sH0NO3Xss0r0upS/kqbitOtSZpLYl6ZtrAGCSYP9PIUkY92eQ
q2EGnI/yuum06ZIya7XzV+hdG82MHauVBJVJ8zUtluNJbd134/tJS7SsVQepj5Wz
tCO7TG1F8PapspUwtP1MVYwnSlcUfIKdzXOS0xZKBgyMUNGPHgm+F6HmIcr9g+UQ
vIOlCsRnKPZzFBQ9RnbDhxSJITRNrw9FDKZJobq7nMWxM4MphQIDAQABo0IwQDAP
BgNVHRMBAf8EBTADAQH/MA4GA1UdDwEB/wQEAwIBhjAdBgNVHQ4EFgQUTiJUIBiV
5uNu5g/6+rkS7QYXjzkwDQYJKoZIhvcNAQELBQADggEBAGBnKJRvDkhj6zHd6mcY
1Yl9PMWLSn/pvtsrF9+wX3N3KjITOYFnQoQj8kVnNeyIv/iPsGEMNKSuIEyExtv4
NeF22d+mQrvHRAiGfzZ0JFrabA0UWTW98kndth/Jsw1HKj2ZL7tcu7XUIOGZX1NG
Fdtom/DzMNU+MeKNhJ7jitralj41E6Vf8PlwUHBHQRFXGU7Aj64GxJUTFy8bJZ91
8rGOmaFvE7FBcf6IKshPECBV1/MUReXgRPTqh5Uykw7+U0b6LJ3/iyK5S9kJRaTe
pLiaWN0bfVKfjllDiIGknibVb63dDcY3fe0Dkhvld1927jyNxF1WW6LZZm6zNTfl
MrY=
-----END CERTIFICATE-----
)EOF";

// ==============================================================
// 3. OBJEK & VARIABEL GLOBAL
// ==============================================================
LiquidCrystal_I2C lcd(0x27, 16, 2);
HX711 timbangan;
WiFiClientSecure koneksiTLS;
PubSubClient mqtt(koneksiTLS);

bool lcdAda = false;
String clientId;
char bootId[37];
uint32_t nomorPesan = 0;

// Variabel Pengukuran Sensor
long nilaiMentah = 0;
long titikNolTare = -33766; // Baseline tare tatakan kosong disamakan persis dengan Web (-33766)
bool pernahBacaSensor = false;
unsigned long terakhirBacaSensor = 0;
const unsigned long BATAS_DATA_SENSOR = 1000;

// Status & Pengukuran
float beratTotalGram = 0.0;
float persenAir = 0.0;
bool botolAda = false;
String pesanStatus = "STANDBY";
bool baruSelesaiRefill = false;

// Kontrol Pompa & Buzzer
bool pompaAktif = false;
unsigned long waktuMulaiPompa = 0;
unsigned long leasePompaMs = 30000;

bool buzzerAktif = false;
bool statusAlarm = false;
unsigned long mulaiBuzzer = 0;
unsigned long durasiBuzzer = 0;
unsigned long terakhirBipRendah = 0;

// Timer Loop
unsigned long terakhirKirimTelemetry = 0;
const unsigned long INTERVAL_TELEMETRY = 500;
unsigned long terakhirCobaMQTT = 0;
const unsigned long INTERVAL_KONEKSI = 4000;
unsigned long terakhirLCD = 0;

// Status Koneksi Listener Server PHP
bool serverListenerAktif = false;
unsigned long terakhirResponListener = 0;
const unsigned long TIMEOUT_LISTENER_MS = 20000; // Tandai belum terhubung jika 20 detik tanpa respon backend
bool pernahTampilListenerKonek = false;

// Topik Dinamis
String topicTelemetry;
String topicRefillCmd;
String topicBuzzerCmd;
String topicDisplayState;
String topicGenericCmd;
String topicListenerStatus;

// ==============================================================
// 4. FUNGSI OUTPUT & AKTUATOR
// ==============================================================
void pompaOff() {
  digitalWrite(RELAY_POMPA, RELAY_OFF);
  digitalWrite(RELAY_DUA, RELAY_OFF);
  pompaAktif = false;
}

void pompaOn(unsigned long durasiMs = 30000) {
  digitalWrite(RELAY_POMPA, RELAY_ON);
  digitalWrite(RELAY_DUA, RELAY_ON);
  pompaAktif = true;
  waktuMulaiPompa = millis();
  leasePompaMs = durasiMs;
  Serial.println(">> [RELAY AKTIF] Pompa ON (Pin 25 & 27 LOW)");
}

void buzzerOff() {
  noTone(BUZZER_PIN);
  digitalWrite(BUZZER_PIN, LOW);
  buzzerAktif = false;
}

void bunyikanBuzzer(unsigned int frekuensi, unsigned long durasiMs) {
  tone(BUZZER_PIN, frekuensi);
  buzzerAktif = true;
  mulaiBuzzer = millis();
  durasiBuzzer = durasiMs;
}

void matikanSemuaOutput() {
  pompaOff();
  buzzerOff();
  digitalWrite(LED_MERAH, LOW);
  digitalWrite(LED_HIJAU, LOW);
}

void tulisBarisLCD(uint8_t baris, const char *teks) {
  if (!lcdAda) return;
  lcd.setCursor(0, baris);
  bool akhir = false;
  for (uint8_t i = 0; i < 16; i++) {
    if (!akhir && teks[i] == '\0') akhir = true;
    lcd.print(akhir ? ' ' : teks[i]);
  }
}

const char* pesanErrorMQTT(int state) {
  switch (state) {
    case -4: return "Timeout Broker";
    case -3: return "Koneksi Hilang";
    case -2: return "Jaringan Putus";
    case -1: return "Terputus";
    case 1:  return "Bad Protocol";
    case 2:  return "Client ID Salah";
    case 3:  return "Server Sibuk";
    case 4:  return "User/Pass Salah";
    case 5:  return "Akses Ditolak";
    default: return "Gagal Konek";
  }
}

void buatBootId() {
  uint8_t bytes[16];
  for (uint8_t i = 0; i < 16; i += 4) {
    uint32_t acak = esp_random();
    bytes[i]     = acak & 0xFF;
    bytes[i + 1] = (acak >> 8) & 0xFF;
    bytes[i + 2] = (acak >> 16) & 0xFF;
    bytes[i + 3] = (acak >> 24) & 0xFF;
  }
  bytes[6] = (bytes[6] & 0x0F) | 0x40;
  bytes[8] = (bytes[8] & 0x3F) | 0x80;

  snprintf(bootId, sizeof(bootId),
    "%02x%02x%02x%02x-%02x%02x-%02x%02x-%02x%02x-%02x%02x%02x%02x%02x%02x",
    bytes[0], bytes[1], bytes[2], bytes[3],
    bytes[4], bytes[5], bytes[6], bytes[7],
    bytes[8], bytes[9], bytes[10], bytes[11],
    bytes[12], bytes[13], bytes[14], bytes[15]
  );
}

void lakukanTareOtomatis() {
  if (timbangan.is_ready()) {
    Serial.println(">> Mengambil data tare titik nol tatakan...");
    titikNolTare = timbangan.read_average(15);
    Serial.printf(">> Titik Nol Berhasil Disimpan: %ld\n", titikNolTare);
  }
}

// ==============================================================
// 5. CALLBACK MQTT (MENERIMA PERINTAH DARI SERVER LARAVEL)
// ==============================================================
void mqttCallback(char* topic, byte* payload, unsigned int length) {
  String pesan = "";
  for (unsigned int i = 0; i < length; i++) {
    pesan += (char)payload[i];
  }
  Serial.print("Pesan MQTT [");
  Serial.print(topic);
  Serial.print("]: ");
  Serial.println(pesan);

  // Tandai listener server side PHP aktif setiap ada pesan masuk dari server
  serverListenerAktif = true;
  terakhirResponListener = millis();

  // A. Perintah Pompa Refill dari Server
  if (topicRefillCmd.equals(topic)) {
    if (pesan.indexOf("\"pump\":true") >= 0 || pesan.indexOf("\"pump\": true") >= 0) {
      pompaOn(30000);
      pesanStatus = "MENGISI";
      baruSelesaiRefill = false;
    } else if (pesan.indexOf("\"pump\":false") >= 0 || pesan.indexOf("\"pump\": false") >= 0) {
      pompaOff();
      if (pesan.indexOf("\"reason\":\"completed\"") >= 0 || pesan.indexOf("\"reason\": \"completed\"") >= 0) {
        pesanStatus = "Pengisian Sukses";
        baruSelesaiRefill = true;
        bunyikanBuzzer(2500, 300);
      } else if (pesan.indexOf("\"reason\":\"water_empty\"") >= 0 || pesan.indexOf("\"reason\": \"water_empty\"") >= 0) {
        pesanStatus = "SUMBER KOSONG";
        baruSelesaiRefill = false;
      } else if (pesan.indexOf("\"reason\":\"paused\"") >= 0 || pesan.indexOf("\"reason\": \"paused\"") >= 0) {
        pesanStatus = "REFILL DIJEDA";
        baruSelesaiRefill = false;
      } else {
        baruSelesaiRefill = false;
      }
    }
  }

  // B. Perintah Buzzer Alarm
  if (topicBuzzerCmd.equals(topic)) {
    if (pesan.indexOf("\"buzzer\":true") >= 0 || pesan.indexOf("\"buzzer\": true") >= 0) {
      unsigned long durasi = 10000;
      int idxDur = pesan.indexOf("\"duration_ms\":");
      if (idxDur >= 0) {
        durasi = pesan.substring(idxDur + 14).toInt();
        if (durasi <= 0) durasi = 10000;
      }
      bunyikanBuzzer(1500, durasi);
      pesanStatus = "SUMBER KOSONG";
      statusAlarm = true;
      baruSelesaiRefill = false;
    } else if (pesan.indexOf("\"buzzer\":false") >= 0 || pesan.indexOf("\"buzzer\": false") >= 0) {
      buzzerOff();
      statusAlarm = false;
    }
  }

  // C. Update State Display dari Server (Sinkronkan Nilai Resmi LCD dengan Web)
  if (topicDisplayState.equals(topic)) {
    int idxStatus = pesan.indexOf("\"status\":");
    if (idxStatus >= 0) {
      int s = pesan.indexOf('"', idxStatus + 9);
      int e = pesan.indexOf('"', s + 1);
      if (s >= 0 && e > s) {
        pesanStatus = pesan.substring(s + 1, e);
      }
      if (pesanStatus.indexOf("KOSONG") >= 0 || pesanStatus.indexOf("DIJEDA") >= 0 || 
          pesanStatus.indexOf("MENGISI") >= 0 || pesanStatus.indexOf("TANPA") >= 0) {
        baruSelesaiRefill = false;
      }
    }

    if (pesan.indexOf("\"alarm\":true") >= 0 || pesan.indexOf("\"alarm\": true") >= 0) {
      statusAlarm = true;
    } else if (pesan.indexOf("\"alarm\":false") >= 0 || pesan.indexOf("\"alarm\": false") >= 0) {
      statusAlarm = false;
    }

    int idxPct = pesan.indexOf("\"percent\":");
    if (idxPct >= 0) {
      persenAir = pesan.substring(idxPct + 10).toFloat();
    }

    int idxW = pesan.indexOf("\"weight\":");
    if (idxW >= 0) {
      beratTotalGram = pesan.substring(idxW + 9).toFloat();
    }

    int idxF = pesan.indexOf("\"factor\":");
    if (idxF >= 0) {
      float f = pesan.substring(idxF + 9).toFloat();
      if (f > 100.0) faktorKalibrasi = f;
    }

    int idxT = pesan.indexOf("\"tare\":");
    if (idxT >= 0) {
      long t = pesan.substring(idxT + 7).toInt();
      titikNolTare = t;
    }

    if (pesan.indexOf("\"pump\":true") >= 0) {
      pompaOn(30000);
    } else if (pesan.indexOf("\"pump\":false") >= 0) {
      pompaOff();
    }
  }

  // D. Perintah Tare via MQTT
  if (topicGenericCmd.equals(topic)) {
    if (pesan.indexOf("\"tare\":true") >= 0 || pesan.indexOf("\"tare\": true") >= 0) {
      lakukanTareOtomatis();
    }
  }
}

// ==============================================================
// 6. PEMBACAAN TIMBANGAN HX711 DENGAN KALKULASI LANGSUNG (EDGE)
// ==============================================================
void bacaTimbangan() {
  if (!timbangan.is_ready()) return;

  nilaiMentah = timbangan.read();
  terakhirBacaSensor = millis();
  pernahBacaSensor = true;

  // Kalkulasi Berat Fisik Langsung (Menggunakan faktor dan tare yang sama dengan Web)
  float beratUkur = (float)(nilaiMentah - titikNolTare) / faktorKalibrasi;
  if (fabs(beratUkur) < 2.0) beratUkur = 0.0;
  beratTotalGram = beratUkur;

  // Deteksi Botol Berdasarkan Berat (Histeresis 14g / 9g)
  if (!botolAda && beratTotalGram >= BATAS_BOTOL_ADA) {
    botolAda = true;
  } else if (botolAda && beratTotalGram <= BATAS_BOTOL_LEPAS) {
    botolAda = false;
    baruSelesaiRefill = false; // Reset tanda selesai refill begitu botol diangkat dari tatakan
  }

  // Kalkulasi Persentase Sisa Air
  if (botolAda) {
    float airG = beratTotalGram - BERAT_BOTOL_KOSONG;
    float pct = (airG / KAPASITAS_AIR) * 100.0;
    if (pct < 0.0) pct = 0.0;
    if (pct > 100.0) pct = 100.0;
    persenAir = pct;
  } else {
    persenAir = 0.0;
  }
}

// ==============================================================
// 7. PENGIRIMAN TELEMETRY KE MQTT BROKER
// ==============================================================
void kirimTelemetry() {
  unsigned long sekarang = millis();
  bool sensorSiap = pernahBacaSensor && (sekarang - terakhirBacaSensor < BATAS_DATA_SENSOR);

  char rawJson[20];
  if (sensorSiap) {
    snprintf(rawJson, sizeof(rawJson), "%ld", nilaiMentah);
  } else {
    snprintf(rawJson, sizeof(rawJson), "null");
  }

  nomorPesan++;
  char payload[384];

  snprintf(payload, sizeof(payload),
    "{\"device_id\":\"%s\","
    "\"boot_id\":\"%s\","
    "\"sequence\":%lu,"
    "\"uptime_ms\":%lu,"
    "\"sensor_ready\":%s,"
    "\"raw_weight\":%s,"
    "\"pump_on\":%s,"
    "\"simulated\":false}",
    DEVICE_ID,
    bootId,
    (unsigned long) nomorPesan,
    sekarang,
    sensorSiap ? "true" : "false",
    rawJson,
    pompaAktif ? "true" : "false"
  );

  bool ok = mqtt.publish(topicTelemetry.c_str(), payload, false);
  if (ok) {
    Serial.printf("[DISPENSER] Telemetry #%lu | Raw: %s | Berat: %.1fg | Air: %.0f%% | Pompa: %s | Botol: %s\n",
      (unsigned long) nomorPesan, rawJson, beratTotalGram, persenAir, pompaAktif ? "ON" : "OFF", botolAda ? "ADA" : "TIDAK");
  }
}

// ==============================================================
// 8. KENDALI INDIKATOR LED & BUZZER (PERSIS SESUAI SPESIFIKASI)
// ==============================================================
void perbaruiIndikator() {
  if (buzzerAktif && durasiBuzzer > 0 && millis() - mulaiBuzzer >= durasiBuzzer) {
    buzzerOff();
    if (statusAlarm) {
      statusAlarm = false;
    }
  }

  // Bip Pengingat Air Rendah (Air <= 30% dan botol ada, tidak ada alarm dan pompa tidak aktif)
  if (botolAda && !pompaAktif && !statusAlarm && persenAir <= 30.0 && !buzzerAktif) {
    if (millis() - terakhirBipRendah >= 2000) {
      terakhirBipRendah = millis();
      bunyikanBuzzer(2000, 100);
    }
  }

  bool merah = false;
  bool hijau = false;
  bool kedip = ((millis() / 500) % 2 == 0);

  // 0. KONDISI LISTENER SERVER PHP BELUM TERHUBUNG
  if (!serverListenerAktif) {
    merah = kedip; // LED merah kedip perlahan menandakan menunggu listener
    hijau = false;
  }
  // 1. KONDISI TANPA BOTOL / BOTOL DIANGKAT
  else if (!botolAda) {
    // Pada saat botol tidak diletakkan, LED merah menyala berkedip, LED hijau mati
    merah = kedip;
    hijau = false;
  }
  // 2. KONDISI TERJADI GANGGUAN (Sumber air tidak terisi, tidak ada kenaikan berat, alarm server)
  else if (statusAlarm || pesanStatus.indexOf("KOSONG") >= 0 || pesanStatus.indexOf("GANGGUAN") >= 0) {
    // Terjadi gangguan: LED merah berkedip, LED hijau mati
    merah = kedip;
    hijau = false;
  }
  // 3. KONDISI SEDANG REFILL (Pompa aktif mengisi air)
  else if (pompaAktif) {
    // LED merah berkedip saat pompa aktif
    merah = kedip;
    hijau = false;
  }
  // 4. KONDISI NORMAL BOTOL DI TATAKAN
  else {
    // Jika penuh / berhasil atau isi botol cukup (> 30%): LED hijau nyala terus
    if (persenAir > 30.0) {
      hijau = true;  // LED hijau nyala terus
      merah = false; // LED merah mati
    } else {
      // Jika sisa air rendah (<= 30%) dan belum mengisi
      merah = true;  // LED merah nyala diam
      hijau = false;
    }
  }

  digitalWrite(LED_MERAH, merah ? HIGH : LOW);
  digitalWrite(LED_HIJAU, hijau ? HIGH : LOW);
}

// ==============================================================
// 9. TAMPILAN LCD
// ==============================================================
void perbaruiTampilan() {
  if (!lcdAda || millis() - terakhirLCD < 400) return;
  terakhirLCD = millis();

  // Jika listener server side PHP belum terhubung, tahan di layar tunggu
  if (!serverListenerAktif) {
    tulisBarisLCD(0, "SMARTFILL");
    tulisBarisLCD(1, "Tunggu Listener");
    return;
  }

  char baris[17];
  if (!botolAda) {
    tulisBarisLCD(0, "Air: --   Tot:0g");
    tulisBarisLCD(1, "TANPA BOTOL");
  } else {
    snprintf(baris, sizeof(baris), "Air:%2.0f%% Tot:%3.0fg", persenAir, beratTotalGram);
    tulisBarisLCD(0, baris);

    // 1. Prioritas Utama: Alarm Sumber Air Kosong / Aliran Macet
    if (statusAlarm || pesanStatus.indexOf("KOSONG") >= 0) {
      tulisBarisLCD(1, "SUMBER KOSONG");
    }
    // 2. Refill Dijeda (botol diangkat saat pengisian lalu ditaruh kembali)
    else if (pesanStatus.indexOf("DIJEDA") >= 0) {
      tulisBarisLCD(1, "REFILL DIJEDA");
    }
    // 3. Pompa sedang aktif mengisi air
    else if (pompaAktif || pesanStatus.indexOf("MENGISI") >= 0) {
      tulisBarisLCD(1, "MENGISI");
    }
    // 4. Jika baru saja selesai refill (target tercapai) dan botol belum diangkat
    else if (baruSelesaiRefill) {
      tulisBarisLCD(1, "Pengisian Sukses");
    }
    // 5. Kondisi normal / standby (ISI CUKUP atau AIR RENDAH)
    else {
      char barisSatu[17];
      if (pesanStatus.indexOf("berhasil") >= 0 || pesanStatus.indexOf("Berhasil") >= 0 || 
          pesanStatus.indexOf("SUKSES") >= 0 || pesanStatus.indexOf("Sukses") >= 0) {
        snprintf(barisSatu, sizeof(barisSatu), "ISI CUKUP");
      } else {
        snprintf(barisSatu, sizeof(barisSatu), "%s", pesanStatus.c_str());
      }
      tulisBarisLCD(1, barisSatu);
    }
  }
}

// ==============================================================
// 10. PEMROSESAN PERINTAH SERIAL (PENGUJIAN CEPAT)
// ==============================================================
void prosesSerial() {
  if (!Serial.available()) return;
  char cmd = Serial.read();

  switch (cmd) {
    case 'T':
    case 't':
      lakukanTareOtomatis();
      break;

    case 'P':
    case 'p':
      Serial.println(">> Tes Manual: Nyalakan Pompa selama 3 detik...");
      pompaOn(3000);
      break;

    case 'X':
    case 'x':
      Serial.println(">> Hentikan seluruh output!");
      matikanSemuaOutput();
      break;

    case '1':
      Serial.println(">> Tes LED Merah...");
      digitalWrite(LED_MERAH, HIGH);
      delay(1000);
      digitalWrite(LED_MERAH, LOW);
      break;

    case '2':
      Serial.println(">> Tes LED Hijau...");
      digitalWrite(LED_HIJAU, HIGH);
      delay(1000);
      digitalWrite(LED_HIJAU, LOW);
      break;

    case 'B':
    case 'b':
      Serial.println(">> Tes Buzzer...");
      bunyikanBuzzer(2000, 300);
      break;

    default:
      break;
  }
}

// ==============================================================
// 11. SETUP
// ==============================================================
void setup() {
  // Setup Pin Output
  pinMode(RELAY_POMPA, OUTPUT);
  pinMode(RELAY_DUA, OUTPUT);
  pinMode(LED_MERAH, OUTPUT);
  pinMode(LED_HIJAU, OUTPUT);
  pinMode(BUZZER_PIN, OUTPUT);

  // Pastikan posisi awal seluruh output mati
  matikanSemuaOutput();

  Serial.begin(115200);
  delay(300);

  // Uji Visual Singkat Hardware: Nyalakan LED Merah & Hijau 600 ms saat boot
  digitalWrite(LED_MERAH, HIGH);
  digitalWrite(LED_HIJAU, HIGH);
  delay(600);
  digitalWrite(LED_MERAH, LOW);
  digitalWrite(LED_HIJAU, LOW);

  // Inisialisasi I2C LCD
  Wire.begin(LCD_SDA, LCD_SCL);
  Wire.setTimeOut(50);
  Wire.beginTransmission(0x27);
  lcdAda = Wire.endTransmission() == 0;

  if (lcdAda) {
    lcd.init();
    lcd.backlight();
    tulisBarisLCD(0, "SMARTFILL");
    tulisBarisLCD(1, "Tare Sensor...");
  }

  // Inisialisasi Sensor HX711
  timbangan.begin(HX_DT, HX_SCK);
  delay(500);

  // Auto-Tare saat boot: Kosongkan tatakan saat menyalakan alat
  lakukanTareOtomatis();

  // Siapkan Topik MQTT Dinamis
  topicTelemetry      = String("smartrefill/") + DEVICE_ID + "/telemetry";
  topicRefillCmd      = String("smartrefill/") + DEVICE_ID + "/refill/cmd";
  topicBuzzerCmd      = String("smartrefill/") + DEVICE_ID + "/buzzer/cmd";
  topicDisplayState   = String("smartrefill/") + DEVICE_ID + "/display/state";
  topicGenericCmd     = String("smartrefill/") + DEVICE_ID + "/cmd";
  topicListenerStatus = String("smartrefill/") + DEVICE_ID + "/listener/status";

  // Tampilkan Informasi Koneksi Wi-Fi di Layar LCD
  if (lcdAda) {
    tulisBarisLCD(0, "Koneksi Wi-Fi...");
    char bufSsid[17];
    snprintf(bufSsid, sizeof(bufSsid), "SSID:%.11s", WIFI_SSID);
    tulisBarisLCD(1, bufSsid);
  }

  // Inisialisasi Wi-Fi
  WiFi.disconnect(true);
  WiFi.mode(WIFI_OFF);
  delay(300);
  WiFi.mode(WIFI_STA);
  WiFi.setAutoReconnect(true);
  WiFi.begin(WIFI_SSID, WIFI_PASSWORD);

  Serial.println("\n==============================================");
  Serial.println("SMART WATER BOTTLE REFILL DISPENSER");
  Serial.println("==============================================");
  Serial.print("Menghubungkan ke Wi-Fi: ");
  Serial.println(WIFI_SSID);

  int cobaWifi = 0;
  while (WiFi.status() != WL_CONNECTED && cobaWifi < 40) {
    delay(500);
    Serial.print(".");
    cobaWifi++;

    // Tampilkan animasi titik di baris 1 LCD
    if (lcdAda) {
      char animasi[17];
      int titik = (cobaWifi % 4);
      char dots[5] = "";
      for (int d = 0; d < titik; d++) dots[d] = '.';
      dots[titik] = '\0';
      snprintf(animasi, sizeof(animasi), "Menghubungkan%-3s", dots);
      tulisBarisLCD(1, animasi);
    }

    if (cobaWifi == 20) {
      WiFi.disconnect(true);
      delay(500);
      WiFi.begin(WIFI_SSID, WIFI_PASSWORD);
    }
  }

  if (WiFi.status() == WL_CONNECTED) {
    Serial.println("\nWi-Fi Terhubung!");
    Serial.printf("IP ESP32: %s\n", WiFi.localIP().toString().c_str());
    if (lcdAda) {
      tulisBarisLCD(0, "Wi-Fi Terhubung!");
      char bufIp[17];
      snprintf(bufIp, sizeof(bufIp), "IP:%s", WiFi.localIP().toString().c_str());
      tulisBarisLCD(1, bufIp);
      delay(1200);
    }
    configTime(0, 0, "pool.ntp.org", "time.google.com");
  } else {
    Serial.println("\n[PERINGATAN] Wi-Fi belum tersambung. Berjalan lokal...");
    if (lcdAda) {
      tulisBarisLCD(0, "Wi-Fi Gagal!");
      tulisBarisLCD(1, "Cek SSID/Pass");
      delay(2000);
    }
  }

  buatBootId();
  clientId = "smart-dispenser-" + WiFi.macAddress();
  clientId.replace(":", "");

  koneksiTLS.setCACert(ROOT_CA);

  mqtt.setServer(MQTT_HOST, MQTT_PORT);
  mqtt.setBufferSize(512);
  mqtt.setKeepAlive(30);
  mqtt.setSocketTimeout(10);
  mqtt.setCallback(mqttCallback);

  // Cek dan tampilkan status koneksi MQTT ke LCD saat boot
  if (WiFi.status() == WL_CONNECTED) {
    if (lcdAda) {
      tulisBarisLCD(0, "Koneksi MQTT...");
      tulisBarisLCD(1, "Menghubungkan..");
    }
    Serial.println("Menghubungkan ke EMQX Broker...");

    int cobaMqtt = 0;
    while (!mqtt.connected() && cobaMqtt < 3) {
      cobaMqtt++;
      if (mqtt.connect(clientId.c_str(), MQTT_USERNAME, MQTT_PASSWORD)) {
        Serial.println(">> MQTT Terhubung! Siap menerima perintah...");
        mqtt.subscribe(topicRefillCmd.c_str());
        mqtt.subscribe(topicBuzzerCmd.c_str());
        mqtt.subscribe(topicDisplayState.c_str());
        mqtt.subscribe(topicGenericCmd.c_str());
        mqtt.subscribe(topicListenerStatus.c_str());
        mqtt.subscribe(topicListenerStatus.c_str());

        if (lcdAda) {
          tulisBarisLCD(0, "MQTT Terhubung!");
          tulisBarisLCD(1, "Cek Listener...");
          delay(800);
        }

        // Kirim telemetry perdana untuk memancing respon dari listener PHP
        kirimTelemetry();

        // Tunggu respon balik dari server listener PHP hingga 2.5 detik
        unsigned long tungguMulai = millis();
        while (!serverListenerAktif && (millis() - tungguMulai < 2500)) {
          mqtt.loop();
          delay(100);
        }

        if (lcdAda) {
          if (serverListenerAktif) {
            pernahTampilListenerKonek = true;
            tulisBarisLCD(0, "Listener Server");
            tulisBarisLCD(1, "Terhubung!");
            delay(1000);
          } else {
            tulisBarisLCD(0, "SMARTFILL");
            tulisBarisLCD(1, "Tunggu Listener");
            delay(1200);
          }
        }
        break;
      } else {
        int errState = mqtt.state();
        Serial.printf("Gagal konek MQTT, status: %d (%s)\n", errState, pesanErrorMQTT(errState));
        if (lcdAda) {
          char errBuf[17];
          snprintf(errBuf, sizeof(errBuf), "MQTT Gagal (%d)", errState);
          tulisBarisLCD(0, errBuf);
          tulisBarisLCD(1, pesanErrorMQTT(errState));
          delay(1500);
        }
        delay(800);
      }
    }
  }

  if (lcdAda) {
    if (serverListenerAktif) {
      tulisBarisLCD(0, "SMARTFILL");
      tulisBarisLCD(1, "Sistem Siap");
      delay(800);
    } else {
      tulisBarisLCD(0, "SMARTFILL");
      tulisBarisLCD(1, "Tunggu Listener");
    }
  }

  Serial.printf("Boot ID: %s\n", bootId);
  Serial.println("Sistem siap digunakan! Ketik 'P' di Serial Monitor untuk tes pompa.");
}

// ==============================================================
// 12. LOOP UTAMA
// ==============================================================
void loop() {
  // Evaluasi koneksi listener server side PHP
  if (serverListenerAktif && millis() - terakhirResponListener > TIMEOUT_LISTENER_MS) {
    serverListenerAktif = false;
    Serial.println(">> [LISTENER] Peringatan: Timeout dari backend listener PHP.");
  }

  // Tampilkan notifikasi sesaat ketika listener baru terhubung
  if (serverListenerAktif && !pernahTampilListenerKonek) {
    pernahTampilListenerKonek = true;
    if (lcdAda) {
      tulisBarisLCD(0, "Listener Server");
      tulisBarisLCD(1, "Terhubung!");
      delay(1000);
    }
  } else if (!serverListenerAktif) {
    pernahTampilListenerKonek = false;
  }

  bacaTimbangan();
  prosesSerial();

  // Safety Cutoff Pompa jika waktu sewa habis
  if (pompaAktif && millis() - waktuMulaiPompa >= leasePompaMs) {
    pompaOff();
    Serial.println(">> [SAFETY] Waktu sewa pompa habis. Pompa dimatikan.");
  }

  // Pengamanan: Jika botol diangkat saat pompa menyala, matikan seketika
  if (pompaAktif && !botolAda) {
    pompaOff();
    pesanStatus = "BOTOL DIANGKAT";
    Serial.println(">> [SAFETY] Botol diangkat! Pompa dimatikan seketika.");
  }

  // Update Indikator Fisik (LED & Buzzer) & Layar LCD
  perbaruiIndikator();
  perbaruiTampilan();

  // Koneksi Wi-Fi Reconnect di latar belakang
  if (WiFi.status() != WL_CONNECTED) {
    pesanStatus = "WIFI TERPUTUS";
    static unsigned long terakhirCobaWiFi = 0;
    if (millis() - terakhirCobaWiFi >= 5000) {
      terakhirCobaWiFi = millis();
      Serial.println("[Wi-Fi] Terputus. Mencoba rekoneksi...");
      WiFi.reconnect();
    }
    return;
  }

  // Koneksi MQTT Reconnect di latar belakang
  if (!mqtt.connected()) {
    if (millis() - terakhirCobaMQTT >= INTERVAL_KONEKSI) {
      terakhirCobaMQTT = millis();
      Serial.println("Menghubungkan ke EMQX Broker...");

      if (mqtt.connect(clientId.c_str(), MQTT_USERNAME, MQTT_PASSWORD)) {
        Serial.println(">> MQTT Terhubung! Siap menerima perintah...");
        pesanStatus = "STANDBY";
        mqtt.subscribe(topicRefillCmd.c_str());
        mqtt.subscribe(topicBuzzerCmd.c_str());
        mqtt.subscribe(topicDisplayState.c_str());
        mqtt.subscribe(topicGenericCmd.c_str());
        mqtt.subscribe(topicListenerStatus.c_str());
      } else {
        int errState = mqtt.state();
        Serial.printf("Gagal konek MQTT, status: %d (%s)\n", errState, pesanErrorMQTT(errState));
        char errPesan[17];
        snprintf(errPesan, sizeof(errPesan), "MQTT:%s", pesanErrorMQTT(errState));
        pesanStatus = String(errPesan);
      }
    }
    return;
  }

  mqtt.loop();

  // Kirim Telemetry Terjadwal (tiap 500 ms)
  if (millis() - terakhirKirimTelemetry >= INTERVAL_TELEMETRY) {
    terakhirKirimTelemetry = millis();
    kirimTelemetry();
  }
}
