<?php

namespace App\Http\Controllers;

use App\Models\Mission;
use App\Models\MissionUser;
use App\Models\Order;
use App\Models\Transaction;
use App\Models\Wallet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class MissionController extends Controller
{
    /**
     * Lista missões com progresso do usuário.
     * - Missões ativas (metadados): cache 24h
     * - Agregados do usuário (hoje): cache 60s por usuário
     */
    public function index()
    {
        $user = auth('api')->user();
        if (!$user) {
            return response()->json(['message' => 'Usuário não autenticado.'], 401);
        }

        $today = Carbon::today()->toDateString();

        // 1) Cache das missões ativas (estático)
        $missions = Cache::remember(
            'missions:v1:active:list',
            now()->addDay(),
            function () {
                return Mission::query()
                    ->where('status', 'active')
                    ->select([
                        'id', 'title', 'description', 'type',
                        'target_amount', 'reward', 'image', 'game_id', 'status'
                    ])
                    ->orderBy('id')
                    ->get();
            }
        );

        // Early return se não há missões ativas
        if ($missions->isEmpty()) {
            return response()->json([]);
        }

        // 2) Cache dos agregados de HOJE por usuário (60s)
        $aggKey = "missions:v1:agg:u:{$user->id}:{$today}";

        $agg = Cache::remember($aggKey, 60, function () use ($user, $today) {
            $start = Carbon::parse($today)->startOfDay();
            $end   = Carbon::parse($today)->endOfDay();

            // --- ORDERS (bets/wins) group by game ---
            // sums por type e game
            $ordersByGame = Order::query()
                ->where('user_id', $user->id)
                ->whereBetween('created_at', [$start, $end])
                ->select([
                    'game',
                    DB::raw("SUM(CASE WHEN type = 'bet' THEN amount ELSE 0 END) AS sum_bet"),
                    DB::raw("SUM(CASE WHEN type = 'win' THEN amount ELSE 0 END) AS sum_win"),
                    DB::raw("SUM(CASE WHEN type IN ('bet','win') THEN 1 ELSE 0 END) AS rounds_cnt")
                ])
                ->groupBy('game')
                ->get();

            $sumBetByGame  = [];
            $sumWinByGame  = [];
            $roundsByGame  = [];

            foreach ($ordersByGame as $row) {
                $g = (string)$row->game;
                $sumBetByGame[$g] = (float) $row->sum_bet;
                $sumWinByGame[$g] = (float) $row->sum_win;
                $roundsByGame[$g] = (int) $row->rounds_cnt;
            }

            // total de bet+win no dia (para missões total_bet)
            $sumTotalBetWin = (float) Order::query()
                ->where('user_id', $user->id)
                ->whereBetween('created_at', [$start, $end])
                ->whereIn('type', ['bet', 'win'])
                ->sum('amount');

            // depósitos do dia
            $sumDeposits = (float) Transaction::query()
                ->where('user_id', $user->id)
                ->whereBetween('created_at', [$start, $end])
                ->sum('price');

            return [
                'sumBetByGame'   => $sumBetByGame,
                'sumWinByGame'   => $sumWinByGame,
                'roundsByGame'   => $roundsByGame,
                'sumTotalBetWin' => $sumTotalBetWin,
                'sumDeposits'    => $sumDeposits,
            ];
        });

        // 3) Monta resposta usando os agregados em memória
        $result = $missions->map(function ($mission) use ($agg) {
            $currentProgress = 0.0;
            $gameId = (string) ($mission->game_id ?? '');

            switch ($mission->type) {
                case 'game_bet':        // soma de bets no jogo
                    $currentProgress = $agg['sumBetByGame'][$gameId] ?? 0.0;
                    break;

                case 'total_bet':       // soma de bet + win do dia (todos os jogos)
                    $currentProgress = $agg['sumTotalBetWin'] ?? 0.0;
                    break;

                case 'deposit':         // soma de depósitos do dia
                    $currentProgress = $agg['sumDeposits'] ?? 0.0;
                    break;

                case 'rounds_played':   // contagem de rounds (bet+win) no jogo
                    $currentProgress = (float) ($agg['roundsByGame'][$gameId] ?? 0);
                    break;

                case 'win_amount':      // soma de wins no jogo
                    $currentProgress = $agg['sumWinByGame'][$gameId] ?? 0.0;
                    break;

                case 'loss_amount':     // perdas = bet - win (não negativo)
                    $bets = $agg['sumBetByGame'][$gameId] ?? 0.0;
                    $wins = $agg['sumWinByGame'][$gameId] ?? 0.0;
                    $currentProgress = max(0.0, $bets - $wins);
                    break;

                default:
                    $currentProgress = 0.0;
            }

            $target = (float) $mission->target_amount;
            $progressPct = $target > 0
                ? min(($currentProgress / $target) * 100.0, 100.0)
                : 0.0;

            return [
                'id'            => $mission->id,
                'title'         => $mission->title,
                'description'   => $mission->description,
                'type'          => $mission->type,
                'target_amount' => (float) $mission->target_amount,
                'reward'        => (float) $mission->reward,
                'image'         => $mission->image,
                'progress'      => round($progressPct, 2),
                'completed'     => $progressPct >= 100.0,
            ];
        });

        return response()->json($result);
    }

    /**
     * Atualiza o progresso de uma missão.
     * (Mantida a lógica original; otimizada com selects e whereBetween)
     */
    public function updateProgress($missionId)
    {
        $user = auth('api')->user();
        if (!$user) {
            return response()->json(['message' => 'Usuário não autenticado.'], 401);
        }

        $mission = Mission::select(['id','type','game_id','target_amount','reward'])->findOrFail($missionId);

        $todayStart = Carbon::today()->startOfDay();
        $todayEnd   = Carbon::today()->endOfDay();

        $progress = MissionUser::firstOrNew(['user_id' => $user->id, 'mission_id' => $mission->id]);

        switch ($mission->type) {
            case 'deposit':
                $progress->reward = (float) $mission->reward;
                break;

            case 'game_bet':
                $progress->current_progress = (float) Order::where('user_id', $user->id)
                    ->where('game', $mission->game_id)
                    ->where('type', 'bet')
                    ->whereBetween('created_at', [$todayStart, $todayEnd])
                    ->sum('amount');
                break;

            case 'total_bet':
                $progress->current_progress = (float) Order::where('user_id', $user->id)
                    ->where('type', 'bet')
                    ->whereBetween('created_at', [$todayStart, $todayEnd])
                    ->sum('amount');
                break;
        }

        $progress->save();

        return response()->json([
            'success'  => true,
            'message'  => 'Progresso atualizado.',
            'progress' => $progress
        ]);
    }

    /**
     * Verifica se uma missão foi resgatada (hoje).
     */
    public function checkIfRedeemed($missionId)
    {
        $user = auth('api')->user();
        if (!$user) {
            return response()->json(['message' => 'Usuário não autenticado.'], 401);
        }

        $today = Carbon::today();

        $missionUser = MissionUser::where('user_id', $user->id)
            ->where('mission_id', $missionId)
            ->whereDate('updated_at', $today) // verifica resgate/atualização de hoje
            ->first(['id','redeemed']);

        return response()->json([
            'redeemed' => $missionUser && (int)$missionUser->redeemed === 1,
        ]);
    }

    /**
     * Resgata a recompensa de uma missão.
     */
    public function redeemReward($missionId)
    {
        $user = auth('api')->user();
        if (!$user) {
            return response()->json(['message' => 'Usuário não autenticado.'], 401);
        }

        $mission = Mission::select(['id','reward'])->find($missionId);
        if (!$mission) {
            return response()->json(['message' => 'Missão não encontrada.'], 404);
        }

        // registro de hoje
        $missionUser = MissionUser::where('user_id', $user->id)
            ->where('mission_id', $missionId)
            ->whereDate('created_at', Carbon::today())
            ->first();

        if ($missionUser && $missionUser->redeemed) {
            return response()->json(['message' => 'Missão já resgatada hoje.'], 400);
        }

        $wallet = Wallet::where('user_id', $user->id)
            ->where('active', 1)
            ->select(['id','user_id','balance_bonus','balance_bonus_rollover'])
            ->first();

        if (!$wallet) {
            return response()->json(['message' => 'Carteira não encontrada.'], 404);
        }

        if (!$missionUser) {
            $missionUser = MissionUser::create([
                'user_id'          => $user->id,
                'mission_id'       => $missionId,
                'current_progress' => $mission->reward, // apenas para registrar algo; se quiser, use target_amount
                'redeemed'         => 1,
            ]);
        } else {
            $missionUser->update(['redeemed' => 1]);
        }

        // incrementa bônus
        $wallet->increment('balance_bonus', (float)$mission->reward);
        $wallet->increment('balance_bonus_rollover', (float)$mission->reward);

        // invalida cache de agregados do usuário (opcional)
        $today = Carbon::today()->toDateString();
        Cache::forget("missions:v1:agg:u:{$user->id}:{$today}");

        return response()->json([
            'message' => 'Recompensa resgatada com sucesso!',
            'wallet'  => [
                'balance_bonus'           => $wallet->fresh()->balance_bonus,
                'balance_bonus_rollover'  => $wallet->fresh()->balance_bonus_rollover,
            ],
        ]);
    }
}
