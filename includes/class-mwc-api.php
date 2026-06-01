<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use Mautic\Auth\ApiAuth;
use Mautic\MauticApi;

class MWC_Api {
    private $auth;
    private $api_url;
    private $token_manager;

    public function __construct() {
        $this->api_url       = rtrim( get_option('mwc_base_url'), '/' );
        $this->token_manager = new MWC_Token_Manager( $this->api_url );
        $this->init_auth();
    }

    private function init_auth() {
        // Fonte única da verdade: o token manager valida e renova se preciso.
        $valid_access_token = $this->token_manager->get_valid_access_token();
        if ( ! $valid_access_token ) {
            return; // falha de autenticação
        }

        $token = $this->token_manager->get_token_data();

        $settings = [
            'baseUrl'      => $this->api_url,
            'version'      => 'OAuth2',
            'clientKey'    => get_option('mwc_client_id'),
            'clientSecret' => get_option('mwc_client_secret'),
            'accessToken'  => $token['access_token'],
            'refreshToken' => $token['refresh_token'],
            'tokenExpires' => $token['expires_at'], // agora SEMPRE um int válido — adeus warnings
        ];

        $initAuth   = new ApiAuth();
        $this->auth = $initAuth->newAuth( $settings );
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