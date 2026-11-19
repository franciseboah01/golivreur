<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\UpdateBusinessProfileRequest;
use App\Http\Requests\Profile\UpdateClientProfileRequest;
use App\Http\Requests\Profile\UpdateDriverProfileRequest;
use App\Http\Requests\Profile\UpdatePasswordRequest;
use App\Http\Resources\BusinessProfileResource;
use App\Http\Resources\ClientProfileResource;
use App\Http\Resources\DriverProfileResource;
use App\Models\ActivityLog;
use App\Models\OtpCode;
use App\Models\User;
use App\Services\SmsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Contrôleur API gérant la consultation et la mise à jour du profil
 * pour les trois rôles mobiles (Client, Driver, Business) ainsi que le mot de passe.
 */
class ProfileController extends Controller
{
    /**
     * Endpoint unifié : retourne le profil selon le rôle de l'utilisateur connecté.
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        return match ($user->role) {
            'client' => $this->showClient($request),
            'driver', 'courier' => $this->showDriver($request),
            'merchant' => $this->showBusiness($request),
            default => response()->json([
                'succes' => true,
                'donnees' => new ClientProfileResource($user->load('city')),
            ]),
        };
    }

    /**
     * Accès explicite au profil d'un utilisateur par ID : bloqué en 403 si autre utilisateur.
     */
    public function showForUser(Request $request, User $user): JsonResponse
    {
        Gate::authorize('viewSelfProfile', $user);

        return $this->show($request);
    }

    /**
     * Mise à jour explicite d'un utilisateur par ID : bloqué en 403 si autre utilisateur.
     */
    public function updateForUser(Request $request, User $user): JsonResponse
    {
        Gate::authorize('updateSelfProfile', $user);

        return match ($user->role) {
            'client' => $this->updateClient(app(UpdateClientProfileRequest::class)),
            'driver', 'courier' => $this->updateDriver(app(UpdateDriverProfileRequest::class)),
            'merchant' => $this->updateBusiness(app(UpdateBusinessProfileRequest::class)),
            default => response()->json([
                'succes' => false,
                'message' => 'Rôle non supporté pour cet endpoint.',
            ], 403),
        };
    }

    // ─── CLIENT ───────────────────────────────────────────────────────────────

    public function showClient(Request $request): JsonResponse
    {
        $user = $request->user()->fresh(['city']);

        return response()->json([
            'succes' => true,
            'donnees' => new ClientProfileResource($user),
        ]);
    }

    public function updateClient(UpdateClientProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validated();

        // 1. Le nom est verrouillé après l'inscription pour le client
        if (
            array_key_exists('name', $validated)
            && trim((string) $user->name) !== ''
            && trim((string) $validated['name']) !== trim((string) $user->name)
        ) {
            return response()->json([
                'succes' => false,
                'code' => 'NAME_LOCKED',
                'message' => 'Le nom ne peut plus être modifié après inscription. Contactez le service client en cas d\'erreur.',
                'errors' => [
                    'name' => ['Le nom ne peut plus être modifié après inscription.'],
                ],
            ], 422);
        }

        // 2. Le numéro de téléphone exige un OTP confirmé sur le nouveau numéro
        if (
            array_key_exists('phone', $validated)
            && (string) $validated['phone'] !== (string) $user->phone
        ) {
            return response()->json([
                'succes' => false,
                'code' => 'PHONE_OTP_REQUIRED',
                'message' => 'La modification du numéro nécessite un code OTP envoyé sur votre nouveau numéro.',
                'errors' => [
                    'phone' => ['Confirmez le nouveau numéro avec le code OTP dédié.'],
                ],
            ], 422);
        }

        // 3. L'ajout ou modification d'email exige un code de confirmation envoyé à l'adresse email
        if (array_key_exists('email', $validated)) {
            $incomingEmail = $validated['email'] !== null ? strtolower(trim((string) $validated['email'])) : null;
            $currentEmail = $user->email !== null ? strtolower(trim((string) $user->email)) : null;

            if ($incomingEmail !== null && $incomingEmail !== '' && $incomingEmail !== $currentEmail) {
                return response()->json([
                    'succes' => false,
                    'code' => 'EMAIL_OTP_REQUIRED',
                    'message' => 'L\'ajout ou la modification d\'une adresse email nécessite un code de confirmation envoyé par email.',
                    'errors' => [
                        'email' => ['Confirmez votre adresse email avec le code reçu par mail.'],
                    ],
                ], 422);
            }
        }

        DB::transaction(function () use ($user, $validated, $request) {
            $userData = [];
            foreach (['language', 'notifications_enabled', 'default_address'] as $field) {
                if (array_key_exists($field, $validated)) {
                    $userData[$field] = $validated[$field];
                }
            }

            if (array_key_exists('email', $validated) && ($validated['email'] === null || $validated['email'] === '')) {
                $userData['email'] = null;
            }

            if ($request->hasFile('photo')) {
                $userData['photo_path'] = $this->replacePublicFile(
                    $request->file('photo'),
                    $user->photo_path,
                    'profils'
                );
            }

            if (! empty($userData)) {
                $user->update($userData);
            }

            $this->logProfileUpdate($user, 'client_profile_updated', $request->ip());
        });

        return response()->json([
            'succes' => true,
            'message' => 'Profil client mis à jour avec succès.',
            'donnees' => new ClientProfileResource($user->fresh()->load('city')),
        ]);
    }

    /**
     * Étape 1 : Envoie un code OTP à 6 chiffres sur le NOUVEAU numéro de téléphone du client.
     */
    public function requestClientPhoneChangeOtp(Request $request, SmsService $smsService): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $normalized = $this->normalizeIvorianPhone((string) $request->input('phone', ''));
        $request->merge(['phone' => $normalized]);

        $validated = $request->validate([
            'phone' => [
                'required',
                'string',
                'regex:/^(01|05|07|21|25|27)[0-9]{8}$/',
                Rule::unique('users', 'phone')->ignore($user->id),
            ],
        ], [
            'phone.required' => 'Veuillez saisir votre nouveau numéro de téléphone.',
            'phone.regex' => 'Format ivoirien invalide : 10 chiffres attendus (ex: 0701000001).',
            'phone.unique' => 'Ce numéro de téléphone est déjà utilisé par un autre compte.',
        ]);

        $newPhone = (string) $validated['phone'];
        if ($newPhone === (string) $user->phone) {
            return response()->json([
                'succes' => false,
                'message' => 'Ce numéro est déjà celui associé à votre compte.',
                'errors' => ['phone' => ['Saisissez un nouveau numéro différent de l\'actuel.']],
            ], 422);
        }

        $rateKey = "client_phone_otp:{$user->id}";
        if (RateLimiter::tooManyAttempts($rateKey, 5)) {
            return response()->json([
                'succes' => false,
                'message' => 'Trop de demandes de code. Patientez quelques minutes.',
            ], 429);
        }
        RateLimiter::hit($rateKey, 600);

        $purpose = "phone_chg_{$user->id}";
        OtpCode::where('purpose', $purpose)
            ->where('is_used', false)
            ->update(['is_used' => true]);

        $plainCode = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        OtpCode::create([
            'phone' => $newPhone,
            'code_hash' => Hash::make($plainCode),
            'purpose' => $purpose,
            'expires_at' => now()->addMinutes(5),
        ]);

        $smsService->envoyerOtp($newPhone, $plainCode, 'phone_change');

        ActivityLog::create([
            'user_id' => $user->id,
            'action' => 'client_phone_change_otp_sent',
            'entity_type' => 'user',
            'entity_id' => $user->id,
            'properties' => ['new_phone_masked' => substr($newPhone, 0, 2).'****'.substr($newPhone, -3)],
            'ip_address' => $request->ip(),
            'severity' => 'info',
        ]);

        return response()->json([
            'succes' => true,
            'message' => 'Un code OTP de confirmation à 6 chiffres a été envoyé sur votre nouveau numéro.',
        ]);
    }

    /**
     * Étape 2 : Vérifie le code OTP reçu sur le nouveau numéro et met à jour `users.phone`.
     */
    public function confirmClientPhoneChange(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $normalized = $this->normalizeIvorianPhone((string) $request->input('phone', ''));
        $request->merge(['phone' => $normalized]);

        $validated = $request->validate([
            'phone' => [
                'required',
                'string',
                'regex:/^(01|05|07|21|25|27)[0-9]{8}$/',
                Rule::unique('users', 'phone')->ignore($user->id),
            ],
            'code' => ['required', 'string', 'regex:/^[0-9]{6}$/'],
        ], [
            'phone.unique' => 'Ce numéro de téléphone est déjà utilisé par un autre compte.',
            'code.regex' => 'Veuillez saisir le code OTP à 6 chiffres.',
        ]);

        $newPhone = (string) $validated['phone'];
        $code = (string) $validated['code'];
        $purpose = "phone_chg_{$user->id}";

        $otp = OtpCode::where('phone', $newPhone)
            ->where('purpose', $purpose)
            ->where('is_used', false)
            ->where('expires_at', '>', now())
            ->latest('id')
            ->first();

        if (! $otp) {
            return response()->json([
                'succes' => false,
                'message' => 'Code OTP expiré ou introuvable. Demandez un nouveau code.',
            ], 422);
        }

        if ($otp->attempts >= 3) {
            $otp->update(['is_used' => true]);

            return response()->json([
                'succes' => false,
                'message' => 'Trop de tentatives incorrectes. Veuillez demander un nouveau code OTP.',
            ], 422);
        }

        if (! Hash::check($code, $otp->code_hash)) {
            $otp->increment('attempts');
            if ($otp->attempts >= 3) {
                $otp->update(['is_used' => true]);
            }

            return response()->json([
                'succes' => false,
                'message' => 'Code OTP incorrect.',
            ], 422);
        }

        DB::transaction(function () use ($otp, $user, $newPhone, $request): void {
            $otp->update(['is_used' => true]);
            $user->update([
                'phone' => $newPhone,
                'phone_verified_at' => now(),
            ]);

            ActivityLog::create([
                'user_id' => $user->id,
                'action' => 'client_phone_changed',
                'entity_type' => 'user',
                'entity_id' => $user->id,
                'properties' => ['new_phone_masked' => substr($newPhone, 0, 2).'****'.substr($newPhone, -3)],
                'ip_address' => $request->ip(),
                'severity' => 'warning',
            ]);
        });

        RateLimiter::clear("client_phone_otp:{$user->id}");

        return response()->json([
            'succes' => true,
            'message' => 'Votre numéro de téléphone a été confirmé et mis à jour avec succès.',
            'donnees' => new ClientProfileResource($user->fresh()->load('city')),
        ]);
    }

    /**
     * Étape 1 : Envoie un code de confirmation à 6 chiffres à la nouvelle adresse email du client.
     */
    public function requestClientEmailChangeOtp(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $normalizedEmail = strtolower(trim((string) $request->input('email', '')));
        $request->merge(['email' => $normalizedEmail]);

        $validated = $request->validate([
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
        ], [
            'email.required' => 'Veuillez saisir une adresse email.',
            'email.email' => 'Veuillez saisir une adresse email valide.',
            'email.unique' => 'Cette adresse email est déjà utilisée par un autre compte.',
        ]);

        $newEmail = (string) $validated['email'];
        if ($user->email !== null && strtolower(trim((string) $user->email)) === $newEmail) {
            return response()->json([
                'succes' => false,
                'message' => 'Cette adresse email est déjà associée à votre compte.',
                'errors' => ['email' => ['Saisissez une adresse email différente de l\'actuelle.']],
            ], 422);
        }

        $rateKey = "client_email_otp:{$user->id}";
        if (RateLimiter::tooManyAttempts($rateKey, 5)) {
            return response()->json([
                'succes' => false,
                'message' => 'Trop de demandes de code. Patientez quelques minutes.',
            ], 429);
        }
        RateLimiter::hit($rateKey, 600);

        $purpose = "email_chg_{$user->id}";
        $emailKey = $this->emailOtpKey($newEmail);

        OtpCode::where('purpose', $purpose)
            ->where('is_used', false)
            ->update(['is_used' => true]);

        $plainCode = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        OtpCode::create([
            'phone' => $emailKey,
            'code_hash' => Hash::make($plainCode),
            'purpose' => $purpose,
            'expires_at' => now()->addMinutes(10),
        ]);

        Mail::raw(
            "GoLivreur : Votre code de confirmation d'adresse email est {$plainCode}. Ce code est valable 10 minutes.",
            static function ($message) use ($newEmail): void {
                $message->to($newEmail)->subject('GoLivreur — Code de confirmation email');
            },
        );

        ActivityLog::create([
            'user_id' => $user->id,
            'action' => 'client_email_change_otp_sent',
            'entity_type' => 'user',
            'entity_id' => $user->id,
            'properties' => ['email_domain' => substr(strrchr($newEmail, '@') ?: '', 1)],
            'ip_address' => $request->ip(),
            'severity' => 'info',
        ]);

        return response()->json([
            'succes' => true,
            'message' => 'Un code de confirmation à 6 chiffres a été envoyé à votre adresse email.',
        ]);
    }

    /**
     * Étape 2 : Vérifie le code de confirmation email et met à jour `users.email`.
     */
    public function confirmClientEmailChange(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $normalizedEmail = strtolower(trim((string) $request->input('email', '')));
        $request->merge(['email' => $normalizedEmail]);

        $validated = $request->validate([
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
            'code' => ['required', 'string', 'regex:/^[0-9]{6}$/'],
        ], [
            'email.email' => 'Veuillez saisir une adresse email valide.',
            'email.unique' => 'Cette adresse email est déjà utilisée par un autre compte.',
            'code.regex' => 'Veuillez saisir le code de confirmation à 6 chiffres.',
        ]);

        $newEmail = (string) $validated['email'];
        $code = (string) $validated['code'];
        $purpose = "email_chg_{$user->id}";
        $emailKey = $this->emailOtpKey($newEmail);

        $otp = OtpCode::where('phone', $emailKey)
            ->where('purpose', $purpose)
            ->where('is_used', false)
            ->where('expires_at', '>', now())
            ->latest('id')
            ->first();

        if (! $otp) {
            return response()->json([
                'succes' => false,
                'message' => 'Code de confirmation expiré ou introuvable. Demandez un nouveau code.',
            ], 422);
        }

        if ($otp->attempts >= 3) {
            $otp->update(['is_used' => true]);

            return response()->json([
                'succes' => false,
                'message' => 'Trop de tentatives incorrectes. Veuillez demander un nouveau code.',
            ], 422);
        }

        if (! Hash::check($code, $otp->code_hash)) {
            $otp->increment('attempts');
            if ($otp->attempts >= 3) {
                $otp->update(['is_used' => true]);
            }

            return response()->json([
                'succes' => false,
                'message' => 'Code de confirmation email incorrect.',
            ], 422);
        }

        DB::transaction(function () use ($otp, $user, $newEmail, $request): void {
            $otp->update(['is_used' => true]);
            $user->update(['email' => $newEmail]);

            ActivityLog::create([
                'user_id' => $user->id,
                'action' => 'client_email_changed',
                'entity_type' => 'user',
                'entity_id' => $user->id,
                'properties' => ['email_domain' => substr(strrchr($newEmail, '@') ?: '', 1)],
                'ip_address' => $request->ip(),
                'severity' => 'info',
            ]);
        });

        RateLimiter::clear("client_email_otp:{$user->id}");

        return response()->json([
            'succes' => true,
            'message' => 'Votre adresse email a été confirmée et enregistrée avec succès.',
            'donnees' => new ClientProfileResource($user->fresh()->load('city')),
        ]);
    }

    // ─── DRIVER / LIVREUR ─────────────────────────────────────────────────────

    public function showDriver(Request $request): JsonResponse
    {
        $user = $request->user()->fresh(['city', 'courier.city']);

        return response()->json([
            'succes' => true,
            'donnees' => new DriverProfileResource($user),
        ]);
    }

    public function updateDriver(UpdateDriverProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        $courier = $user->courier;

        if (! $courier) {
            return response()->json([
                'succes' => false,
                'message' => 'Fiche livreur introuvable pour ce compte.',
            ], 404);
        }

        $validated = $request->validated();

        DB::transaction(function () use ($user, $courier, $validated, $request) {
            $userData = $this->extractCommonUserData($validated);

            $courierData = [];
            foreach (['vehicle_type', 'vehicle_plate', 'id_card_number', 'driver_license_number'] as $field) {
                if (array_key_exists($field, $validated)) {
                    $courierData[$field] = $validated[$field];
                }
            }

            if ($request->hasFile('photo')) {
                $newPath = $this->replacePublicFile(
                    $request->file('photo'),
                    $courier->photo_path ?: $user->photo_path,
                    'livreurs'
                );
                $courierData['photo_path'] = $newPath;
                $userData['photo_path'] = $newPath;
            }

            if (! empty($userData)) {
                $user->update($userData);
            }

            if (! empty($courierData)) {
                $courier->update($courierData);
            }

            $this->logProfileUpdate($user, 'driver_profile_updated', $request->ip());
        });

        return response()->json([
            'succes' => true,
            'message' => 'Profil livreur mis à jour avec succès.',
            'donnees' => new DriverProfileResource($user->fresh()->load(['city', 'courier.city'])),
        ]);
    }

    // ─── BUSINESS / COMMERCE ──────────────────────────────────────────────────

    public function showBusiness(Request $request): JsonResponse
    {
        $user = $request->user()->fresh(['city', 'merchant.city', 'merchant.categoryModel']);

        return response()->json([
            'succes' => true,
            'donnees' => new BusinessProfileResource($user),
        ]);
    }

    public function updateBusiness(UpdateBusinessProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        $merchant = $user->merchant;

        if (! $merchant) {
            return response()->json([
                'succes' => false,
                'message' => 'Fiche commerce introuvable pour ce compte.',
            ], 404);
        }

        if (! $merchant->is_active) {
            return response()->json([
                'succes' => false,
                'message' => 'Votre compte commerce est suspendu ou inactif.',
            ], 403);
        }

        $validated = $request->validated();

        DB::transaction(function () use ($user, $merchant, $validated, $request) {
            $userData = $this->extractCommonUserData($validated);

            if ($request->hasFile('photo')) {
                $userData['photo_path'] = $this->replacePublicFile(
                    $request->file('photo'),
                    $user->photo_path,
                    'profils'
                );
            }

            if (! empty($userData)) {
                $user->update($userData);
            }

            $merchantData = [];
            if (array_key_exists('business_name', $validated)) {
                $merchantData['name'] = $validated['business_name'];
            } elseif (array_key_exists('merchant_name', $validated)) {
                $merchantData['name'] = $validated['merchant_name'];
            }

            foreach (['category', 'emoji', 'address', 'description', 'prep_time_minutes'] as $field) {
                if (array_key_exists($field, $validated)) {
                    $merchantData[$field] = $validated[$field];
                }
            }

            if (array_key_exists('open_hours', $validated)) {
                $openHours = $validated['open_hours'];
                if (is_string($openHours)) {
                    $decoded = json_decode($openHours, true);
                    $merchantData['open_hours'] = is_array($decoded)
                        ? $decoded
                        : ($openHours !== '' ? ['summary' => $openHours] : null);
                } else {
                    $merchantData['open_hours'] = $openHours;
                }
            }

            if ($request->hasFile('logo')) {
                $merchantData['logo_path'] = $this->replacePublicFile(
                    $request->file('logo'),
                    $merchant->logo_path,
                    'commerces/logos'
                );
            }

            if ($request->hasFile('merchant_photo')) {
                $merchantData['photo_path'] = $this->replacePublicFile(
                    $request->file('merchant_photo'),
                    $merchant->photo_path,
                    'commerces'
                );
            }

            if (! empty($merchantData)) {
                $merchant->update($merchantData);
            }

            $this->logProfileUpdate($user, 'business_profile_updated', $request->ip());
        });

        return response()->json([
            'succes' => true,
            'message' => 'Profil commerce mis à jour avec succès.',
            'donnees' => new BusinessProfileResource(
                $user->fresh()->load(['city', 'merchant.city', 'merchant.categoryModel'])
            ),
        ]);
    }

    // ─── MOT DE PASSE ─────────────────────────────────────────────────────────

    public function updatePassword(UpdatePasswordRequest $request): JsonResponse
    {
        $user = $request->user();
        $currentPassword = (string) $request->validated('current_password');

        if (! $user->password || ! Hash::check($currentPassword, $user->password)) {
            return response()->json([
                'succes' => false,
                'message' => 'Le mot de passe actuel est incorrect.',
                'errors' => [
                    'current_password' => ['Le mot de passe actuel est incorrect.'],
                ],
            ], 422);
        }

        $user->update([
            'password' => Hash::make((string) $request->validated('password')),
        ]);

        if ($user->mustChangePassword()) {
            $user->setMustChangePassword(false, $user->id);
        }

        $this->logProfileUpdate($user, 'profile_password_updated', $request->ip());

        return response()->json([
            'succes' => true,
            'message' => 'Mot de passe modifié avec succès.',
        ]);
    }

    // ─── HELPERS PRIVÉS ───────────────────────────────────────────────────────

    private function extractCommonUserData(array $validated): array
    {
        $fields = ['name', 'phone', 'email', 'language', 'notifications_enabled'];
        $data = [];

        foreach ($fields as $field) {
            if (array_key_exists($field, $validated)) {
                $data[$field] = $validated[$field];
            }
        }

        return $data;
    }

    /**
     * Enregistre le nouveau fichier sur le disque public et supprime l'ancien fichier s'il existe.
     */
    private function replacePublicFile(UploadedFile $file, ?string $oldPath, string $directory): string
    {
        $newPath = $file->store($directory, 'public');

        if ($oldPath && $oldPath !== $newPath && Storage::disk('public')->exists($oldPath)) {
            Storage::disk('public')->delete($oldPath);
        }

        return $newPath;
    }

    private function normalizeIvorianPhone(string $raw): string
    {
        $cleaned = preg_replace('/[\s\.\-\(\)]+/', '', trim($raw)) ?? '';
        if (str_starts_with($cleaned, '+225')) {
            return substr($cleaned, 4);
        }
        if (str_starts_with($cleaned, '00225')) {
            return substr($cleaned, 5);
        }
        if (strlen($cleaned) === 13 && str_starts_with($cleaned, '225')) {
            return substr($cleaned, 3);
        }

        return $cleaned;
    }

    /**
     * Clé déterministe de 20 caractères (compatible `otp_codes.phone VARCHAR(20)`)
     * pour rattacher un code de confirmation email sans aucune modification de schéma SQL.
     */
    private function emailOtpKey(string $normalizedEmail): string
    {
        return 'e:'.substr(hash('sha256', $normalizedEmail), 0, 18);
    }

    private function logProfileUpdate(User $user, string $action, ?string $ipAddress): void
    {
        ActivityLog::create([
            'user_id' => $user->id,
            'action' => $action,
            'entity_type' => 'user',
            'entity_id' => $user->id,
            'properties' => ['role' => $user->role],
            'ip_address' => $ipAddress,
            'severity' => 'info',
        ]);
    }
}
