<?php
/**
 * Clase que maneja la UI, AJAX y la configuración del plugin en el área de administración.
 */


class WC_Alegra_Admin
{

    public function __construct()
    {
        // Hooks de Configuración
        add_action('admin_menu', [$this, 'settings_page']);
        add_action('admin_init', [$this, 'register_settings']);

        // Hooks de UI en Pedido
        add_action('add_meta_boxes', [$this, 'add_button_metabox']);
        add_action('woocommerce_admin_order_data_after_order_details', [$this, 'render_manual_invoice_box']);

        // Hooks AJAX
        add_action('wp_ajax_alegra_ajax_generate', [$this, 'ajax_process_invoice']);
        add_action('wp_ajax_alegra_ajax_delete', [$this, 'ajax_delete_invoice_id']);

        // Hooks de Notificaciones
        add_action('admin_notices', [$this, 'maybe_show_missing_credentials_notice']);

        // Add Product ID, we will populate it
        add_action('woocommerce_product_options_general_product_data', [$this, 'add_alegra_product_field']);
        add_action('woocommerce_process_product_meta', [$this, 'save_alegra_product_field']);
    }

    // --- SECCIÓN: CONFIGURACIÓN Y NOTICES ---
    // (Código de settings_page, register_settings, etc. - Sin cambios)

    public function register_settings()
    {
        register_setting('wc_alegra_settings', 'wc_alegra_email');
        register_setting('wc_alegra_settings', 'wc_alegra_token');
        register_setting('wc_alegra_settings', 'wc_alegra_tax_exempt_id');
    }

    public function settings_page()
    {
        add_menu_page('Alegra', 'Alegra', 'manage_options', 'wc-alegra', [$this, 'settings_html'], 'dashicons-media-text', 58);
    }

    public function settings_html()
    {
        // ... (HTML para la página de ajustes)
        if (!current_user_can('manage_options'))
            return;
        ?>
        <div class="wrap">
            <h1>Ajustes de Alegra</h1>
            <form method="POST" action="options.php">
                <?php settings_fields('wc_alegra_settings'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">Email API</th>
                        <td><input type="text" name="wc_alegra_email"
                                value="<?php echo esc_attr(get_option('wc_alegra_email')); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row">Token API</th>
                        <td><input type="password" name="wc_alegra_token"
                                value="<?php echo esc_attr(get_option('wc_alegra_token')); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row">ID impuesto exento</th>
                        <td>
                            <input type="number" name="wc_alegra_tax_exempt_id"
                                value="<?php echo esc_attr(get_option('wc_alegra_tax_exempt_id', 1)); ?>" class="small-text" />
                            <p class="description">Por defecto es 1.</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    public function maybe_show_missing_credentials_notice()
    {
        if (!current_user_can('manage_options'))
            return;
        if (!get_option('wc_alegra_email') || !get_option('wc_alegra_token')) {
            echo '<div class="notice notice-warning"><p><strong>WooCommerce Alegra:</strong> Configura tus credenciales <a href="' . admin_url('admin.php?page=wc-alegra') . '">aquí</a>.</p></div>';
        }
    }

    // --- SECCIÓN: UI EN PEDIDO ---

    public function add_button_metabox()
    {
        add_meta_box('alegra_invoice_box', 'Alegra', [$this, 'render_content'], 'shop_order', 'side', 'default');
    }

    public function render_manual_invoice_box($order)
    {
        $order_id = $order instanceof WC_Order ? $order->get_id() : intval($order);
        echo '<div style="margin-top:20px; padding:15px; background:#fff; border:1px solid #ccd0d4; border-radius:4px; box-shadow:0 1px 1px rgba(0,0,0,.04);">';
        echo '<h3 style="margin:0 0 10px; font-size:1.1em;">Alegra</h3>';
        $this->render_content($order_id);
        echo '</div>';
    }

    public function render_content($post_or_id)
    {
        // ... (Cuerpo del render_content con los scripts JS para generar y borrar)
        $order_id = is_object($post_or_id) ? $post_or_id->ID : $post_or_id;
        $invoice_id = get_post_meta($order_id, 'alegra_invoice_id', true);
        $url = get_post_meta($order_id, 'alegra_invoice_url', true) ?: 'https://app.alegra.com/invoices/' . rawurlencode($invoice_id);

        if ($invoice_id) {
            ?>
            <p style="color:green; font-weight:bold;">✓ Factura Creada: <?php echo esc_html($invoice_id); ?></p>
            <a href="<?php echo esc_url($url); ?>" target="_blank" class="button">Ver en Alegra</a>
            <button type="button" id="btn-alegra-delete" class="button">Borrar ID</button>

            <script type="text/javascript">
                // [Script de Borrado JS]
                jQuery(document).ready(function ($) {
                    $('#btn-alegra-delete').on('click', function (e) {
                        e.preventDefault();
                        var btn = $(this);
                        if (confirm('¿Estás seguro de borrar el ID de factura? Esto permitirá regenerarla.')) {
                            btn.prop('disabled', true).text('Borrando...');

                            $.post(ajaxurl, {
                                action: 'alegra_ajax_delete',
                                security: '<?php echo wp_create_nonce("alegra_delete_nonce"); ?>',
                                order_id: <?php echo $order_id; ?>
                            }, function (response) {
                                if (response.success) {
                                    alert('ID borrado. Recargando para regenerar.');
                                    location.reload();
                                } else {
                                    alert('Error al borrar: ' + (response.data || 'Desconocido'));
                                    btn.prop('disabled', false).text('Borrar ID');
                                }
                            }).fail(function () {
                                alert('Error de conexión.');
                                btn.prop('disabled', false).text('Borrar ID');
                            });
                        }
                    });
                });
            </script>
            <?php
        } else {
            ?>
            <button type="button" id="btn-alegra-generate" class="button button-primary" style="width:100%; text-align:center;">
                Generar Factura en Alegra
            </button>
            <p id="alegra-msg" style="margin-top:10px; color:#666; font-size:12px;">Creará un borrador en Alegra.</p>

            <script type="text/javascript">
                // [Script de Generación JS]
                jQuery(document).ready(function ($) {
                    $('#btn-alegra-generate').on('click', function (e) {
                        e.preventDefault();
                        var btn = $(this);
                        var msg = $('#alegra-msg');

                        if (confirm('¿Estás seguro de generar la factura en Alegra?')) {
                            btn.prop('disabled', true).text('Procesando...');
                            msg.text('Conectando con Alegra, por favor espera...');

                            $.post(ajaxurl, {
                                action: 'alegra_ajax_generate',
                                security: '<?php echo wp_create_nonce("alegra_ajax_nonce"); ?>',
                                order_id: <?php echo $order_id; ?>
                            }, function (response) {
                                if (response.success) {
                                    msg.css('color', 'green').text('¡Éxito! Recargando...');
                                    location.reload();
                                } else {
                                    btn.prop('disabled', false).text('Generar Factura en Alegra');
                                    msg.css('color', 'red').text('Error: ' + (response.data || 'Desconocido'));
                                    alert('Error Alegra: ' + (response.data || 'Desconocido'));
                                }
                            }).fail(function () {
                                btn.prop('disabled', false).text('Reintentar');
                                msg.css('color', 'red').text('Error de conexión con el servidor.');
                                alert('Error de conexión (500/404). Revisa el error_log.');
                            });
                        }
                    });
                });
            </script>
            <?php
        }
    }


    // --- SECCIÓN: LÓGICA DE CONTROL AJAX ---

    private function initialize_services()
    {
        $api_email = get_option('wc_alegra_email');
        $api_token = get_option('wc_alegra_token');

        // Lanzamos la excepción para que sea capturada en el hook AJAX
        $api_client = new Alegra_API_Client($api_email, $api_token);
        $mapper = new Alegra_Data_Mapper($api_client);
        return [$api_client, $mapper];
    }

    public function ajax_process_invoice()
    {
        check_ajax_referer('alegra_ajax_nonce', 'security');
        if (!current_user_can('manage_woocommerce'))
            wp_send_json_error('Permisos insuficientes.');

        try {
            list($api_client, $mapper) = $this->initialize_services();
        } catch (Exception $e) {
            wp_send_json_error('Error de inicialización: ' . $e->getMessage());
        }

        $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
        $order = wc_get_order($order_id);
        if (!$order)
            wp_send_json_error('El pedido no existe.');
        if (get_post_meta($order_id, 'alegra_invoice_id', true))
            wp_send_json_error('Este pedido ya tiene una factura generada.');


        // 1. Cliente
        try {
            $client_id = $mapper->get_or_create_client($order);
        } catch (Exception $e) {
            wp_send_json_error('Error Cliente: ' . $e->getMessage());
        }

        if (!$client_id)
            wp_send_json_error('No se pudo obtener el ID del cliente de Alegra.');

        // 2. Factura
        try {
            $invoice_payload = $mapper->build_invoice_payload($order, $client_id);
            $response = $api_client->call_api('invoices', $invoice_payload, 'POST');
        } catch (Exception $e) {
            // Error de API capturado en call_api
            $order->add_order_note("Error Alegra al crear factura: " . $e->getMessage());
            wp_send_json_error("Alegra rechazó la factura: " . $e->getMessage());
        }


        if (isset($response->id)) {
            update_post_meta($order_id, 'alegra_invoice_id', sanitize_text_field($response->id));
            if (isset($response->link))
                update_post_meta($order_id, 'alegra_invoice_url', esc_url_raw($response->link));
            $order->add_order_note("Factura Alegra creada: " . $response->id);
            wp_send_json_success('Factura creada correctamente');
        } else {
            $err = isset($response->message) ? $response->message : json_encode($response);
            $order->add_order_note("Error Alegra al crear factura: $err");
            wp_send_json_error("Alegra rechazó la factura (Respuesta inesperada): $err");
        }
    }

    public function ajax_delete_invoice_id()
    {
        check_ajax_referer('alegra_delete_nonce', 'security');
        if (!current_user_can('manage_woocommerce'))
            wp_send_json_error('Permisos insuficientes.');

        $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
        if (!$order_id)
            wp_send_json_error('ID de pedido inválido.');

        $deleted_id = delete_post_meta($order_id, 'alegra_invoice_id');
        $deleted_url = delete_post_meta($order_id, 'alegra_invoice_url');

        if ($deleted_id || $deleted_url) {
            $order = wc_get_order($order_id);
            if ($order)
                $order->add_order_note("ID de factura Alegra borrado manualmente. Lista para regenerar.");
            wp_send_json_success('ID de factura borrado.');
        } else {
            wp_send_json_error('El ID no existía o no se pudo borrar.');
        }
    }

    // Nuevos métodos en includes/class-wc-alegra-admin.php

/**
 * Añade el campo de ID de Alegra en la edición del producto.
 */
public function add_alegra_product_field() {
    echo '<div class="options_group show_if_simple">';
    
    woocommerce_wp_text_input([
        'id'          => '_alegra_item_id',
        'label'       => __('ID de Item Alegra', 'wc-alegra-invoices'),
        'placeholder' => __('Escribe el ID de producto/servicio de Alegra', 'wc-alegra-invoices'),
        'desc_tip'    => 'true',
        'description' => __('ID numérico de Alegra (requerido para facturación precisa).', 'wc-alegra-invoices'),
        'data_type'   => 'integer',
    ]);
    
    echo '</div>';
}

/**
 * Guarda el campo de ID de Alegra al guardar el producto.
 */
public function save_alegra_product_field($post_id) {
    $alegra_item_id = isset($_POST['_alegra_item_id']) ? sanitize_text_field($_POST['_alegra_item_id']) : '';
    
    if (!empty($alegra_item_id) && is_numeric($alegra_item_id)) {
        update_post_meta($post_id, '_alegra_item_id', $alegra_item_id);
    } else {
        delete_post_meta($post_id, '_alegra_item_id');
    }
}
}