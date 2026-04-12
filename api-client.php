<?php

declare(strict_types=1);

/**
 * Cliente HTTP para consumir la API remota Node.js
 */
class RemoteApiClient
{
    private string $baseUrl;
    private string $token;
    private int $timeout;

    public function __construct(string $baseUrl, string $token, int $timeout = 15)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->token = $token;
        $this->timeout = $timeout;
    }

    /**
     * Hace una petición GET a la API remota
     */
    public function get(string $endpoint, array $query = []): array
    {
        $url = $this->buildUrl($endpoint, $query);
        return $this->request('GET', $url);
    }

    /**
     * Hace una petición POST a la API remota
     */
    public function post(string $endpoint, array $body = []): array
    {
        $url = $this->buildUrl($endpoint);
        return $this->request('POST', $url, $body);
    }

    /**
     * Construye la URL completa con query params
     */
    private function buildUrl(string $endpoint, array $query = []): string
    {
        $url = $this->baseUrl . '/api' . (str_starts_with($endpoint, '/') ? '' : '/') . $endpoint;
        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }
        return $url;
    }

    /**
     * Ejecuta la petición HTTP
     */
    private function request(string $method, string $url, array $body = []): array
    {
        try {
            $options = [
                'http' => [
                    'method' => $method,
                    'timeout' => $this->timeout,
                    'ignore_errors' => true,
                    'header' => [
                        'Content-Type: application/json',
                        'Authorization: Bearer ' . $this->token,
                        'X-API-KEY: ' . $this->token,
                    ],
                ],
            ];

            if (!empty($body) && $method === 'POST') {
                $options['http']['content'] = json_encode($body, JSON_UNESCAPED_UNICODE);
            }

            $context = stream_context_create($options);
            $response = @file_get_contents($url, false, $context);

            if ($response === false) {
                return [
                    'ok' => false,
                    'error' => 'No se pudo conectar a la API remota',
                    'status' => 0,
                ];
            }

            $result = json_decode($response, true);
            if (!is_array($result)) {
                return [
                    'ok' => false,
                    'error' => 'Respuesta inválida de la API remota',
                    'status' => 0,
                ];
            }

            return $result;
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'error' => $e->getMessage(),
                'status' => 0,
            ];
        }
    }

    /**
     * Obtiene los horarios disponibles para una fecha
     */
    public function obtenerHorarios(string $fecha): array
    {
        return $this->get('horarios', ['fecha' => $fecha]);
    }

    /**
     * Obtiene datos de un vehículo por matrícula
     */
    public function obtenerVehiculo(string $matricula): array
    {
        return $this->get('vehiculos/lookup', ['matricula' => $matricula]);
    }

    /**
     * Crea una nueva reserva
     */
    public function crearReserva(array $data): array
    {
        return $this->post('reservas', $data);
    }
}
