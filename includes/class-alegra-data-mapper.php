<?php
/**
 * Clase que se encarga de mapear datos de WooCommerce a la estructura de Alegra.
 */
class Alegra_Data_Mapper {

    private $api_client;

    public function __construct( Alegra_API_Client $client ) {
        $this->api_client = $client;
    }

    /**
     * Obtiene o crea el cliente en Alegra.
     */
    public function get_or_create_client( WC_Order $order ) {
        // [CÓDIGO DE get_or_create_client, incluyendo el cálculo del DV, va aquí]

        // A. Identificación
        $billing_id = $order->get_meta('_billing_cedula') 
                    ?: $order->get_meta('billing_cedula') 
                    ?: $order->get_meta('_billing_identification')
                    ?: $order->get_meta('billing_identification')
                    ?: $order->get_billing_email();

        if ( empty($billing_id) ) {
            $billing_id = 'CF-' . $order->get_id(); // Consumidor Final si no hay ID
        }

        // B. Buscar existente
        $search = $this->api_client->call_api('contacts?query=' . urlencode($billing_id), null, 'GET');
        
        if ( is_array($search) && !empty($search) && isset($search[0]->id) ) {
            return $search[0]->id;
        }

        // C. Crear nuevo 
        $first_name = $order->get_billing_first_name();
        $last_name  = $order->get_billing_last_name();
        $full_name  = trim($first_name . ' ' . $last_name);
        if ( empty($full_name) ) $full_name = 'Cliente WooCommerce';

        $company    = $order->get_billing_company();
        $email      = $order->get_billing_email();

        $wc_type = $order->get_meta('billing_typeid') ?: 'CC';
        $doc_type = 'CC'; 
        if ( stripos($wc_type, 'NIT') !== false ) $doc_type = 'NIT';
        if ( stripos($wc_type, 'CE') !== false )  $doc_type = 'CE';
        if ( stripos($wc_type, 'TI') !== false )  $doc_type = 'TI';
        if ( stripos($wc_type, 'PP') !== false )  $doc_type = 'PP';

        if ( !empty($company) || $doc_type === 'NIT' ) {
            $kindOfPerson = 'LEGAL_ENTITY';
            $regime = 'COMMON_REGIME';
            $display_name = !empty($company) ? $company : $full_name;
        } else {
            $kindOfPerson = 'PERSON_ENTITY';
            $regime = 'SIMPLIFIED_REGIME';
            $display_name = $full_name;
        }

        $client_payload = [
            'name' => $display_name,
            'identificationObject' => [
                'type'   => $doc_type,
                'number' => $billing_id
            ],
            'email' => $email,
            'kindOfPerson' => $kindOfPerson,
            'regime' => $regime
        ];

        if ( $doc_type === 'NIT' ) {
            $client_payload['identificationObject']['dv'] = $this->calcular_dv($billing_id);
        }

        if ( $kindOfPerson === 'PERSON_ENTITY' ) {
            $client_payload['nameObject'] = [
                'firstName' => $first_name ?: 'Cliente',
                'lastName'  => $last_name  ?: 'Final'
            ];
        }

        $created = $this->api_client->call_api('contacts', $client_payload, 'POST');

        if ( isset($created->id) ) {
            return $created->id;
        }

        $err_msg = isset($created->message) ? $created->message : json_encode($created);
        throw new Exception($err_msg);
    }

    /**
     * Construye el payload de la factura (build_invoice_payload) con toda la lógica de ítems, fees y descuentos.
     */
    public function build_invoice_payload( WC_Order $order, $client_id ) {
        // [CÓDIGO DE build_invoice_payload (versión final) va aquí]

        $items = [];
        $alegra_product_id = 1; 

        // 1. Procesar items de producto
        foreach ( $order->get_items() as $line ) {
            $qty = (float) $line->get_quantity();
            $total = (float) $line->get_total();
            $unit = $qty ? round($total / $qty, 2) : 0;

            $items[] = [
                'id'          => $alegra_product_id, 
                'name'        => substr( $line->get_name(), 0, 190 ),
                'price'       => $unit,
                'quantity'    => $qty,
                'discount'    => 0,
                'description' => substr( $line->get_name(), 0, 400 ),
                'tax'         => [], 
            ];
        }

        // 2. Procesar cargos/tarifas (Fees: Positivos y Negativos)
        foreach ( $order->get_fees() as $fee ) {
            $fee_total = (float) $fee->get_total();
            $fee_name = substr( $fee->get_name(), 0, 150 );
            
            if ( $fee_total > 0 ) {
                // Tarifa Positiva (Cargo)
                $items[] = [
                    'id'          => $alegra_product_id,
                    'name'        => 'Cargo: ' . $fee_name,
                    'price'       => round( $fee_total, 2 ),
                    'quantity'    => 1,
                    'discount'    => 0,
                    'tax'         => [],
                ];
            } elseif ( $fee_total < 0 ) {
                // Tarifa Negativa (Descuento/Cupón)
                $discount_amount = abs($fee_total);
                
                $items[] = [
                    'id'          => $alegra_product_id, 
                    'name'        => 'Descuento Aplicado: ' . $fee_name,
                    'price'       => round( $discount_amount, 2 ),
                    'quantity'    => 1,
                    'discount'    => round( $discount_amount, 2 ), 
                    'description' => 'Cupón/Descuento aplicado al pedido.',
                    'tax'         => [],
                ];
            }
        }


        // 3. Procesar envío
        $shipping = (float) $order->get_shipping_total();
        if ( $shipping > 0 ) {
            $items[] = [
                'id'          => $alegra_product_id,
                'name'        => 'Envío',
                'price'       => $shipping,
                'quantity'    => 1,
                'discount'    => 0,
                'tax'         => [],
            ];
        }

        // Estructura de la factura
        return [
            'client'        => [ 'id' => $client_id ],
            'items'         => $items,
            'status'        => 'draft',
            'date'          => date('Y-m-d'),
            'dueDate'       => date('Y-m-d'),
            'paymentMethod' => 'CASH',
            'paymentForm'   => 'CASH',
            'type'          => 'NATIONAL',
            'operationType' => 'STANDARD',
            'notes'         => 'Pedido WooCommerce #' . $order->get_id()
        ];
    }
    
    /**
     * Función utilitaria para calcular el Dígito de Verificación (DV) para NITs.
     */
    private function calcular_dv($nit) {
        $weights = [71,67,59,53,47,43,41,37,29,23,19,17,13,7,3];
        $nit = preg_replace('/\D/', '', $nit);
        $len = strlen($nit);
        $sum = 0;
        for ($i = 0; $i < $len; $i++) {
            $sum += $nit[$i] * $weights[(15 - $len) + $i];
        }
        $dv = $sum % 11;
        return ($dv > 1) ? 11 - $dv : $dv;
    }
}