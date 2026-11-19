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
                    ) use ($service) {
                        if ($retained) {
                            $this->warn('Telemetry retained diabaikan.');
                            return;
                        }

                        $this->simpanTelemetry($message, $service);
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
        TelemetryService $service
    ): void {
        try {
            $data = $this->decodePayload($message);

            // Topik ini khusus rack01.
            Validator::make($data, [
                'device_id' => ['required', 'string', 'in:rack01'],
            ])->validate();

            $record = $service->handle($data);

            if ($record->wasRecentlyCreated) {
                $this->info(
                    "Telemetry tersimpan | ID: {$record->id}" .
                    " | Sequence: {$record->sequence}"
                );
            } else {
                $this->line(
                    "Duplikat diabaikan | ID lama: {$record->id}" .
                    " | Sequence: {$record->sequence}"
                );
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
}