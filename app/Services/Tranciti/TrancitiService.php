<?php

namespace App\Services\Tranciti;

use App\Models\OrdersFlow\Order;
use Illuminate\Support\Facades\Http;
use App\Models\OrdersFlow\System\Sale;
use App\Models\OrdersFlow\System\Invoice;
use App\Models\OrdersFlow\System\InvoiceDetail;

use App\Models\System\Customer;
use Carbon\Carbon;

use SimpleXMLElement;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Barryvdh\DomPDF\Facade\Pdf;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

class TrancitiService
{
    protected $authUrl = 'https://auth.waypoint.cl/simplelogin/login';
    protected $baseUrl = 'https://api.waypoint.cl/lastmile/api/';
    protected $username; // Tu nombre de usuario
    protected $password; // Tu contraseña
    protected $client;

    public function __construct()
    {
        $this->username = config('services.lioren.username');
        $this->password = config('services.lioren.password');
        $this->client = new Client([
            'base_uri' => $this->baseUrl,
            'timeout'  => 10.0,
        ]);
    }/**
     * Autenticación para obtener el AccessToken.
     */
    public function authenticate()
    {
        try {
            $client = new \GuzzleHttp\Client();

            // Encabezados requeridos
            $headers = [
                'Content-Type' => 'application/json',
            ];

            // Cuerpo de la solicitud como string JSON exacto
            $body = '{
                "username": "' . $this->username . '",
                "password": "' . $this->password . '"
            }';

            // Crear y enviar la solicitud
            $request = new \GuzzleHttp\Psr7\Request('POST', $this->authUrl, $headers, $body);
            $response = $client->send($request);

            // Decodificar la respuesta
            $data = json_decode($response->getBody(), true);

            if (isset($data['AccessToken'])) {
                return $data['AccessToken'];
            }

            throw new \Exception('No se pudo obtener el AccessToken');
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            $responseBody = $e->getResponse() ? $e->getResponse()->getBody()->getContents() : $e->getMessage();
            throw new \Exception('Error en la autenticación: ' . $responseBody);
        }
    }



    /**
     * Realiza una solicitud GET al endpoint especificado.
     */
    public function get(string $endpoint, array $query = [])
    {
        $token = $this->authenticate();

        try {
            $response = $this->client->get($endpoint, [
                'headers' => [
                    'Authorization' => "Bearer $token",
                    'Accept' => 'application/json',
                ],
                'query' => $query,
            ]);

            return json_decode($response->getBody(), true);
        } catch (RequestException $e) {
            $responseBody = $e->getResponse() ? $e->getResponse()->getBody()->getContents() : $e->getMessage();
            throw new \Exception('Error en la solicitud GET: ' . $responseBody);
        }
    }

    /**
     * Realiza una solicitud POST al endpoint especificado.
     */
    public function post(string $endpoint, array $data = [])
    {
        $token = $this->authenticate();

        try {
            $response = $this->client->post($endpoint, [
                'headers' => [
                    'Authorization' => "Bearer $token",
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ],
                'json' => $data,
            ]);

            return json_decode($response->getBody(), true);
        } catch (RequestException $e) {
            $responseBody = $e->getResponse() ? $e->getResponse()->getBody()->getContents() : $e->getMessage();
            throw new \Exception('Error en la solicitud POST: ' . $responseBody);
        }
    }
}
