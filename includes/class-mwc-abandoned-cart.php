<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MWC_Abandoned_Cart {

    public function __construct() {
        // Carrega o script apenas na tela de checkout
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_checkout_script' ] );
        
        // Recebe o Ajax do usuário não-logado ou logado
        add_action( 'wp_ajax_nopriv_mwc_capture_checkout_email', [ $this, 'capture_email' ] );
        add_action( 'wp_ajax_mwc_capture_checkout_email', [ $this, 'capture_email' ] );
    }

    public function enqueue_checkout_script() {
    // Se o usuário NÃO estiver logado, o script de captura entra em ação para capturar o lead.
    if ( ! is_user_logged_in() ) {
        $rel_path  = 'assets/js/checkout-capture.js';
        $file_path = MWC_PLUGIN_DIR . $rel_path;

        // Cache-busting correto: a versão muda só quando o arquivo muda (filemtime),
        // e cai para MWC_VERSION se o arquivo não for encontrado.
        $version = file_exists( $file_path ) ? filemtime( $file_path ) : MWC_VERSION;

        wp_enqueue_script( 'mwc-checkout-capture', MWC_PLUGIN_URL . $rel_path, [ 'jquery' ], $version, true );
        wp_localize_script( 'mwc-checkout-capture', 'mwc_ajax', [
            'url'   => admin_url( 'admin-ajax.php' ),
            'nonce' => wp_create_nonce( 'mwc_checkout_nonce' )
        ] );
    }
}

    public function capture_email() {
        check_ajax_referer( 'mwc_checkout_nonce', 'nonce' );
        
        $email = sanitize_email( $_POST['email'] );
        if ( ! is_email( $email ) ) {
            wp_send_json_error( 'Email inválido' );
        }

        // REDE DE SEGURANÇA: Captura erros fatais e transforma em resposta JSON
        try {
            // 1. Gera um Cupom Único de 10% válido por 2 dias
            $coupon_code = $this->generate_recovery_coupon( $email );

            // 2. Pega a URL do Carrinho (Para link de recuperação mágica no e-mail)
            $cart_url = wc_get_cart_url();

            $tag_abandoned = get_option( 'mwc_tag_abandoned', 'carrinho-abandonado' );

            // 3. Monta o pacote para o Mautic
            $data = [
                'email' => $email,
                'tags'  => 'checkout-iniciado,' . $tag_abandoned,
                'cupom_recuperacao' => $coupon_code,
                'url_carrinho'      => $cart_url
            ];

            // 4. Envia imediatamente para o Mautic 
            require_once MWC_PLUGIN_DIR . 'includes/class-mwc-api.php';
            $api = new MWC_Api();
            $response = $api->create_or_update_contact( $data );

            // LOG DE DEBUG: Escreve a resposta do Mautic no arquivo debug.log do WordPress
            error_log( 'Teste Abandono Mautic: ' . print_r( $response, true ) );

            // Retorna sucesso para o JavaScript
            wp_send_json_success( $response );

        } catch ( Exception $e ) {
            // Se algo falhar, loga o erro e devolve para o navegador de forma limpa
            error_log( 'Erro 500 no Abandono Mautic: ' . $e->getMessage() );
            wp_send_json_error( $e->getMessage() );
        }
    }

    /**
     * Motor de Geração de Cupom Dinâmico
     */
    private function generate_recovery_coupon( $email ) {
        $coupon_code = 'VOLTA-' . strtoupper( substr( md5( $email . time() ), 0, 6 ) );
        
        $coupon = new WC_Coupon();
        $coupon->set_code( $coupon_code );
        $coupon->set_discount_type( 'percent' );
        $discount_amount = get_option( 'mwc_recovery_discount', 10 );
        $coupon->set_amount( $discount_amount );
        $coupon->set_usage_limit( 1 ); // Pode ser usado apenas 1 vez
        $coupon->set_email_restrictions( [ $email ] ); 
        
        // CORREÇÃO: O método correto no WooCommerce moderno
        if ( is_callable( [$coupon, 'set_date_expires'] ) ) {
            $coupon->set_date_expires( strtotime( '+2 days' ) ); 
        }

        $coupon->save();

        return $coupon_code;
    }
}