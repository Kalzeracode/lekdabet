<?php

namespace App\Http\Controllers\Api\Categories;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class CategoryController extends Controller
{
    /**
     * Lista categorias com cache longo (30 dias) + ETag (304).
     */
    public function index(Request $request)
    {
        $ttl = now()->addDays(30);
        $cacheKey = 'pf:v1:categories:list';

        $categories = Cache::remember($cacheKey, $ttl, function () {
            return Category::query()
                ->select(['id','name','slug','image','url','description','created_at','updated_at'])
                ->orderBy('name', 'asc')
                ->get()
                ->toArray();
        });

        // ETag baseado no conteúdo (ids + updated_at já entram no json)
        $etag = md5(json_encode($categories));

        if ($request->headers->get('If-None-Match') === $etag) {
            return response()->noContent(304);
        }

        return response()
            ->json(['categories' => $categories])
            ->setEtag($etag)
            ->header('Cache-Control', 'public, max-age=2592000'); // 30 dias
    }

    /**
     * Detalhe de categoria (por id ou slug), também com cache e ETag.
     */
    public function show(Request $request, string $idOrSlug)
    {
        $ttl = now()->addDays(30);
        $isNumeric = ctype_digit($idOrSlug);

        $cacheKey = $isNumeric
            ? "pf:v1:categories:show:id:{$idOrSlug}"
            : "pf:v1:categories:show:slug:{$idOrSlug}";

        $category = Cache::remember($cacheKey, $ttl, function () use ($idOrSlug, $isNumeric) {
            $q = Category::query()
                ->select(['id','name','slug','image','url','description','created_at','updated_at']);

            return $isNumeric
                ? $q->find($idOrSlug)
                : $q->where('slug', $idOrSlug)->first();
        });

        if (!$category) {
            return response()->json(['message' => 'Categoria não encontrada.'], 404);
        }

        $etag = md5(json_encode($category));

        if ($request->headers->get('If-None-Match') === $etag) {
            return response()->noContent(304);
        }

        return response()
            ->json(['category' => $category])
            ->setEtag($etag)
            ->header('Cache-Control', 'public, max-age=2592000');
    }

    // Lembre: se criar/editar/excluir categoria no admin,
    // chame Cache::forget('pf:v1:categories:list') e o respectivo show.
    public function create() {}
    public function store(Request $request) {}
    public function edit(string $id) {}
    public function update(Request $request, string $id) {}
    public function destroy(string $id) {}
}
