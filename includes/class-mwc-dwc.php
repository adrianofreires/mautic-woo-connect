<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MWC_DWC {

    public function __construct() {
        // Registra o nosso shortcode exclusivo no WordPress
        add_shortcode( 'mwc_dwc', [ $this, 'render_dynamic_content' ] );
    }

    /**
     * Renderiza o HTML exato que o Mautic precisa para injetar o conteúdo dinâmico
     */
    public function render_dynamic_content( $atts, $content = null ) {
        // Puxa o nome do slot passado pelo lojista
        $atts = shortcode_atts( [
            'slot' => '',
        ], $atts, 'mwc_dwc' );

        // Se não houver nome do slot, devolvemos apenas o conteúdo padrão
        if ( empty( $atts['slot'] ) ) {
            return $content; 
        }

        $slot_name = esc_attr( $atts['slot'] );
        
        // Estrutura HTML obrigatória do Mautic para DWC
        ob_start();
        ?>
        <div data-slot="dwc" data-param-slot-name="<?php echo $slot_name; ?>">
            <?php 
            // O conteúdo padrão será exibido para visitantes não rastreados 
            // ou se os filtros do Mautic não forem correspondidos.
            echo do_shortcode( $content ); 
            ?>
        </div>
        <?php
        return ob_get_clean();
    }
}