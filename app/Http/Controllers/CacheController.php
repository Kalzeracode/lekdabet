<?php

namespace App\Http\Controllers;

use Illuminate\Cache\TaggableStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

class CacheController extends Controller
{
    public function nuke(Request $request)
    {
        $this->authorize('admin');

        $deep          = filter_var($request->input('deep', true), FILTER_VALIDATE_BOOLEAN);
        $clearSessions = filter_var($request->input('sessions', true), FILTER_VALIDATE_BOOLEAN);
        $clearQueues   = filter_var($request->input('queues', true), FILTER_VALIDATE_BOOLEAN);

        $report = [
            'env' => [
                'cache_driver'   => config('cache.default'),
                'session_driver' => config('session.driver'),
                'queue_driver'   => config('queue.default'),
            ],
            'artisan'            => [],
            'cache_fixed_forget' => 0,
            'cache_pattern_del'  => 0,
            'cache_files_del'    => 0,
            'view_files_del'     => 0,
            'bootstrap_del'      => 0,
            'session_del'        => 0,
            'queue_del'          => 0,
            'tags_flushed'       => false,
            'asset_version'      => null,
            'opcache_reset'      => false,
        ];

        // base
        $report['artisan'][] = Artisan::call('optimize:clear');
        $report['artisan'][] = Artisan::call('event:clear');

        // limpa chaves do app (as usadas nos controllers indicados)
        $this->clearAppCaches($report);

        // arquivos locais (quando deep = true)
        if ($deep) {
            $report['view_files_del']  += $this->purgeDir(base_path('storage/framework/views'));
            $report['cache_files_del'] += $this->purgeDir(base_path('storage/framework/cache/data'));
            $report['bootstrap_del']   += $this->purgeDir(base_path('bootstrap/cache'), ['.gitignore']);
        }

        if ($clearSessions) {
            $report['session_del'] += $this->clearSessionsByDriver();
        }

        if ($clearQueues) {
            $report['queue_del'] += $this->clearQueuesByDriver();
        }

        // força recarregar assets no front
        $assetVersion = (string) Str::uuid();
        Cache::forever('asset_version', $assetVersion);
        $report['asset_version'] = $assetVersion;

        if (function_exists('opcache_reset')) {
            @opcache_reset();
            $report['opcache_reset'] = true;
        }

        return back()
            ->with('status', 'Cache limpo com sucesso!')
            ->with('cache_report', $report);
    }

    private function clearAppCaches(array &$report): void
    {
        // 1) Tags (se suportado pelo driver)
        if (Cache::getStore() instanceof TaggableStore) {
            Cache::tags(['providers','categories','games','missions','settings'])->flush();
            $report['tags_flushed'] = true;
        }

        // 2) Chaves fixas (forget direto)
        $fixed = [
            // SettingsController
            'api:settings:index:v1',

            // GameController
            'pf:v5:providers_with_games_priority_min',
            'pf:v1:games_by_categories',
            'pf:v1:featured_games:min',

            // CategoryController
            'pf:v1:categories:list',
        ];

        foreach ($fixed as $k) {
            if (Cache::forget($k)) {
                $report['cache_fixed_forget']++;
            }
        }

        // 3) Padrões (para Redis) — allGames e categories show*
        if (config('cache.default') === 'redis') {
            $redis = $this->getRedisConnectionForCache();
            if ($redis) {
                $patterns = [
                    // GameController@allGames (todas as combinações)
                    'pf:v1:all_games:*',

                    // CategoryController@show (id/slug variáveis)
                    'pf:v1:categories:show:id:*',
                    'pf:v1:categories:show:slug:*',
                ];

                foreach ($patterns as $pat) {
                    $keys = $redis->keys($pat);
                    foreach ($keys as $key) {
                        $redis->del($key);
                        $report['cache_pattern_del']++;
                    }
                }
            }
        }
        // Em FILE driver não há wildcard; a limpeza de “deep” acima remove os arquivos do store.
        // Se quiser limpar esses padrões também no FILE sem deep, uma estratégia é manter
        // um "índice" de keys gravadas. Mas o deep resolve na prática.
    }

    private function clearSessionsByDriver(): int
    {
        $driver  = config('session.driver');
        $deleted = 0;

        if ($driver === 'file') {
            return $this->purgeDir(storage_path('framework/sessions'));
        }

        if ($driver === 'redis') {
            $connName     = config('session.connection') ?: 'default';
            $sessionName  = config('session.cookie', 'laravel_session');
            $globalPrefix = config('database.redis.options.prefix', '');
            $redis = Redis::connection($connName);

            $patterns = [
                "{$globalPrefix}{$sessionName}:*",
                "{$sessionName}:*",
                "{$globalPrefix}sessions:*",
                "sessions:*",
            ];

            foreach ($patterns as $pat) {
                $keys = $redis->keys($pat);
                foreach ($keys as $k) {
                    $redis->del($k);
                    $deleted++;
                }
            }
        }

        return $deleted;
    }

    private function clearQueuesByDriver(): int
    {
        $driver  = config('queue.default');
        $deleted = 0;

        if ($driver === 'sync') return 0;

        if ($driver === 'redis') {
            $connName = config('queue.connections.redis.connection', 'default');
            $queue    = config('queue.connections.redis.queue', 'default');
            $prefix   = config('database.redis.options.prefix', '');

            $patterns = [
                "{$prefix}queues:{$queue}*",
                "{$prefix}queues:*",
                "queues:{$queue}*",
                "queues:*",
            ];

            $redis = Redis::connection($connName);
            foreach ($patterns as $pat) {
                $keys = $redis->keys($pat);
                foreach ($keys as $k) {
                    $redis->del($k);
                    $deleted++;
                }
            }
        }

        return $deleted;
    }

    private function purgeDir(string $dir, array $keep = []): int
    {
        if (!is_dir($dir)) return 0;
        $deleted = 0;
        foreach (File::allFiles($dir) as $file) {
            if (in_array($file->getFilename(), $keep, true)) continue;
            try {
                File::delete($file->getRealPath());
                $deleted++;
            } catch (\Throwable $e) {}
        }
        return $deleted;
    }

    private function getRedisConnectionForCache()
    {
        $store  = config('cache.default');
        $stores = config('cache.stores');

        if (!isset($stores[$store])) return null;
        $conf = $stores[$store];

        if (($conf['driver'] ?? null) !== 'redis') return null;

        $connectionName = $conf['connection'] ?? 'default';
        try {
            return Redis::connection($connectionName);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
