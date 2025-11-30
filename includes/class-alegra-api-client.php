<?php
/**
 * Clase que maneja la comunicación con la API REST de Alegra.
 */
class Alegra_API_Client {

    private $api_base = 'https://api.alegra.com/api/v1/';
    private $auth_header;

    public function __construct( $email, $token ) {
        if ( empty($email) || empty($token) ) {
            throw new Exception('Credenciales de Alegra faltantes.');
        }
        $this->auth_header = 'Basic ' . base64_encode("$email:$token");
    }

    /**
     * Llama a la API de Alegra.
     */
    public function call_api( $endpoint, $body, $method ) {
        $url = rtrim($this->api_base, '/') . '/' . ltrim($endpoint, '/');
        $args = [
            'method' => $method,
            'headers' => [
                'Authorization' => $this->auth_header,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json'
            ],
            'timeout' => 45
        ];

        if ( !empty($body) ) {
            $args['body'] = json_encode($body);
        }

        $response = wp_remote_request($url, $args);

        if ( is_wp_error($response) ) {
            // Error de conexión a nivel de WP
            throw new Exception("Error de conexión WP: " . $response->get_error_message());
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body);

        if ( wp_remote_retrieve_response_code($response) >= 400 ) {
            // Error devuelto por Alegra (ej: 401 Unauthorized, 400 Bad Request)
            $err_msg = isset($data->message) ? $data->message : "Error desconocido: " . $body;
            throw new Exception("Error Alegra API: " . $err_msg);
        }
        
        return $data;
    }
}