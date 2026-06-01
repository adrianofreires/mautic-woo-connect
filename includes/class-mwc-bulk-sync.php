<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MWC_Bulk_Sync {

    public function __construct() {
        // Escuta o clique do botão no painel de administração
        add_action( 'admin_post_mwc_run_bulk_sync', [ $this, 'process_bulk_sync_request' ] );
        
        // Exibe a notificação de sucesso
        add_action( 'admin_notices', [ $this, 'display_bulk_sync_notice' ] );
    }

    /**
     * Pega todos os pedidos antigos e os coloca na fila de processamento assíncrono
     */
    public function process_bulk_sync_request() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Acesso negado.' );
        }

        // Verifica o nonce: garante que a requisição partiu do formulário legítimo (anti-CSRF).
        check_admin_referer( 'mwc_bulk_sync_action', 'mwc_bulk_sync_nonce' );

        // Puxa os status salvos nas configurações ou usa os status de "pago" nativos do Woo como padrão
        $valid_statuses = get_option( 'mwc_valid_order_statuses', wc_get_is_paid_statuses() );

        $orders = wc_get_orders( [
            'status' => $valid_statuses,
            'limit'  => -1, 
            'return' => 'ids', 
        ] );

        if ( ! empty( $orders ) && function_exists( 'as_enqueue_async_action' ) ) {
            $count = 0;
            foreach ( $orders as $order_id ) {
                // Previne que um mesmo pedido seja adicionado na fila duas vezes
                if ( ! as_has_scheduled_action( 'mwc_process_mautic_contact_sync', [ 'order_id' => $order_id ], 'mwc_integration' ) ) {
                    as_enqueue_async_action( 'mwc_process_mautic_contact_sync', [ 'order_id' => $order_id ], 'mwc_integration' );
                    $count++;
                }
            }
            // Salva a quantidade de itens enfileirados para mostrar na notificação
            update_option( 'mwc_bulk_sync_notice', $count );
        }

        // Redireciona o lojista de volta para a página de configurações
        wp_redirect( admin_url( 'admin.php?page=mwc-settings' ) );
        exit;
    }

    /**
     * Exibe o aviso verde no painel informando quantos pedidos entraram na fila
     */
    public function display_bulk_sync_notice() {
        $count = get_option( 'mwc_bulk_sync_notice' );
        if ( $count !== false ) {
            echo '<div class="notice notice-success is-dismissible"><p><strong>Sincronização em Massa Iniciada!</strong> ' . intval($count) . ' pedidos históricos foram adicionados à fila de processamento (Action Scheduler). Eles serão enviados ao Mautic silenciosamente em segundo plano nas próximas horas.</p></div>';
            delete_option( 'mwc_bulk_sync_notice' );
        }
    }
}