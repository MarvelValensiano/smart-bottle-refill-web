<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\MqttClient;
use Throwable;
use App\Models\MqttMessage;
use Illuminate\Support\Facades\Validator;

class MqttListen extends Command
{
    protected $signature = 'mqtt:listen';

    protected $description = 'Menerima pesan uji dari EMQX';

    public function handle(): int
    {
        $caFile = config('mqtt.ca_file');

        if (! is_readable($caFile)) {
            $this->error("Sertifikat tidak ditemukan: {$caFile}");
            return self::FAILURE;
        }

        try {
            $mqtt = new MqttClient(
                config('mqtt.host'),
                config('mqtt.port'),
                config('mqtt.client_id'),
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

            $mqtt->subscribe(
                'smartrefill/rack01/test',
                function (string $topic, string $message) {
                    $data = json_decode($message, true);

                    if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
                        $this->warn('Pesan diabaikan: format JSON tidak valid.');
                        return;
                    }

                    $validator = Validator::make($data, [
                        'device_id' => ['required', 'string', 'in:rack01'],
                        'message' => ['required', 'string', 'max:500'],
                        'simulated' => ['required', 'boolean'],
                    ]);

                    if ($validator->fails()) {
                        $this->warn(
                            'Pesan diabaikan: ' .
                                $validator->errors()->first()
                        );
                        return;
                    }

                    try {
                        $record = MqttMessage::create([
                            'device_id' => $data['device_id'],
                            'topic' => $topic,
                            'payload' => $message,
                            'simulated' => $data['simulated'],
                        ]);

                        $this->info(
                            "Pesan tersimpan! ID: {$record->id} | " .
                                "Perangkat: {$record->device_id}"
                        );

                        $this->line($message);
                    } catch (Throwable $e) {
                        $this->error('Gagal menyimpan pesan: ' . $e->getMessage());
                    }
                },
                0
            );

            $this->info('Terhubung ke EMQX. Menunggu pesan...');
            $this->info('Tekan Ctrl+C untuk menghentikan.');

            $mqtt->loop(true);
            $mqtt->disconnect();

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('Koneksi/penerimaan gagal: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
