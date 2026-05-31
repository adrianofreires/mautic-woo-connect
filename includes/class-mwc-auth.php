<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use Mautic\Auth\ApiAuth;

class MWC_Auth {

    public function __construct() {
        // Registra as ações AJAX no WordPress
        add_action( 'wp_ajax_mwc_oauth_connect', [ $this, 'oauth_connect' ] );
        add_action( 'wp_ajax_mwc_oauth_callback', [ $this, 'oauth_callback' ] );
    }

    /**
     * Inicia a Sessão PHP de forma segura (Exigência do Mautic para o OAuth2_state)
     */
    private function maybe_start_session() {
        if ( session_status() === PHP_SESSION_NONE ) {
            session_start();
        }
    }

    /**
     * Retorna o array de configurações exigido pela biblioteca do Mautic
     */
    private function get_auth_settings() {
        return [
            'baseUrl'      => rtrim( get_option('mwc_base_url'), '/' ),
            'version'      => 'OAuth2',
            'clientKey'    => get_option('mwc_client_id'),
            'clientSecret' => get_option('mwc_client_secret'),
            'callback'     => admin_url('admin-ajax.php?action=mwc_oauth_callback')
        ];
    }

    /**
     * Inicia a requisição de autorização para o Mautic
     */
    public function oauth_connect() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Acesso negado' );
        }

        // 1. OBRIGATÓRIO: Iniciar sessão para salvar o OAuth State
        $this->maybe_start_session();

        $settings = $this->get_auth_settings();

        if ( empty($settings['baseUrl']) || empty($settings['clientKey']) || empty($settings['clientSecret']) ) {
            wp_die( 'Por favor, salve a URL do Mautic, Client ID e Client Secret antes de conectar.' );
        }

        $initAuth = new ApiAuth();
        $auth = $initAuth->newAuth( $settings );

        try {
            // Isso irá popular a $_SESSION e redirecionar para o Mautic
            $auth->validateAccessToken();
        } catch ( \Exception $e ) {
            wp_die( 'Erro ao iniciar conexão com Mautic: ' . $e->getMessage() );
        }
    }

    /**
     * Recebe a resposta (callback) do Mautic e salva o Token
     */
    public function oauth_callback() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Acesso negado' );
        }

        // 1. OBRIGATÓRIO: Retomar sessão para ler o OAuth State recebido
        $this->maybe_start_session();

        $settings = $this->get_auth_settings();
        $initAuth = new ApiAuth();
        $auth = $initAuth->newAuth( $settings );

        try {
            // Tenta validar o token e o "state" recebido na URL
            if ( $auth->validateAccessToken() ) {
                if ( $auth->accessTokenUpdated() ) {
                    $accessTokenData = $auth->getAccessTokenData();
                    update_option( 'mwc_access_token_data', $accessTokenData );
                }
                
                // Redireciona com Sucesso
                wp_redirect( admin_url( 'admin.php?page=mwc-settings&connected=success' ) );
                exit;
            } else {
                // Se a validação retornar FALSE, mostramos uma tela de erro clara ao invés de um "0"
                wp_die( '<h2>Falha na Validação do Mautic</h2><p>O token ou a sessão (state) expirou. Por favor, volte e clique em "Conectar e Autorizar" novamente.</p>', 'Erro de OAuth', ['response' => 400] );
            }
        } catch ( \Exception $e ) {
            wp_die( 'Erro crítico no callback do Mautic: ' . $e->getMessage() );
        }
    }
}