<?php
/**
 * Plugin Name: Mautic Woo Connect
 * Plugin URI: https://prodigito.com.br
 * Description: Integração avançada de inteligência e performance entre WooCommerce e Mautic. Sincronização bidirecional, cálculos RFM em tempo real e rastreamento comportamental dinâmico.
 * Version: 1.0.1
 * Author: Prodígito
 * Author URI: https://prodigito.com.br
 * License: GPL v2 or later
 * Text Domain: mautic-woo-connect
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Segurança: Evita acesso direto ao arquivo
}

// 1. O 'use' fica solto aqui no topo, fora de qualquer if!
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

// Carrega o autoloader do Composer para a API do Mautic
if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
    require_once __DIR__ . '/vendor/autoload.php';
}

// Define as constantes principais do plugin
define( 'MWC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MWC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'MWC_VERSION', '1.0.1' );

// Declara compatibilidade com HPOS (High-Performance Order Storage) do WooCommerce
add_action( 'before_woocommerce_init', function () {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            __FILE__,
            true
        );
    }
} );

// ==============================================================================
// 🚀 SISTEMA DE ATUALIZAÇÃO AUTOMÁTICA (OTA VIA GITHUB)
// ==============================================================================
// Verifica se a biblioteca foi baixada e colocada na pasta do plugin
if ( file_exists( MWC_PLUGIN_DIR . 'plugin-update-checker/plugin-update-checker.php' ) ) {
    require_once MWC_PLUGIN_DIR . 'plugin-update-checker/plugin-update-checker.php';

    // 2. Aqui a gente só chama a classe PucFactory direto, pois ela já foi importada lá em cima
    $mwcUpdateChecker = PucFactory::buildUpdateChecker(
        'https://github.com/adrianofreires/mautic-woo-connect/',
        __FILE__,
        'mautic-woo-connect'
    );

    // Define a branch principal
    $mwcUpdateChecker->setBranch('main');
}
// ==============================================================================

// Carrega as classes
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
add_action( 'plugins_loaded', function () {
    // Sem WooCommerce, o plugin não tem o que fazer e usaria funções inexistentes (fatal).
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', function () {
            echo '<div class="notice notice-error"><p><strong>Mautic Woo Connect:</strong> requer o WooCommerce instalado e ativo.</p></div>';
        } );
        return;
    }

    new MWC_Admin();
    new MWC_Auth();
    new MWC_Scheduler();
    new MWC_Frontend();
    new MWC_Abandoned_Cart();
    new MWC_Privacy();
    new MWC_Bulk_Sync();
    new MWC_DWC();
    new MWC_Webhook();
} );