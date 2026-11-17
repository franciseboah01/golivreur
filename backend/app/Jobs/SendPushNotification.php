<?php

namespace App\Jobs;

use App\Models\User;
use App\Support\FcmChannels;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Exception\Messaging\InvalidArgument as MessagingInvalidArgument;
use Kreait\Firebase\Exception\Messaging\InvalidMessage;
use Kreait\Firebase\Exception\Messaging\NotFound;
use Kreait\Firebase\Messaging\AndroidConfig;
use Kreait\Firebase\Messaging\ApnsConfig;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification as FcmNotification;

class SendPushNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const CHANNEL_ORDERS_NEW = FcmChannels::ORDERS_NEW;

    public const CHANNEL_COURSES_NEW = FcmChannels::COURSES_NEW;

    public const CHANNEL_GENERAL = FcmChannels::GENERAL;

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public string $deviceToken,
        public string $title,
        public string $body,
        public array $data = [],
        public string $channelId = FcmChannels::GENERAL,
        public bool $highPriority = false,
        public ?int $ttlSeconds = null,
    ) {}

    public function handle(): void
    {
        $token = trim($this->deviceToken);
        if ($token === '') {
            return;
        }

        $client = $this->resolveMessaging();
        if ($client === null) {
            return;
        }

        $androidNotification = [
            'channel_id' => $this->channelId,
            'sound' => 'default',
        ];

        $androidConfigArray = [
            'notification' => $androidNotification,
        ];

        $apnsHeaders = [
            'apns-priority' => $this->highPriority ? '10' : '5',
        ];

        if ($this->highPriority) {
            $androidConfigArray['priority'] = 'high';
        }

        if ($this->ttlSeconds !== null && $this->ttlSeconds > 0) {
            $androidConfigArray['ttl'] = $this->ttlSeconds.'s';
            $apnsHeaders['apns-expiration'] = (string) (now()->getTimestamp() + $this->ttlSeconds);
        }

        $message = CloudMessage::new()
            ->withToken($token)
            ->withNotification(FcmNotification::create($this->title, $this->body))
            ->withData($this->formatDataPayload())
            ->withAndroidConfig(AndroidConfig::fromArray($androidConfigArray))
            ->withApnsConfig(
                ApnsConfig::fromArray([
                    'headers' => $apnsHeaders,
                    'payload' => ['aps' => ['sound' => 'default']],
                ])
            );

        try {
            $client->send($message);

            Log::info('Push FCM envoyé', [
                'token_prefix' => substr($token, 0, 6).'...',
                'channel_id' => $this->channelId,
                'high_priority' => $this->highPriority,
            ]);
        } catch (NotFound|InvalidMessage|MessagingInvalidArgument $e) {
            User::where('device_token', $token)->update(['device_token' => null]);

            Log::warning('Jeton FCM invalide ou désinscrit — supprimé de users.device_token', [
                'token_prefix' => substr($token, 0, 6).'...',
                'exception' => $e::class,
                'channel_id' => $this->channelId,
            ]);
        } catch (\Throwable $e) {
            Log::error('Échec envoi push FCM', [
                'token_prefix' => substr($token, 0, 6).'...',
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'channel_id' => $this->channelId,
            ]);
        }
    }

    /**
     * @return array<non-empty-string, string>
     */
    private function formatDataPayload(): array
    {
        $payload = [
            'channel_id' => $this->channelId,
        ];

        foreach ($this->data as $key => $value) {
            $cleanKey = trim((string) $key);
            if ($cleanKey === '' || $value === null) {
                continue;
            }

            if (is_bool($value)) {
                $payload[$cleanKey] = $value ? '1' : '0';
            } elseif (is_scalar($value)) {
                $payload[$cleanKey] = (string) $value;
            } else {
                $encoded = json_encode($value);
                if ($encoded !== false) {
                    $payload[$cleanKey] = $encoded;
                }
            }
        }

        return $payload;
    }

    private function resolveMessaging(): ?Messaging
    {
        $container = app();
        if (! $container->resolved(Messaging::class)) {
            $credentials = config('firebase.projects.app.credentials');
            if (! is_string($credentials) || trim($credentials) === '' || ! is_file($credentials)) {
                Log::info('Push FCM ignoré : identifiants Firebase absents ou introuvables.', [
                    'channel_id' => $this->channelId,
                ]);

                return null;
            }
        }

        try {
            return $container->make(Messaging::class);
        } catch (\Throwable $e) {
            Log::warning('Impossible d\'initialiser Firebase Messaging', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
