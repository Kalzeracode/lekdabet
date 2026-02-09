<?php

namespace App\Traits\Gateways;

use App\Helpers\Core;
use App\Models\AffiliateHistory;
use App\Models\AffiliateWithdraw;
use App\Models\ConfigRoundsFree;
use App\Models\Deposit;
use App\Models\Gateway;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Withdrawal;
use App\Notifications\NewDepositNotification;
use App\Services\PlayFiverService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

trait WooviTrait
{
    protected static string $uriWoovi;
    protected static string $appIdWoovi;
    protected static string $clientSecretWoovi;
    protected static string $webhookSecretWoovi;

    /**
     * Load Woovi credentials from gateways table.
     */
    private static function generateCredentialsWoovi(): void
    {
        self::$uriWoovi = 'https://api.openpix.com.br/api/openpix/v1/';
        self::$appIdWoovi = '';
        self::$clientSecretWoovi = '';
        self::$webhookSecretWoovi = '';

        $gateway = Gateway::first();
        if (empty($gateway)) {
            return;
        }

        $attributes = $gateway->getAttributes();

        self::$uriWoovi = self::normalizeWooviBaseUri((string) ($attributes['woovi_uri'] ?? ''));
        self::$appIdWoovi = trim((string) ($attributes['woovi_client_id'] ?? ''));
        self::$clientSecretWoovi = trim((string) ($attributes['woovi_client_secret'] ?? ''));
        self::$webhookSecretWoovi = trim((string) ($attributes['woovi_webhook_secret'] ?? ''));
    }

    /**
     * Accepts:
     * - base host (https://api.openpix.com.br)
     * - /api/openpix/v1
     * - /api/v1
     */
    private static function normalizeWooviBaseUri(string $uri): string
    {
        $uri = trim($uri);

        if ($uri === '') {
            return 'https://api.openpix.com.br/api/openpix/v1/';
        }

        $uri = rtrim($uri, '/') . '/';

        if (str_contains($uri, '/api/openpix/v1/')) {
            return $uri;
        }

        if (str_contains($uri, '/api/v1/')) {
            return $uri;
        }

        return $uri . 'api/openpix/v1/';
    }

    /**
     * Woovi accepts Authorization with AppID/token.
     *
     * If a plain client id and client secret are provided, this also supports
     * building a base64 token for compatibility.
     */
    private static function resolveWooviAuthorization(): string
    {
        $tokenOrAppId = trim(self::$appIdWoovi ?? '');
        $secret = trim(self::$clientSecretWoovi ?? '');

        if ($tokenOrAppId === '') {
            return '';
        }

        $lower = strtolower($tokenOrAppId);
        if (str_starts_with($lower, 'basic ') || str_starts_with($lower, 'bearer ')) {
            return $tokenOrAppId;
        }

        $decoded = base64_decode($tokenOrAppId, true);
        if ($decoded !== false && str_contains($decoded, ':')) {
            return $tokenOrAppId;
        }

        if ($secret !== '') {
            return base64_encode($tokenOrAppId . ':' . $secret);
        }

        return $tokenOrAppId;
    }

    private static function wooviHeaders(): array
    {
        return [
            'Authorization' => self::resolveWooviAuthorization(),
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
    }

    /**
     * Post helper with fallback between old/new Woovi base paths.
     */
    private static function wooviPost(string $endpoint, array $payload)
    {
        $baseUri = rtrim(self::$uriWoovi, '/') . '/';
        $endpoint = ltrim($endpoint, '/');

        $candidates = [$baseUri . $endpoint];

        if (str_contains($baseUri, '/api/openpix/v1/')) {
            $candidates[] = str_replace('/api/openpix/v1/', '/api/v1/', $baseUri) . $endpoint;
        } elseif (str_contains($baseUri, '/api/v1/')) {
            $candidates[] = str_replace('/api/v1/', '/api/openpix/v1/', $baseUri) . $endpoint;
        }

        $lastResponse = null;

        foreach (array_values(array_unique($candidates)) as $url) {
            $response = Http::withOptions([
                // Woovi currently authorizes this account by IPv4; IPv6 from VPS is rejected.
                'force_ip_resolve' => 'v4',
                'timeout' => 20,
            ])->withHeaders(self::wooviHeaders())->post($url, $payload);
            $lastResponse = $response;

            Log::info('Woovi request result', [
                'url' => $url,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            if ($response->successful()) {
                return $response;
            }

            if (!in_array($response->status(), [404, 405], true)) {
                return $response;
            }
        }

        return $lastResponse;
    }

    /**
     * Request PIX QR code for deposit (cash in).
     */
    public function requestQrcodeWoovi($request)
    {
        try {
            $setting = Core::getSetting();
            $rules = [
                'amount' => ['required', 'numeric', 'min:' . $setting->min_deposit, 'max:' . $setting->max_deposit],
                'cpf' => ['required', 'string', 'max:255'],
            ];

            $validator = Validator::make($request->all(), $rules);
            if ($validator->fails()) {
                return response()->json($validator->errors(), 400);
            }

            $user = auth('api')->user();
            if (empty($user)) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            self::generateCredentialsWoovi();

            if (empty(self::resolveWooviAuthorization())) {
                return response()->json(['error' => 'Woovi authorization not configured'], 500);
            }

            $idUnico = uniqid();
            $valueInCents = (int) round((float) $request->input('amount') * 100);

            $payload = [
                'correlationID' => $idUnico,
                'value' => $valueInCents,
                'comment' => 'Deposito via PIX - ' . config('app.name'),
                'customer' => [
                    'name' => $user->name,
                    'email' => $user->email,
                    'taxID' => \Helper::soNumero($request->cpf),
                ],
            ];

            Log::info('Woovi creating charge', ['payload' => $payload]);

            $response = self::wooviPost('charge', $payload);
            if (empty($response)) {
                return response()->json(['error' => 'Unable to connect to Woovi'], 500);
            }

            if ($response->successful()) {
                $responseData = $response->json();
                $charge = data_get($responseData, 'charge', $responseData);

                $transactionId = data_get($charge, 'correlationID', $idUnico);
                $brCode = data_get($charge, 'brCode')
                    ?? data_get($charge, 'pixCode')
                    ?? data_get($charge, 'qrCode')
                    ?? data_get($responseData, 'brCode')
                    ?? data_get($responseData, 'pixCode')
                    ?? data_get($responseData, 'qrcode');

                $qrCodeImage = data_get($charge, 'qrCodeImage')
                    ?? data_get($responseData, 'qrCodeImage')
                    ?? data_get($responseData, 'qrCode');

                $paymentLinkUrl = data_get($charge, 'paymentLinkUrl')
                    ?? data_get($responseData, 'paymentLinkUrl');

                self::generateTransactionWoovi($transactionId, $request->input('amount'), $idUnico);
                self::generateDepositWoovi($transactionId, $request->input('amount'));

                return response()->json([
                    'status' => true,
                    'idTransaction' => $transactionId,
                    'qrcode' => $brCode,
                    'pixCode' => $brCode,
                    'qrCodeImage' => $qrCodeImage,
                    'paymentLinkUrl' => $paymentLinkUrl,
                ]);
            }

            Log::error('Woovi error creating charge', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return response()->json([
                'error' => 'Erro ao gerar QR Code: ' . $response->body(),
            ], 500);
        } catch (Exception $e) {
            Log::error('Woovi requestQrcode exception: ' . $e->getMessage());
            return response()->json(['error' => 'Erro interno'], 500);
        }
    }

    /**
     * Send PIX transfer for withdrawal (cash out).
     */
    private static function pixCashOutWoovi($id, $tipo)
    {
        Log::info('pixCashOutWoovi started', ['id' => $id, 'tipo' => $tipo]);

        $withdrawal = Withdrawal::find($id);
        if ($tipo === 'afiliado') {
            $withdrawal = AffiliateWithdraw::find($id);
        }

        if ($withdrawal === null) {
            Log::warning('Woovi withdrawal not found', ['id' => $id]);
            return false;
        }

        self::generateCredentialsWoovi();

        if (empty(self::resolveWooviAuthorization())) {
            Log::error('Woovi authorization not configured');
            return false;
        }

        $pixKey = $withdrawal->pix_key;
        $pixType = $withdrawal->pix_type;

        switch ($pixType) {
            case 'document':
                $pixKey = preg_replace('/[^0-9]/', '', (string) $pixKey);
                $pixType = strlen($pixKey) === 11 ? 'CPF' : 'CNPJ';
                break;
            case 'phoneNumber':
                $pixKey = '+55' . preg_replace('/[^0-9]/', '', (string) $pixKey);
                $pixType = 'PHONE';
                break;
            case 'email':
                $pixType = 'EMAIL';
                break;
            case 'randomKey':
                $pixType = 'RANDOM';
                break;
        }

        $idUnico = uniqid();
        $valueInCents = (int) round((float) $withdrawal->amount * 100);

        $payload = [
            'correlationID' => $idUnico,
            'value' => $valueInCents,
            'pixKey' => $pixKey,
            'pixKeyType' => $pixType,
            'comment' => 'Saque - ' . config('app.name'),
        ];

        Log::info('Woovi withdrawal payload', ['payload' => $payload]);

        $response = self::wooviPost('pix-transfers', $payload);

        if (!empty($response) && $response->successful()) {
            $withdrawal->update(['status' => 1]);
            Log::info('Woovi withdrawal success', ['id' => $id]);
            return true;
        }

        Log::error('Woovi withdrawal failed', [
            'id' => $id,
            'status' => $response?->status(),
            'body' => $response?->body(),
        ]);

        return false;
    }

    private static function isValidWooviSignature(Request $request): bool
    {
        $secret = trim(self::$webhookSecretWoovi ?? '');
        if ($secret === '') {
            Log::warning('Woovi webhook secret not configured, skipping signature validation');
            return true;
        }

        $signature = trim((string) $request->header('X-OpenPix-Signature', ''));
        if ($signature === '') {
            Log::warning('Woovi webhook signature header missing');
            return false;
        }

        $payload = $request->getContent();
        $expected = base64_encode(hash_hmac('sha1', $payload, $secret, true));

        return hash_equals($expected, $signature);
    }

    /**
     * Woovi callback/webhook endpoint.
     */
    private static function webhookWoovi(Request $request)
    {
        self::generateCredentialsWoovi();

        if (!self::isValidWooviSignature($request)) {
            return response()->json(['error' => 'invalid signature'], 401);
        }

        $data = $request->json()->all();
        if (empty($data)) {
            $data = $request->all();
        }

        Log::info('Woovi webhook received', ['data' => $data]);

        $event = data_get($data, 'event');

        $validPaymentEvents = [
            'OPENPIX:CHARGE_COMPLETED',
            'OPENPIX:TRANSACTION_RECEIVED',
            'OPENPIX:CHARGE_PAID',
            'charge.completed',
        ];

        $expiredEvents = [
            'OPENPIX:CHARGE_EXPIRED',
            'charge.expired',
        ];

        if (in_array($event, $expiredEvents, true)) {
            Log::info('Woovi webhook charge expired', ['event' => $event]);
            return response()->json(['status' => 'expired'], 200);
        }

        if (!in_array($event, $validPaymentEvents, true)) {
            Log::info('Woovi webhook ignored event', ['event' => $event]);
            return response()->json(['status' => 'ignored'], 200);
        }

        $charge = data_get($data, 'charge')
            ?? data_get($data, 'data.charge')
            ?? data_get($data, 'payment.charge');

        if (empty($charge)) {
            Log::error('Woovi webhook charge not found');
            return response()->json(['error' => 'charge not found'], 400);
        }

        $transactionId = data_get($charge, 'correlationID')
            ?? data_get($charge, 'id')
            ?? data_get($data, 'correlationID');

        if (empty($transactionId)) {
            Log::error('Woovi webhook transaction id not found');
            return response()->json(['error' => 'transaction id not found'], 400);
        }

        $transaction = Transaction::where('payment_id', $transactionId)
            ->where('status', 0)
            ->first();

        if (empty($transaction)) {
            Log::warning('Woovi webhook transaction missing or already processed', [
                'transactionId' => $transactionId,
            ]);

            return response()->json(['status' => 'already processed or not found'], 200);
        }

        $payment = self::finalizePaymentWoovi($transactionId, $charge);

        if ($payment) {
            Log::info('Woovi webhook payment processed', [
                'transactionId' => $transactionId,
            ]);
            return response()->json(['status' => 'success'], 200);
        }

        Log::error('Woovi webhook payment failed', [
            'transactionId' => $transactionId,
        ]);

        return response()->json(['error' => 'payment processing failed'], 500);
    }

    /**
     * Finalize payment and credit wallet.
     */
    private static function finalizePaymentWoovi($transactionId, $chargeData = null)
    {
        $transaction = Transaction::where('payment_id', $transactionId)
            ->where('status', 0)
            ->first();

        if (empty($transaction)) {
            Log::error('Woovi finalizePayment: transaction not found', ['id' => $transactionId]);
            return false;
        }

        $user = User::find($transaction->user_id);
        $wallet = Wallet::where('user_id', $transaction->user_id)->first();

        if (empty($wallet)) {
            Log::error('Woovi finalizePayment: wallet not found', ['user_id' => $transaction->user_id]);
            return false;
        }

        $setting = Setting::first();

        $checkTransactions = Transaction::where('user_id', $transaction->user_id)
            ->where('status', 1)
            ->count();

        if ($checkTransactions == 0) {
            $bonus = Core::porcentagem_xn($setting->initial_bonus, $transaction->price);
            $wallet->increment('balance_bonus', $bonus);
            $wallet->update(['balance_bonus_rollover' => $bonus * $setting->rollover]);
        }

        $wallet->update(['balance_deposit_rollover' => $transaction->price * intval($setting->rollover_deposit)]);

        $configRounds = ConfigRoundsFree::orderBy('value', 'asc')->get();
        foreach ($configRounds as $value) {
            if ($transaction->price >= $value->value) {
                $dados = [
                    'username' => $user->email,
                    'game_code' => $value->game_code,
                    'rounds' => $value->spins,
                ];
                PlayFiverService::RoundsFree($dados);
                break;
            }
        }

        if ($wallet->increment('balance', $transaction->price)) {
            if ($transaction->update(['status' => 1])) {
                $deposit = Deposit::where('payment_id', $transactionId)
                    ->where('status', 0)
                    ->first();

                if (!empty($deposit)) {
                    $affHistoryCPA = AffiliateHistory::where('user_id', $user->id)
                        ->where('commission_type', 'cpa')
                        ->where('status', 0)
                        ->first();

                    if (!empty($affHistoryCPA)) {
                        $sponsorCpa = User::find($user->inviter);
                        if (!empty($sponsorCpa)) {
                            $depositedAmount = $transaction->price;

                            if ($affHistoryCPA->deposited_amount >= $sponsorCpa->affiliate_baseline
                                || $deposit->amount >= $sponsorCpa->affiliate_baseline) {
                                $walletCpa = Wallet::where('user_id', $affHistoryCPA->inviter)->first();
                                if (!empty($walletCpa)) {
                                    $walletCpa->increment('refer_rewards', $sponsorCpa->affiliate_cpa);
                                    $affHistoryCPA->update([
                                        'status' => 1,
                                        'deposited' => $depositedAmount,
                                        'commission_paid' => $sponsorCpa->affiliate_cpa,
                                    ]);
                                }
                            } else {
                                $affHistoryCPA->update(['deposited_amount' => $transaction->price]);
                            }
                        }
                    }

                    if ($deposit->update(['status' => 1])) {
                        $admins = User::where('role_id', 0)->get();
                        foreach ($admins as $admin) {
                            $admin->notify(new NewDepositNotification($user->name, $transaction->price));
                        }

                        return true;
                    }
                }

                return true;
            }
        }

        return false;
    }

    /**
     * Create deposit record.
     */
    private static function generateDepositWoovi($idTransaction, $amount)
    {
        $userId = auth('api')->user()->id;
        $wallet = Wallet::where('user_id', $userId)->first();

        Deposit::create([
            'payment_id' => $idTransaction,
            'user_id' => $userId,
            'amount' => $amount,
            'type' => 'pix',
            'currency' => $wallet->currency ?? 'BRL',
            'symbol' => $wallet->symbol ?? 'R$',
            'status' => 0,
        ]);
    }

    /**
     * Create transaction record.
     */
    private static function generateTransactionWoovi($idTransaction, $amount, $id)
    {
        $setting = Core::getSetting();

        Transaction::create([
            'payment_id' => $idTransaction,
            'user_id' => auth('api')->user()->id,
            'payment_method' => 'pix',
            'price' => $amount,
            'currency' => $setting->currency_code ?? 'BRL',
            'status' => 0,
            'idUnico' => $id,
        ]);
    }
}
