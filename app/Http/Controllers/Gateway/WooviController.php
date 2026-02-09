<?php

namespace App\Http\Controllers\Gateway;

use App\Http\Controllers\Controller;
use App\Traits\Gateways\WooviTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WooviController extends Controller
{
    use WooviTrait;

    /**
     * Gera QR Code PIX para depósito
     */
    public function getQRCodePix(Request $request)
    {
        return self::requestQrcodeWoovi($request);
    }

    /**
     * Callback/Webhook do Woovi
     */
    public function callbackMethod(Request $request)
    {
        try {
            DB::table('debug')->insert(['text' => json_encode($request->all())]);
        } catch (\Throwable $e) {
            // Avoid breaking production webhook if debug table does not exist.
        }

        return self::webhookWoovi($request);
    }

    /**
     * Callback de pagamento (se necessário endpoint separado)
     */
    public function callbackMethodPayment(Request $request)
    {
        $data = $request->all();
        DB::table('debug')->insert(['text' => json_encode($request->all())]);

        return response()->json([], 200);
    }
}
