<?php

namespace App\Filament\Pages;

use App\Services\CacheNuker;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class SystemTools extends Page
{
    protected static ?string $navigationIcon  = 'heroicon-o-cog-8-tooth';
    protected static ?string $navigationGroup = 'SISTEMA';
    protected static ?string $title           = 'Ferramentas do Sistema';
    protected static ?string $navigationLabel = 'LIMPEZA DE CACHE';
    protected static ?int    $navigationSort  = 1;

    protected static string $view = 'filament.pages.system-tools';

    public static function shouldRegisterNavigation(): bool
    {
        $user = auth()->user();
        return $user && method_exists($user, 'hasRole') ? $user->hasRole('admin') : true;
    }

    public function getHeaderActions(): array
    {
        return [
            Actions\Action::make('clear_app_cache')
                ->label('Limpar cache da aplicação')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->requiresConfirmation()
                ->action(function (CacheNuker $nuker) {
                    $nuker->run(['deep' => true, 'sessions' => false, 'queues' => false]);
                    Notification::make()->title('Cache limpo com sucesso')->success()->send();
                }),

            Actions\Action::make('clear_sessions')
                ->label('Limpar sessões')
                ->icon('heroicon-o-user-minus')
                ->color('warning')
                ->requiresConfirmation()
                ->action(function (CacheNuker $nuker) {
                    $nuker->run(['deep' => false, 'sessions' => true, 'queues' => false]);
                    Notification::make()->title('Sessões limpas')->success()->send();
                }),

            Actions\Action::make('clear_advanced')
                ->label('Limpeza avançada (event/opcache)')
                ->icon('heroicon-o-bolt')
                ->color('info')
                ->requiresConfirmation()
                ->action(function (CacheNuker $nuker) {
                    // “avançado”: sem sessões/filas; optimize+event+opcache já rodam no service
                    $nuker->run(['deep' => false, 'sessions' => false, 'queues' => false]);
                    Notification::make()->title('Limpeza avançada concluída')->success()->send();
                }),

            Actions\Action::make('rebuild_caches')
                ->label('Recriar caches (config/rotas/views)')
                ->icon('heroicon-o-arrow-path')
                ->color('primary')
                ->requiresConfirmation()
                ->action(function () {
                    try { \Artisan::call('config:cache'); } catch (\Throwable $e) {}
                    try { \Artisan::call('route:cache'); } catch (\Throwable $e) {}
                    try { \Artisan::call('view:cache'); } catch (\Throwable $e) {}
                    try { \Artisan::call('event:cache'); } catch (\Throwable $e) {}
                    try { \Artisan::call('optimize'); } catch (\Throwable $e) {}
                    Notification::make()->title('Caches recompilados')->success()->send();
                }),
        ];
    }
}
