<?php

namespace App\Filament\Widgets;

use Carbon\Carbon;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\DB;

class NewUsers14Days extends ChartWidget
{
    protected static ?string $heading = 'Novos usuários (14 dias)';
    protected static ?int $sort = 4;
    protected static ?string $pollingInterval = '60s';

    protected function getData(): array
    {
        $labels = [];
        $values = [];

        for ($i = 13; $i >= 0; $i--) {
            $day = Carbon::today()->subDays($i);
            $labels[] = $day->format('d/m');

            $values[] = (int) DB::table('users')
                ->whereDate('created_at', $day)
                ->count();
        }

        return [
            'labels' => $labels,
            'datasets' => [
                [
                    'label' => 'Cadastros',
                    'data' => $values,
                    'tension' => 0.3,
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
