<?php

use App\Jobs\SendPushNotification;
use App\Models\City;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\User;
use App\Services\NotificationService;
use App\Support\FcmChannels;
use Illuminate\Support\Facades\Queue;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Exception\Messaging\InvalidMessage;
use Kreait\Firebase\Exception\Messaging\NotFound;
use Kreait\Firebase\Messaging\AppInstance;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Message;
use Kreait\Firebase\Messaging\MulticastSendReport;
use Tymon\JWTAuth\Facades\JWTAuth;

final class FakeRecordingMessaging implements Messaging
{
    /** @var list<array<string, mixed>> */
    public array $sentPayloads = [];

    public ?Throwable $exceptionToThrow = null;

    public function send(Message|array $message, bool $validateOnly = false): array
    {
        if ($this->exceptionToThrow !== null) {
            throw $this->exceptionToThrow;
        }

        if ($message instanceof CloudMessage) {
            $this->sentPayloads[] = $message->jsonSerialize();
        } elseif (is_array($message)) {
            $this->sentPayloads[] = $message;
        }

        return ['name' => 'projects/golivreur-test/messages/fake-msg-1'];
    }

    public function sendMulticast(Message|array $message, $registrationTokens, bool $validateOnly = false): MulticastSendReport
    {
        throw new BadMethodCallException('Not used in unit test.');
    }

    public function sendAll($messages, bool $validateOnly = false): MulticastSendReport
    {
        throw new BadMethodCallException('Not used in unit test.');
    }

    public function validate(Message|array $message): array
    {
        return [];
    }

    public function validateRegistrationTokens($registrationTokenOrTokens): array
    {
        return ['valid' => [], 'unknown' => [], 'invalid' => []];
    }

    public function subscribeToTopic($topic, $registrationTokenOrTokens): array
    {
        return [];
    }

    public function subscribeToTopics(iterable $topics, $registrationTokenOrTokens): array
    {
        return [];
    }

    public function unsubscribeFromTopic($topic, $registrationTokenOrTokens): array
    {
        return [];
    }

    public function unsubscribeFromTopics(iterable $topics, $registrationTokenOrTokens): array
    {
        return [];
    }

    public function unsubscribeFromAllTopics($registrationTokenOrTokens): array
    {
        return [];
    }

    public function getAppInstance($registrationToken): AppInstance
    {
        throw new BadMethodCallException('Not used in unit test.');
    }
}

it('definit les constantes de canaux Android et ignore les identifiants Firebase dans .gitignore', function () {
    expect(FcmChannels::ORDERS_NEW)->toBe('orders_new')
        ->and(FcmChannels::COURSES_NEW)->toBe('courses_new')
        ->and(FcmChannels::GENERAL)->toBe('general');

    $gitignore = (string) file_get_contents(base_path('../.gitignore'));
    expect($gitignore)->toContain('google-services.json')
        ->toContain('firebase-service-account')
        ->toContain('firebase-adminsdk');
});

it('enregistre et met a jour le jeton appareil via POST /api/v1/devices/token authentifie et throttle', function () {
    $client = User::factory()->create(['role' => 'client', 'is_active' => true, 'device_token' => null]);

    // Non authentifié -> 401
    $this->postJson('/api/v1/devices/token', [
        'token' => 'fcm_token_exemple_valide_1234567890',
    ])->assertStatus(401);

    // Authentifié -> 200 et device_token persisté en base (masqué dans la réponse JSON)
    $jwt = JWTAuth::fromUser($client);
    $res = $this->withHeader('Authorization', "Bearer {$jwt}")
        ->postJson('/api/v1/devices/token', [
            'token' => 'fcm_token_exemple_valide_1234567890',
        ]);

    $res->assertOk()
        ->assertJsonPath('succes', true);

    expect($client->fresh()->device_token)->toBe('fcm_token_exemple_valide_1234567890')
        ->and($res->content())->not->toContain('fcm_token_exemple_valide_1234567890');

    // Validation stricte -> 422 si vide ou trop court
    $this->withHeader('Authorization', "Bearer {$jwt}")
        ->postJson('/api/v1/devices/token', ['token' => 'abc'])
        ->assertStatus(422);
});

it('retourne 429 au-dela de 10 requetes par minute sur POST /api/v1/devices/token', function () {
    $throttledUser = User::factory()->create(['role' => 'client', 'is_active' => true]);
    $throttledJwt = JWTAuth::fromUser($throttledUser);

    for ($i = 0; $i < 10; $i++) {
        $this->withHeader('Authorization', "Bearer {$throttledJwt}")
            ->postJson('/api/v1/devices/token', ['token' => 'fcm_token_exemple_valide_1234567890_'.$i])
            ->assertOk();
    }

    $this->withHeader('Authorization', "Bearer {$throttledJwt}")
        ->postJson('/api/v1/devices/token', ['token' => 'fcm_token_exemple_valide_1234567890_11'])
        ->assertStatus(429);
});

it('envoie un push FCM reel via faux Messaging avec priorite haute et ttl 60s sur nouvelle commande et nouvelle course', function () {
    Queue::fake();

    $fake = new FakeRecordingMessaging;
    $this->app->instance(Messaging::class, $fake);

    $city = City::factory()->create();
    $merchantUser = User::factory()->create(['role' => 'merchant', 'city_id' => $city->id, 'device_token' => 'fcm-merchant-tok-001']);
    $merchant = Merchant::factory()->create(['city_id' => $city->id, 'user_id' => $merchantUser->id]);
    $driverUser = User::factory()->create(['role' => 'driver', 'city_id' => $city->id, 'device_token' => 'fcm-driver-tok-001']);
    $courier = Courier::factory()->create(['city_id' => $city->id, 'user_id' => $driverUser->id]);
    $clientUser = User::factory()->create(['role' => 'client', 'city_id' => $city->id, 'device_token' => 'fcm-client-tok-001']);

    $order = Order::factory()->create(['city_id' => $city->id, 'merchant_id' => $merchant->id, 'client_id' => $clientUser->id]);

    $notifier = app(NotificationService::class);
    $notifier->notifierNouvelleCommande($merchant, $order);
    $notifier->proposerCourseGoShop($courier, $order);
    $notifier->notifierCommandeAcceptee($order);

    // Exécuter les 3 jobs poussés (sur la file default)
    $pushed = Queue::pushedJobs()[SendPushNotification::class] ?? [];
    expect($pushed)->toHaveCount(3);

    foreach ($pushed as $entry) {
        expect($entry['queue'])->toBe('default');
        $entry['job']->handle();
    }

    expect($fake->sentPayloads)->toHaveCount(3);

    // 1. Commerce : nouvelle commande -> channel orders_new, priority high, ttl 60s
    $orderPayload = $fake->sentPayloads[0];
    expect($orderPayload['token'])->toBe('fcm-merchant-tok-001')
        ->and($orderPayload['notification']['title'])->toBe('Nouvelle commande GoShop')
        ->and($orderPayload['data']['channel_id'])->toBe('orders_new')
        ->and($orderPayload['data']['order_id'])->toBe((string) $order->id)
        ->and($orderPayload['android']['priority'])->toBe('high')
        ->and($orderPayload['android']['ttl'])->toBe('60s')
        ->and($orderPayload['android']['notification']['channel_id'])->toBe('orders_new');

    // 2. Livreur : nouvelle course -> channel courses_new, priority high, ttl 60s
    $coursePayload = $fake->sentPayloads[1];
    expect($coursePayload['token'])->toBe('fcm-driver-tok-001')
        ->and($coursePayload['data']['channel_id'])->toBe('courses_new')
        ->and($coursePayload['android']['priority'])->toBe('high')
        ->and($coursePayload['android']['ttl'])->toBe('60s')
        ->and($coursePayload['android']['notification']['channel_id'])->toBe('courses_new');

    // 3. Client : notification standard -> channel general, pas de ttl 60s urgent
    $generalPayload = $fake->sentPayloads[2];
    expect($generalPayload['token'])->toBe('fcm-client-tok-001')
        ->and($generalPayload['data']['channel_id'])->toBe('general')
        ->and($generalPayload['android']['notification']['channel_id'])->toBe('general')
        ->and($generalPayload['android']['ttl'] ?? null)->toBeNull();
});

it('remet device_token a null lorsque FCM signale un jeton desinscrit (NotFound) ou invalide (InvalidMessage)', function () {
    $userUnregistered = User::factory()->create(['role' => 'client', 'device_token' => 'stale-token-404']);
    $userInvalid = User::factory()->create(['role' => 'driver', 'device_token' => 'bad-token-400']);

    $fakeNotFound = new FakeRecordingMessaging;
    $fakeNotFound->exceptionToThrow = NotFound::becauseTokenNotFound('stale-token-404');
    $this->app->instance(Messaging::class, $fakeNotFound);

    $job1 = new SendPushNotification(
        deviceToken: 'stale-token-404',
        title: 'Test',
        body: 'Test body',
    );
    $job1->handle();

    expect($userUnregistered->fresh()->device_token)->toBeNull();

    $fakeInvalid = new FakeRecordingMessaging;
    $fakeInvalid->exceptionToThrow = new InvalidMessage('The registration token is not a valid FCM registration token');
    $this->app->instance(Messaging::class, $fakeInvalid);

    $job2 = new SendPushNotification(
        deviceToken: 'bad-token-400',
        title: 'Test',
        body: 'Test body',
    );
    $job2->handle();

    expect($userInvalid->fresh()->device_token)->toBeNull();
});
