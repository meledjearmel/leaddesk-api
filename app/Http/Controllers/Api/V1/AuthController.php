<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Fortify\CreateNewUser;
use App\Http\Resources\V1\UserResource;
use App\Models\User;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

/**
 * Comptes et jetons de l'application de bureau.
 */
#[Group('Comptes et jetons', weight: 1)]
class AuthController extends ApiController
{
    /**
     * Créer un compte.
     *
     * Crée le compte du CEO et délivre le jeton de l'appareil.
     *
     * @unauthenticated
     */
    public function register(Request $request, CreateNewUser $createUser): JsonResponse
    {
        $device = $request->validate(['device_name' => ['required', 'string', 'max:100']]);

        /** @var array<string, string> $input */
        $input = $request->only(['name', 'email', 'password', 'password_confirmation']);
        $user = $createUser->create($input);

        return $this->tokenResponse($user, $device['device_name'], Response::HTTP_CREATED);
    }

    /**
     * Se connecter.
     *
     * Délivre un jeton pour l'appareil. Le message d'erreur est le même que
     * l'e-mail soit inconnu ou le mot de passe faux.
     *
     * @unauthenticated
     */
    public function token(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:100'],
        ]);

        $user = User::query()->where('email', $credentials['email'])->first();

        if ($user === null || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => [__('auth.failed')],
            ]);
        }

        return $this->tokenResponse($user, $credentials['device_name']);
    }

    /**
     * Mot de passe oublié.
     *
     * Envoie par e-mail un lien vers la page web de réinitialisation. La
     * réponse (202) est la même que l'adresse corresponde ou non à un compte,
     * pour ne rien dévoiler.
     *
     * @unauthenticated
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'string', 'email', 'max:255']]);

        Password::sendResetLink(['email' => $data['email']]);

        return response()->json([
            'message' => 'Si un compte correspond à cette adresse, un lien de réinitialisation vient d\'y être envoyé.',
        ], Response::HTTP_ACCEPTED);
    }

    /**
     * Lire le compte connecté.
     */
    public function show(Request $request): UserResource
    {
        return new UserResource($this->user($request)->load('company'));
    }

    /**
     * Se déconnecter.
     *
     * Révoque le jeton de l'appareil appelant ; les autres appareils restent connectés.
     */
    public function destroy(Request $request): Response
    {
        $this->user($request)->currentAccessToken()->delete();

        return response()->noContent();
    }

    private function tokenResponse(User $user, string $deviceName, int $status = Response::HTTP_OK): JsonResponse
    {
        return response()->json([
            'token' => $user->createToken($deviceName)->plainTextToken,
            'user' => new UserResource($user->load('company')),
        ], $status);
    }
}
