<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Casts\Attribute;

class Gateway extends Model
{
    use HasFactory;

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'gateways';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [

        // Suitpay
        'suitpay_uri',
        'suitpay_cliente_id',
        'suitpay_cliente_secret',

        // Stripe
        'stripe_production',
        'stripe_public_key',
        'stripe_secret_key',
        'stripe_webhook_key',

        //EzzePay
        'ezze_uri',
        'ezze_client',
        'ezze_secret',
        'ezze_user',
        'ezze_senha',

        //DigitoPay
        'digito_uri',
        'digito_client',
        'digito_secret',
        
        //OndaPay
        'ondapay_uri',
        'ondapay_client',
        'ondapay_secret',
        
        //BsPay
        'bspay_uri',
        'bspay_cliente_id',
        'bspay_cliente_secret',
        
        // Woovi (NOVO)
        'woovi_uri',
        'woovi_client_id',
        'woovi_client_secret',

    ];

    protected $hidden = array('updated_at');

    /**
     * Suitpay Cliente ID - oculta em DEMO
     */
    protected function suitpayClienteId(): Attribute
    {
        return Attribute::make(
            get: fn(?string $value) => env('APP_DEMO') ? '*********************' : $value,
        );
    }

    /**
     * Suitpay Cliente Secret - oculta em DEMO
     */
    protected function suitpayClienteSecret(): Attribute
    {
        return Attribute::make(
            get: fn(?string $value) => env('APP_DEMO') ? '*********************' : $value,
        );
    }

    /**
     * BsPay Cliente ID - oculta em DEMO
     */
    protected function bspayClienteId(): Attribute
    {
        return Attribute::make(
            get: fn(?string $value) => env('APP_DEMO') ? '*********************' : $value,
        );
    }

    /**
     * BsPay Cliente Secret - oculta em DEMO
     */
    protected function bspayClienteSecret(): Attribute
    {
        return Attribute::make(
            get: fn(?string $value) => env('APP_DEMO') ? '*********************' : $value,
        );
    }

    /**
     * Woovi Client ID - oculta em DEMO (NOVO)
     */
    protected function wooviClientId(): Attribute
    {
        return Attribute::make(
            get: fn(?string $value) => env('APP_DEMO') ? '*********************' : $value,
        );
    }

    /**
     * Woovi Client Secret - oculta em DEMO (NOVO)
     */
    protected function wooviClientSecret(): Attribute
    {
        return Attribute::make(
            get: fn(?string $value) => env('APP_DEMO') ? '*********************' : $value,
        );
    }

    /**
     * Stripe Public Key - oculta em DEMO
     */
    protected function stripePublicKey(): Attribute
    {
        return Attribute::make(
            get: fn(?string $value) => env('APP_DEMO') ? '*********************' : $value,
        );
    }

    /**
     * Stripe Secret Key - oculta em DEMO
     */
    protected function stripeSecretKey(): Attribute
    {
        return Attribute::make(
            get: fn(?string $value) => env('APP_DEMO') ? '*********************' : $value,
        );
    }
}