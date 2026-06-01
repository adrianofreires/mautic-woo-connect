<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MWC_Admin {

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );
        add_action( 'admin_init', [ $this, 'register_mwc_settings' ] );
        add_action( 'admin_notices', [ $this, 'display_auth_error_notice' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
        add_action( 'admin_init', [ $this, 'handle_mautic_authorization' ] );
    }

    /**
     * Carrega estilos e scripts apenas na nossa página de configurações
     */
    public function enqueue_admin_assets( $hook ) {
    if ( $hook !== 'toplevel_page_mwc-settings' ) {
        return;
    }

    // Select2 (SelectWoo) nativo do WooCommerce
    wp_enqueue_style( 'woocommerce_admin_styles', WC()->plugin_url() . '/assets/css/admin.css' );
    wp_enqueue_script( 'selectWoo' );

    // CSS próprio do admin do plugin
    $css_path = MWC_PLUGIN_DIR . 'assets/css/admin.css';
    wp_enqueue_style(
        'mwc-admin',
        MWC_PLUGIN_URL . 'assets/css/admin.css',
        [],
        file_exists( $css_path ) ? filemtime( $css_path ) : MWC_VERSION
    );
}

    public function add_admin_menu() {
        // String limpa em 1 linha, usando viewBox apropriado e paths preenchidos sem bordas (stroke)
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 350 350"><g fill="#a0a5aa" fill-rule="evenodd" transform="translate(-303.933, -0.589)"><path d="M478.822,350.368C382.545,350.368 303.932,272.05 303.932,175.478C303.932,78.906 382.544,0.588 478.822,0.588C502.082,0.588 524.753,5.005 545.951,14.131C551.84,16.781 554.784,23.553 552.429,29.736C549.78,35.624 543.008,38.569 536.825,36.213C518.276,28.558 498.845,24.731 478.823,24.731C395.5,24.731 327.782,92.449 327.782,175.772C327.782,259.095 395.5,326.813 478.822,326.813C562.145,326.813 629.863,259.095 629.863,175.772C629.863,157.812 626.919,140.44 620.736,123.953C618.381,117.77 621.619,110.998 627.802,108.643C633.985,106.288 640.756,109.526 643.112,115.709C650.178,134.847 653.712,155.162 653.712,175.772C653.712,271.756 575.395,350.368 478.822,350.368Z"/><path d="M555.373,157.519L528.286,185.783L543.302,249.085L577.455,249.085L555.373,157.519Z"/><path d="M544.186,73.901L553.606,83.323L478.822,162.523L414.932,96.866L378.128,249.085L412.282,249.085L432.598,164.584L478.822,214.932L577.75,107.171L587.172,116.888L596.593,63.596L544.186,73.901Z"/></g></svg>';

        $mautic_icon = 'data:image/svg+xml;base64,' . base64_encode( $svg );

        add_menu_page(
            'Mautic Woo Connect',           // Título da página
            'Mautic Connect',               // Título do menu
            'manage_options',               // Permissão necessária
            'mwc-settings',                 // Slug (URL)
            [ $this, 'render_admin_page' ], // Função de renderização
            $mautic_icon,                   // A variável dinâmica corrigida
            56                              // Posição no menu
        );
    }

    /**
     * OTIMIZAÇÃO: Usa um array para registrar todas as variáveis de uma vez (DRY)
     */
    public function register_mwc_settings() {
        $settings = [
            'mwc_base_url',
            'mwc_client_id',
            'mwc_client_secret',
            'mwc_valid_order_statuses',
            'mwc_tag_abandoned',
            'mwc_prefix_category',
            'mwc_prefix_product',
            'mwc_prefix_coupon',
            'mwc_recovery_discount',
            'mwc_field_mapping',
            'mwc_enable_auto_origin',
            'mwc_origin_prefix',
            'mwc_origin_type',
            'mwc_site_origin_tag'
        ];

        foreach ( $settings as $setting ) {
            register_setting( 'mwc_settings_group', $setting );
        }
    }

    /**
     * Sistema de Sirene (Alerta)
     */
    public function display_auth_error_notice() {
        if ( get_option( 'mwc_mautic_auth_error' ) ) {
            $settings_url = admin_url( 'admin.php?page=mwc-settings' );
            ?>
            <div class="notice notice-error is-dismissible">
                <p>
                    <strong>🚨 Atenção - Integração WooCommerce + Mautic paralisada!</strong><br>
                    A conexão perdeu a validade por segurança (OAuth 2.0). 
                    <a href="<?php echo esc_url( $settings_url ); ?>">Clique aqui para Reconectar</a>.
                </p>
            </div>
            <?php
        }
    }

    /**
     * Intercepta o clique no botão "Conectar" e processa o OAuth 2.0
     */
    public function handle_mautic_authorization() {
        // 1. GARANTIA DE SESSÃO: Essencial para a biblioteca do Mautic validar o "state"
        if ( ! session_id() ) {
            session_start();
        }

        if ( isset( $_GET['page'] ) && $_GET['page'] === 'mwc-settings' ) {
            if ( ( isset( $_GET['mwc_action'] ) && $_GET['mwc_action'] === 'authorize' ) || isset( $_GET['code'] ) ) {
                
                $api_url       = rtrim( get_option('mwc_base_url'), '/' );
                $client_id     = get_option('mwc_client_id');
                $client_secret = get_option('mwc_client_secret');

                if ( empty( $api_url ) || empty( $client_id ) || empty( $client_secret ) ) {
                    return;
                }

                $settings = [
                    'baseUrl'      => $api_url,
                    'version'      => 'OAuth2',
                    'clientKey'    => $client_id,
                    'clientSecret' => $client_secret,
                    'callback'     => admin_url( 'admin.php?page=mwc-settings' )
                ];

                $initAuth = new \Mautic\Auth\ApiAuth();
                $auth     = $initAuth->newAuth( $settings );

                try {
                    // Tenta validar o retorno do Mautic
                    if ( $auth->validateAccessToken() ) {
                        $token_data = $auth->getAccessTokenData();
                        
                        if ( ! empty( $token_data['access_token'] ) ) {
                            $token_data['created_at'] = time(); 
                            
                            update_option( 'mwc_access_token_data', $token_data );
                            delete_option( 'mwc_mautic_auth_error' );
                            
                            // Redireciona e limpa a URL do navegador
                            wp_safe_redirect( admin_url( 'admin.php?page=mwc-settings' ) );
                            exit;
                        }
                    } 
                    // 2. MODO DEBUG: Se chegou o código na URL mas a biblioteca recusou
                    elseif ( isset( $_GET['code'] ) ) {
                        wp_die( 
                            '<h2>Falha de Segurança na Sessão</h2><p>O Mautic autorizou, mas o seu servidor perdeu a sessão PHP durante o redirecionamento. Verifique se o seu servidor não está bloqueando sessões nativas ou se a URL Base está perfeitamente igual (com ou sem www).</p><br><a href="' . admin_url( 'admin.php?page=mwc-settings' ) . '" class="button button-primary">Voltar e tentar de novo</a>', 
                            'Erro de Sessão PHP'
                        );
                    }
                } catch ( \Exception $e ) {
                    wp_die( 
                        '<h2>Erro na Autenticação com o Mautic</h2><p>Detalhe técnico: ' . $e->getMessage() . '</p><a href="' . admin_url( 'admin.php?page=mwc-settings' ) . '" class="button button-primary">Tentar Novamente</a>', 
                        'Erro Mautic Connect'
                    );
                }
            }
        }
    }

    /**
     * Ponto central de renderização da página (Layout com CSS Nativo do WP)
     */
    public function render_admin_page() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        ?>
        <div class="wrap">
            <h1>⚙️ Mautic Woo Connect</h1>
            <p>Conecte seu WooCommerce ao Mautic e transforme dados em vendas.</p>

            <div id="poststuff">
                <div id="post-body" class="metabox-holder columns-2">
                    
                    <!-- Coluna Principal: Formulário de Configurações -->
                    <div id="post-body-content">
                        <form action="options.php" method="post">
                            <?php settings_fields( 'mwc_settings_group' ); ?>
                            
                            <?php $this->render_card_credentials(); ?>
                            <?php $this->render_card_data_tags(); ?>
                            <?php $this->render_card_abandoned_cart(); ?>
                            <?php $this->render_card_field_mapping(); ?>
                            <?php $this->render_card_webhooks(); ?>
                            
                            <?php submit_button( 'Salvar Todas as Configurações', 'primary', 'submit', true, ['style' => 'width:100%; text-align:center; font-size:16px; padding:10px;'] ); ?>
                        </form>
                    </div>

                    <?php $this->render_admin_scripts(); ?>

                    <!-- Coluna Lateral: Status e Ferramentas Extra -->
                    <div id="postbox-container-1" class="postbox-container">
                        <?php $this->render_card_connection_status(); ?>
                        <?php $this->render_card_bulk_sync(); ?>
                    </div>

                </div>
            </div>
        </div>
        <?php
    }

    /* ==========================================================================
       SUB-BLOCOS DE INTERFACE (CARDS) PARA MANTER O CÓDIGO LIMPO E MODULAR
       ========================================================================== */

    private function render_card_credentials() {
        // Gera a URL de redirecionamento dinâmica exata desta instalação do WordPress
        $callback_url = admin_url( 'admin.php?page=mwc-settings' );
        ?>
        <div class="postbox">
            <h2 class="hndle"><span>🔑 1. Credenciais da API (Mautic)</span></h2>
            <div class="inside">
                <table class="form-table">
                    <tr>
                        <th scope="row">URL Base do Mautic</th>
                        <td><input type="url" name="mwc_base_url" value="<?php echo esc_attr( get_option('mwc_base_url') ); ?>" class="regular-text" placeholder="https://seu-mautic.com.br" /></td>
                    </tr>
                    
                    <!-- NOVO CAMPO: URI de Redirecionamento para facilitar a vida do usuário -->
                    <tr>
                        <th scope="row">URI de Redirecionamento (Callback)</th>
                        <td>
                            <input type="text" readonly="readonly" value="<?php echo esc_attr( $callback_url ); ?>" class="regular-text code" onclick="this.select();" style="background:#f0f0f1; cursor:pointer;" />
                            <p class="description">Copie esta URL e cole no campo "URI de Redirecionamento" ao criar sua credencial OAuth 2 no painel do Mautic.</p>
                        </td>
                    </tr>
                    <!-- ------------------------------------------------------------------- -->

                    <tr>
                        <th scope="row">Client ID (OAuth 2)</th>
                        <td><input type="text" name="mwc_client_id" value="<?php echo esc_attr( get_option('mwc_client_id') ); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row">Client Secret (OAuth 2)</th>
                        <td><input type="password" name="mwc_client_secret" value="<?php echo esc_attr( get_option('mwc_client_secret') ); ?>" class="regular-text" /></td>
                    </tr>
                </table>
            </div>
        </div>
        <?php
    }

    private function render_card_data_tags() {
        $all_statuses = function_exists('wc_get_order_statuses') ? wc_get_order_statuses() : [];
        $selected_statuses = get_option('mwc_valid_order_statuses', ['wc-processing', 'wc-completed']);
        if (!is_array($selected_statuses)) $selected_statuses = [];
        ?>

        <div class="postbox">
            <h2 class="hndle"><span>🏷️ 2. Sincronização e Nomenclatura de Tags</span></h2>
            <div class="inside">
                <table class="form-table">
                    <tr>
                        <th scope="row">Status para Sincronização (RFM)</th>
                        <td>
                            <select name="mwc_valid_order_statuses[]" multiple="multiple" style="width: 100%; max-width:400px; height: 100px;">
                                <?php foreach ( $all_statuses as $slug => $name ) : ?>
                                    <option value="<?php echo esc_attr($slug); ?>" <?php echo in_array($slug, $selected_statuses) ? 'selected' : ''; ?>>
                                        <?php echo esc_html($name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">Segure CTRL/CMD para selecionar múltiplos. Apenas estes status geram LTV.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Prefixo de Categoria</th>
                        <td><input type="text" name="mwc_prefix_category" value="<?php echo esc_attr( get_option('mwc_prefix_category', 'categoria:') ); ?>" class="regular-text" /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Prefixo de Produto</th>
                        <td>
                            <input type="text" name="mwc_prefix_product" value="<?php echo esc_attr( get_option('mwc_prefix_product', 'produto:') ); ?>" class="regular-text" />
                            <p class="description">Exemplo: se preencher com "equipamento:", as tags no Mautic serão criadas como "equipamento:nome-do-item".</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Prefixo de Cupom</th>
                        <td><input type="text" name="mwc_prefix_coupon" value="<?php echo esc_attr( get_option('mwc_prefix_coupon', 'cupom:') ); ?>" class="regular-text" /></td>
                    </tr>
                    
                    <tr valign="top">
                        <th scope="row">Habilitar Origem Automática?</th>
                        <td>
                            <label class="mwc-switch">
                                <input type="hidden" name="mwc_enable_auto_origin" value="0">
                                <input type="checkbox" id="mwc_enable_auto_origin" name="mwc_enable_auto_origin" value="1" <?php checked( get_option('mwc_enable_auto_origin', '0'), '1' ); ?>>
                                <span class="mwc-slider"></span>
                            </label>
                            <span style="margin-left: 10px; vertical-align: middle; color: #555; font-weight: 500;" id="mwc_auto_origin_label">
                                <?php echo get_option('mwc_enable_auto_origin', '0') === '1' ? 'Sim (Detectar do Domínio)' : 'Não (Usar Texto Fixo)'; ?>
                            </span>
                        </td>
                    </tr>

                    <tr valign="top" class="mwc-origin-dependent-field">
                        <th scope="row">Prefixo da Origem</th>
                        <td>
                            <input type="text" name="mwc_origin_prefix" value="<?php echo esc_attr( get_option('mwc_origin_prefix', 'origem:') ); ?>" class="regular-text" />
                        </td>
                    </tr>
                    <tr valign="top" class="mwc-origin-dependent-field">
                        <th scope="row">Tipo de Captura de Origem</th>
                        <td>
                            <select name="mwc_origin_type">
                                <option value="custom" <?php selected( get_option('mwc_origin_type', 'custom'), 'custom' ); ?>>Texto Personalizado (Abaixo)</option>
                                <option value="dominio" <?php selected( get_option('mwc_origin_type', 'custom'), 'dominio' ); ?>>Apenas Domínio (Ex: meu-site)</option>
                                <option value="subdominio" <?php selected( get_option('mwc_origin_type', 'custom'), 'subdominio' ); ?>>Subdomínio (Ex: m-meu-site-com-br)</option>
                            </select>
                        </td>
                    </tr>
                    <tr valign="top" class="mwc-origin-dependent-field">
                        <th scope="row">Texto da Origem Customizada</th>
                        <td>
                            <input type="text" name="mwc_site_origin_tag" value="<?php echo esc_attr( get_option('mwc_site_origin_tag', 'meu-site') ); ?>" class="regular-text" />
                            <p class="description">Utilizado se estiver em "Texto Personalizado" ou com a automação desligada.</p>
                        </td>
                    </tr>

                </table>
            </div>
        </div>
        <?php
    }

    private function render_card_abandoned_cart() {
        ?>
        <div class="postbox">
            <h2 class="hndle"><span>🛒 3. Máquina de Carrinho Abandonado</span></h2>
            <div class="inside">
                <table class="form-table">
                    <tr>
                        <th scope="row">Tag de Abandono</th>
                        <td><input type="text" name="mwc_tag_abandoned" value="<?php echo esc_attr( get_option('mwc_tag_abandoned', 'carrinho-abandonado') ); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row">Desconto Automático (%)</th>
                        <td>
                            <input type="number" name="mwc_recovery_discount" value="<?php echo esc_attr( get_option('mwc_recovery_discount', '10') ); ?>" class="small-text" />
                            <p class="description">Porcentagem do cupom de recuperação gerado no Mautic.</p>
                        </td>
                    </tr>
                </table>
            </div>
        </div>
        <?php
    }

    private function render_card_connection_status() {
        $conectado = get_option('mwc_access_token_data') ? true : false;
        ?>
        <div class="postbox">
            <h2 class="hndle"><span>🔌 Status da Conexão</span></h2>
            <div class="inside" style="text-align: center;">
                <?php if ( $conectado ) : ?>
                    <div style="background:#d4edda; color:#155724; padding:15px; border-radius:5px; margin-bottom:15px;">
                        <span class="dashicons dashicons-yes-alt"></span> Conectado e Autenticado!
                    </div>
                    <!-- NOTA: Botão para forçar renovação/reautenticação caso o lojista precise -->
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=mwc-settings&mwc_action=authorize' ) ); ?>" class="button">Reautenticar Mautic</a>
                <?php else : ?>
                    <div style="background:#f8d7da; color:#721c24; padding:15px; border-radius:5px; margin-bottom:15px;">
                        <span class="dashicons dashicons-warning"></span> Desconectado
                    </div>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=mwc-settings&mwc_action=authorize' ) ); ?>" class="button button-primary">Conectar ao Mautic</a>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    private function render_card_bulk_sync() {
        ?>
        <div class="postbox">
            <h2 class="hndle"><span>🔄 Sincronização Histórica</span></h2>
            <div class="inside">
                <p>Use esta ferramenta apenas uma vez para processar os clientes do passado via Action Scheduler.</p>
                <form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
                    <input type="hidden" name="action" value="mwc_run_bulk_sync">
                    <?php wp_nonce_field( 'mwc_bulk_sync_action', 'mwc_bulk_sync_nonce' ); ?>
                    <?php submit_button( 'Iniciar Bulk Sync', 'secondary', 'submit', false, ['style' => 'width:100%'] ); ?>
                </form>
            </div>
        </div>
        <?php
    }

    private function render_card_field_mapping() {
        $saved_mapping = get_option( 'mwc_field_mapping', [] );
        if ( ! is_array( $saved_mapping ) || ! isset( $saved_mapping['woo'] ) ) {
            $saved_mapping = [ 'woo' => [], 'mautic' => [] ]; // Garante que seja um array
        }

        // Uma lista rica e mastigada dos campos nativos e brasileiros do Woo
        $woo_fields = [
            'billing_first_name'   => 'Nome (Faturamento)',
            'billing_last_name'    => 'Sobrenome (Faturamento)',
            'billing_email'        => 'E-mail (Faturamento)',
            'billing_phone'        => 'Telefone Fixo (Faturamento)',
            'billing_cellphone'    => 'Celular (Faturamento)',
            'billing_cpf'          => 'CPF',
            'billing_cnpj'         => 'CNPJ',
            'billing_company'      => 'Nome da Empresa',
            'billing_postcode'     => 'CEP (Faturamento)',
            'billing_address_1'    => 'Endereço 1 (Rua)',
            'billing_number'       => 'Número da Casa/Prédio',
            'billing_address_2'    => 'Endereço 2 (Complemento)',
            'billing_neighborhood' => 'Bairro (Faturamento)',
            'billing_city'         => 'Cidade (Faturamento)',
            'billing_state'        => 'Estado (Faturamento)',
            'shipping_first_name'  => 'Nome (Entrega)',
            'customer_note'        => 'Observação do Pedido',
        ];

        $mautic_fields = $this->get_mautic_fields();
        $row_count = max( 1, count( $saved_mapping['woo'] ) ); // Mostra pelo menos 1 linha
        ?>
        <div class="postbox">
            <h2 class="hndle"><span>🔄 4. Mapeamento Inteligente de Campos</span></h2>
            <div class="inside">
                <p>Selecione um campo do WooCommerce na esquerda e aponte para o campo correspondente no Mautic à direita. Os campos do Mautic são trazidos automaticamente via API.</p>
                
                <table class="form-table" style="width:100%; margin-bottom: 15px;">
                    <thead>
                        <tr>
                            <th style="padding-left:0;">Campo do WordPress / WooCommerce</th>
                            <th>Receber no campo do Mautic</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="mwc-mapping-body">
                        <?php for ( $i = 0; $i < $row_count; $i++ ) : 
                            $woo_val = isset($saved_mapping['woo'][$i]) ? $saved_mapping['woo'][$i] : '';
                            $mautic_val = isset($saved_mapping['mautic'][$i]) ? $saved_mapping['mautic'][$i] : '';
                        ?>
                        <tr class="mwc-mapping-row">
                            <td style="padding-left:0; width:45%;">
                                <select name="mwc_field_mapping[woo][]" style="width:100%;">
                                    <option value="">-- Escolha a Origem --</option>
                                    <?php foreach ( $woo_fields as $key => $label ) : ?>
                                        <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $woo_val, $key ); ?>><?php echo esc_html( $label ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td style="width:45%;">
                                <select name="mwc_field_mapping[mautic][]" class="mwc-mautic-select" style="width:100%;">
                                    <option value="">-- Escolha o Destino --</option>
                                    <?php foreach ( $mautic_fields as $alias => $label ) : ?>
                                        <option value="<?php echo esc_attr( $alias ); ?>" <?php selected( $mautic_val, $alias ); ?>><?php echo esc_html( $label ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td style="width:10%; text-align:right;">
                                <button type="button" class="button mwc-remove-row" style="color:#b32d2e; border-color:#b32d2e;">Remover</button>
                            </td>
                        </tr>
                        <?php endfor; ?>
                    </tbody>
                </table>
                <button type="button" class="button button-primary" id="mwc-add-row">+ Adicionar Mapeamento</button>
            </div>
        </div>
        <?php
    }

    private function render_card_webhooks() {
        // Gera um token de segurança inquebrável se não existir
        $secret = get_option( 'mwc_webhook_secret' );
        if ( empty( $secret ) ) {
            $secret = wp_generate_password( 32, false );
            update_option( 'mwc_webhook_secret', $secret );
        }

        // Monta a URL completa do endpoint
        $webhook_url = rest_url( 'mwc/v1/mautic-webhook' ) . '?secret=' . $secret;
        ?>
        <div class="postbox">
            <h2 class="hndle"><span>🔗 5. Sincronização Bidirecional (Webhooks)</span></h2>
            <div class="inside">
                <p>O Mautic pode avisar o WordPress automaticamente sempre que um contato se descadastrar de um e-mail (Unsubscribe). Isso garante que sua base no WooCommerce respeite a LGPD.</p>
                <table class="form-table">
                    <tr>
                        <th scope="row">Webhook URL (Segura)</th>
                        <td>
                            <input type="text" readonly="readonly" value="<?php echo esc_attr( $webhook_url ); ?>" class="large-text code" onclick="this.select();" style="background:#f0f0f1; cursor:pointer;" />
                            <p class="description">Copie esta URL, vá ao painel do seu Mautic em <strong>Configurações > Webhooks > Novo</strong>. Cole a URL e marque o evento <strong>"Contact Do Not Contact changed"</strong>.</p>
                        </td>
                    </tr>
                </table>
            </div>
        </div>
        <?php
    }

    /**
     * Busca os campos personalizados direto da API do Mautic com Cache
     */
    private function get_mautic_fields() {
        $cached_fields = get_transient( 'mwc_mautic_custom_fields' );
        if ( false !== $cached_fields ) {
            return $cached_fields;
        }

        $token_data = get_option( 'mwc_access_token_data' );
        $base_url   = rtrim( get_option( 'mwc_base_url' ), '/' );
        $fields     = [];

        if ( ! empty( $token_data['access_token'] ) && ! empty( $base_url ) ) {
            // Requisição nativa do WP para a API do Mautic
            $response = wp_remote_get( $base_url . '/api/fields/contact?limit=200', [
                'headers' => [ 'Authorization' => 'Bearer ' . $token_data['access_token'] ],
                'timeout' => 15
            ]);

            if ( ! is_wp_error( $response ) ) {
                $body = json_decode( wp_remote_retrieve_body( $response ), true );
                if ( ! empty( $body['fields'] ) ) {
                    foreach ( $body['fields'] as $field ) {
                        if ( ! empty( $field['isPublished'] ) ) {
                            $fields[ $field['alias'] ] = $field['label'] . ' (' . $field['alias'] . ')';
                        }
                    }
                }
            }
        }

        // Fallback: Se houver erro de conexão, carrega os campos padrão
        if ( empty( $fields ) ) {
            $fields = [
                'firstname' => 'First Name (firstname)', 'lastname' => 'Last Name (lastname)',
                'email' => 'Email (email)', 'phone' => 'Phone (phone)', 'mobile' => 'Mobile (mobile)',
                'company' => 'Company (company)', 'address1' => 'Address 1 (address1)',
                'city' => 'City (city)', 'state' => 'State (state)', 'country' => 'Country (country)',
                'zipcode' => 'Zipcode (zipcode)', 'cpf' => 'CPF (cpf)'
            ];
        } else {
            asort( $fields ); // Organiza em ordem alfabética para facilitar a vida do usuário
            set_transient( 'mwc_mautic_custom_fields', $fields, HOUR_IN_SECONDS ); // Salva no cache
        }

        return $fields;
    }

    /**
     * Scripts de interatividade da página
     */
    private function render_admin_scripts() {
        ?>
        <script type="text/javascript">
            jQuery(document).ready(function($) {
                // 1. Inicializa o campo de Status de Pedido para ficar bonito e pesquisável
                if ( $.fn.selectWoo ) {
                    $('select[name="mwc_valid_order_statuses[]"]').selectWoo({
                        placeholder: "Clique para selecionar os status...",
                        allowClear: true
                    });
                }

                // 2. Trava de segurança para o botão de Sincronização em Massa (Bulk Sync)
                $('input[value="mwc_run_bulk_sync"]').closest('form').on('submit', function(e) {
                    var confirmacao = confirm('Atenção: Você está prestes a sincronizar todo o histórico da loja para o Mautic. Este processo rodará em segundo plano. Deseja continuar?');
                    if ( ! confirmacao ) {
                        e.preventDefault(); // Cancela o envio se o usuário clicar em "Cancelar"
                    }
                });

                // --- LÓGICA DO MAPEAMENTO DE CAMPOS (REPEATER) ---
                
                // Função para bloquear campos do Mautic já selecionados
                function updateMauticSelects() {
                    var selectedFields = [];
                    // Descobre tudo que está selecionado
                    $('.mwc-mautic-select').each(function() {
                        var val = $(this).val();
                        if ( val !== '' ) {
                            selectedFields.push(val);
                        }
                    });

                    // Varre todos os selects e desabilita os que já estão na lista
                    $('.mwc-mautic-select').each(function() {
                        var currentVal = $(this).val();
                        $(this).find('option').each(function() {
                            var optionVal = $(this).val();
                            if ( optionVal !== '' && optionVal !== currentVal && selectedFields.includes(optionVal) ) {
                                $(this).prop('disabled', true);
                            } else {
                                $(this).prop('disabled', false);
                            }
                        });
                    });
                }

                // Adicionar nova linha
                $('#mwc-add-row').on('click', function(e) {
                    e.preventDefault();
                    var firstRow = $('.mwc-mapping-row').first().clone();
                    firstRow.find('select').val(''); // Limpa a seleção da nova linha
                    $('#mwc-mapping-body').append(firstRow);
                    updateMauticSelects();
                });

                // Remover linha
                $(document).on('click', '.mwc-remove-row', function(e) {
                    e.preventDefault();
                    if ( $('.mwc-mapping-row').length > 1 ) {
                        $(this).closest('tr').remove();
                        updateMauticSelects();
                    } else {
                        alert('A última linha não pode ser removida. Apenas deixe em branco se não quiser mapear.');
                    }
                });

                // Roda a verificação de duplicadas sempre que o usuário mudar um valor
                $(document).on('change', '.mwc-mautic-select', updateMauticSelects);
                
                // Roda na primeira vez que a página carrega
                updateMauticSelects();

                // --- INSERIR AQUI A LÓGICA DE ORIGEM ---
                function toggleOriginFields() {
                    // Verifica se o checkbox está marcado
                    if ($('#mwc_enable_auto_origin').is(':checked')) {
                        $('.mwc-origin-dependent-field').fadeIn('fast');
                        $('#mwc_auto_origin_label').text('Sim (Detectar do Domínio)');
                    } else {
                        $('.mwc-origin-dependent-field').hide();
                        $('#mwc_auto_origin_label').text('Não (Usar Texto Fixo)');
                    }
                }
                
                $('#mwc_enable_auto_origin').on('change', toggleOriginFields);
                toggleOriginFields();
                // ---------------------------------------
            });
        </script>
        <?php
    }
}