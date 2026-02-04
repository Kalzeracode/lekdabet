<?php

namespace App\Http\Controllers\Api\Games;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\CategoryGame;
use App\Models\Game;
use App\Models\GameFavorite;
use App\Models\GameLike;
use App\Models\GamesKey;
use App\Models\Gateway;
use App\Models\Provider;
use App\Models\Wallet;
use App\Traits\Providers\PlayFiverTrait;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

class GameController extends Controller
{
    use PlayFiverTrait;

    /**
     * Listagem de provedores com jogos (somente ativos).
     * Cache: 24h
     */

    public function index()
    {
        // --- Server-side cache: 24h ---
        $ttl = now()->addDay();
        $cacheKey = 'pf:v5:providers_with_games_priority_min';

        $providers = Cache::remember($cacheKey, $ttl, function () {
            $orderRaw = "
                CASE
                    WHEN code = 'PGSOFT' THEN 0
                    WHEN code = 'PRAGMATIC' THEN 1
                    WHEN code = 'SPRIBE' THEN 2
                    WHEN code IN ('EVOLUTION_LIVE','EVOLUTION LIVE','OFICIAL - EVOLUTION LIVE')
                        OR name LIKE '%EVOLUTION LIVE%' OR name LIKE '%EVOLUTION%' THEN 3
                    WHEN code = 'FATPANDA' THEN 4
                    WHEN code IN ('PRAGMATIC_LIVE','PRAGMATIC LIVE','OFICIAL - PRAGMATIC LIVE')
                        OR name LIKE '%PRAGMATIC LIVE%' THEN 5
                    ELSE 99
                END
            ";

            return Provider::query()
                ->where('status', 1)
                ->whereHas('games', fn($q) => $q->where('status', 1))
                ->select(['id','cover','code','name','views','distribution'])
                ->with([
                    'games' => function ($q) {
                        $q->where('status', 1)
                        ->orderBy('views', 'desc')
                        ->select([
                            'id','provider_id','game_id','game_name','game_code',
                            'cover','views','distribution','original',
                        ]);
                    },
                ])
                ->orderByRaw($orderRaw)
                ->orderBy('name', 'asc') // resto A–Z
                ->get();
        });

        // --- ETag/304 ---
        // NOVO: incorpora a versão global de assets/cache-bust gerada no CacheController@nuke
        $ver = Cache::get('asset_version', 'v1'); // <- importante
        $etag = md5($ver . '|' . implode('|', $providers->map(
            fn ($p) => $p->id.'#'.$p->code.'#'.$p->name.'#'.$p->games->count()
        )->all()));

        if (request()->header('If-None-Match') === $etag) {
            return response('', 304)
                ->header('ETag', $etag)
                ->header('Cache-Control', 'public, max-age=86400');
        }

        // opcional: permitir bust manual via ?bust=1 (ignora 304 uma vez)
        if (request()->boolean('bust')) {
            return response()->json(['providers' => $providers])
                ->header('ETag', $etag)
                ->header('Cache-Control', 'no-cache, no-store, must-revalidate');
        }

        return response()->json(['providers' => $providers])
            ->header('ETag', $etag)
            ->header('Cache-Control', 'public, max-age=86400');
    }



    /**
     * Blocos por categorias (primeiros 12 por categoria + contagem).
     * Cache: 24h
     */
    public function gamesCategories()
    {
        $ttl = now()->addDay(); // 24h
        $cacheKey = 'pf:v1:games_by_categories';

        $gamesByCategory = Cache::remember($cacheKey, $ttl, function () {
            $payload = [];
            $categories = Category::query()
                ->select(['id','name','description','image','slug','url','created_at','updated_at'])
                ->orderBy('id')
                ->get();

            foreach ($categories as $category) {
                // Pega até 12 jogos ativos dessa categoria (com eager load do jogo)
                $firstTwelve = CategoryGame::query()
                    ->where('category_id', $category->id)
                    ->whereHas('game', function (Builder $q) {
                        $q->where('status', 1);
                    })
                    ->with(['game' => function ($q) {
                        $q->select([
                            'id','provider_id','game_server_url','game_id','game_name','game_code','game_type',
                            'description','cover','status','technology','has_lobby','is_mobile','has_freespins',
                            'has_tables','only_demo','rtp','distribution','views','is_featured','show_home',
                            'created_at','updated_at','original'
                        ]);
                    }])
                    ->limit(12)
                    ->get();

                if ($firstTwelve->isNotEmpty()) {
                    // Total de jogos ativos na categoria
                    $total = CategoryGame::query()
                        ->where('category_id', $category->id)
                        ->whereHas('game', function (Builder $q) {
                            $q->where('status', 1);
                        })
                        ->count();

                    // Monta a estrutura esperada (mantendo compatibilidade)
                    foreach ($firstTwelve as $rel) {
                        if ($rel->game) {
                            $payload[$category->name]['games'][$rel->game->game_name] = $rel->game;
                        }
                    }

                    $payload[$category->name]['quantidade']   = $total;
                    $payload[$category->name]['pagina']       = 1;
                    $payload[$category->name]['UPagina']      = ($total <= 12) ? 1 : null;
                    $payload[$category->name]['quantidadeA']  = isset($payload[$category->name]['games'])
                        ? count($payload[$category->name]['games'])
                        : 0;
                }
            }

            return $payload;
        });

        return response()->json(['games' => $gamesByCategory]);
    }


    /**
     * Jogos em destaque (is_featured = 1).
     * Cache: 24h + ETag (304)
     */
    public function featured(Request $request)
    {
        $ttl = now()->addDay(); // 24h
        $cacheKey = 'pf:v1:featured_games:min';

        // Cache do dataset já "enxuto" para reduzir payload
        $featured = Cache::remember($cacheKey, $ttl, function () {
            return Game::query()
                ->where('is_featured', 1)
                ->where('status', 1)
                ->with([
                    'provider' => function ($q) {
                        $q->select([
                            'id','cover','code','name',
                            // se precisar, adicione: 'distribution','rtp','views'
                        ]);
                    }
                ])
                ->select([
                    'id','provider_id','game_id','game_name','game_code',
                    'cover','distribution','views','is_featured','show_home',
                    'updated_at',
                ])
                ->orderByDesc('views')
                ->get()
                ->toArray(); // importante p/ hash do ETag ficar estável
        });

        // ETag baseado no conteúdo
        $etag = md5(json_encode($featured));

        // Se o cliente já tem a mesma versão, devolve 304
        if ($request->headers->get('If-None-Match') === $etag) {
            return response()->noContent(304)
                ->setEtag($etag)
                ->header('Cache-Control', 'public, max-age=86400'); // 24h
        }

        return response()
            ->json(['featured_games' => $featured])
            ->setEtag($etag)
            ->header('Cache-Control', 'public, max-age=86400'); // 24h
    }

    /**
     * Proxy para o provedor (sem cache – dependente de sessão/jogada).
     */
    public function sourceProvider(Request $request, $token, $action)
    {
        $tokenOpen = \Helper::DecToken($token);
        $validEndpoints = ['session', 'icons', 'spin', 'freenum'];

        if (in_array($action, $validEndpoints)) {
            if (isset($tokenOpen['status']) && $tokenOpen['status']) {
                $game = Game::whereStatus(1)->where('game_code', $tokenOpen['game'])->first();
                if (!empty($game)) {
                    $controller = \Helper::createController($game->game_code);

                    switch ($action) {
                        case 'session':
                            return $controller->session($token);
                        case 'spin':
                            return $controller->spin($request, $token);
                        case 'freenum':
                            return $controller->freenum($request, $token);
                        case 'icons':
                            return $controller->icons();
                    }
                }
            }
        } else {
            return response()->json([], 500);
        }
    }

    /**
     * Favoritos (sem cache – por usuário).
     */
    public function toggleFavorite($id)
    {
        if (auth('api')->check()) {
            $checkExist = GameFavorite::where('user_id', auth('api')->id())->where('game_id', $id)->first();
            if (!empty($checkExist)) {
                if ($checkExist->delete()) {
                    return response()->json(['status' => true, 'message' => 'Removido com sucesso']);
                }
            } else {
                $gameFavoriteCreate = GameFavorite::create([
                    'user_id' => auth('api')->id(),
                    'game_id' => $id
                ]);

                if ($gameFavoriteCreate) {
                    return response()->json(['status' => true, 'message' => 'Criado com sucesso']);
                }
            }
        }
    }

    /**
     * Likes (sem cache – por usuário).
     */
    public function toggleLike($id)
    {
        if (auth('api')->check()) {
            $checkExist = GameLike::where('user_id', auth('api')->id())->where('game_id', $id)->first();
            if (!empty($checkExist)) {
                if ($checkExist->delete()) {
                    return response()->json(['status' => true, 'message' => 'Removido com sucesso']);
                }
            } else {
                $gameLikeCreate = GameLike::create([
                    'user_id' => auth('api')->id(),
                    'game_id' => $id
                ]);

                if ($gameLikeCreate) {
                    return response()->json(['status' => true, 'message' => 'Criado com sucesso']);
                }
            }
        }
    }

    /**
     * Exibe/lança um jogo (sem cache – incrementa views e depende de login).
     */
    public function show(string $id)
    {
        $game = Game::with(['categories', 'provider'])->whereStatus(1)->find($id);
        if (!empty($game)) {
            if (Auth::guard("api")->check()) {
                $game->increment('views');

                $token = \Helper::MakeToken([
                    'id' => auth('api')->id(),
                    'game' => $game->game_code
                ]);

                switch ($game->distribution) {
                    case 'play_fiver':
                        $playfiver = self::playFiverLaunch($game->game_id, $game->only_demo);

                        if (isset($playfiver['launch_url'])) {
                            return response()->json([
                                'game' => $game,
                                'gameUrl' => $playfiver['launch_url'],
                                'token' => $token
                            ]);
                        }

                        return response()->json(['error' => $playfiver, 'status' => false], 400);
                }
            } else {
                return response()->json(['error' => 'Você precisa tá autenticado para jogar', 'status' => false], 400);
            }
        }
        return response()->json(['error' => '', 'status' => false], 500);
    }

    /**
     * Catálogo com filtros/pesquisa/paginação.
     * Cache: 24h por combinação de filtros (provider, category, searchTerm) + página.
     */
    public function allGames(Request $request)
    {
        // Normaliza filtros
        $provider   = $request->filled('provider') && $request->provider !== 'all' ? (int)$request->provider : 'all';
        $category   = $request->filled('category') && $request->category !== 'all' ? (string)$request->category : 'all';
        $searchTerm = trim((string)($request->searchTerm ?? ''));
        $page       = (int) $request->get('page', 1);

        // TTLs diferentes: busca muda mais
        $ttlNoSearch  = now()->addDay();         // 24h
        $ttlWithSearch= now()->addMinutes(20);   // 20min (ajuste se quiser)

        // Chave de cache estável por combinação de filtros + página
        $cacheKey = 'pf:v1:all_games:'.md5(json_encode([
            'provider'   => $provider,
            'category'   => $category,
            'searchTerm' => mb_strtolower($searchTerm),
            'page'       => $page,
        ]));

        $ttl = strlen($searchTerm) >= 3 ? $ttlWithSearch : $ttlNoSearch;

        $games = Cache::remember($cacheKey, $ttl, function () use ($provider, $category, $searchTerm, $page, $request) {
            $query = Game::query()
                ->with([
                    'provider:id,cover,code,name,status,rtp,views,distribution,created_at,updated_at',
                    'categories:id,name,slug'
                ])
                ->select([
                    'id','provider_id','game_server_url','game_id','game_name','game_code','game_type',
                    'description','cover','status','technology','has_lobby','is_mobile','has_freespins',
                    'has_tables','only_demo','rtp','distribution','views','is_featured','show_home',
                    'created_at','updated_at','original'
                ])
                ->where('status', 1);

            if ($provider !== 'all') {
                $query->where('provider_id', $provider);
            }

            if ($category !== 'all') {
                $query->whereHas('categories', function ($q) use ($category) {
                    $q->where('slug', $category);
                });
            }

            if (strlen($searchTerm) >= 3) {
                // mantém o seu helper whereLike
                $query->whereLike(['game_code','game_name','distribution','provider.name'], $searchTerm);
            } else {
                $query->orderBy('views', 'desc');
            }

            return $query->paginate(12)->appends($request->query());
        });

        // ETag leve: hash dos IDs desta página + meta
        $ids  = implode(',', collect($games->items())->pluck('id')->all());
        $etag = md5($ids.'|'.$games->currentPage().'|'.$games->lastPage());

        if (request()->header('If-None-Match') === $etag) {
            return response('', 304)->header('ETag', $etag);
        }

        // Browser/proxy cache (HTTP). Se quiser, reduza quando houver searchTerm.
        $maxAge = strlen($searchTerm) >= 3 ? 1200 : 86400; // 20min ou 24h
        return response()->json(['games' => $games])
            ->header('Cache-Control', "public, max-age={$maxAge}, stale-while-revalidate=60")
            ->header('ETag', $etag);
    }

    /**
     * Webhook PlayFiver (sem cache).
     */
    public function webhookPlayFiver(Request $request)
    {
        return self::webhookPlayFiverAPI($request);
    }

    public function webhookMoneyCallbackMethod(Request $request)
    {
        // Implementação futura se necessário
    }

    public function destroy(string $id)
    {
        //
    }
}
