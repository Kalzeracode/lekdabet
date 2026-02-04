<?php

namespace App\Filament\Widgets;

use Carbon\Carbon;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\DB;

class DepositsByHourToday extends ChartWidget
{
    protected static ?string $heading = 'Depósitos por hora (hoje)';
    protected static ?int $sort = 3;
    protected static ?string $pollingInterval = '60s';

    protected function getData(): array
    {
        $today = Carbon::today();
        $labels = [];
        $data = [];

        for ($h = 0; $h < 24; $h++) {
            $labels[] = sprintf('%02d:00', $h);

            $sum = (float) DB::table('deposits')
                ->where('status', '1')
                ->whereDate('created_at', $today)
                ->whereRaw('HOUR(created_at) = ?', [$h])
                ->sum('amount');

            $data[] = $sum;
        }

        return [
            'labels' => $labels,
            'datasets' => [
                [
                    'label' => 'Depósitos',
                    'data' => $data,
                ],
            ],
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }

    public static function canView(): bool
    {
        return auth()->user()->hasRole('admin');
    }
}
