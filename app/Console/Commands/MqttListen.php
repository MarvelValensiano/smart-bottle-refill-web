<?php

namespace App\Console\Commands;

use App\Models\MqttMessage;
use App\Services\TelemetryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use JsonException;
use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\MqttClient;
use Throwable;

class MqttListen extends Command
{
    protected $signature = 'mqtt:listen';

    protected $description =
        'Menerima pesan uji dan telemetry dari EMQX';

    public function handle(): int
    {
        $host = config('mqtt.host');
        $port = (int) config('mqtt.port');
        $clientId = config('mqtt.client_id');
        $caFile = config('mqtt.ca_file');

        $testTopic = config('mqtt.topics.test');
        $telemetryTopic = config('mqtt.topics.telemetry');

        if (
            !is_string($host) || trim($host) === '' ||
            !is_string($clientId) || trim($clientId) === '' ||
            $port < 1 || $port > 65535
        ) {
            $this->error('Konfigurasi host, port, atau client ID tidak valid.');
            return self::FAILURE;
        }

        if (!is_string($caFile) || !is_readable($caFile)) {
            $this->error('Sertifikat CA tidak ditemukan atau tidak terbaca.');
            return self::FAILURE;
        }

        if (!$testTopic || !$telemetryTopic) {
            $this->error('Konfigurasi topik MQTT belum lengkap.');
            return self::FAILURE;
        }

        $service = app(TelemetryService::class);

        while (true) {
            $mqtt = null;

            try {
                $this->info("Menghubungkan ke EMQX: {$host}:{$port}");

                $mqtt = new MqttClient(
                    $host,
                    $port,
                    $clientId,
                    MqttClient::MQTT_3_1_1
                );

                $settings = (new ConnectionSettings())
                    ->setUsername(config('mqtt.username'))
                    ->setPassword(config('mqtt.password'))
                    ->setUseTls(true)
                    ->setTlsVerifyPeer(true)
                    ->setTlsVerifyPeerName(true)
                    ->setTlsCertificateAuthorityFile($caFile)
                    ->setConnectTimeout(15)
                    ->setKeepAliveInterval(30);

                $mqtt->connect($settings, true);

                // ===== TOPIK TES LAMA =====
                $mqtt->subscribe(
                    $testTopic,
                    function (
                        string $topic,
                        string $message,
                        bool $retained = false
                    ) {
                        if ($retained) {
                            $this->warn('Pesan tes retained diabaikan.');
                            return;
                        }

                        $this->simpanPesanUji($topic, $message);
                    },
                    0
                );

                // ===== TELEMETRY BARU =====
                $mqtt->subscribe(
                    $telemetryTopic,
                    function (
                        string $topic,
                        string $message,
                        bool $retained = false
                    ) use ($service, $mqtt) {
                        if ($retained) {
                            $this->warn('Telemetry retained diabaikan.');
                            return;
                        }

                        $this->simpanTelemetry($message, $service, $mqtt);
                    },
                    0
                );

                $this->info('Terhubung ke EMQX.');
                $this->line("Topik tes: {$testTopic}");
                $this->line("Topik sensor: {$telemetryTopic}");
                $this->line('Ctrl+C untuk menghentikan.');

                $mqtt->loop(true);
            } catch (Throwable $e) {
                $this->error(
                    'Koneksi MQTT terputus/gagal: ' . $e->getMessage()
                );
            } finally {
                if ($mqtt !== null) {
                    try {
                        $mqtt->disconnect();
                    } catch (Throwable $e) {
                        // Koneksi mungkin sudah terputus.
                    }
                }
            }

            $delay = max(1, (int) config('mqtt.reconnect_delay', 5));

            $this->warn("Mencoba koneksi kembali dalam {$delay} detik...");
            sleep($delay);
        }
    }

    private function decodePayload(string $message): array
    {
        if (strlen($message) > 8192) {
            throw new \RuntimeException('Pesan melebihi batas 8 KB.');
        }

        $data = json_decode(
            $message,
            true,
            32,
            JSON_THROW_ON_ERROR
        );

        // Payload harus objek JSON, bukan array/list.
        if (!is_array($data) || array_is_list($data)) {
            throw new \RuntimeException('Payload harus berupa objek JSON.');
        }

        return $data;
    }

    private function simpanPesanUji(
        string $topic,
        string $message
    ): void {
        try {
            $data = $this->decodePayload($message);

            Validator::make($data, [
                'device_id' => ['required', 'string', 'in:rack01'],
                'message' => ['required', 'string', 'max:500'],
                'simulated' => ['required', 'boolean'],
            ])->validate();

            $record = MqttMessage::create([
                'device_id' => $data['device_id'],
                'topic' => $topic,
                'payload' => $message,
                'simulated' => $data['simulated'],
            ]);

            $this->info("Pesan tes tersimpan | ID: {$record->id}");
        } catch (ValidationException $e) {
            $this->warn(
                'Pesan tes ditolak: ' .
                collect($e->errors())->flatten()->first()
            );
        } catch (JsonException $e) {
            $this->warn('Pesan tes ditolak: JSON tidak valid.');
        } catch (Throwable $e) {
            $this->error('Gagal memproses pesan tes: ' . $e->getMessage());
        }
    }

    private function simpanTelemetry(
        string $message,
        TelemetryService $service,
        MqttClient $mqtt
    ): void {
        try {
            $data = $this->decodePayload($message);

            // Mendukung rack01, rack02, dan rack03
            Validator::make($data, [
                'device_id' => ['required', 'string', 'in:rack01,rack02,rack03'],
            ])->validate();

            $record = $service->handle($data);
            $deviceCode = $data['device_id'];

            if ($record->wasRecentlyCreated) {
                $this->info(
                    "Telemetry [{$deviceCode}] tersimpan | ID: {$record->id}" .
                    " | Sequence: {$record->sequence}"
                );
            } else {
                $this->line(
                    "Duplikat [{$deviceCode}] diabaikan | ID lama: {$record->id}" .
                    " | Sequence: {$record->sequence}"
                );
            }

            $record->refresh();

            // Kirim perintah aktuator yang dihasilkan oleh keputusan backend ke ESP32
            $this->dispatchPendingCommands($mqtt, (int) $record->device_id);

            // Kirim status display ke ESP32 untuk indikator LCD & LED
            $activeRefill = \App\Models\Refill::where('device_id', $record->device_id)->where('status', 'filling')->first();

            $statusText = 'STANDBY';
            if ($activeRefill) {
                $statusText = 'MENGISI';
            } elseif (!$record->bottle_present) {
                $statusText = 'TANPA BOTOL';
            } elseif ($record->water_percent !== null && $record->water_percent <= 30.0) {
                $statusText = 'AIR RENDAH';
            } elseif ($record->water_percent !== null && $record->water_percent >= 80.0) {
                $statusText = 'ISI >=80%';
            } else {
                $statusText = 'ISI CUKUP';
            }

            $statePayload = json_encode([
                'status' => $statusText,
                'percent' => $record->water_percent !== null ? round($record->water_percent, 1) : 0,
                'weight' => $record->total_weight_g !== null ? round($record->total_weight_g, 1) : 0,
                'bottle' => (bool) $record->bottle_present,
                'pump' => (bool) ($activeRefill !== null),
                'alarm' => false,
            ]);

            try {
                $mqtt->publish("smartrefill/{$deviceCode}/display/state", $statePayload, 0);
            } catch (\Throwable $e) {
                // Abaikan kesalahan kirim state display
            }
        } catch (ValidationException $e) {
            $this->warn(
                'Telemetry ditolak: ' .
                collect($e->errors())->flatten()->first()
            );
        } catch (JsonException $e) {
            $this->warn('Telemetry ditolak: JSON tidak valid.');
        } catch (Throwable $e) {
            $this->error(
                'Gagal memproses telemetry: ' . $e->getMessage()
            );
        }
    }

    private function dispatchPendingCommands(MqttClient $mqtt, int $deviceId): void
    {
        $device = \App\Models\Device::find($deviceId);
        $deviceCode = $device ? $device->code : 'rack01';

        $pending = \App\Models\DeviceCommand::where('device_id', $deviceId)
            ->where('status', 'pending')
            ->orderBy('id', 'asc')
            ->get();

        foreach ($pending as $cmd) {
            $topic = match ($cmd->type) {
                'set_pump' => "smartrefill/{$deviceCode}/refill/cmd",
                'set_buzzer' => "smartrefill/{$deviceCode}/buzzer/cmd",
                default => "smartrefill/{$deviceCode}/cmd",
            };

            try {
                $payloadJson = json_encode($cmd->payload, JSON_THROW_ON_ERROR);
                $mqtt->publish($topic, $payloadJson, 0);

                $cmd->update([
                    'status' => 'published',
                    'published_at' => now(),
                ]);

                $this->info("Perintah dikirim ke ESP32 [{$deviceCode}]: [{$cmd->type}] -> {$topic} | Data: {$payloadJson}");
            } catch (Throwable $e) {
                $cmd->update([
                    'status' => 'failed',
                    'error_message' => $e->getMessage(),
                ]);
                $this->error("Gagal mengirim perintah {$cmd->type}: " . $e->getMessage());
            }
        }
    }
}