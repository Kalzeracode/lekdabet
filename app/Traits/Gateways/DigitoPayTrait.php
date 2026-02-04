<?php

namespace App\Traits\Gateways;

use App\Helpers\Core;
use App\Models\AffiliateHistory;
use App\Models\AffiliateLogs;
use App\Models\AffiliateWithdraw;
use App\Models\Deposit;
use App\Models\DigitoPay;
use App\Models\Gateway;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Withdrawal;
use App\Notifications\NewDepositNotification;
use Exception;
use App\Helpers\Core as Helper;
use App\Models\ConfigRoundsFree;
use App\Services\PlayFiverService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

trait DigitoPayTrait
{
    protected static string $uriDigito;
    protected static string $clienteIdDigito;
    protected static string $clienteSecretDigito;

    private static function generateCredentialsDigito()
    {
        $setting = Gateway::first();
        if (!empty($setting)) {
            self::$uriDigito = $setting->getAttributes()['digito_uri'];

            self::$clienteIdDigito = $setting->getAttributes()['digito_client'];
            self::$clienteSecretDigito = $setting->getAttributes()['digito_secret'];
        }
    }
    private static function getToken()
    {
        try {
            $response = Http::post(self::$uriDigito . 'token/api', array_merge([
                "clientId" => self::$clienteIdDigito,
                "secret" => self::$clienteSecretDigito
            ]));
            if ($response->successful()) {
                $responseData = $response->json();
                if (isset($responseData['accessToken'])) {
                    return ['error' => '', 'acessToken' => $responseData['accessToken']];
                } else {
                    return ['error' => 'Internal Server Error', 'acessToken' => ""];
                }
            } else {
                return ['error' => 'Internal Server Error', 'acessToken' => ""];
            }
        } catch (Exception $e) {
            return ['error' => 'Internal Server Error', 'acessToken' => ""];
        }
    }
    public function requestQrcodeDigito($request)
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


            /// cpfgenerator ANTI-MEDPIX

            self::generateCredentialsDigito();
            $token = self::getToken();
            if ($token['error'] != "") {
                return response()->json(['error' => 'Ocorreu uma falha ao entrar em contato com o banco.'], 500);
            }


            $idUnico = uniqid();

            $response = Http::withHeaders([
                "Authorization" => "Bearer " . $token['acessToken']
            ])->post(self::$uriDigito . 'deposit', array_merge([
                "dueDate" => date('Y-m-d\TH:i:s\Z', strtotime('+1 day')),
                "paymentOptions" => ["PIX"],
                "person" => [
                    'cpf' => \Helper::soNumero($request->cpf),
                    'name' => auth('api')->user()->name,
                ],
                "value" => (float) $request->input("amount"),
                "callbackUrl" =>  url('/digitopay/callback?id_unico=' . self::$clienteIdDigito, [], true)
            ]));
            if ($response->successful()) {
                $responseData = $response->json();
                self::generateTransactionDigito($responseData['id'], $request->input("amount"), $idUnico);
                self::generateDepositDigito($responseData['id'], $request->input("amount"));
                return response()->json(['status' => true, 'idTransaction' => $responseData['id'], 'qrcode' => $responseData['pixCopiaECola']]);
            }
            return response()->json(['error' => "Ocorreu uma falha ao entrar em contato com o bancoe."], 500);
        } catch (Exception $e) {
            return response()->json(['error' => 'Erro interno'], 500);
        }
    }

    public function webhookDigito($request)
    {
        self::generateCredentialsDigito();
        if (self::finalizaPaymentDigito($request->input("id")) == true && self::$clienteIdDigito == $request->input("id_unico")) {
            return response()->json([], 200);
        } else {
            return response()->json([], 500);
        }
    }
    private static function finalizaPaymentDigito($idTransaction)
    {


        $transaction = Transaction::where('payment_id', $idTransaction)->where('status', 0)->first();
        $setting = Helper::getSetting();
        if (!empty($transaction)) {
            $user = User::find($transaction->user_id);

            $wallet = Wallet::where('user_id', $transaction->user_id)->first();

            if (!empty($wallet)) {
                $setting = Setting::first();

                /// verifica se é o primeiro deposito, verifica as transações, somente se for transações concluidas
                $checkTransactions = Transaction::where('user_id', $transaction->user_id)
                    ->where('status', 1)
                    ->count();

                if ($checkTransactions == 0 || empty($checkTransactions)) {
                    /// pagar o bonus
                    $bonus = Helper::porcentagem_xn($setting->initial_bonus, $transaction->price);
                    $wallet->increment('balance_bonus', $bonus);
                    $wallet->update(['balance_bonus_rollover' => $bonus * $setting->rollover]);
                }

                /// rollover deposito
                $wallet->update(['balance_deposit_rollover' => $transaction->price * intval($setting->rollover_deposit)]);

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

                if ($wallet->increment('balance', $transaction->price)) {
                    if ($transaction->update(['status' => 1])) {
                        $deposit = Deposit::where('payment_id', $idTransaction)->where('status', 0)->first();
                        if (!empty($deposit)) {

                            /// fazer o deposito em cpa
                            $affHistoryCPA = AffiliateHistory::where('user_id', $user->id)
                                ->where('commission_type', 'cpa')
                                //->where('deposited', 1)
                                ->where('status', 0)
                                ->first();

                            if (!empty($affHistoryCPA)) {

                                // Verifica se o CPA pode ser pago com base no baseline do sponsor
                                $sponsorCpa = User::find($user->inviter);
                                if (!empty($sponsorCpa)) {
                                    // Defina o valor do depósito para ser atualizado
                                    $deposited_amount = $transaction->price;

                                    // Verifica se o valor acumulado ou o depósito atual atinge o baseline
                                    if ($affHistoryCPA->deposited_amount >= $sponsorCpa->affiliate_baseline || $deposit->amount >= $sponsorCpa->affiliate_baseline) {
                                        $walletCpa = Wallet::where('user_id', $affHistoryCPA->inviter)->first();
                                        if (!empty($walletCpa)) {

                                            // Paga o valor de CPA
                                            $walletCpa->increment('refer_rewards', $sponsorCpa->affiliate_cpa); // Adiciona a comissão
                                            $affHistoryCPA->update([
                                                'status' => 1,
                                                'deposited' => $deposited_amount,
                                                'commission_paid' => $sponsorCpa->affiliate_cpa
                                            ]); // Atualiza e desativa CPA

                                        }
                                    } else {
                                        // Atualiza o valor depositado no histórico de afiliados
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
                            return false;
                        }
                        return false;
                    }
                }

                return false;
            }
            return false;
        }
        return false;
    }


    public function pixCashOutDigito($id, $tipo)
    {
        $corrId = (string) Str::uuid();

        // mantém apenas dígitos
        $digits = function (?string $v) {
            return $v ? preg_replace('/\D+/', '', $v) : null;
        };

        // máscara segura p/ logs
        $maskPix = function (?string $key) {
            if (empty($key)) return null;
            $len = mb_strlen($key);
            if ($len <= 4) return str_repeat('*', $len);
            return mb_substr($key, 0, 2) . str_repeat('*', max(0, $len - 4)) . mb_substr($key, -2);
        };

        try {


            $withdrawal = Withdrawal::find($id);
            self::generateCredentialsDigito();

            if ($tipo === "afiliado") {
                $withdrawal = AffiliateWithdraw::find($id);
            }

            $token = self::getToken();
            if (!is_array($token) || (isset($token['error']) && $token['error'] !== "")) {

                return false;
            }

            if (!$withdrawal) {
                return false;
            }

            // Definição de chave PIX e documento
            $pixTipo = null;   // person.pixKeyTypes
            $pixKey  = null;   // person.pixKey
            $cpf  = null;      // person.cpf (opcional)
            $cnpj = null;      // person.cnpj (opcional)

            switch ($withdrawal->pix_type) {
                case 'document':
                    $raw = $digits($withdrawal->pix_key);
                    if (strlen($raw) > 11) {
                        $pixTipo = 'CNPJ';
                        $pixKey  = $raw;  // chave = CNPJ só números
                        $cnpj    = $raw;
                    } else {
                        $pixTipo = 'CPF';
                        $pixKey  = $raw;  // chave = CPF só números
                        $cpf     = $raw;
                    }
                    break;

                case 'phoneNumber':
                    // API usa rótulo PT-BR
                    $pixTipo = 'TELEFONE';
                    $pixKey  = '+55' . $digits($withdrawal->pix_key);
                    break;

                case 'email':
                    $pixTipo = 'EMAIL';
                    $pixKey  = $withdrawal->pix_key;
                    break;

                case 'randomKey':
                    $pixTipo = 'CHAVE_ALEATORIA';
                    $pixKey  = $withdrawal->pix_key;
                    break;
            }

            // Monta "person" com campos condicionais
            $person = [
                'name'        => $withdrawal->name,
                'pixKeyTypes' => $pixTipo,
                'pixKey'      => $pixKey,
            ];
            if ($cpf)  { $person['cpf']  = $cpf; }
            if ($cnpj) { $person['cnpj'] = $cnpj; }

            $payload = [
                "value"          => $withdrawal->amount,
                "endToEndId"     => null,
                "paymentOptions" => ["PIX"],
                "person"         => $person,
            ];


            // Importante: não lançar exceção automática (422/4xx)
            $response = Http::withHeaders([
                    "Authorization" => "Bearer " . $token['acessToken']
                ])
                ->timeout(30)
                ->retry(2, 500, null, false)              // não chamar throw() após última tentativa
                ->withOptions(['http_errors' => false])   // Guzzle não lança exceção por 4xx/5xx
                ->post(rtrim(self::$uriDigito, '/') . '/withdraw', $payload);

            $status = $response->status();
            $json   = null;
            try { $json = $response->json(); } catch (\Throwable $e) {}


            if ($response->successful()) {
                $withdrawal->update(['status' => 1]);
                DigitoPay::create([
                    "user_id"       => $withdrawal->user_id,
                    "withdrawal_id" => $withdrawal->id,
                    "amount"        => $withdrawal->amount,
                    "status"        => 1
                ]);


                return true;
            }

            // Regra especial 422 + análise manual
            $mensagem = is_array($json) ? ($json['mensagem'] ?? $json['message'] ?? null) : null;
            if ($status == 422 && $mensagem && mb_stripos($mensagem, 'necessita de analise manual') !== false) {
                $withdrawal->update(['status' => 1]);
                DigitoPay::create([
                    "user_id"       => $withdrawal->user_id,
                    "withdrawal_id" => $withdrawal->id,
                    "amount"        => $withdrawal->amount,
                    "status"        => 1
                ]);


                return true;
            }


            return false;

        } catch (\Throwable $e) {

            return false;
        }
    }

    private static function generateDepositDigito($idTransaction, $amount)
    {
        $userId = auth('api')->user()->id;
        $wallet = Wallet::where('user_id', $userId)->first();

        Deposit::create([
            'payment_id' => $idTransaction,
            'user_id'   => $userId,
            'amount'    => $amount,
            'type'      => 'pix',
            'currency'  => $wallet->currency,
            'symbol'    => $wallet->symbol,
            'status'    => 0
        ]);
    }
    private static function generateTransactionDigito($idTransaction, $amount, $id)
    {
        $setting = Core::getSetting();

        Transaction::create([
            'payment_id' => $idTransaction,
            'user_id' => auth('api')->user()->id,
            'payment_method' => 'pix',
            'price' => $amount,
            'currency' => $setting->currency_code,
            'status' => 0,
            "idUnico" => $id
        ]);
    }
}
