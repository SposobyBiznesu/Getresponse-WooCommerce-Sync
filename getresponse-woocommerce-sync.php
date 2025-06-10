<?php
/**
 * Plugin Name: GetResponse WooCommerce Sync
 * Description: Automatycznie subskrybuje klientów WooCommerce do list GetResponse na podstawie zakupionych produktów.
 * Version:     1.0.3
 * Author:      adamekk.pl
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 1. Ustawienia z sanitize_callback
 */
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
    register_setting(
        'grwc_pluginPage',
        'grwc_options',
        'grwc_options_sanitize'
    );

    add_settings_section(
        'grwc_pluginPage_section',
        'Ustawienia GetResponse',
        null,
        'grwc_pluginPage'
    );

    add_settings_field(
        'grwc_api_key',
        'Klucz API GetResponse',
        'grwc_api_key_render',
        'grwc_pluginPage',
        'grwc_pluginPage_section'
    );

    add_settings_field(
        'grwc_mapping',
        'Mapowanie produktów → list',
        'grwc_mapping_render',
        'grwc_pluginPage',
        'grwc_pluginPage_section'
    );
}

function grwc_api_key_render() {
    $opts = get_option( 'grwc_options', [] );
    ?>
    <input type="text"
           name="grwc_options[grwc_api_key]"
           value="<?php echo esc_attr( $opts['grwc_api_key'] ?? '' ); ?>"
           style="width:400px;" />
    <p class="description">Wprowadź swój klucz API z panelu GetResponse (Settings → API).</p>
    <?php
}

function grwc_mapping_render() {
    $opts     = get_option( 'grwc_options', [] );
    $mapping  = $opts['grwc_mapping'] ?? [];

    // pobierz produkty
    if ( function_exists( 'wc_get_products' ) ) {
        $products = wc_get_products( [ 'limit' => -1 ] );
    } else {
        $products = [];
        $posts    = get_posts( [ 'post_type' => 'product', 'numberposts' => -1 ] );
        foreach ( $posts as $p ) {
            if ( function_exists( 'wc_get_product' ) ) {
                if ( $prod = wc_get_product( $p->ID ) ) {
                    $products[] = $prod;
                }
            }
        }
    }

    // pobierz kampanie
    $campaigns = [];
    $apikey    = trim( $opts['grwc_api_key'] ?? '' );
    if ( $apikey ) {
        $resp = wp_remote_get( 'https://api.getresponse.com/v3/campaigns', [
            'headers' => [
                'X-Auth-Token'=> 'api-key ' . $apikey,
                'Content-Type'=> 'application/json',
            ],
        ] );
        if ( ! is_wp_error( $resp ) && wp_remote_retrieve_response_code( $resp ) === 200 ) {
            $campaigns = json_decode( wp_remote_retrieve_body( $resp ), true );
        }
    }

    // tabela
    echo '<table class="widefat" id="gr-mapping-table">
            <thead>
              <tr><th>Produkt</th><th>GetResponse List (Campaign)</th><th></th></tr>
            </thead>
            <tbody>';
    if ( empty( $mapping ) ) {
        // pusty wiersz
        grwc_mapping_row( [], $products, $campaigns );
    } else {
        foreach ( $mapping as $row ) {
            grwc_mapping_row( $row, $products, $campaigns );
        }
    }
    echo   '</tbody>
          </table>
          <p><button type="button" class="button" id="gr-add-row">+ Dodaj wiersz</button></p>';

    // skrypt do numerowania name po add/remove
    ?>
    <script>
    jQuery(function($){
        function updateNames() {
            $('#gr-mapping-table tbody tr').each(function(i){
                $(this).find('select.product-select')
                       .attr('name','grwc_options[grwc_mapping]['+i+'][product]');
                $(this).find('select.campaign-select')
                       .attr('name','grwc_options[grwc_mapping]['+i+'][campaign]');
            });
        }

        // przy ładowaniu
        updateNames();

        $('#gr-add-row').on('click', function(){
            var $new = $('#gr-mapping-table tbody tr:first').clone();
            $new.find('select').val('');  // wyczyść selekty
            $('#gr-mapping-table tbody').append($new);
            updateNames();
        });

        $(document).on('click','.gr-remove-row', function(){
            $(this).closest('tr').remove();
            updateNames();
        });
    });
    </script>
    <?php
}

function grwc_mapping_row( $row, $products, $campaigns ) {
    $prod_id = $row['product']  ?? '';
    $camp_id = $row['campaign'] ?? '';

    echo '<tr>';
    // produkt
    echo '<td><select class="product-select">';
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

    // kampania
    echo '<td><select class="campaign-select">';
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

    // usuń
    echo '<td><button type="button" class="button gr-remove-row">Usuń</button></td>';
    echo '</tr>';
}

function grwc_options_page() {
    ?>
    <div class="wrap">
      <h1>GetResponse & WooCommerce Sync</h1>
      <form action="options.php" method="post">
        <?php
          settings_fields( 'grwc_pluginPage' );
          do_settings_sections( 'grwc_pluginPage' );
          submit_button();
        ?>
      </form>
    </div>
    <?php
}

/**
 * 2. Sanitizacja
 */
function grwc_options_sanitize( $input ) {
    $output = [];

    // API key
    if ( ! empty( $input['grwc_api_key'] ) ) {
        $output['grwc_api_key'] = sanitize_text_field( $input['grwc_api_key'] );
    }

    // mapping: tylko pełne i unikalne
    if ( ! empty( $input['grwc_mapping'] ) && is_array( $input['grwc_mapping'] ) ) {
        $seen   = [];
        $uniq   = [];
        foreach ( $input['grwc_mapping'] as $row ) {
            if ( empty( $row['product'] ) || empty( $row['campaign'] ) ) {
                continue;
            }
            $key = intval( $row['product'] ) . '|' . sanitize_text_field( $row['campaign'] );
            if ( isset( $seen[ $key ] ) ) {
                continue;
            }
            $seen[ $key ] = true;
            $uniq[] = [
                'product'  => intval( $row['product'] ),
                'campaign' => sanitize_text_field( $row['campaign'] ),
            ];
        }
        $output['grwc_mapping'] = array_values( $uniq );
    }

    return $output;
}

/**
 * 3. Subskrypcja przy zamówieniu
 */
add_action( 'woocommerce_order_status_completed', 'gr_subscribe_on_order', 10, 1 );

function gr_subscribe_on_order( $order_id ) {
    if ( ! class_exists( 'WC_Order' ) ) {
        return;
    }

    $order   = wc_get_order( $order_id );
    $opts    = get_option( 'grwc_options', [] );
    $map     = $opts['grwc_mapping'] ?? [];
    $apikey  = trim( $opts['grwc_api_key'] ?? '' );

    if ( ! $order || ! $apikey || empty( $map ) ) {
        return;
    }

    $email = $order->get_billing_email();
    $name  = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );

    foreach ( $order->get_items() as $item ) {
        $pid = $item->get_product_id();
        foreach ( $map as $m ) {
            if ( intval( $m['product'] ) === $pid ) {
                grwc_create_contact( $apikey, $email, $name, $m['campaign'] );
            }
        }
    }
}

function grwc_create_contact( $apikey, $email, $name, $campaign_id ) {
    $body = [
        'email'    => $email,
        'name'     => $name,
        'campaign' => ['campaignId' => $campaign_id],
        'cycleDay' => 0,
    ];
    $resp = wp_remote_post( 'https://api.getresponse.com/v3/contacts', [
        'headers' => [
            'X-Auth-Token'=> 'api-key ' . $apikey,
            'Content-Type'=> 'application/json',
        ],
        'body'    => wp_json_encode( $body ),
        'timeout' => 20,
    ] );

    if ( is_wp_error( $resp ) ) {
        error_log( 'GetResponse error: ' . $resp->get_error_message() );
    } else {
        $code = wp_remote_retrieve_response_code( $resp );
        if ( 409 !== $code && $code >= 400 ) {
            error_log( 'GetResponse HTTP ' . $code . ': ' . wp_remote_retrieve_body( $resp ) );
        }
    }
}
