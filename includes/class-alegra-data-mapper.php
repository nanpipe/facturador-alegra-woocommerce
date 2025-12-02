<?php
/**
 * Clase que se encarga de mapear datos de WooCommerce a la estructura de Alegra.
 */
class Alegra_Data_Mapper
{

    private $api_client;

    public function __construct(Alegra_API_Client $client)
    {
        $this->api_client = $client;
    }

    /**
     * Obtiene o crea el cliente en Alegra.
     */
    public function get_or_create_client(WC_Order $order)
    {
        // [CÓDIGO DE get_or_create_client, incluyendo el cálculo del DV, va aquí]

        // A. Identificación
        $billing_id = $order->get_meta('_billing_cedula')
            ?: $order->get_meta('billing_cedula')
            ?: $order->get_meta('billing_identification');

        if (empty($billing_id)) {
            $billing_id = '222222222' . $order->get_id(); // Consumidor Final si no hay ID
        }

        // B. Buscar existente
        $search = $this->api_client->call_api('contacts?query=' . urlencode($billing_id), null, 'GET');

        if (is_array($search) && !empty($search) && isset($search[0]->id)) {
            return $search[0]->id;
        }

        // C. Crear nuevo 
        $first_name = $order->get_billing_first_name();
        $last_name = $order->get_billing_last_name();
        $full_name = trim($first_name . ' ' . $last_name);
        if (empty($full_name))
            $full_name = 'Cliente WooCommerce';

        $company = $order->get_billing_company();
        $email = $order->get_billing_email();
        $wc_regimen = $order->get_meta('billing_regimen');
        $wc_regimen_id = $order->get_meta('billing_regimen');
        $wc_person_type_id = $order->get_meta('billing_persontype');

        $wc_type = $order->get_meta('billing_typeid') ?: 'CC';
        $doc_type = 'CC';

        if (stripos($wc_type, 'NIT') !== false)
            $doc_type = 'NIT';
        if (stripos($wc_type, 'CE') !== false)
            $doc_type = 'CE';
        if (stripos($wc_type, 'TI') !== false)
            $doc_type = 'TI';
        if (stripos($wc_type, 'PP') !== false)
            $doc_type = 'PP';


        // --- 1. Mapeo de Tipo de Persona (kindOfPerson) ---
        // Si el valor del formulario está presente, lo usamos. Si no, usamos el fallback.
        if (!empty($wc_person_type_id) && ($wc_person_type_id === 'LEGAL_ENTITY' || $wc_person_type_id === 'PERSON_ENTITY')) {
            $kindOfPerson = $wc_person_type_id;
        } elseif ($doc_type === 'NIT') {
            // Fallback: Si es NIT y no hay formulario, asumimos Jurídica
            $kindOfPerson = 'LEGAL_ENTITY';
        } else {
            // Fallback general
            $kindOfPerson = 'PERSON_ENTITY';
        }

        // --- 2. Mapeo de Régimen (regime) ---
        // Usamos el valor del formulario si está presente.
        if (!empty($wc_regimen_id) && $wc_regimen_id !== '0') { // Asumiendo '0' o vacío si no se selecciona
            $regime = $wc_regimen_id;
        } elseif ($kindOfPerson === 'LEGAL_ENTITY') {
            // Fallback: Si es Persona Jurídica y no hay formulario, asumimos Común
            $regime = 'COMMON_REGIME';
        } else {
            // Fallback general
            $regime = 'SIMPLIFIED_REGIME';
        }


        // --- Lógica de Asignación de Nombre ---
        if ($kindOfPerson === 'LEGAL_ENTITY') {
            $display_name = !empty($company) ? $company : $full_name;
        } else {
            $display_name = $full_name;
        }

        $client_payload = [
            'name' => $display_name,
            'identificationObject' => [
                'type' => $doc_type,
                'number' => $billing_id
            ],
            'email' => $email,
            'kindOfPerson' => $kindOfPerson,
            'regime' => $regime
        ];

        if ($doc_type === 'NIT') {
            $client_payload['identificationObject']['dv'] = $this->calcular_dv($billing_id);
        }

        if ($kindOfPerson === 'PERSON_ENTITY') {
            $client_payload['nameObject'] = [
                'firstName' => $first_name ?: 'Cliente',
                'lastName' => $last_name ?: 'Final'
            ];
        }

        $created = $this->api_client->call_api('contacts', $client_payload, 'POST');

        if (isset($created->id)) {
            return $created->id;
        }

        $err_msg = isset($created->message) ? $created->message : json_encode($created);
        throw new Exception($err_msg);
    }

    /**
     * Construye el payload de la factura (build_invoice_payload) con toda la lógica de ítems, fees y descuentos.
     */
    /**
     * Construye el payload de la factura, distribuyendo los descuentos de las tarifas negativas 
     * entre los ítems del producto.
     */
    // Dentro de includes/class-alegra-data-mapper.php

    /**
     * Construye el payload de la factura, distribuyendo los descuentos de las tarifas negativas 
     * de manera exacta al RESTAR del precio unitario de los ítems del producto.
     */
    public function build_invoice_payload(WC_Order $order, $client_id)
    {
        $items = [];
        $alegra_product_id = 1;

        // --- 1. Calcular y Separar Fees/Descuentos (Pre-cálculo) ---

        // Usaremos floor() y round() en todas partes si la moneda no tiene decimales. 
        // Si la moneda DEBE usar decimales (como COP), usaremos round(..., 0) para forzar el entero.

        // Asumiendo que trabajamos en la unidad monetaria entera (ej: pesos sin centavos)
        $negative_fees_discount = 0; // Total de descuento proveniente de fees negativos
        $positive_fees = [];// Array para guardar fees positivos

        foreach ($order->get_fees() as $fee) {
            // Redondeamos para asegurar que el total es un entero
            $fee_total = round((float) $fee->get_total());
            $fee_name = substr($fee->get_name(), 0, 150);

            if ($fee_total <= 0) {
                // Sumar el valor absoluto del descuento total (como entero)
                $negative_fees_discount += abs($fee_total);
            } else {
                // Guardar tarifas positivas para procesarlas más tarde
                $positive_fees[] = [
                    'id' => 130,
                    'name' => 'RECARGO',
                    'price' => $fee_total, // Ya es un entero redondeado
                    'quantity' => 1,
                    'discount' => 0,
                    'description' => $fee_name,
                    'tax' => [],
                ];
            }
        }

        // --- 2. Lógica Exacta de Distribución de Descuentos (ENTEROS SIN DECIMALES) ---

        $product_items_array = $order->get_items();
        $product_item_count = count($product_items_array);

        $remaining_discount = 0;

        // Solo procedemos a distribuir si hay productos y un descuento total
        if ($product_item_count > 0 && $negative_fees_discount > 0) {

            // Calcular la distribución base por ítem (división entera)
            $base_discount_per_item = floor($negative_fees_discount / $product_item_count);

            // Calcular el remanente de unidades enteras que quedan (módulo)
            $remaining_discount = $negative_fees_discount % $product_item_count;

            // Contador para saber a cuántos ítems les queda por aplicar el remanente
            $remainder_counter = $remaining_discount;

        } else {
            $base_discount_per_item = 0;
        }


        foreach ($product_items_array as $line) {
            // Obtener el objeto producto asociado a la línea del pedido
            $product = $line->get_product();

            $product_id = $line->get_product_id();       // ID del Producto Padre (ej: 3467)
            $variation_id = $line->get_variation_id();   // ID de la Variación (ej: 9516)

            // 1. INTENTAR LEER DESDE LA VARIACIÓN (ID 9516)
            $item_alegra_id = get_post_meta($variation_id, '_alegra_item_id', true);

            // 2. SI LA VARIACIÓN NO TIENE EL DATO, LEER DESDE EL PADRE (ID 3467)
            if (empty($item_alegra_id) || !is_numeric($item_alegra_id)) {
                // Usar el ID del producto padre
                $item_alegra_id = get_post_meta($product_id, '_alegra_item_id', true);
            }

            // 3. Lógica de Fallback Final (Si aún no es válido, usa el valor global)
            $final_alegra_id = !empty($item_alegra_id) && is_numeric($item_alegra_id)
                ? (int) $item_alegra_id
                : $alegra_product_id; // <-- Usa el ID global/defecto (ej: 1)


            error_log(
                'Alegra Debug | ID Producto WC: ' . $product_id .
                ' | variation ID: ' . $variation_id . // <--- CORRECCIÓN AQUÍ ($variation_id)
                ' | Metadato encontrado (_alegra_item_id): ' . print_r($item_alegra_id, true) .
                ' | ID Final Usado: ' . $final_alegra_id .
                ' | alegra id: ' . $item_alegra_id // <--- CORRECCIÓN AQUÍ ($item_alegra_id)
            );

            $qty = (int) $line->get_quantity();

            // Usamos el subtotal (total * quantity) como entero
            $subtotal_line = round((float) $line->get_total());

            // Precio unitario base redondeado (ya incluye descuentos internos de WC)
            $unit_price_base = $qty > 0 ? round($subtotal_line / $qty) : 0;

            $line_discount_amount = 0;

            // Aplicamos el descuento solo si existe
            if ($negative_fees_discount > 0) {
                // 2a. Descuento base (ej: 3333)
                $line_discount_amount = $base_discount_per_item;

                // 2b. Aplicar el remanente (acumulador)
                if ($remainder_counter > 0) {
                    $line_discount_amount += 1;
                    $remainder_counter--;
                }
            }

            // Verificación de seguridad: El descuento a restar no debe ser mayor que el subtotal del ítem.
            if ($line_discount_amount > $subtotal_line) {
                $line_discount_amount = $subtotal_line;
            }

            // --- APLICACIÓN DIRECTA AL PRECIO (TU REQUERIMIENTO) ---

            // Nuevo Precio Unitario = (Precio Base * Cantidad - Descuento Aplicado) / Cantidad
            $new_unit_price = ($unit_price_base * $qty - $line_discount_amount) / $qty;

            // Forzamos el nuevo precio unitario a un entero si es necesario, redondeando hacia arriba o abajo
            $final_price = round($new_unit_price);

            // --------------------------------------------------------

            $items[] = [
                'id' => $final_alegra_id, // aQUI NO FUNCIONA
                'name' => substr($line->get_name(), 0, 190),
                // El precio unitario es el que se redujo por el descuento
                'price' => $final_price,
                'quantity' => $qty,
                'discount' => 0, // El descuento ya está incluido en 'price'
                'description' => substr($line->get_name(), 0, 400),
                'tax' => [],
            ];
        }

        // --- 3. Combinar Items, Fees Positivos y Envío ---

        // Añadir fees positivos (Tarifas)
        $items = array_merge($items, $positive_fees);

        // Procesar envío (como entero)
        $shipping = round((float) $order->get_shipping_total());
        if ($shipping > 0) {
            $items[] = [
                'id' => 131,
                'name' => 'ENVIO',
                'price' => $shipping, // Ya es un entero
                'quantity' => 1,
                'discount' => 0,
                'tax' => [],
            ];
        }

        // --- 4. Estructura Final ---

        // 1. Obtener la zona horaria configurada en WordPress (ej: 'America/Bogota')
        $timezone = wp_timezone_string();

        // 2. Crear un objeto DateTime con la zona horaria correcta
        // Esto asegura que la fecha actual sea calculada localmente.
        $local_datetime = new DateTime('now', new DateTimeZone($timezone));

        // 3. Formatear la fecha local
        $local_date = $local_datetime->format('Y-m-d');

        // La API de Alegra suele usar 'open' o 'draft'. He dejado 'open' como está.
        $status = 'open';

        return [
            'client' => ['id' => $client_id],
            'items' => $items,
            'status' => $status,
            'date' => $local_date,
            'dueDate' => $local_date,
            'paymentMethod' => 'CASH',
            'paymentForm' => 'CASH',
            'type' => 'NATIONAL',
            'operationType' => 'STANDARD',
            'notes' => 'Pedido WooCommerce #' . $order->get_id()
        ];
    }
}