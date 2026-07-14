<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use App\Http\Requests\Category\StoreCategoryRequest;
use App\Http\Requests\Category\UpdateCategoryRequest;
use App\Services\DefaultCategoryService;

class CategoryController extends Controller
{
    public function __construct(private readonly DefaultCategoryService $defaultCategoryService)
    {
    }

    /**
     * Display a listing of the resource.
     */
    public function index(): JsonResponse
    {
        if (request()->user()->categories()->doesntExist()) {
            $this->defaultCategoryService->ensureForUser(request()->user());
        }

        $query = request()->user()
            ->categories()
            ->latest();

        if (request()->has('type')) {
            $query->where('type', request('type'));
        }

        $categories = $query->get();

        return response()->json([
            'message' => 'Data kategori berhasil diambil',
            'data' => $categories,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreCategoryRequest $request): JsonResponse
    {
        $category = request()->user()->categories()->create([
            'name' => $request->name,
            'type' => $request->type,
            'icon' => $request->icon,
            'color' => $request->color,
            'is_default' => $request->is_default ?? false,
        ]);

        return response()->json([
            'message' => 'Kategori berhasil dibuat',
            'data' => $category,
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Category $category) : JsonResponse
    {
        $this->ensureCategoryBelongsToUser($category);

        return response()->json([
            'message' => 'Detail kategori berhasil diambil',
            'data' => $category,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateCategoryRequest $request, Category $category): JsonResponse
    {
        $this->ensureCategoryBelongsToUser($category);

        $category->update($request->validated());

        return response()->json([
            'message' => 'Kategori berhasil diupdate',
            'data' => $category->fresh(),
        ]);
    }
    

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Category $category): JsonResponse
    {
        $this->ensureCategoryBelongsToUser($category);

        $category->delete();

        return response()->json([
            'message' => 'Kategori berhasil dihapus',
        ]);
    }

    private function ensureCategoryBelongsToUser(Category $category): void
    {
        if ($category->user_id !== request()->user()->id) {
            abort(403, 'Kamu tidak punya akses ke kategori ini');
        }
    }
}
