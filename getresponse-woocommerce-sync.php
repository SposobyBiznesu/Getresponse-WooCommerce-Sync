<?php
/**
 * Plugin Name: GetResponse WooCommerce Sync for Wordpress
 * Description: Automatically subscribes WooCommerce customers to GetResponse lists based on products they purchase.
 * Version:     1.0.0
 * Author:      adamekk.pl
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// --- 1. Rejestracja ustawień i strony w panelu admina ---
add_action( 'admin_menu', 'grwc_add_admin_menu' );
add_action( 'admin_init', 'grwc_settings_init' );

function grwc_add_admin_menu() {
    add_submenu_page(
        'woocommerce',
        'GetResponse Sync',
        'GetResponse Sync',
        'manage_options',
        'getresponse-sync',
        'grwc_options_page'
    );
}

function grwc_settings_init() {
    register_setting( 'grwc_pluginPage', 'grwc_options' );

    add_settings_section(
        'grwc_pluginPage_section',
        'Ustawienia GetResponse',
        null,
        'grwc_pluginPage'
    );

    // Pole: klucz API
    add_settings_field(
        'grwc_api_key',
        'Klucz API GetResponse',
        'grwc_api_key_render',
        'grwc_pluginPage',
        'grwc_pluginPage_section'
    );

    // Pole: mapowanie produktów → kampanii
    add_settings_field(
        'grwc_mapping',
        'Mapowanie produktów → list',
        'grwc_mapping_render',
        'grwc_pluginPage',
        'grwc_pluginPage_section'
    );
}

function grwc_api_key_render() {
    $options = get_option( 'grwc_options' );
    ?>
    <input type='text' name='grwc_options[grwc_api_key]' value='<?php echo esc_attr( $options['grwc_api_key'] ?? '' ); ?>' style='width:400px;' />
    <p class="description">Wprowadź swój klucz API z panelu GetResponse (Settings → API).</p>
    <?php
}

function grwc_mapping_render() {
    $options   = get_option( 'grwc_options' );
    $mapping   = $options['grwc_mapping'] ?? [];
    $products  = wc_get_products( [ 'limit' => -1 ] );
    $api_key   = trim( $options['grwc_api_key'] ?? '' );
    $campaigns = [];

    // Pobierz listę kampanii tylko jeśli API key jest ustawiony
    if ( $api_key ) {
        $response = wp_remote_get( 'https://api.getresponse.com/v3/campaigns', [
            'headers' => [
                'X-Auth-Token' => 'api-key ' . $api_key,
                'Content-Type' => 'application/json',
            ],
        ] );
        if ( ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200 ) {
            $campaigns = json_decode( wp_remote_retrieve_body( $response ), true );
        }
    }

    // Render mapowania
    echo '<table id="gr-mapping-table" class="widefat">';
    echo '<thead><tr><th>Produkt</th><th>GetResponse List (Campaign)</th><th></th></tr></thead><tbody>';
    if ( ! empty( $mapping ) ) {
        foreach ( $mapping as $row ) {
            grwc_mapping_row( $row, $products, $campaigns );
        }
    } else {
        grwc_mapping_row( [], $products, $campaigns );
    }
    echo '</tbody></table>';
    echo '<p><button type="button" class="button" id="gr-add-row">+ Dodaj wiersz</button></p>';
    ?>
    <script>
    jQuery(document).ready(function($){
        $('#gr-add-row').on('click', function(){
            var $tbody = $('#gr-mapping-table tbody');
            var row = <?php
                ob_start();
                grwc_mapping_row([], $products, $campaigns);
                $html = ob_get_clean();
                echo json_encode( $html );
            ?>;
            $tbody.append(row);
        });
        $(document).on('click', '.gr-remove-row', function(){
            $(this).closest('tr').remove();
        });
    });
    </script>
    <?php
}

function grwc_mapping_row( $row, $products, $campaigns ) {
    $prod_id = $row['product'] ?? '';
    $camp_id = $row['campaign'] ?? '';
    echo '<tr>';
    // Produkt
    echo '<td><select name="grwc_options[grwc_mapping][][product]">';
    echo '<option value="">— wybierz produkt —</option>';
    foreach ( $products as $p ) {
        printf(
            '<option value="%1$u"%2$s>%3$s</option>',
            $p->get_id(),
            selected( $p->get_id(), $prod_id, false ),
            esc_html( $p->get_name() )
        );
    }
    echo '</select></td>';
    // Kampania
    echo '<td><select name="grwc_options[grwc_mapping][][campaign]">';
    echo '<option value="">— wybierz listę —</option>';
    foreach ( $campaigns as $c ) {
        printf(
            '<option value="%1$s"%2$s>%3$s</option>',
            esc_attr( $c['campaignId'] ),
            selected( $c['campaignId'], $camp_id, false ),
            esc_html( $c['name'] )
        );
    }
    echo '</select></td>';
    // Usuń
    echo '<td><button type="button" class="button gr-remove-row">Usuń</button></td>';
    echo '</tr>';
}

function grwc_options_page() {
    ?>
    <form action='options.php' method='post'>
        <h1>GetResponse & WooCommerce Sync</h1>
        <?php
        settings_fields( 'grwc_pluginPage' );
        do_settings_sections( 'grwc_pluginPage' );
        submit_button();
        ?>
    </form>
    <?php
}

// --- 2. Podpinamy się pod zakończenie zamówienia ---
add_action( 'woocommerce_order_status_completed', 'gr_subscribe_on_order', 10, 1 );

function gr_subscribe_on_order( $order_id ) {
    $order   = wc_get_order( $order_id );
    $options = get_option( 'grwc_options' );
    $mapping = $options['grwc_mapping'] ?? [];

    if ( ! $order || empty( $mapping ) ) {
        return;
    }

    $api_key = trim( $options['grwc_api_key'] ?? '' );
    if ( ! $api_key ) {
        return;
    }

    $email      = $order->get_billing_email();
    $first_name = $order->get_billing_first_name();
    $last_name  = $order->get_billing_last_name();

    // Dla każdego produktu w zamówieniu
    foreach ( $order->get_items() as $item ) {
        $prod_id = $item->get_product_id();
        // znajdź kampanię
        foreach ( $mapping as $map ) {
            if ( intval( $map['product'] ) === $prod_id && $map['campaign'] ) {
                grwc_create_contact( $api_key, $email, $first_name, $last_name, $map['campaign'] );
            }
        }
    }
}

// --- 3. Funkcja tworząca kontakt w GetResponse ---
function grwc_create_contact( $api_key, $email, $first_name, $last_name, $campaign_id ) {
    $body = [
        'email'      => $email,
        'name'       => trim( $first_name . ' ' . $last_name ),
        'campaign'   => [ 'campaignId' => $campaign_id ],
        'cycleDay'   => 0
    ];
    $response = wp_remote_post( 'https://api.getresponse.com/v3/contacts', [
        'headers' => [
            'X-Auth-Token' => 'api-key ' . $api_key,
            'Content-Type' => 'application/json',
        ],
        'body'    => wp_json_encode( $body ),
        'timeout' => 20,
    ] );

    // Jeśli już istnieje (409), ignoruj
    if ( is_wp_error( $response ) ) {
        error_log( 'GetResponse error: ' . $response->get_error_message() );
    } elseif ( wp_remote_retrieve_response_code( $response ) === 409 ) {
        // kontakt już istnieje – można ewentualnie dodać tag lub wysłać ponownie
    } elseif ( wp_remote_retrieve_response_code( $response ) >= 400 ) {
        error_log( 'GetResponse HTTP ' . wp_remote_retrieve_response_code( $response ) . ': ' . wp_remote_retrieve_body( $response ) );
    }
}
