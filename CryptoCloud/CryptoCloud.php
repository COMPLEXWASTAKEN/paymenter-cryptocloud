<?php

namespace Paymenter\Extensions\Gateways\CryptoCloud;

use App\Classes\Extension\Gateway;
use Illuminate\Http\Request;
use App\Helpers\ExtensionHelper;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Log;
use Exception;

class CryptoCloud extends Gateway
{
    private $api_key;
    private $shop_id;
    private $base_url = 'https://api.cryptocloud.plus/v2/';

    public function boot()
    {
        require __DIR__ . '/routes.php';
        View::addNamespace('gateways.cryptocloud', __DIR__ . '/views');
    }

    public function getConfig($values = [])
    {
        return [
            [
                'name' => 'cryptocloud_api_key',
                'label' => 'API Key',
                'type' => 'text',
                'description' => 'Your CryptoCloud API Key',
                'required' => true,
            ],
            [
                'name' => 'cryptocloud_shop_id',
                'label' => 'Shop ID',
                'type' => 'text',
                'description' => 'Your CryptoCloud Shop ID',
                'required' => true,
            ],
        ];
    }

    public function pay($invoice, $total)
    {
        try {
            $this->api_key = $this->config('cryptocloud_api_key');
            $this->shop_id = $this->config('cryptocloud_shop_id');

            if (empty($this->api_key) || empty($this->shop_id)) {
                throw new Exception('API Key or Shop ID is missing.');
            }

            $paymentData = [
                'shop_id' => $this->shop_id,
                'amount' => number_format($total, 2, '.', ''),
                'currency' => $invoice->currency_code ?? 'USD',
                'order_id' => (string) $invoice->id,
                'callback_url' => url('/extensions/cryptocloud/webhook'),
                'locale' => 'en',
                'add_fields' => [
                    'time_to_pay' => ['hours' => 24, 'minutes' => 0]
                ],
            ];

            Log::info('Creating payment request:', $paymentData);

            $response = $this->makeRequest('invoice/create', $paymentData);

            if (!$response || !isset($response->result->link)) {
                Log::error('CryptoCloud Payment Error: No pay_url received.', (array) $response);
                throw new Exception('CryptoCloud did not return a payment URL.');
            }

            return view('gateways.cryptocloud::pay', ['url' => $response->result->link]);
        } catch (Exception $e) {
            Log::error('Payment Processing Error: ' . $e->getMessage());
            return response('Payment creation failed: ' . $e->getMessage(), 500);
        }
    }

    public function webhook(Request $request)
    {
        try {
            $data = $request->all();
            Log::info('Received CryptoCloud webhook:', $data);

            if (empty($data['order_id']) || empty($data['status'])) {
                throw new Exception('Missing required webhook parameters.');
            }

            if ($data['status'] === 'success') {
                ExtensionHelper::addPayment(
                    $data['order_id'],
                    'CryptoCloud',
                    $data['amount_crypto'],
                    0,
                    $data['invoice_id']
                );                
                Log::info("Payment successful for Order ID: {$data['order_id']}");
            } else {
                Log::warning("Payment not completed for Order ID: {$data['order_id']}, Status: {$data['status']}");
            }

            return response('*ok*');
        } catch (Exception $e) {
            Log::error('Webhook Error: ' . $e->getMessage());
            return response('Webhook processing failed: ' . $e->getMessage(), 400);
        }
    }

    private function makeRequest($endpoint, $params, $method = 'POST')
    {
        try {
            $url = $this->base_url . $endpoint;
            $ch = curl_init($url);

            $headers = [
                'Content-Type: application/json',
                'Authorization: Token ' . $this->config('cryptocloud_api_key'),
            ];

            $options = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_TIMEOUT => 30,
            ];

            if ($method === 'POST') {
                $options[CURLOPT_POST] = true;
                $options[CURLOPT_POSTFIELDS] = json_encode($params);
            }

            curl_setopt_array($ch, $options);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($curlError) {
                Log::error('CURL Error: ' . $curlError);
                throw new Exception('CURL Error: ' . $curlError);
            }

            Log::info("CryptoCloud API Response (HTTP {$httpCode}): " . $response);

            $decodedResponse = json_decode($response);

            if ($httpCode !== 200 || !$decodedResponse) {
                throw new Exception('API Request Failed: ' . $response);
            }

            return $decodedResponse;
        } catch (Exception $e) {
            Log::error('API Request Error: ' . $e->getMessage());
            return false;
        }
    }
}
