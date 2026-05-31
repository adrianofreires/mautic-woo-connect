<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MWC_Frontend {

    private $api_url;

    public function __construct() {
        $this->api_url = rtrim( get_option('mwc_base_url'), '/' );
        
        // Se a URL do Mautic não estiver configurada, aborta
        if ( empty( $this->api_url ) ) return;

        // Injeta o script no rodapé (wp_footer) para Alta Performance
        // Isso evita o bloqueio de renderização (Render-Blocking) que prejudica o SEO
        add_action( 'wp_footer', [ $this, 'inject_mautic_tracking_script' ], 99 );
    }

    /**
     * Descobre quais categorias/produtos o cliente está vendo agora
     */
    private function get_current_page_tags() {
        $tags = [];

        // Se o cliente estiver na página de um produto específico
        if ( function_exists('is_product') && is_product() ) {
            global $post;
            
            $tags[] = 'comportamento:visualizou-produto';
            
            // Pega as categorias do produto que ele está olhando
            $terms = wp_get_object_terms( $post->ID, 'product_cat' );
            if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
                foreach ( $terms as $term ) {
                    $tags[] = 'interesse:' . $term->slug; // Ex: interesse:tenis-de-corrida
                }
            }
        } 
        // Se o cliente estiver navegando em uma página de categoria
        elseif ( function_exists('is_product_category') && is_product_category() ) {
            $tags[] = 'comportamento:navegou-categoria';
            
            $queried_object = get_queried_object();
            if ( $queried_object ) {
                $tags[] = 'interesse:' . $queried_object->slug;
            }
        }

        // Se estiver no carrinho
        elseif ( function_exists('is_cart') && is_cart() ) {
            $tags[] = 'funil:acessou-carrinho';
        }

        return $tags;
    }

    /**
     * Gera o script de rastreamento assíncrono com as Tags Dinâmicas
     */
    public function inject_mautic_tracking_script() {
        // Pega as tags do comportamento atual
        $tags = $this->get_current_page_tags();
        $tags_string = !empty($tags) ? implode(',', $tags) : '';

        // Script base nativo do Mautic otimizado
        ?>
        <script>
            (function(w,d,t,u,n,a,m){w['MauticTrackingObject']=n;
                w[n]=w[n]||function(){(w[n].q=w[n].q||[]).push(arguments)},a=d.createElement(t),
                m=d.getElementsByTagName(t)[0];a.async=1;a.src=u;m.parentNode.insertBefore(a,m)
            })(window,document,'script','<?php echo esc_url( $this->api_url ); ?>/mtc.js','mt');

            // Envia as tags de interesse capturadas pelo PHP direto para o Mautic via JS
            mt('send', 'pageview', { tags: '<?php echo esc_js( $tags_string ); ?>' });
        </script>
        <?php
    }
}