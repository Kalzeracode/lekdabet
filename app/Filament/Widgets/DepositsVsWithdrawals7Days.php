<?php

namespace App\Filament\Widgets;

use Carbon\Carbon;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\DB;

class DepositsVsWithdrawals7Days extends ChartWidget
{
    protected static ?string $heading = 'Depósitos x Saques (7 dias)';
    protected static ?int $sort = 2;
    protected static ?string $pollingInterval = '30s';

    protected function getData(): array
    {
        $labels = [];
        $dep = [];
        $wd  = [];

        for ($i = 6; $i >= 0; $i--) {
            $day = Carbon::today()->subDays($i);
            $labels[] = $day->format('d/m');

            $dep[] = (float) DB::table('deposits')->where('status', '1')->whereDate('created_at', $day)->sum('amount');
            $wd[]  = (float) DB::table('withdrawals')->where('status', '1')->whereDate('created_at', $day)->sum('amount');
        }

        return [
            'labels' => $labels,
            'datasets' => [
                [
                    'label' => 'Depósitos',
                    'data' => $dep,
                    'tension' => 0.35,
                    'fill' => true,
                ],
                [
                    'label' => 'Saques',
                    'data' => $wd,
                    'tension' => 0.35,
                    'fill' => true,
                ],
            ],
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }

    public static function canView(): bool
    {
        return auth()->user()->hasRole('admin');
    }
}
