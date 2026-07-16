<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Services\DefaultCategoryService;

class AuthController extends Controller
{
    public function __construct(private readonly DefaultCategoryService $defaultCategoryService)
    {
    }

    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'timezone' => 'Asia/Jakarta',
            'base_currency' => 'IDR',
        ]);

        $this->defaultCategoryService->ensureForUser($user);

        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'message' => 'Register berhasil',
            'token' => $token,
            'user' => $user,
        ], 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        if (!Auth::attempt($request->only('email', 'password'))) {
            return response()->json([
                'message' => 'Email atau password salah',
            ], 422);
        }

        /** @var User $user */
        $user = Auth::user();

        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'message' => 'Login berhasil',
            'token' => $token,
            'user' => $user,
        ]);
    }

    public function me(): JsonResponse
    {
        return response()->json([
            'user' => request()->user(),
        ]);
    }

    public function logout(): JsonResponse
    {
        request()->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logout berhasil',
        ]);
    }
}
