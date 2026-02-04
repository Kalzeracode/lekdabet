<?php

namespace App\Filament\Pages;

use App\Models\Gateway;
use Illuminate\Support\HtmlString;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;


class GatewayPage extends Page
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static string $view = 'filament.pages.gateway-page';

    public ?array $data = [];
    public Gateway $setting;

    /**
     * @dev
     * @return bool
     */
    public static function canAccess(): bool
    {
        return auth()->user()->hasRole('admin'); // Controla o acesso total à página
    }

    public static function canView(): bool
    {
        return auth()->user()->hasRole('admin'); // Controla a visualização de elementos específicos
    }

    /**
     * @return void
     */
    public function mount(): void
    {
        $gateway = Gateway::first();
        if (!empty($gateway)) {
            $this->setting = $gateway;
            $this->form->fill($this->setting->toArray());
        } else {
            $this->form->fill();
        }
    }

    /**
     * @param Form $form
     * @return Form
     */
    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('OBETZERACRIOU ESSA PLATAFORMA PARA VOCÊ')
                    ->description(new HtmlString('
                    <div style="font-weight: 600; display: flex; align-items: center;">
                        SAIBA MAIS SOBRE NÓS. PARTICIPE DA NOSSA COMUNIDADE IGAMING. ACESSE AGORA!
                        <a class="dark:text-white"
                           style="
                                font-size: 14px;
                                font-weight: 600;
                                width: 127px;
                                display: flex;
                                background-color: #00b91e;
                                padding: 10px;
                                border-radius: 11px;
                                justify-content: center;
                                margin-left: 10px;
                           "
                           href="https://obetzera.com "
                           target="_blank">
                            SITE OFICIAL
                        </a>
                        <a class="dark:text-white"
                           style="
                                font-size: 14px;
                                font-weight: 600;
                                width: 127px;
                                display: flex;
                                background-color: #00b91e;
                                padding: 10px;
                                border-radius: 11px;
                                justify-content: center;
                                margin-left: 10px;
                           "
                           href="https://t.me/obetzera01 "
                           target="_blank">
                            GRUPO TELEGRAM
                        </a>
                    </div>
            ')),


                Section::make('REGISTRE SUAS CHAVES DE API GATEWAY')
                    ->description('Configure suas chaves de API para os gateways de pagamento')
                    ->schema([
                        Section::make('ONDAPAY A MAIS RECOMENDADA NO MERCADO')
                            ->description(new HtmlString('
                                <div style="display: flex; align-items: center;">
                                    Crie sua conta para processar pagamentos com a ONDAPAY:
                                    <a class="dark:text-white"
                                        style="
                                            font-size: 14px;
                                            font-weight: 600;
                                            width: 127px;
                                            display: flex;
                                            background-color: #00b91e;
                                            padding: 10px;
                                            border-radius: 11px;
                                            justify-content: center;
                                            margin-left: 10px;
                                        "
                                        href="https://ondapay.app/ "
                                        target="_blank">
                                        Abrir Conta
                                    </a>
                                </div>
                        '),)
                            ->schema([
                                TextInput::make('ondapay_uri')
                                    ->label('CLIENTE URL')
                                    ->placeholder('Digite a url da api')
                                    ->maxLength(191)
                                    ->columnSpanFull(),
                                TextInput::make('ondapay_client')
                                    ->label('CLIENTE ID')
                                    ->placeholder('Digite o client ID')
                                    ->maxLength(191)
                                    ->columnSpanFull(),
                                TextInput::make('ondapay_secret')
                                    ->label('CLIENTE SECRETO')
                                    ->placeholder('Digite o client secret')
                                    ->maxLength(191)
                                    ->columnSpanFull(),
                            ]),
                        Section::make('DIGITO PAY | SUPORTE RUIM')
                            ->schema([
                                TextInput::make('digito_uri')
                                    ->label('CLIENTE URL')
                                    ->placeholder('Digite a url da api')
                                    ->maxLength(191)
                                    ->columnSpanFull(),
                                TextInput::make('digito_client')
                                    ->label('CLIENTE ID')
                                    ->placeholder('Digite o client ID')
                                    ->maxLength(191)
                                    ->columnSpanFull(),
                                TextInput::make('digito_secret')
                                    ->label('CLIENTE SECRETO')
                                    ->placeholder('Digite o client secret')
                                    ->maxLength(191)
                                    ->columnSpanFull(),
                            ]),

                        Section::make('BSPAY E PIXUP | INVENTA MEDPIX PRA TE ROUBAR')
                            ->description(new HtmlString('
                        <b>Seu Webhook:  ' . url("/bspay/callback", [], true) . "</b>"))
                            ->schema([
                                TextInput::make('bspay_uri')
                                    ->label('CLIENTE URL')
                                    ->placeholder('Digite a url da api')
                                    ->maxLength(191)
                                    ->columnSpanFull(),
                                TextInput::make('bspay_cliente_id')
                                    ->label('CLIENTE ID')
                                    ->placeholder('Digite o client ID')
                                    ->maxLength(191)
                                    ->columnSpanFull(),
                                TextInput::make('bspay_cliente_secret')
                                    ->label('CLIENTE SECRETO')
                                    ->placeholder('Digite o client secret')
                                    ->maxLength(191)
                                    ->columnSpanFull(),
                            ]),
                        
                        // ← NOVO: SECTION WOOVI ADICIONADA AQUI
                        Section::make('WOOVI | GATEWAY MODERNO E CONFIÁVEL')
                            ->description(new HtmlString('
                        <b>Seu Webhook:  ' . url("/woovi/callback", [], true) . "</b>"))
                            ->schema([
                                TextInput::make('woovi_uri')
                                    ->label('CLIENTE URL')
                                    ->placeholder('Digite a url da api (ex: https://api.woovi.com/)')
                                    ->maxLength(255)
                                    ->columnSpanFull(),
                                TextInput::make('woovi_client_id')
                                    ->label('CLIENTE ID (App ID)')
                                    ->placeholder('Digite o client ID')
                                    ->maxLength(255)
                                    ->columnSpanFull(),
                                TextInput::make('woovi_client_secret')
                                    ->label('CLIENTE SECRETO')
                                    ->placeholder('Digite o client secret')
                                    ->maxLength(255)
                                    ->columnSpanFull(),
                            ]),
                        
                        Section::make('EZZEPAY | ESTA ATUALMENTE SENDO INVESTIGADA')
                            ->description(new HtmlString('
                        <b>Seu Webhook:  ' . url("/ezzepay/webhook", [], true) . "</b>"))
                            ->schema([
                                TextInput::make('ezze_uri')
                                    ->label('CLIENTE URL')
                                    ->placeholder('Digite a url da api')
                                    ->maxLength(191)
                                    ->columnSpanFull(),
                                TextInput::make('ezze_client')
                                    ->label('CLIENTE ID')
                                    ->placeholder('Digite o client ID')
                                    ->maxLength(191)
                                    ->columnSpanFull(),
                                TextInput::make('ezze_secret')
                                    ->label('CLIENTE SECRETO')
                                    ->placeholder('Digite o client secret')
                                    ->maxLength(191)
                                    ->columnSpanFull(),
                                TextInput::make('ezze_user')
                                    ->label('USUARIO DO WEBHOOK')
                                    ->placeholder('Digite o usuário de autenticação do webhook')
                                    ->maxLength(191)
                                    ->columnSpanFull(),
                                TextInput::make('ezze_senha')
                                    ->label('SENHA DO WEBHOOK')
                                    ->placeholder('Digite a senha de autenticação do webhook')
                                    ->maxLength(191)
                                    ->columnSpanFull(),
                            ]),


                        Section::make('SUITEPAY | MUITO MEDPIX E DIFICIL DE ABRIR CONTAS')
                            ->description(new HtmlString('
                        <b>Para fazer saques libere o IP</b>'))
                            ->schema([
                                TextInput::make('suitpay_uri')
                                    ->label('CLIENTE URL')
                                    ->placeholder('Digite a url da api')
                                    ->maxLength(191)
                                    ->columnSpanFull(),
                                TextInput::make('suitpay_cliente_id')
                                    ->label('CLIENTE ID')
                                    ->placeholder('Digite o client ID')
                                    ->maxLength(191)
                                    ->columnSpanFull(),
                                TextInput::make('suitpay_cliente_secret')
                                    ->label('CLIENTE SECRETO')
                                    ->placeholder('Digite o client secret')
                                    ->maxLength(191)
                                    ->columnSpanFull(),
                            ]),
                        // Adicione esta seção dentro do array passado para ->schema([ ... ])
                        Section::make('Confirmação de Alteração')
                            ->schema([
                                TextInput::make('admin_password')
                                    ->label('Senha de 2FA a que esta no arquivo (.env)')
                                    ->placeholder('Digite a senha de 2FA')
                                    ->password()
                                    ->required()
                                    ->dehydrateStateUsing(fn($state) => null), // Para que o valor não seja persistido
                            ]),

                    ]),
            ])
            ->statePath('data');
    }


    /**
     * @return void
     */
    /**
     * @return void
     */
    public function submit(): void
    {
        try {
            if (env('APP_DEMO')) {
                Notification::make()
                    ->title('Atenção')
                    ->body('Você não pode realizar esta alteração na versão demo')
                    ->danger()
                    ->send();
                return;
            }

            // Validação da senha de 2FA
            if (
                !isset($this->data['admin_password']) ||
                $this->data['admin_password'] !== env('TOKEN_DE_2FA')
            ) {
                Notification::make()
                    ->title('Acesso Negado')
                    ->body('A senha de 2FA está incorreta. Você não pode atualizar os dados.')
                    ->danger()
                    ->send();
                return;
            }

            $setting = Gateway::first();
            if (!empty($setting)) {
                if ($setting->update($this->data)) {
                    Notification::make()
                        ->title('ACESSE ONDAGAMES.COM')
                        ->body('Suas configurações foram atualizadas com sucesso!')
                        ->success()
                        ->send();
                }
            } else {
                if (Gateway::create($this->data)) {
                    Notification::make()
                        ->title('ACESSE ONDAGAMES.COM')
                        ->body('Suas configurações foram criadas com sucesso!')
                        ->success()
                        ->send();
                }
            }
        } catch (\Filament\Support\Exceptions\Halt $exception) {
            Notification::make()
                ->title('Erro ao alterar dados!')
                ->body('Erro ao alterar dados!')
                ->danger()
                ->send();
        }
    }

}