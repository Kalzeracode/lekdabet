<?php

namespace App\Http\Controllers\Api\Wallet;

use App\Helpers\Core;
use App\Http\Controllers\Controller;
use App\Models\Deposit;
use App\Models\Transaction;
use App\Traits\Gateways\DigitoPayTrait;
use App\Traits\Gateways\EzzepayTrait;
use App\Traits\Gateways\BsPayTrait;
use App\Traits\Gateways\OndaPayTrait;
use App\Traits\Gateways\SuitpayTrait;
use App\Traits\Gateways\WooviTrait; // ← NOVO
use Illuminate\Http\Request;

class DepositController extends Controller
{
    use SuitpayTrait, DigitoPayTrait, EzzepayTrait, BsPayTrait, OndaPayTrait, WooviTrait; // ← ADICIONADO WooviTrait

    /**
     * @param Request $request
     * @return array|false[]|\Illuminate\Http\JsonResponse
     */
    public function submitPayment(Request $request)
    {
        $setting = Core::getSetting();
        $gateway = strtolower(trim((string) $request->input('gateway', '')));

        $enabledGateways = [
            'woovi' => (int) ($setting->woovi_is_enable ?? 0) === 1,
            'ondapay' => (int) ($setting->ondapay_is_enable ?? 0) === 1,
            'ezzepay' => (int) ($setting->ezzepay_is_enable ?? 0) === 1,
            'digitopay' => (int) ($setting->digito_is_enable ?? 0) === 1,
            'bspay' => (int) ($setting->bspay_is_enable ?? 0) === 1,
            'suitpay' => (int) ($setting->suitpay_is_enable ?? 0) === 1,
        ];

        if ($gateway === '' || !($enabledGateways[$gateway] ?? false)) {
            foreach (['woovi', 'ondapay', 'ezzepay', 'digitopay', 'bspay', 'suitpay'] as $candidate) {
                if ($enabledGateways[$candidate] ?? false) {
                    $gateway = $candidate;
                    break;
                }
            }
        }

        switch ($gateway) {
            case 'suitpay':
                return self::requestQrcode($request);
            case 'ezzepay':
                return self::requestQrcodeEzze($request);
            case 'digitopay':
                return self::requestQrcodeDigito($request);
            case 'ondapay':
                return self::requestQrCodeOnda($request);
            case 'bspay':
                return self::requestQrcodeBsPay($request);
            case 'woovi': // ← NOVO
                return self::requestQrcodeWoovi($request);
        }

        return response()->json([
            'error' => 'Gateway de pagamento não configurado.',
        ], 400);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function consultStatusTransactionPix(Request $request)
    {
        return self::consultStatusTransaction($request);
    }
    /**
     * @param $request
     * @return \Illuminate\Http\JsonResponse
     */
    public static function consultStatusTransaction($request)
    {
        self::generateCredentials();

        $transaction = Transaction::where('payment_id', $request->input("idTransaction"))->first();

        if ($transaction != null && $transaction->status) {
            return response()->json(['status' => 'PAID']);
        } elseif ($transaction != null) {
            // Transação encontrada, mas ainda não paga
            return response()->json(['status' => 'PENDING']);
        } else {
            // Transação não encontrada
            return response()->json(['status' => 'NOT_FOUND'], 404);
        }
    }
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $deposits = Deposit::whereUserId(auth('api')->id())->paginate();
        return response()->json(['deposits' => $deposits], 200);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}
