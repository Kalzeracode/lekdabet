<?php

namespace App\Http\Controllers\Api\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SettingController extends Controller
{
    // ajuste fino do cache de resposta (cliente) e do cache interno (servidor)
    private const CLIENT_MAX_AGE_SECONDS = 300; // 5min para browsers/proxies revalidarem com ETag
    private const SERVER_CACHE_TTL       = 86400; // 24h para o objeto em si (revalida pelo fingerprint)

    /**
     * GET /api/settings
     *
     * Cache com verificação de mudança:
     * - Compara o fingerprint (MAX(updated_at) da tabela `settings`) com o salvo no cache.
     * - Se mudou, reconstrói e salva nova versão.
     * - Sempre envia ETag e Last-Modified; retorna 304 se cliente tiver a mesma ETag/LM.
     */
    public function index(Request $request)
    {
        $cacheKey = 'api:settings:index:v2'; // bump na versão da chave ao alterar formato

        // fingerprint atual do "mundo real"
        $fingerprint = $this->currentFingerprint(); // string tipo "173.../YYYYmmddHHMMSS" etc.

        // tenta pegar do cache
        $cached = Cache::get($cacheKey);

        // se não existe cache OU fingerprint mudou, reconstrói
        if (!$cached || ($cached['fingerprint'] ?? null) !== $fingerprint) {
            // evita thundering herd (opcional)
            $lock = Cache::lock('lock:'.$cacheKey, 5);
            try {
                if ($lock->get()) {
                    // revalida dentro do lock (outro processo pode ter populado)
                    $cached = Cache::get($cacheKey);
                    if (!$cached || ($cached['fingerprint'] ?? null) !== $fingerprint) {
                        $setting = \Helper::getSetting();

                        $payload = ['setting' => $setting];

                        // ETag com base no conteúdo + fingerprint (mais barato de comparar)
                        $etag = '"'.md5($fingerprint . '|' . json_encode($payload)).'"';

                        // Last-Modified baseado no fingerprint (tentamos converter para timestamp)
                        $lastModified = $this->fingerprintToHttpDate($fingerprint);

                        $cached = [
                            'fingerprint'   => $fingerprint,
                            'payload'       => $payload,
                            'etag'          => $etag,
                            'last_modified' => $lastModified,
                        ];

                        Cache::put($cacheKey, $cached, self::SERVER_CACHE_TTL);
                    }
                } else {
                    // sem lock: fallback rápido ao cache existente (se houver)
                    $cached = $cached ?? [
                        'fingerprint'   => $fingerprint,
                        'payload'       => ['setting' => \Helper::getSetting()],
                        'etag'          => '"'.md5($fingerprint).'"',
                        'last_modified' => $this->fingerprintToHttpDate($fingerprint),
                    ];
                }
            } finally {
                optional($lock)->release();
            }
        }

        // Condicionais do cliente (revalidação leve)
        $ifNoneMatch     = $request->header('If-None-Match');
        $ifModifiedSince = $request->header('If-Modified-Since');

        // Se o cliente tem a mesma ETag, 304
        if ($ifNoneMatch && trim($ifNoneMatch) === $cached['etag']) {
            return response('', 304)
                ->header('ETag', $cached['etag'])
                ->header('Last-Modified', $cached['last_modified'])
                ->header('Cache-Control', $this->clientCacheControl());
        }

        // Ou se manda If-Modified-Since >= last_modified, 304
        if ($ifModifiedSince && $this->httpDateToTimestamp($ifModifiedSince) >= $this->httpDateToTimestamp($cached['last_modified'])) {
            return response('', 304)
                ->header('ETag', $cached['etag'])
                ->header('Last-Modified', $cached['last_modified'])
                ->header('Cache-Control', $this->clientCacheControl());
        }

        // Resposta JSON com ETag/Last-Modified
        return response()
            ->json($cached['payload'], 200, [], JSON_UNESCAPED_UNICODE)
            ->header('ETag', $cached['etag'])
            ->header('Last-Modified', $cached['last_modified'])
            ->header('Cache-Control', $this->clientCacheControl());
    }

    /**
     * Invalida o cache (útil após alterações no admin).
     * Proteja com auth/policy/ability.
     */
    public function bust()
    {
        Cache::forget('api:settings:index:v2');
        return response()->json(['ok' => true]);
    }

    /* ===================== Helpers ====================== */

    /**
     * Gera um "fingerprint" barato de calcular que muda quando as settings mudam.
     * Aqui: MAX(updated_at) da tabela `settings`. Ajuste conforme seu schema.
     */
    private function currentFingerprint(): string
    {
        // Se tiver um método dedicado:
        // return (string) \Helper::getSettingVersion();

        // Exemplo usando DB: supondo tabela `settings` com coluna updated_at
        $maxUpdated = DB::table('settings')->max('updated_at'); // Carbon|string|null

        if ($maxUpdated) {
            $ts = is_string($maxUpdated) ? strtotime($maxUpdated) : $maxUpdated->getTimestamp();
            return 'settings:' . $ts; // e.g. "settings:173...”
        }

        // fallback (não deve acontecer): muda 1x por dia só para não “colar”
        return 'settings:no-updated:' . now()->format('Ymd');
    }

    /**
     * Converte fingerprint em data HTTP (Last-Modified).
     */
    private function fingerprintToHttpDate(string $fp): string
    {
        // espera formato 'settings:{timestamp}'
        if (Str::contains($fp, ':')) {
            $parts = explode(':', $fp, 2);
            $maybeTs = (int) $parts[1];
            if ($maybeTs > 0) {
                return gmdate('D, d M Y H:i:s', $maybeTs) . ' GMT';
            }
        }
        return gmdate('D, d M Y H:i:s') . ' GMT';
        // opcional: armazenar a data no cache junto com payload e reaproveitar
    }

    /**
     * Parse HTTP date → timestamp (para If-Modified-Since).
     */
    private function httpDateToTimestamp(?string $httpDate): int
    {
        if (!$httpDate) return 0;
        $ts = strtotime($httpDate);
        return $ts !== false ? $ts : 0;
    }

    /**
     * Política de cache para clientes (navegador/proxy).
     * max-age curto + stale-while-revalidate para UX boa.
     */
    private function clientCacheControl(): string
    {
        $maxAge = self::CLIENT_MAX_AGE_SECONDS;
        // clientes podem usar resposta por até 5min e revalidar via ETag;
        // proxies (se houver) idem; stale-while-revalidate permite servir “stale” enquanto revalida.
        return "public, max-age={$maxAge}, s-maxage={$maxAge}, stale-while-revalidate=300";
    }

    /* ===== stubs gerados pelo artisan (não usados) ===== */
    public function create() {}
    public function store(Request $request) {}
    public function show(string $id) {}
    public function edit(string $id) {}
    public function update(Request $request, string $id) {}
    public function destroy(string $id) {}
}
