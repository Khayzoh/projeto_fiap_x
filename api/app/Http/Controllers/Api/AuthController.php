<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Models\User;
use App\Support\Jwt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autenticacao por usuario e senha, emitindo JWT para as rotas protegidas.
 */
class AuthController extends Controller
{
    public function __construct(private readonly Jwt $jwt) {}

    public function register(RegisterRequest $request): JsonResponse
    {
        $dados = $request->validated();

        $user = User::create([
            'name' => $dados['name'],
            'email' => $dados['email'],
            'password' => Hash::make($dados['password']),
        ]);

        Log::info('usuario cadastrado', ['user_id' => $user->id]);

        return response()->json([
            'user' => $this->serializeUser($user),
            'token' => $this->jwt->issue($user->id, ['email' => $user->email]),
            'token_type' => 'Bearer',
            'expires_in' => $this->jwt->ttlSeconds(),
        ], Response::HTTP_CREATED);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $dados = $request->validated();
        $user = User::where('email', $dados['email'])->first();

        // Hash::check mesmo com usuario inexistente evita revelar, pelo tempo de
        // resposta, se um e-mail esta ou nao cadastrado.
        if ($user === null || ! Hash::check($dados['password'], $user->password)) {
            return response()->json([
                'message' => 'Credenciais invalidas.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        return response()->json([
            'user' => $this->serializeUser($user),
            'token' => $this->jwt->issue($user->id, ['email' => $user->email]),
            'token_type' => 'Bearer',
            'expires_in' => $this->jwt->ttlSeconds(),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => $this->serializeUser($request->user()),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeUser(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
        ];
    }
}
