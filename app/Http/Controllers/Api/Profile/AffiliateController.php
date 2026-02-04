<?php

namespace App\Http\Controllers\Api\Profile;

use App\Http\Controllers\Controller;
use App\Models\AffiliateWithdraw;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AffiliateController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $userId       = auth('api')->id();
        $user         = User::find($userId);
        $indications  = User::where('inviter', $userId)->count();
        $walletDefault = Wallet::where('user_id', $userId)->first();

        return response()->json([
            'status'      => true,
            'code'        => $user?->inviter_code,
            'url'         => config('app.url') . '/register?code=' . ($user?->inviter_code),
            'indications' => $indications,
            'wallet'      => $walletDefault,
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function generateCode()
    {
        $code = $this->gencode();
        $setting = \Helper::getSetting();

        if (!empty($code)) {
            $user = auth('api')->user();

            \DB::table('model_has_roles')->updateOrInsert(
                [
                    'role_id'    => 2,
                    'model_type' => 'App\Models\User',
                    'model_id'   => $user->id,
                ],
            );

            if ($user->update([
                'inviter_code'           => $code,
                'affiliate_revenue_share'=> $setting->revshare_percentage
            ])) {
                return response()->json(['status' => true, 'message' => trans('Successfully generated code')]);
            }

            return response()->json(['error' => ''], 400);
        }

        return response()->json(['error' => ''], 400);
    }

    /**
     * @return null
     */
    private function gencode()
    {
        $code = \Helper::generateCode(10);

        $checkCode = User::where('inviter_code', $code)->first();
        if (empty($checkCode)) {
            return $code;
        }

        return $this->gencode();
    }

    /**
     * Store a newly created resource in storage.
     */
    public function makeRequest(Request $request)
    {
        $rules = [
            'amount'   => ['required', 'numeric', 'min:0.01'],
            'pix_type' => ['required'],
        ];

        switch ($request->pix_type) {
            case 'document':
                $rules['pix_key'] = 'required|cpf_ou_cnpj';
                break;
            case 'email':
                $rules['pix_key'] = 'required|email';
                break;
            case 'phoneNumber':
                // só números, 10 ou 11 dígitos
                $rules['pix_key'] = ['required', 'regex:/^\d{10,11}$/'];
                break;
            default:
                $rules['pix_key'] = 'required';
                break;
        }

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 400);
        }

        $userId = auth('api')->id();
        $userName = User::where('id', $userId)->value('name'); // <- pega nome direto do banco
        $wallet = Wallet::where('user_id', $userId)->first();
        $comission = (float) ($wallet?->refer_rewards ?? 0);

        if ($comission >= (float) $request->amount) {
            AffiliateWithdraw::create([
                'user_id'  => $userId,
                'amount'   => $request->amount,
                'pix_key'  => $request->pix_key,
                'pix_type' => $request->pix_type,
                'currency' => 'BRL',
                'symbol'   => 'R$',
                'name'     => $userName, // <- sem fallback esquisito
            ]);

            // decrementa com segurança
            $wallet?->decrement('refer_rewards', (float) $request->amount);

            return response()->json(['message' => trans('Commission withdrawal successfully carried out')], 200);
        }

        return response()->json(['status' => false, 'error' => 'Você não tem saldo suficiente']);
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
