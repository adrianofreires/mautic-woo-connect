<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MWC_Webhook {

    public function __construct() {
        // Registra uma nova rota na REST API do WordPress nativamente
        add_action( 'rest_api_init', [ $this, 'register_webhook_endpoint' ] );
    }

    public function register_webhook_endpoint() {
        register_rest_route( 'mwc/v1', '/mautic-webhook', [
            'methods'             => 'POST', // Mautic envia os webhooks via POST
            'callback'            => [ $this, 'handle_mautic_webhook' ],
            'permission_callback' => [ $this, 'verify_webhook_secret' ],
        ] );
    }

    /**
     * Trava de Segurança: Só aceita chamadas que tenham o nosso token secreto
     */
    public function verify_webhook_secret( $request ) {
    $saved = (string) get_option( 'mwc_webhook_secret' );
    $sent  = (string) $request->get_param( 'secret' );

    // Sem secret configurado, recusa por segurança.
    if ( $saved === '' ) {
        return new WP_Error( 'rest_forbidden', 'Webhook não configurado.', [ 'status' => 403 ] );
    }

    // Comparação à prova de timing attack.
    if ( ! hash_equals( $saved, $sent ) ) {
        return new WP_Error( 'rest_forbidden', 'Token inválido.', [ 'status' => 401 ] );
    }

    return true;
}

    /**
     * O "Cérebro" dinâmico que processa o aviso enviado pelo Mautic
     */
    public function handle_mautic_webhook( WP_REST_Request $request ) {
        $payload = $request->get_json_params();
        $events = [];

        // 1. Identifica o formato do evento enviado pelo Mautic (Novo ou Antigo)
        if ( isset( $payload['mautic.contact_channel_subscription_change'] ) ) {
            $events = $payload['mautic.contact_channel_subscription_change'];
        } elseif ( isset( $payload['mautic.lead_post_save_dnc'] ) ) {
            $events = $payload['mautic.lead_post_save_dnc'];
        } elseif ( isset( $payload['mautic.lead_post_save_update'] ) ) {
            $events = $payload['mautic.lead_post_save_update'];
        }

        if ( ! empty( $events ) ) {
            foreach ( $events as $event ) {
                // Tenta capturar o e-mail do contato
                $email = '';
                if ( ! empty( $event['contact']['fields']['core']['email']['value'] ) ) {
                    $email = sanitize_email( $event['contact']['fields']['core']['email']['value'] );
                }

                // 2. Inteligência: Verifica se foi uma ação de "Não Contatar" (Unsubscribe)
                $is_unsubscribed = false;
                
                // Regra A: Verifica o novo sistema de Canais do Mautic
                if ( isset( $event['is_subscribed'] ) && $event['is_subscribed'] === false ) {
                    $is_unsubscribed = true;
                }
                
                // Regra B: Fallback para a flag Global de DNC do Mautic
                if ( ! empty( $event['contact']['doNotContact'] ) ) {
                    $is_unsubscribed = true;
                }

                // 3. Sincronização Bidirecional: Se o usuário descadastrou, revoga a LGPD no WordPress
                if ( $email && $is_unsubscribed ) {
                    $user = get_user_by( 'email', $email );
                    if ( $user ) {
                        update_user_meta( $user->ID, '_mwc_marketing_consent', 'nao' );
                    }
                }
            }
        }

        // Responde ao Mautic com 200 OK
        return rest_ensure_response( [ 'success' => true, 'message' => 'Webhook do Mautic processado com sucesso.' ] );
    }
}