<?php

namespace App\Filament\Widgets;

use App\Models\User;
use App\Models\Wallet;
use Carbon\Carbon;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

class StatsOverview extends BaseWidget
{
    protected static ?int $sort = 1;
    protected static ?string $pollingInterval = '15s';
    protected static bool $isLazy = true;

    /** @return Stat[] */
    protected function getStats(): array
    {
        $today      = Carbon::today();
        $yesterday  = Carbon::yesterday();
        $startWeek  = Carbon::now()->startOfWeek();
        $startMonth = Carbon::now()->startOfMonth();

        // --------- Totais hoje
        $depToday = (float) DB::table('deposits')->whereDate('created_at', $today)->where('status', '1')->sum('amount');
        $wdToday  = (float) DB::table('withdrawals')->whereDate('created_at', $today)->where('status', '1')->sum('amount');
        $netToday = $depToday - $wdToday;

        // --------- Totais ontem (para variação %)
        $depYday = (float) DB::table('deposits')->whereDate('created_at', $yesterday)->where('status', '1')->sum('amount');
        $wdYday  = (float) DB::table('withdrawals')->whereDate('created_at', $yesterday)->where('status', '1')->sum('amount');

        // --------- Semana/Mês (acumulados)
        $depWeek = (float) DB::table('deposits')->where('status', '1')->whereBetween('created_at', [$startWeek, now()])->sum('amount');
        $wdWeek  = (float) DB::table('withdrawals')->where('status', '1')->whereBetween('created_at', [$startWeek, now()])->sum('amount');

        $depMonth = (float) DB::table('deposits')->where('status', '1')->whereBetween('created_at', [$startMonth, now()])->sum('amount');
        $wdMonth  = (float) DB::table('withdrawals')->where('status', '1')->whereBetween('created_at', [$startMonth, now()])->sum('amount');

        // --------- Saldos players (wallets)
        $playersBalance = (float) DB::table('wallets')
            ->join('users', 'users.id', '=', 'wallets.user_id')
            ->sum(DB::raw('wallets.balance + wallets.balance_bonus + wallets.balance_withdrawal'));

        // --------- Ganhos afiliados (acumulado)
        $affRewards = (float) Wallet::where('refer_rewards', '>=', 1)->sum('refer_rewards');

        // --------- Conversões / ticket médio (hoje)
        $newUsersToday  = (int) DB::table('users')->whereDate('created_at', $today)->count();
        $depUsersToday  = (int) DB::table('deposits')->whereDate('created_at', $today)->where('status', '1')->distinct()->count('user_id');
        $totalUsers     = (int) DB::table('users')->count();

        $convToday = $newUsersToday > 0 ? ($depUsersToday / max($newUsersToday, 1)) * 100 : 0.0; // conversão dos NOVOS de hoje
        $arpuToday = $depUsersToday > 0 ? ($depToday / $depUsersToday) : 0.0; // ticket médio depositante de hoje

        // --------- Distribuição de depósitos por usuário (global)
        $depositCounts = DB::table('deposits')
            ->select('user_id', DB::raw('count(*) as deposit_count'))
            ->where('status', '1')
            ->groupBy('user_id')
            ->get();

        $dep1 = $depositCounts->where('deposit_count', 1)->count();
        $dep2 = $depositCounts->where('deposit_count', 2)->count();
        $dep3 = $depositCounts->where('deposit_count', 3)->count();
        $dep4p = $depositCounts->where('deposit_count', '>=', 4)->count();

        // --------- Mini séries para sparklines (últimos 7 dias)
        $sparkDeposits = [];
        $sparkWithdraws = [];
        for ($i = 6; $i >= 0; $i--) {
            $day = Carbon::today()->subDays($i);
            $sparkDeposits[] = (float) DB::table('deposits')->where('status', '1')->whereDate('created_at', $day)->sum('amount');
            $sparkWithdraws[] = (float) DB::table('withdrawals')->where('status', '1')->whereDate('created_at', $day)->sum('amount');
        }

        // --------- Helpers de variação
        $var = fn (float $today, float $yday) => $yday == 0.0 ? null : (($today - $yday) / $yday) * 100;

        $depVar = $var($depToday, $depYday);
        $wdVar  = $var($wdToday, $wdYday);
        $netVar = $var($netToday, $depYday - $wdYday);

        return [
            Stat::make('Usuários', number_format($totalUsers))
                ->description('Total cadastrados')
                ->descriptionIcon('heroicon-o-user-group')
                ->color('blue')
                ->chart($this->normalizeSparkline($sparkDeposits))
                ->chartColor('rgba(59, 130, 246, 0.5)'),

            Stat::make('Depósitos (hoje)', \Helper::amountFormatDecimal($depToday))
                ->description($this->trendText('vs ontem', $depVar))
                ->descriptionIcon('heroicon-o-currency-dollar')
                ->color('success')
                ->chart($sparkDeposits)
                ->chartColor('rgba(16, 185, 129, 0.5)'),

            Stat::make('Saques (hoje)', \Helper::amountFormatDecimal($wdToday))
                ->description($this->trendText('vs ontem', $wdVar))
                ->descriptionIcon('heroicon-o-arrow-down-tray')
                ->color('danger')
                ->chart($sparkWithdraws)
                ->chartColor('rgba(239, 68, 68, 0.5)'),

            Stat::make('Fluxo líquido (hoje)', \Helper::amountFormatDecimal($netToday))
                ->description($this->trendText('vs ontem', $netVar))
                ->descriptionIcon('heroicon-o-arrows-right-left')
                ->color($netToday >= 0 ? 'success' : 'danger')
                ->chart($this->normalizeSparkline(array_map(fn($i) => $sparkDeposits[$i] - $sparkWithdraws[$i], array_keys($sparkDeposits))))
                ->chartColor('rgba(99, 102, 241, 0.5)'), // indigo

            Stat::make('Saldo players', \Helper::amountFormatDecimal($playersBalance))
                ->description('Somatório de wallets')
                ->descriptionIcon('heroicon-o-wallet')
                ->color('purple')
                ->chart([15, 30, 25, 40, 35, 50, 45])
                ->chartColor('rgba(168, 85, 247, 0.5)'),

            Stat::make('Afiliados (acumulado)', \Helper::amountFormatDecimal($affRewards))
                ->description('Ganhos a pagar')
                ->descriptionIcon('heroicon-o-briefcase')
                ->color('yellow')
                ->chart([5, 15, 10, 20, 25, 30, 35])
                ->chartColor('rgba(250, 204, 21, 0.5)'),

            Stat::make('Conversão novos (hoje)', number_format($convToday, 2) . '%')
                ->description("{$depUsersToday} depositantes / {$newUsersToday} novos")
                ->descriptionIcon('heroicon-o-sparkles')
                ->color('info')
                ->chart([10, 20, 15, 30, 25, 35, 45])
                ->chartColor('rgba(2, 132, 199, 0.5)'),

            Stat::make('Ticket médio (hoje)', \Helper::amountFormatDecimal($arpuToday))
                ->description('Média por depositante de hoje')
                ->descriptionIcon('heroicon-o-chart-bar')
                ->color('gray')
                ->chart([20, 30, 25, 35, 45, 50, 55])
                ->chartColor('rgba(107, 114, 128, 0.5)'),

            Stat::make('1 depósito', $dep1)
                ->description('Distribuição usuários')
                ->descriptionIcon('heroicon-o-user')
                ->color('orange')
                ->chart([10, 20, 15, 30, 25, 35, 45])
                ->chartColor('rgba(249, 115, 22, 0.5)'),

            Stat::make('2 depósitos', $dep2)
                ->description('Distribuição usuários')
                ->descriptionIcon('heroicon-o-user')
                ->color('rose')
                ->chart([20, 30, 25, 35, 45, 50, 55])
                ->chartColor('rgba(244, 63, 94, 0.5)'),

            Stat::make('3 depósitos', $dep3)
                ->description('Distribuição usuários')
                ->descriptionIcon('heroicon-o-user')
                ->color('indigo')
                ->chart([15, 25, 20, 30, 35, 40, 50])
                ->chartColor('rgba(79, 70, 229, 0.5)'),

            Stat::make('4+ depósitos', $dep4p)
                ->description('Distribuição usuários')
                ->descriptionIcon('heroicon-o-user')
                ->color('teal')
                ->chart([25, 35, 30, 40, 45, 55, 60])
                ->chartColor('rgba(13, 148, 136, 0.5)'),
        ];
    }

    private function trendText(string $prefix, ?float $var): string
    {
        if ($var === null) return "$prefix: n/d";
        $arrow = $var >= 0 ? '↑' : '↓';
        return sprintf('%s: %s %s%%', $prefix, $arrow, number_format(abs($var), 2));
    }

    /** Normaliza para valores >= 0 (evita gráficos negativos estranhos em sparklines) */
    private function normalizeSparkline(array $series): array
    {
        if (empty($series)) return [];
        $min = min($series);
        if ($min >= 0) return $series;
        return array_map(fn($v) => $v - $min, $series);
    }

    public static function canView(): bool
    {
        return auth()->user()->hasRole('admin');
    }
}
