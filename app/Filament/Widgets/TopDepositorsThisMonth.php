<?php

namespace App\Filament\Widgets;

use App\Models\User;
use Carbon\Carbon;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Illuminate\Support\Facades\DB;

class TopDepositorsThisMonth extends BaseWidget
{
    use InteractsWithPageFilters;

    protected static ?string $heading = 'Top 10 depositantes (período)';
    protected static ?int $sort = 5;
    protected static ?string $pollingInterval = '60s';

    public function table(Table $table): Table
    {
        // Lê os filtros do Dashboard (se existirem)
        $start = data_get($this->filters, 'startDate');
        $end   = data_get($this->filters, 'endDate');

        $startDate = $start ? Carbon::parse($start)->startOfDay() : Carbon::now()->startOfMonth();
        $endDate   = $end ? Carbon::parse($end)->endOfDay() : now();

        // >>> IMPORTANTE: Partimos de User::query() (Eloquent\Builder)
        $query = User::query()
            ->select(
                'users.id',
                'users.name',
                DB::raw('COALESCE(SUM(deposits.amount), 0) as total')
            )
            ->join('deposits', 'deposits.user_id', '=', 'users.id')
            ->where('deposits.status', '1')
            ->whereBetween('deposits.created_at', [$startDate, $endDate])
            ->groupBy('users.id', 'users.name')
            ->orderByDesc('total');

        return $table
            ->query(fn () => $query) // passa um Eloquent\Builder (ou Closure)
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Usuário')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('total')
                    ->label('Total depositado')
                    ->sortable()
                    ->formatStateUsing(fn ($state) => \Helper::amountFormatDecimal((float) $state)),
            ])
            ->paginated(false)
            ->striped();
    }

    public static function canView(): bool
    {
        return auth()->user()->hasRole('admin');
    }
}
