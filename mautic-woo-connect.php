<?php
/**
 * Plugin Name: Mautic Woo Connect
 * Description: Integração entre WooCommerce e Mautic.
 * Version: 1.0.0
 * Author: Prodígito
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Segurança: Evita acesso direto ao arquivo
}

// Carrega o autoloader do Composer para a API do Mautic
if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
    require_once __DIR__ . '/vendor/autoload.php';
}

// Define as constantes principais do plugin
define( 'MWC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MWC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Carrega as classes (vamos criá-las no próximo passo)
require_once MWC_PLUGIN_DIR . 'includes/class-mwc-admin.php';
require_once MWC_PLUGIN_DIR . 'includes/class-mwc-auth.php';
require_once MWC_PLUGIN_DIR . 'includes/class-mwc-scheduler.php';
require_once MWC_PLUGIN_DIR . 'includes/class-mwc-frontend.php';
require_once MWC_PLUGIN_DIR . 'includes/class-mwc-abandoned-cart.php';
require_once MWC_PLUGIN_DIR . 'includes/class-mwc-privacy.php';
require_once MWC_PLUGIN_DIR . 'includes/class-mwc-bulk-sync.php';
require_once MWC_PLUGIN_DIR . 'includes/class-mwc-dwc.php';
require_once MWC_PLUGIN_DIR . 'includes/class-mwc-webhook.php';

// Inicializa a interface de Administração
add_action( 'plugins_loaded', function() {
    new MWC_Admin();
    new MWC_Auth();
    new MWC_Scheduler();
    new MWC_Frontend();
    new MWC_Abandoned_Cart();
    new MWC_Privacy();
    new MWC_Bulk_Sync();
    new MWC_DWC();
    new MWC_Webhook();
});