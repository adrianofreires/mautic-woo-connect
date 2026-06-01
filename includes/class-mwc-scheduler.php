<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MWC_Scheduler {

    public function __construct() {
        add_action( 'woocommerce_payment_complete', [ $this, 'schedule_contact_sync' ], 10, 1 );
        add_action( 'woocommerce_order_status_changed', [ $this, 'schedule_contact_sync_on_status_change' ], 10, 4 );
        add_action( 'mwc_process_mautic_contact_sync', [ $this, 'process_contact_sync' ], 10, 1 );
    }

    public function schedule_contact_sync_on_status_change( $order_id, $status_from, $status_to, $order ) {
        $this->schedule_contact_sync( $order_id );
    }

    public function schedule_contact_sync( $order_id ) {
        if ( function_exists( 'as_enqueue_async_action' ) ) {
            $hook = 'mwc_process_mautic_contact_sync';
            $args = [ 'order_id' => $order_id ];
            if ( ! as_has_scheduled_action( $hook, $args ) ) {
                as_enqueue_async_action( $hook, $args, 'mwc_integration' );
            }
        }
    }

    private function calculate_rfm( $email ) {
        global $wpdb;

        // Status no formato salvo no banco (com prefixo wc-).
        $statuses = get_option( 'mwc_valid_order_statuses', [ 'wc-processing', 'wc-completed' ] );
        if ( ! is_array( $statuses ) || empty( $statuses ) ) {
            $statuses = [ 'wc-processing', 'wc-completed' ];
        }
        $placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );

        $hpos = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
            && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

        if ( $hpos ) {
            $sql = "SELECT COUNT(*) AS freq,
                        COALESCE(SUM(total_amount), 0) AS monetary,
                        MAX(date_created_gmt) AS last_date
                    FROM {$wpdb->prefix}wc_orders
                    WHERE billing_email = %s
                    AND type = 'shop_order'
                    AND status IN ($placeholders)";
        } else {
            $sql = "SELECT COUNT(p.ID) AS freq,
                        COALESCE(SUM(pm_total.meta_value), 0) AS monetary,
                        MAX(p.post_date_gmt) AS last_date
                    FROM {$wpdb->prefix}posts p
                    INNER JOIN {$wpdb->prefix}postmeta pm_email
                            ON p.ID = pm_email.post_id AND pm_email.meta_key = '_billing_email'
                    INNER JOIN {$wpdb->prefix}postmeta pm_total
                            ON p.ID = pm_total.post_id AND pm_total.meta_key = '_order_total'
                    WHERE p.post_type = 'shop_order'
                    AND pm_email.meta_value = %s
                    AND p.post_status IN ($placeholders)";
        }

        $params = array_merge( [ $email ], $statuses );
        $row    = $wpdb->get_row( $wpdb->prepare( $sql, ...$params ) );

        $frequency = $row ? (int) $row->freq : 0;
        $monetary  = $row ? (float) $row->monetary : 0;

        $recency = 9999;
        if ( $frequency > 0 && ! empty( $row->last_date ) ) {
            $recency = (int) floor( ( time() - strtotime( $row->last_date . ' UTC' ) ) / DAY_IN_SECONDS );
            if ( $recency < 0 ) { $recency = 0; }
        }

        // Faixas idênticas às anteriores (pra validação bater).
        $r_score = 1;
        if ( $recency <= 30 ) $r_score = 5;
        elseif ( $recency <= 90 ) $r_score = 4;
        elseif ( $recency <= 180 ) $r_score = 3;
        elseif ( $recency <= 365 ) $r_score = 2;

        $f_score = 1;
        if ( $frequency >= 10 ) $f_score = 5;
        elseif ( $frequency >= 5 ) $f_score = 4;
        elseif ( $frequency >= 3 ) $f_score = 3;
        elseif ( $frequency >= 2 ) $f_score = 2;

        $m_score = 1;
        if ( $monetary >= 1000 ) $m_score = 5;
        elseif ( $monetary >= 500 ) $m_score = 4;
        elseif ( $monetary >= 100 ) $m_score = 3;
        elseif ( $monetary >= 50 ) $m_score = 2;

        return [
            'recency_score'   => $r_score,
            'frequency_score' => $f_score,
            'monetary_score'  => $m_score,
            'ltv'             => $monetary,
            'total_pedidos'   => $frequency,
        ];
    }

    /**
     * O Caçador de Comportamento (Tagueamento Dinâmico)
     */
    private function get_order_tags( $order ) {
        $tags = [];

        $pref_coupon = get_option( 'mwc_prefix_coupon', 'cupom:' );
        $pref_cat    = get_option( 'mwc_prefix_category', 'categoria:' );
        $pref_prod   = get_option( 'mwc_prefix_product', 'produto:' );

        // Captura Cupons Utilizados
        $coupons = $order->get_coupon_codes();
        foreach ( $coupons as $coupon ) {
            $tags[] = $pref_coupon . strtolower( $coupon );
        }

        // Captura SKU e Categorias dos produtos
        $items = $order->get_items();
        foreach ( $items as $item ) {
            $product = $item->get_product();
            if ( $product ) {
                
                // Usa o prefixo dinâmico configurado pelo lojista
                $tags[] = $pref_prod . $product->get_slug();
                
                if ( $product->get_sku() ) {
                    $tags[] = 'sku:' . $product->get_sku();
                }
                
                $term_ids = $product->get_category_ids();
                foreach ( $term_ids as $term_id ) {
                    $term = get_term( $term_id, 'product_cat' );
                    if ( $term && ! is_wp_error($term) ) {
                        $tags[] = $pref_cat . $term->slug;
                    }
                }
            }
        }

        // Status atual do funil
        // 1. Status atual do funil (Dinâmico e Universal para qualquer plugin)
        // Pega o nome amigável registrado no painel (ex: "Nova Solicitação de Cotação" ou "Aguardando Pagamento")
        $status_name = wc_get_order_status_name( $order->get_status() );
        
        // Converte para formato de tag limpa (ex: "nova-solicitacao-de-cotacao")
        $status_clean_tag = sanitize_title( $status_name );
        
        $tags[] = 'status:' . $status_clean_tag;

        // 2. Pagamento (Só cria a tag se o cliente realmente usou um método)
        $payment_method = $order->get_payment_method();
        if ( ! empty( $payment_method ) ) {
            $tags[] = 'pagamento:' . $payment_method;
        }

        // Verifica o consentimento da LGPD
        $consent = $order->get_meta( '_mwc_marketing_consent' );
        if ( $consent === 'sim' ) {
            $tags[] = 'consentimento:concedido';
        }

        // --- TAG DE ORIGEM INTELIGENTE E CONFIGURÁVEL ---
        $enable_auto_origin = get_option( 'mwc_enable_auto_origin', '0' );
        $origin_prefix      = get_option( 'mwc_origin_prefix', 'origem:' );

        // Validação estrita de Booleano do WordPress (1)
        if ( '1' === $enable_auto_origin ) {
            $origin_type = get_option( 'mwc_origin_type', 'custom' );
            $site_url    = wp_parse_url( get_site_url(), PHP_URL_HOST ); 

            if ( 'subdominio' === $origin_type ) {
                $domain_tag = str_replace( '.', '-', $site_url );
                $tags[]     = $origin_prefix . $domain_tag;
            } elseif ( 'dominio' === $origin_type ) {
                $parts = explode( '.', $site_url );
                $count = count( $parts );
                if ( $count >= 3 && ( end($parts) === 'br' || end($parts) === 'org' ) ) {
                    $domain_tag = $parts[$count - 3] . '-' . $parts[$count - 2];
                } else {
                    $domain_tag = isset($parts[$count - 2]) ? $parts[$count - 2] : $site_url;
                }
                $tags[] = $origin_prefix . $domain_tag;
            } else {
                $site_tag = get_option( 'mwc_site_origin_tag', 'desconhecida' );
                $tags[]   = $origin_prefix . $site_tag;
            }
        } else {
            // Se for '0' (false), usa o fallback fixo
            $site_tag = get_option( 'mwc_site_origin_tag', 'origem:desconhecida' );
            $tags[]   = $site_tag;
        }
        // ------------------------------------------------

        // Retorna a array filtrando tags duplicadas
        return array_unique( $tags );
    }

    public function process_contact_sync( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        // --- CAÇADOR DE E-MAIL (Fallback) ---
        $email      = $order->get_billing_email();
        $first_name = $order->get_billing_first_name();
        $last_name  = $order->get_billing_last_name();

        if ( empty( $email ) && $order->get_customer_id() ) {
            $user = get_userdata( $order->get_customer_id() );
            if ( $user ) {
                $email      = $user->user_email;
                $first_name = empty( $first_name ) ? $user->first_name : $first_name;
                $last_name  = empty( $last_name ) ? $user->last_name : $last_name;
            }
        }

        if ( empty( $email ) ) {
            $yith_email = $order->get_meta( '_ywraq_customer_email' ) ?: $order->get_meta( 'ywraq_customer_email' );
            if ( ! empty( $yith_email ) ) {
                $email = $yith_email;
                $first_name = empty( $first_name ) ? $order->get_meta( 'ywraq_customer_name' ) : $first_name;
            }
        }

        if ( empty( $email ) ) {
            $all_meta = $order->get_meta_data();
            foreach ( $all_meta as $meta ) {
                $value = $meta->get_value();
                if ( is_string( $value ) && is_email( $value ) ) {
                    $email = $value;
                    break;
                }
            }
        }

        if ( empty( $email ) ) {
            throw new Exception( 'Sincronização Abortada: Nenhum e-mail encontrado no Order ID: ' . $order_id );
        }

        // --- INTELIGÊNCIA RFM E TAGS ---
        $rfm  = $this->calculate_rfm( $email );
        $tags = $this->get_order_tags( $order );

        // --- PAYLOAD FINAL ---
        $data = [
            'firstname' => $first_name,
            'lastname'  => $last_name,
            'email'     => $email,
            'phone'     => $order->get_billing_phone(),
            'city'      => $order->get_billing_city(),
            'country'   => $order->get_billing_country(),
            
            // Injeta as notas de inteligência nos campos personalizados criados no Mautic
            'ltv'             => $rfm['ltv'],
            'total_pedidos'   => $rfm['total_pedidos'],
            'recency_score'   => $rfm['recency_score'],
            'frequency_score' => $rfm['frequency_score'],
            'monetary_score'  => $rfm['monetary_score'],
            
            // O Mautic aceita uma string separada por vírgulas para as Tags
            'tags'            => implode( ',', $tags )
        ];

        // ==================================================================
        // 🚀 INÍCIO DA INTELIGÊNCIA DE MAPEAMENTO VISUAL (Field-to-Field Sync)
        // ==================================================================
        $mapping = get_option( 'mwc_field_mapping', [] );
        
        if ( is_array( $mapping ) && ! empty( $mapping['woo'] ) && ! empty( $mapping['mautic'] ) ) {
            $count = count( $mapping['woo'] );
            
            for ( $i = 0; $i < $count; $i++ ) {
                $wp_key       = isset( $mapping['woo'][$i] ) ? trim( $mapping['woo'][$i] ) : '';
                $mautic_alias = isset( $mapping['mautic'][$i] ) ? trim( $mapping['mautic'][$i] ) : '';

                if ( ! empty( $wp_key ) && ! empty( $mautic_alias ) ) {
                    // 1º Tenta pegar o dado diretamente do Pedido (Checkout)
                    $meta_value = $order->get_meta( $wp_key );
                    
                    // 2º Fallback: Se não achou no pedido, tenta buscar no perfil do Cliente
                    if ( empty( $meta_value ) && $order->get_customer_id() ) {
                        $meta_value = get_user_meta( $order->get_customer_id(), $wp_key, true );
                    }

                    // Se o dado existe, injeta no pacote que vai para a API do Mautic
                    if ( ! empty( $meta_value ) ) {
                        $data[ $mautic_alias ] = $meta_value;
                    }
                }
            }
        }
        // ==================================================================
        // FIM DA INTELIGÊNCIA DE MAPEAMENTO
        // ==================================================================


        require_once MWC_PLUGIN_DIR . 'includes/class-mwc-api.php';
        $api = new MWC_Api();
        $response = $api->create_or_update_contact( $data );

        if ( isset($response['errors']) || (isset($response['status_code']) && $response['status_code'] >= 400) ) {
            throw new Exception( 'Falha na API do Mautic: ' . json_encode($response) );
        }
    }
}