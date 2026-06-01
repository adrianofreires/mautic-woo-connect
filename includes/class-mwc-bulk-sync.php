<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MWC_Bulk_Sync {

    const BATCH_SIZE = 100;

    public function __construct() {
        add_action( 'admin_post_mwc_run_bulk_sync', [ $this, 'process_bulk_sync_request' ] );
        add_action( 'mwc_bulk_sync_page', [ $this, 'process_bulk_sync_page' ], 10, 1 );
        add_action( 'admin_notices', [ $this, 'display_bulk_sync_notice' ] );
    }

    /**
     * Clique no botão: só dispara a primeira página. Nada de loop pesado aqui
     * (era o que dava timeout em lojas grandes).
     */
    public function process_bulk_sync_request() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Acesso negado.' );
        }
        check_admin_referer( 'mwc_bulk_sync_action', 'mwc_bulk_sync_nonce' );

        if ( ! function_exists( 'as_enqueue_async_action' ) ) {
            wp_die( 'Action Scheduler indisponível.' );
        }

        // Contagem barata só para a notificação (paginate traz o total sem carregar tudo).
        $count_query = wc_get_orders( [
            'status'   => $this->get_valid_statuses(),
            'limit'    => 1,
            'paginate' => true,
            'return'   => 'ids',
        ] );
        $total = isset( $count_query->total ) ? (int) $count_query->total : 0;

        update_option( 'mwc_bulk_sync_notice', $total );

        // Dispara a página 1; ela se reagenda sozinha até acabar.
        as_enqueue_async_action( 'mwc_bulk_sync_page', [ 'page' => 1 ], 'mwc_integration' );

        wp_redirect( admin_url( 'admin.php?page=mwc-settings' ) );
        exit;
    }

    /**
     * Processa um lote de pedidos e reagenda a próxima página (cursor).
     */
    public function process_bulk_sync_page( $page = 1 ) {
        $page = max( 1, (int) $page );

        $order_ids = wc_get_orders( [
            'status'  => $this->get_valid_statuses(),
            'limit'   => self::BATCH_SIZE,
            'paged'   => $page,
            'orderby' => 'date',
            'order'   => 'DESC',
            'return'  => 'ids',
        ] );

        if ( empty( $order_ids ) ) {
            return; // fim natural da paginação
        }

        foreach ( $order_ids as $order_id ) {
            // Sem checagem prévia (era o gargalo): o sync é um upsert idempotente no Mautic.
            as_enqueue_async_action( 'mwc_process_mautic_contact_sync', [ 'order_id' => $order_id ], 'mwc_integration' );
        }

        as_enqueue_async_action( 'mwc_bulk_sync_page', [ 'page' => $page + 1 ], 'mwc_integration' );
    }

    public function display_bulk_sync_notice() {
        $count = get_option( 'mwc_bulk_sync_notice' );
        if ( $count !== false ) {
            echo '<div class="notice notice-success is-dismissible"><p><strong>Sincronização em Massa Iniciada!</strong> ' . intval( $count ) . ' pedidos históricos serão processados em segundo plano (Action Scheduler), em lotes, ao longo das próximas horas.</p></div>';
            delete_option( 'mwc_bulk_sync_notice' );
        }
    }

    private function get_valid_statuses() {
        $statuses = get_option( 'mwc_valid_order_statuses', [ 'wc-processing', 'wc-completed' ] );
        return ( is_array( $statuses ) && ! empty( $statuses ) ) ? $statuses : [ 'wc-processing', 'wc-completed' ];
    }
}