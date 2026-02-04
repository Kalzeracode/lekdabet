<?php

namespace App\Filament\Widgets;

use App\Models\Order;
use Carbon\Carbon;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class HouseProfitOverview extends BaseWidget
{
    use InteractsWithPageFilters;

    // ✅ heading não-estático (ou use o método getHeading() abaixo)

    protected static ?int $sort = 6;
    protected static ?string $pollingInterval = '30s';
    protected static bool $isLazy = true;

    // Alternativa ainda mais segura (pode remover a propriedade acima se preferir):
    // protected function getHeading(): string
    // {
    //     return 'Perdas, Ganhos e Lucro';
    // }

    /** @return Stat[] */
    protected function getStats(): array
    {
        [$from, $to] = $this->getRangeOrToday();

        $base = Order::query()
            ->where('status', '1')
            ->where('refunded', '0')
            ->whereBetween('created_at', [$from, $to]);

        $totalBets = (float) (clone $base)->where('type', 'bet')->sum('amount'); // perdas (apostas)
        $totalWins = (float) (clone $base)->where('type', 'win')->sum('amount'); // ganhos pagos
        $profit    = $totalBets - $totalWins; // lucro da casa

        [, $betsSeries, $winsSeries, $profitSeries] = $this->seriesByDay($from, $to);

        return [
            Stat::make('Total de Perdas (apostas)', \Helper::amountFormatDecimal($totalBets))
                ->description($this->periodLabel($from, $to))
                ->descriptionIcon('heroicon-o-fire')
                ->color('orange')
                ->chart($betsSeries)
                ->chartColor('rgba(249, 115, 22, 0.5)'),

            Stat::make('Total de Ganhos (pagos)', \Helper::amountFormatDecimal($totalWins))
                ->description($this->periodLabel($from, $to))
                ->descriptionIcon('heroicon-o-gift')
                ->color('info')
                ->chart($winsSeries)
                ->chartColor('rgba(2, 132, 199, 0.5)'),

            Stat::make('Lucro da Casa', \Helper::amountFormatDecimal($profit))
                ->description($this->periodLabel($from, $to))
                ->descriptionIcon('heroicon-o-banknotes')
                ->color($profit >= 0 ? 'success' : 'danger')
                ->chart($this->normalizeSparkline($profitSeries))
                ->chartColor('rgba(16, 185, 129, 0.5)'),
        ];
    }

    private function getRangeOrToday(): array
    {
        $start = data_get($this->filters, 'startDate');
        $end   = data_get($this->filters, 'endDate');

        if ($start && $end) {
            return [Carbon::parse($start)->startOfDay(), Carbon::parse($end)->endOfDay()];
        }

        return [Carbon::today(), Carbon::today()->endOfDay()];
    }

    private function seriesByDay(Carbon $from, Carbon $to): array
    {
        $days = $from->diffInDays($to) + 1;
        if ($days > 14) {
            $from = (clone $to)->subDays(13)->startOfDay();
            $days = 14;
        }

        $labels = [];
        $bets = [];
        $wins = [];
        $profit = [];

        for ($i = 0; $i < $days; $i++) {
            $day = (clone $from)->addDays($i);
            $labels[] = $day->format('d/m');

            $bet = (float) Order::query()
                ->where('status', '1')
                ->where('refunded', '0')
                ->where('type', 'bet')
                ->whereDate('created_at', $day)
                ->sum('amount');

            $win = (float) Order::query()
                ->where('status', '1')
                ->where('refunded', '0')
                ->where('type', 'win')
                ->whereDate('created_at', $day)
                ->sum('amount');

            $bets[] = $bet;
            $wins[] = $win;
            $profit[] = $bet - $win;
        }

        return [$labels, $bets, $wins, $profit];
    }

    private function periodLabel(Carbon $from, Carbon $to): string
    {
        return $from->isSameDay($to)
            ? 'Hoje'
            : 'Período: ' . $from->format('d/m') . ' - ' . $to->format('d/m');
    }

    private function normalizeSparkline(array $series): array
    {
        if (!$series) return [];
        $min = min($series);
        return $min < 0 ? array_map(fn ($v) => $v - $min, $series) : $series;
    }

    public static function canView(): bool
    {
        return auth()->user()->hasRole('admin');
    }
}
