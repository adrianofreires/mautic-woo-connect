<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MWC_Privacy {

    public function __construct() {
        // 1. Adiciona o checkbox de consentimento no final do Checkout
        add_action( 'woocommerce_review_order_before_submit', [ $this, 'add_privacy_checkbox' ] );
        
        // 2. Salva a escolha do cliente nos metadados do pedido
        add_action( 'woocommerce_checkout_update_order_meta', [ $this, 'save_privacy_consent' ] );

        // 3. O Direito ao Esquecimento (Deleta/Anonimiza no Mautic se a conta do WP for deletada)
        add_action( 'delete_user', [ $this, 'process_right_to_be_forgotten' ] );
    }

    /**
     * Injeta o Checkbox de Consentimento para a LGPD no Checkout
     */
    public function add_privacy_checkbox() {
        woocommerce_form_field( 'mwc_marketing_consent', [
            'type'          => 'checkbox',
            'class'         => [ 'form-row privacy' ],
            'label_class'   => [ 'woocommerce-form__label woocommerce-form__label-for-checkbox checkbox' ],
            'input_class'   => [ 'woocommerce-form__input woocommerce-form__input-checkbox input-checkbox' ],
            'required'      => false,
            'label'         => 'Eu aceito receber ofertas exclusivas e dicas personalizadas por e-mail.',
        ]);
    }

    /**
     * Salva a resposta do cliente no pedido (Order Meta)
     */
    public function save_privacy_consent( $order_id ) {
        if ( ! empty( $_POST['mwc_marketing_consent'] ) ) {
            update_post_meta( $order_id, '_mwc_marketing_consent', 'sim' );
        } else {
            update_post_meta( $order_id, '_mwc_marketing_consent', 'nao' );
        }
    }

    /**
     * Envia um sinal para o Mautic de que o usuário solicitou exclusão dos dados
     */
    public function process_right_to_be_forgotten( $user_id ) {
        $user = get_userdata( $user_id );
        if ( ! $user ) return;

        // Ao enviar essa tag, você pode criar uma campanha no Mautic que deleta o 
        // contato ou o adiciona na lista de "Do Not Contact" (DNC).
        $data = [
            'email' => $user->user_email,
            'tags'  => 'lgpd-exclusao-solicitada'
        ];

        require_once MWC_PLUGIN_DIR . 'includes/class-mwc-api.php';
        $api = new MWC_Api();
        $api->create_or_update_contact( $data );
    }
}