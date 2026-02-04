<?php

namespace App\Filament\Resources\GameResource\Pages;

use App\Filament\Resources\GameResource;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditGame extends EditRecord
{
    protected static string $resource = GameResource::class;

    protected function getHeaderActions(): array
    {
        return [
            \Filament\Actions\DeleteAction::make(),
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        if (isset($data['show_home']) && $data['show_home'] == 1) {
            $data['status'] = 1;
        }

        // Mesmo comportamento da criação
        if (($data['cover_mode'] ?? null) === 'url') {
            $data['cover'] = $data['cover_url'] ?? $record->cover;
        }

        unset($data['cover_mode'], $data['cover_url']);

        $record->update($data);

        return $record;
    }
}
