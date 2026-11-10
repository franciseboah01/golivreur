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
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

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
            'client'            => $this->showClient($request),
            'driver', 'courier' => $this->showDriver($request),
            'merchant'          => $this->showBusiness($request),
            default             => response()->json([
                'succes'  => true,
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
            'client'            => $this->updateClient(app(UpdateClientProfileRequest::class)),
            'driver', 'courier' => $this->updateDriver(app(UpdateDriverProfileRequest::class)),
            'merchant'          => $this->updateBusiness(app(UpdateBusinessProfileRequest::class)),
            default             => response()->json([
                'succes'  => false,
                'message' => 'Rôle non supporté pour cet endpoint.',
            ], 403),
        };
    }

    // ─── CLIENT ───────────────────────────────────────────────────────────────

    public function showClient(Request $request): JsonResponse
    {
        $user = $request->user()->fresh(['city']);

        return response()->json([
            'succes'  => true,
            'donnees' => new ClientProfileResource($user),
        ]);
    }

    public function updateClient(UpdateClientProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validated();

        DB::transaction(function () use ($user, $validated, $request) {
            $userData = $this->extractCommonUserData($validated);

            if (array_key_exists('default_address', $validated)) {
                $userData['default_address'] = $validated['default_address'];
            }

            if ($request->hasFile('photo')) {
                $userData['photo_path'] = $this->replacePublicFile(
                    $request->file('photo'),
                    $user->photo_path,
                    'profils'
                );
            }

            if (!empty($userData)) {
                $user->update($userData);
            }

            $this->logProfileUpdate($user, 'client_profile_updated', $request->ip());
        });

        return response()->json([
            'succes'  => true,
            'message' => 'Profil client mis à jour avec succès.',
            'donnees' => new ClientProfileResource($user->fresh()->load('city')),
        ]);
    }

    // ─── DRIVER / LIVREUR ─────────────────────────────────────────────────────

    public function showDriver(Request $request): JsonResponse
    {
        $user = $request->user()->fresh(['city', 'courier.city']);

        return response()->json([
            'succes'  => true,
            'donnees' => new DriverProfileResource($user),
        ]);
    }

    public function updateDriver(UpdateDriverProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        $courier = $user->courier;

        if (!$courier) {
            return response()->json([
                'succes'  => false,
                'message' => 'Fiche livreur introuvable pour ce compte.',
            ], 404);
        }

        $validated = $request->validated();

        DB::transaction(function () use ($user, $courier, $validated, $request) {
            $userData = $this->extractCommonUserData($validated);

            $courierData = [];
            foreach (['city_id', 'vehicle_type', 'vehicle_plate', 'id_card_number', 'driver_license_number'] as $field) {
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

            if (!empty($userData)) {
                $user->update($userData);
            }

            if (!empty($courierData)) {
                $courier->update($courierData);
            }

            $this->logProfileUpdate($user, 'driver_profile_updated', $request->ip());
        });

        return response()->json([
            'succes'  => true,
            'message' => 'Profil livreur mis à jour avec succès.',
            'donnees' => new DriverProfileResource($user->fresh()->load(['city', 'courier.city'])),
        ]);
    }

    // ─── BUSINESS / COMMERCE ──────────────────────────────────────────────────

    public function showBusiness(Request $request): JsonResponse
    {
        $user = $request->user()->fresh(['city', 'merchant.city', 'merchant.categoryModel']);

        return response()->json([
            'succes'  => true,
            'donnees' => new BusinessProfileResource($user),
        ]);
    }

    public function updateBusiness(UpdateBusinessProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        $merchant = $user->merchant;

        if (!$merchant) {
            return response()->json([
                'succes'  => false,
                'message' => 'Fiche commerce introuvable pour ce compte.',
            ], 404);
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

            if (!empty($userData)) {
                $user->update($userData);
            }

            $merchantData = [];
            if (array_key_exists('business_name', $validated)) {
                $merchantData['name'] = $validated['business_name'];
            } elseif (array_key_exists('merchant_name', $validated)) {
                $merchantData['name'] = $validated['merchant_name'];
            }

            foreach (['city_id', 'category', 'emoji', 'address', 'description', 'prep_time_minutes'] as $field) {
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

            if (!empty($merchantData)) {
                $merchant->update($merchantData);
            }

            $this->logProfileUpdate($user, 'business_profile_updated', $request->ip());
        });

        return response()->json([
            'succes'  => true,
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

        if (!$user->password || !Hash::check($currentPassword, $user->password)) {
            return response()->json([
                'succes'  => false,
                'message' => 'Le mot de passe actuel est incorrect.',
                'errors'  => [
                    'current_password' => ['Le mot de passe actuel est incorrect.'],
                ],
            ], 422);
        }

        $user->update([
            'password' => Hash::make((string) $request->validated('password')),
        ]);

        $this->logProfileUpdate($user, 'profile_password_updated', $request->ip());

        return response()->json([
            'succes'  => true,
            'message' => 'Mot de passe modifié avec succès.',
        ]);
    }

    // ─── HELPERS PRIVÉS ───────────────────────────────────────────────────────

    private function extractCommonUserData(array $validated): array
    {
        $fields = ['name', 'phone', 'email', 'city_id', 'language', 'notifications_enabled'];
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

    private function logProfileUpdate(User $user, string $action, ?string $ipAddress): void
    {
        ActivityLog::create([
            'user_id'     => $user->id,
            'action'      => $action,
            'entity_type' => 'user',
            'entity_id'   => $user->id,
            'properties'  => ['role' => $user->role],
            'ip_address'  => $ipAddress,
            'severity'    => 'info',
        ]);
    }
}
