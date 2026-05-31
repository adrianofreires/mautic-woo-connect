<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use Mautic\Auth\ApiAuth;
use Mautic\MauticApi;

class MWC_Api {
    private $auth;
    private $api_url;

    public function __construct() {
        $this->api_url = rtrim( get_option('mwc_base_url'), '/' );
        $this->init_auth();
    }

    /**
     * Inicializa a autenticação com os tokens salvos, 
     * validando e renovando proativamente se necessário.
     */
    private function init_auth() {
        // Validação Proativa: Busca o Token Válido (ou renova de forma segura)
        $valid_access_token = $this->get_valid_access_token();

        if ( ! $valid_access_token ) {
            return; // Falha grave na autenticação, encerra a inicialização.
        }

        // Recupera os dados (que agora temos certeza que estão atualizados)
        $accessTokenData = get_option( 'mwc_access_token_data' );

        $settings = [
            'baseUrl'      => $this->api_url,
            'version'      => 'OAuth2',
            'clientKey'    => get_option('mwc_client_id'),
            'clientSecret' => get_option('mwc_client_secret'),
            'accessToken'  => $accessTokenData['access_token'],
            'refreshToken' => $accessTokenData['refresh_token'],
            'tokenExpires' => $accessTokenData['expires']
        ];

        $initAuth = new ApiAuth();
        $this->auth = $initAuth->newAuth( $settings );
    }

    /**
     * Verifica a validade do Token com base no tempo de expiração.
     * Se estiver expirado (ou quase expirando), aciona a renovação.
     */
    private function get_valid_access_token() {
        $token_data = get_option( 'mwc_access_token_data' );
        
        if ( empty( $token_data ) || ! isset( $token_data['access_token'] ) ) {
            update_option( 'mwc_mautic_auth_error', true );
            return false;
        }

        $now = time();
        $created_at = isset( $token_data['created_at'] ) ? $token_data['created_at'] : 0;
        $expires_in = isset( $token_data['expires_in'] ) ? $token_data['expires_in'] : 3600;
        
        // Margem de segurança: Se faltam menos de 5 minutos (300 seg) para expirar, renova.
        if ( $now >= ( $created_at + $expires_in - 300 ) ) {
            return $this->refresh_mautic_token( $token_data );
        }

        // Se está válido, retorna normalmente
        return $token_data['access_token'];
    }

    /**
     * Renova o token OAuth2 no Mautic utilizando um "Lock" (Transient) 
     * para evitar a Race Condition em envios Assíncronos (Bulk Sync).
     */
    private function refresh_mautic_token( $token_data ) {
        // 1. O Locking: Se outro processo já está renovando agora, nós esperamos.
        if ( get_transient( 'mwc_refreshing_token_lock' ) ) {
            sleep( 3 ); // Espera 3 segundos
            $updated_data = get_option( 'mwc_access_token_data' );
            return isset( $updated_data['access_token'] ) ? $updated_data['access_token'] : false;
        }

        // Trava o sistema pelos próximos 30 segundos enquanto esta requisição processa
        set_transient( 'mwc_refreshing_token_lock', true, 30 );

        $token_url = $this->api_url . '/oauth/v2/token';
        
        $args = [
            'body' => [
                'client_id'     => get_option('mwc_client_id'),
                'client_secret' => get_option('mwc_client_secret'),
                'grant_type'    => 'refresh_token',
                'refresh_token' => $token_data['refresh_token']
            ],
            'timeout' => 15
        ];

        // Faz o disparo POST puro via WordPress para o Mautic
        $response = wp_remote_post( $token_url, $args );

        if ( is_wp_error( $response ) ) {
            delete_transient( 'mwc_refreshing_token_lock' );
            update_option( 'mwc_mautic_auth_error', true );
            return false;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        // 2. Se a renovação foi um sucesso
        if ( isset( $body['access_token'] ) ) {
            // Salva a hora exata da renovação para o próximo cálculo matemático de tempo
            $body['created_at'] = time();
            
            update_option( 'mwc_access_token_data', $body );
            delete_option( 'mwc_mautic_auth_error' ); 
            delete_transient( 'mwc_refreshing_token_lock' ); // Libera a porta para as outras filas
            
            return $body['access_token'];
        }

        // 3. Se falhou (o refresh_token expirou por completo no Mautic, ex: após 14 dias off)
        delete_transient( 'mwc_refreshing_token_lock' );
        update_option( 'mwc_mautic_auth_error', true );
        return false;
    }

    /**
     * Cria ou atualiza um contato no Mautic
     */
    public function create_or_update_contact( $data ) {
        if ( ! $this->auth ) {
            throw new Exception( 'A inicialização da API falhou. Provavelmente o token expirou e a renovação falhou.' );
        }

        $api = new MauticApi();
        $contactApi = $api->newApi( 'contacts', $this->auth, $this->api_url );

        // Envia o payload do contato (Neste ponto, temos 100% de certeza que o token está válido)
        $response = $contactApi->create( $data );

        return $response;
    }
}