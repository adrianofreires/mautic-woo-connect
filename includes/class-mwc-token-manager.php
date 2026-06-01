<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Gerencia o token OAuth2 do Mautic: normalização, validação e renovação.
 * Fonte única da verdade do token — elimina os shapes divergentes
 * (expires vs expires_in) que causavam os warnings no log.
 */
class MWC_Token_Manager {

    const OPTION_KEY   = 'mwc_access_token_data';
    const ERROR_FLAG   = 'mwc_mautic_auth_error';
    const LOCK_KEY     = 'mwc_token_refresh_lock';
    const REFRESH_SKEW = 300; // renova 5 min antes de expirar

    private $api_url;

    public function __construct( $api_url ) {
        $this->api_url = rtrim( $api_url, '/' );
    }

    /**
     * Lê o token salvo e devolve um array NORMALIZADO, aceitando os dois
     * formatos antigos (biblioteca e refresh manual) por retrocompatibilidade.
     */
    public function get_token_data() {
        $raw = get_option( self::OPTION_KEY );
        if ( empty( $raw ) || empty( $raw['access_token'] ) ) {
            return null;
        }

        if ( isset( $raw['expires_at'] ) ) {
            $expires_at = (int) $raw['expires_at'];          // shape novo (canônico)
        } elseif ( isset( $raw['expires'] ) ) {
            $expires_at = (int) $raw['expires'];             // shape da biblioteca Mautic
        } else {
            $created    = isset( $raw['created_at'] ) ? (int) $raw['created_at'] : time();
            $expires_in = isset( $raw['expires_in'] ) ? (int) $raw['expires_in'] : 3600;
            $expires_at = $created + $expires_in;            // shape do refresh manual
        }

        return [
            'access_token'  => $raw['access_token'],
            'refresh_token' => isset( $raw['refresh_token'] ) ? $raw['refresh_token'] : '',
            'expires_at'    => $expires_at,
        ];
    }

    /**
     * Grava o token sempre no shape canônico (expires_at absoluto).
     */
    public function save_token( array $payload, $previous_refresh_token = '' ) {
        $expires_in = isset( $payload['expires_in'] ) ? (int) $payload['expires_in'] : 3600;

        update_option( self::OPTION_KEY, [
            'access_token'  => isset( $payload['access_token'] ) ? $payload['access_token'] : '',
            // Mantém o refresh_token antigo se o Mautic não devolver um novo.
            'refresh_token' => ! empty( $payload['refresh_token'] ) ? $payload['refresh_token'] : $previous_refresh_token,
            'expires_at'    => time() + $expires_in,
        ] );

        delete_option( self::ERROR_FLAG );
    }

    /**
     * Retorna um access_token válido, renovando proativamente se necessário.
     */
    public function get_valid_access_token() {
        $token = $this->get_token_data();

        if ( ! $token ) {
            update_option( self::ERROR_FLAG, true );
            return null;
        }

        if ( time() < ( $token['expires_at'] - self::REFRESH_SKEW ) ) {
            return $token['access_token'];
        }

        return $this->refresh( $token );
    }

    /**
     * Renova via refresh_token, com lock para evitar corrida no Bulk Sync.
     */
    private function refresh( array $token ) {
        if ( empty( $token['refresh_token'] ) ) {
            update_option( self::ERROR_FLAG, true );
            return null;
        }

        if ( ! $this->acquire_lock() ) {
            // Outro processo já está renovando: espera curta e relê o resultado.
            usleep( 500000 ); // 0,5s
            $fresh = $this->get_token_data();
            return $fresh ? $fresh['access_token'] : null;
        }

        try {
            $response = wp_remote_post( $this->api_url . '/oauth/v2/token', [
                'timeout' => 15,
                'body'    => [
                    'client_id'     => get_option( 'mwc_client_id' ),
                    'client_secret' => get_option( 'mwc_client_secret' ),
                    'grant_type'    => 'refresh_token',
                    'refresh_token' => $token['refresh_token'],
                ],
            ] );

            if ( is_wp_error( $response ) ) {
                update_option( self::ERROR_FLAG, true );
                return null;
            }

            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            if ( empty( $body['access_token'] ) ) {
                update_option( self::ERROR_FLAG, true );
                return null;
            }

            $this->save_token( $body, $token['refresh_token'] );
            return $body['access_token'];

        } finally {
            $this->release_lock();
        }
    }

    /** Lock atômico via object cache; cai para transient se não houver. */
    private function acquire_lock() {
        if ( wp_using_ext_object_cache() ) {
            return wp_cache_add( self::LOCK_KEY, 1, 'mwc', 30 );
        }
        if ( get_transient( self::LOCK_KEY ) ) {
            return false;
        }
        set_transient( self::LOCK_KEY, 1, 30 );
        return true;
    }

    private function release_lock() {
        if ( wp_using_ext_object_cache() ) {
            wp_cache_delete( self::LOCK_KEY, 'mwc' );
        } else {
            delete_transient( self::LOCK_KEY );
        }
    }
}