<?php

namespace App\Filament\Resources\GameResource\Pages;

use App\Filament\Resources\GameResource;
use Filament\Resources\Pages\CreateRecord;

class CreateGame extends CreateRecord
{
    protected static string $resource = GameResource::class;

    /**
     * Mutate Form Data Before Create
     * @param array $data
     * @return array|mixed[]
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (isset($data['show_home']) && $data['show_home'] == 1) {
            $data['status'] = 1;
        }

        // Se o modo selecionado for URL, grava a URL diretamente no campo real `cover`
        if (($data['cover_mode'] ?? null) === 'url') {
            $data['cover'] = $data['cover_url'] ?? null;
        }

        // Remove campos auxiliares (não existem no banco)
        unset($data['cover_mode'], $data['cover_url']);

        return parent::mutateFormDataBeforeCreate($data);
    }
}
