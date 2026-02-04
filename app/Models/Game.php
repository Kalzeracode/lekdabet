<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Game extends Model
{
    use HasFactory;

    protected $table = 'games';
    protected $primaryKey = 'id';

    protected $fillable = [
        'provider_id',
        'game_server_url',
        'game_id',
        'game_name',
        'game_code',
        'game_type',
        'description',
        'cover',
        'status',
        'technology',
        'has_lobby',
        'is_mobile',
        'has_freespins',
        'has_tables',
        'only_demo',
        'rtp',
        'distribution',
        'views',
        'is_featured',
        'show_home',
        'original',
    ];

    /**
     * Converte tipos conforme seu schema (tinyint/bigint etc.)
     */
    protected $casts = [
        'status'        => 'boolean',
        'has_lobby'     => 'boolean',
        'is_mobile'     => 'boolean',
        'has_freespins' => 'boolean',
        'has_tables'    => 'boolean',
        'only_demo'     => 'boolean',
        'is_featured'   => 'boolean',
        'show_home'     => 'boolean',
        'original'      => 'boolean',

        'views' => 'integer',
        'rtp'   => 'integer',

        'created_at' => 'datetime', // mantém Carbon
        'updated_at' => 'datetime',
    ];

    /**
     * Se quiser esconder campos na API (opcional)
     */
    // protected $hidden = ['game_server_url'];

    /**
     * Atributos calculados adicionados automaticamente na saída JSON
     */
    protected $appends = [
        'date_human_readable',
        'created_at_formatted',
    ];

    /** RELACIONAMENTOS */

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class, 'provider_id', 'id');
    }

    public function categories(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        // Torna explícito o nome da tabela pivô e chaves
        return $this->belongsToMany(Category::class, 'category_game', 'game_id', 'category_id');
    }

    /** ACCESSORS SEGUROS (não sobrescreva created_at!) */

    public function getCreatedAtFormattedAttribute(): ?string
    {
        return $this->created_at?->format('Y-m-d');
    }

    public function getDateHumanReadableAttribute(): ?string
    {
        return $this->created_at?->diffForHumans();
    }

    /**
     * Caso queira padronizar a serialização de datas no JSON
     * (opcional; mantém created_at/updated_at como ISO 8601)
     */
    protected function serializeDate(\DateTimeInterface $date): string
    {
        return $date->format('Y-m-d\TH:i:sP');
    }

    /** SCOPES ÚTEIS */

    public function scopeActive($query)
    {
        return $query->where('status', 1);
    }

    public function scopeFeatured($query)
    {
        return $query->where('is_featured', 1);
    }

    /**
     * Busca simples reutilizável (mesmo critério que você usa no controller)
     */
    public function scopeSearch($query, ?string $term)
    {
        if (!$term || mb_strlen($term) < 3) {
            return $query;
        }

        return $query->where(function ($q) use ($term) {
            $q->where('game_code', 'like', "%{$term}%")
              ->orWhere('game_name', 'like', "%{$term}%")
              ->orWhere('distribution', 'like', "%{$term}%")
              ->orWhereHas('provider', function ($p) use ($term) {
                  $p->where('name', 'like', "%{$term}%");
              });
        });
    }
}
