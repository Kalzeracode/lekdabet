<?php

namespace App\Traits\Gateways;

use App\Helpers\Core;
use App\Models\AffiliateHistory;
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

    /**
     * Gera credenciais do Woovi
     */
    private static function generateCredentialsWoovi()
    {
        $setting = Gateway::first();
        if (!empty($setting)) {
            // URL base da API - garante barra no final
            $uri = $setting->getAttributes()['woovi_uri'] ?? 'https://api.openpix.com.br/';
            self::$uriWoovi = rtrim($uri, '/') . '/api/openpix/v1/';
            
            // AppID vai no header Authorization
            self::$appIdWoovi = $setting->getAttributes()['woovi_client_id'] ?? '';
        }
    }

    /**
     * Solicita QR Code PIX para depósito (Cash In)
     */
    public function requestQrcodeWoovi($request)
    {
        try {
            $setting = Core::getSetting();
            $rules = [
                'amount' => ['required', 'numeric', 'min:' . $setting->min_deposit, 'max:' . $setting->max_deposit],
                'cpf'    => ['required', 'string', 'max:255'],
            ];

            $validator = Validator::make($request->all(), $rules);
            if ($validator->fails()) {
                return response()->json($validator->errors(), 400);
            }

            self::generateCredentialsWoovi();
            
            if (empty(self::$appIdWoovi)) {
                return response()->json(['error' => "AppID não configurado"], 500);
            }

            $idUnico = uniqid();
            $user = auth('api')->user();

            // Valor em centavos (Woovi usa centavos)
            $valueInCents = (int) round($request->input("amount") * 100);

            // Payload para criar cobrança na Woovi/OpenPix
            $payload = [
                "correlationID" => $idUnico,
                "value" => $valueInCents,
                "comment" => "Depósito via PIX - " . config('app.name'),
                "customer" => [
                    "name" => $user->name,
                    "email" => $user->email,
                    "taxID" => \Helper::soNumero($request->cpf),
                ]
            ];

            Log::info('Woovi criando cobrança', ['payload' => $payload]);

            // Chamada à API da Woovi - AppID no header Authorization
            $response = Http::withHeaders([
                'Authorization' => self::$appIdWoovi,
                'Content-Type' => 'application/json',
            ])->post(self::$uriWoovi . 'charge', $payload);

            Log::info('Woovi resposta', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            if ($response->successful()) {
                $responseData = $response->json();
                
                // Extrair dados da resposta
                $charge = $responseData['charge'] ?? $responseData;
                $transactionId = $charge['correlationID'] ?? $idUnico;
                $qrCode = $charge['qrCode'] ?? $charge['brCode'] ?? null;
                $paymentLinkUrl = $charge['paymentLinkUrl'] ?? null;
                
                // Salvar no banco
                self::generateTransactionWoovi($transactionId, $request->input("amount"), $idUnico);
                self::generateDepositWoovi($transactionId, $request->input("amount"));
                
                return response()->json([
                    'status' => true, 
                    'idTransaction' => $transactionId, 
                    'qrcode' => $qrCode,
                    'pixCode' => $qrCode, // brCode é o código PIX copia e cola
                    'paymentLinkUrl' => $paymentLinkUrl,
                ]);
            }

            Log::error('Woovi erro ao criar cobrança', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            
            return response()->json([
                'error' => "Erro ao gerar QR Code: " . $response->body()
            ], 500);

        } catch (Exception $e) {
            Log::error('Woovi requestQrcode exception: ' . $e->getMessage());
            return response()->json(['error' => 'Erro interno'], 500);
        }
    }

    /**
     * Envia PIX para saque (Cash Out)
     * NOTA: A Woovi/OpenPix usa endpoint diferente para saques
     */
    private static function pixCashOutWoovi($id, $tipo)
    {
        Log::info('pixCashOutWoovi iniciada', ['id' => $id, 'tipo' => $tipo]);

        $withdrawal = Withdrawal::find($id);
        if ($tipo === 'afiliado') {
            $withdrawal = \App\Models\AffiliateWithdraw::find($id);
        }
        
        if ($withdrawal === null) {
            Log::warning('Withdrawal não encontrado', ['id' => $id]);
            return false;
        }

        self::generateCredentialsWoovi();

        if (empty(self::$appIdWoovi)) {
            Log::error('AppID não configurado');
            return false;
        }

        // Formata a chave PIX
        $pixKey = $withdrawal->pix_key;
        $pixType = $withdrawal->pix_type;

        switch ($pixType) {
            case 'document':
                $pixKey = preg_replace('/[^0-9]/', '', $pixKey);
                $pixType = strlen($pixKey) === 11 ? 'CPF' : 'CNPJ';
                break;
            case 'phoneNumber':
                $pixKey = '+55' . preg_replace('/[^0-9]/', '', $pixKey);
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
        $valueInCents = (int) round($withdrawal->amount * 100);

        // Payload para transferência PIX na Woovi
        $payload = [
            'correlationID' => $idUnico,
            'value' => $valueInCents,
            'pixKey' => $pixKey,
            'pixKeyType' => $pixType,
            'comment' => 'Saque - ' . config('app.name'),
        ];

        Log::info('Woovi saque payload', ['payload' => $payload]);

        // Endpoint de transferência pode variar - ajuste conforme documentação
        $response = Http::withHeaders([
            'Authorization' => self::$appIdWoovi,
            'Content-Type' => 'application/json',
        ])->post(self::$uriWoovi . 'pix-transfers', $payload);

        Log::info('Woovi saque resposta', [
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        if ($response->successful()) {
            $withdrawal->update(['status' => 1]);
            Log::info('Saque Woovi realizado com sucesso', ['id' => $id]);
            return true;
        }

        Log::error('Woovi saque falhou', [
            'status' => $response->status(),
            'body' => $response->body(),
        ]);
        return false;
    }

    /**
     * Webhook para callback do Woovi
     * Evento: OPENPIX:CHARGE_COMPLETED (cobrança paga)
     */
    private static function webhookWoovi(Request $request)
    {
        $data = $request->all();
        
        Log::info('Woovi webhook recebido', ['data' => $data]);
        
        // Verifica o tipo do evento
        $event = $data['event'] ?? null;
        
        // Eventos válidos de pagamento
        $validEvents = [
            'OPENPIX:CHARGE_COMPLETED',
            'charge.completed',
            'OPENPIX:CHARGE_PAID',
        ];
        
        if (!in_array($event, $validEvents)) {
            Log::info('Woovi webhook: evento ignorado ou não é pagamento', ['event' => $event]);
            return response()->json(['status' => 'ignored'], 200);
        }
        
        // Extrair dados da cobrança
        $charge = $data['charge'] ?? $data['data']['charge'] ?? null;
        
        if (!$charge) {
            Log::error('Woovi webhook: charge não encontrado nos dados');
            return response()->json(['error' => 'Charge not found'], 400);
        }
        
        $transactionId = $charge['correlationID'] ?? $charge['id'] ?? null;
        
        if (!$transactionId) {
            Log::error('Woovi webhook: correlationID não encontrado');
            return response()->json(['error' => 'CorrelationID not found'], 400);
        }

        Log::info('Woovi webhook processando pagamento', [
            'transactionId' => $transactionId,
            'value' => $charge['value'] ?? null,
        ]);

        // Busca transação pendente
        $transaction = Transaction::where('payment_id', $transactionId)
            ->where('status', 0)
            ->first();

        if (!$transaction) {
            Log::warning('Woovi webhook: transação não encontrada ou já processada', [
                'transactionId' => $transactionId
            ]);
            return response()->json(['status' => 'already processed or not found'], 200);
        }

        // Processa o pagamento
        $payment = self::finalizePaymentWoovi($transactionId, $charge);
        
        if ($payment) {
            Log::info('Woovi webhook: pagamento processado com sucesso', [
                'transactionId' => $transactionId
            ]);
            return response()->json(['status' => 'success'], 200);
        } else {
            Log::error('Woovi webhook: falha ao processar pagamento', [
                'transactionId' => $transactionId
            ]);
            return response()->json(['error' => 'Payment processing failed'], 500);
        }
    }

    /**
     * Finaliza o pagamento (atualiza saldo, bônus, etc)
     */
    private static function finalizePaymentWoovi($transactionId, $chargeData = null)
    {
        $transaction = Transaction::where('payment_id', $transactionId)
            ->where('status', 0)
            ->first();
            
        if (empty($transaction)) {
            Log::error('Woovi finalizePayment: transação não encontrada', ['id' => $transactionId]);
            return false;
        }
        
        $user = User::find($transaction->user_id);
        $wallet = Wallet::where('user_id', $transaction->user_id)->first();

        if (empty($wallet)) {
            Log::error('Woovi finalizePayment: carteira não encontrada', ['user_id' => $transaction->user_id]);
            return false;
        }

        $setting = Setting::first();

        // Verifica se é o primeiro depósito
        $checkTransactions = Transaction::where('user_id', $transaction->user_id)
            ->where('status', 1)
            ->count();

        if ($checkTransactions == 0) {
            // Paga o bônus de primeiro depósito
            $bonus = Core::porcentagem_xn($setting->initial_bonus, $transaction->price);
            $wallet->increment('balance_bonus', $bonus);
            $wallet->update(['balance_bonus_rollover' => $bonus * $setting->rollover]);
        }

        // Rollover do depósito
        $wallet->update(['balance_deposit_rollover' => $transaction->price * intval($setting->rollover_deposit)]);

        // Rodadas grátis
        $configRounds = ConfigRoundsFree::orderBy('value', 'asc')->get();
        foreach ($configRounds as $value) {
            if ($transaction->price >= $value->value) {
                $dados = [
                    "username" => $user->email,
                    "game_code" => $value->game_code,
                    "rounds" => $value->spins
                ];
                PlayFiverService::RoundsFree($dados);
                break;
            }
        }

        // Adiciona saldo à carteira
        if ($wallet->increment('balance', $transaction->price)) {
            if ($transaction->update(['status' => 1])) {
                $deposit = Deposit::where('payment_id', $transactionId)
                    ->where('status', 0)
                    ->first();
                    
                if (!empty($deposit)) {
                    // Processa CPA de afiliado
                    $affHistoryCPA = AffiliateHistory::where('user_id', $user->id)
                        ->where('commission_type', 'cpa')
                        ->where('status', 0)
                        ->first();

                    if (!empty($affHistoryCPA)) {
                        $sponsorCpa = User::find($user->inviter);
                        if (!empty($sponsorCpa)) {
                            $deposited_amount = $transaction->price;

                            if ($affHistoryCPA->deposited_amount >= $sponsorCpa->affiliate_baseline 
                                || $deposit->amount >= $sponsorCpa->affiliate_baseline) {
                                $walletCpa = Wallet::where('user_id', $affHistoryCPA->inviter)->first();
                                if (!empty($walletCpa)) {
                                    $walletCpa->increment('refer_rewards', $sponsorCpa->affiliate_cpa);
                                    $affHistoryCPA->update([
                                        'status' => 1,
                                        'deposited' => $deposited_amount,
                                        'commission_paid' => $sponsorCpa->affiliate_cpa
                                    ]);
                                }
                            } else {
                                $affHistoryCPA->update(['deposited_amount' => $transaction->price]);
                            }
                        }
                    }
                    
                    if ($deposit->update(['status' => 1])) {
                        // Notifica admins
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
     * Cria registro de depósito
     */
    private static function generateDepositWoovi($idTransaction, $amount)
    {
        $userId = auth('api')->user()->id;
        $wallet = Wallet::where('user_id', $userId)->first();

        Deposit::create([
            'payment_id' => $idTransaction,
            'user_id'   => $userId,
            'amount'    => $amount,
            'type'      => 'pix',
            'currency'  => $wallet->currency ?? 'BRL',
            'symbol'    => $wallet->symbol ?? 'R$',
            'status'    => 0
        ]);
    }

    /**
     * Cria registro de transação
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
            "idUnico" => $id
        ]);
    }
}