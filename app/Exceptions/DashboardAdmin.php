<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\StatsOverview;
use App\Filament\Widgets\DepositsVsWithdrawals7Days;
use App\Filament\Widgets\DepositsByHourToday;
use App\Filament\Widgets\NewUsers14Days;
use App\Filament\Widgets\TopDepositorsThisMonth;

use App\Livewire\WalletOverview;
use Illuminate\Support\HtmlString;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Section;
use Filament\Forms\Form;
use Filament\Pages\Dashboard\Actions\FilterAction;
use Filament\Pages\Dashboard\Concerns\HasFiltersAction;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;

use App\Filament\Widgets\HouseProfitOverview;

class DashboardAdmin extends \Filament\Pages\Dashboard
{
    use HasFiltersForm, HasFiltersAction;

    public function getSubheading(): string|\Illuminate\Contracts\Support\Htmlable|null
    {
        return "Bem-vindo(a) de volta, Admin! Seu painel está pronto para você.";
    }

    public function filtersForm(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('OBETZERA CRIOU ESSA PLATAFORMA PARA VOCÊ')
                    ->description(new HtmlString('
                        <div style="font-weight: 600; display: flex; align-items: center; flex-wrap: wrap; gap: 10px;">
                            SAIBA MAIS SOBRE NÓS. PARTICIPE DA NOSSA COMUNIDADE IGAMING. ACESSE AGORA!
                            <a class="dark:text-white"
                               style="font-size: 14px; font-weight: 600; min-width: 127px; display: inline-flex; background-color: #00b91e; padding: 10px; border-radius: 11px; justify-content: center;"
                               href="https://obetzera.com" target="_blank">SITE OFICIAL</a>
                            <a class="dark:text-white"
                               style="font-size: 14px; font-weight: 600; min-width: 127px; display: inline-flex; background-color: #00b91e; padding: 10px; border-radius: 11px; justify-content: center;"
                               href="https://t.me/obetzera01" target="_blank">GRUPO TELEGRAM</a>
                        </div>
                    ')),
                Section::make('Filtros do painel')
                    ->schema([
                        DatePicker::make('startDate')->label('Data Inicial'),
                        DatePicker::make('endDate')->label('Data Final'),
                    ])->columns(2),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            FilterAction::make()
                ->label('Filtro')
                ->form([
                    DatePicker::make('startDate')->label('Data Inicial'),
                    DatePicker::make('endDate')->label('Data Final'),
                ]),
        ];
    }

    public static function canAccess(): bool
    {
        return auth()->user()->hasRole('admin');
    }

    public static function canView(): bool
    {
        return auth()->user()->hasRole('admin');
    }

    public function getWidgets(): array
    {
        return [
            // seu componente Livewire atual
            WalletOverview::class,
            HouseProfitOverview::class,

            // KPIs + gráficos
            StatsOverview::class,
            DepositsVsWithdrawals7Days::class,
            DepositsByHourToday::class,
            NewUsers14Days::class,
            TopDepositorsThisMonth::class,
        ];
    }
}
