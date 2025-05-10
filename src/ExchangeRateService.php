<?php

namespace App;

require_once __DIR__ . '/../config.php';

class ExchangeRateService
{
    private const COINGECKO_API_BASE_URL = 'https://api.coingecko.com/api/v3';

    public function __construct()
    {
        // Constructor can be used for API key initialization or other setup if needed in the future.
    }

    /**
     * Fetches the current exchange rate for Bitcoin (BTC) against the target currency (e.g., USD)
     * from the CoinGecko API.
     *
     * @return float The exchange rate (e.g., price of 1 BTC in the target currency).
     * @throws \Exception If the API call fails, data is not found, or JSON decoding fails.
     */
    public function getBtcToTargetCurrencyRate(): float
    {
        $apiKey = defined('COINGECKO_API_KEY') && !empty(COINGECKO_API_KEY) ? '&x_cg_demo_api_key=' . COINGECKO_API_KEY : '';
        $targetCurrency = defined('TARGET_CURRENCY') ? TARGET_CURRENCY : 'usd'; // Default to 'usd' if not set
        
        $url = self::COINGECKO_API_BASE_URL . '/simple/price?ids=bitcoin&vs_currencies=' . strtolower($targetCurrency) . $apiKey;

        try {
            $curl = curl_init();
            curl_setopt_array($curl, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10, // 10-second timeout for the cURL request
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json'
                ],
            ]);

            $response = curl_exec($curl);
            $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            $curlError = curl_error($curl);
            curl_close($curl);

            if ($curlError) {
                throw new \Exception("cURL Error fetching BTC exchange rate: " . $curlError);
            }

            if ($httpCode !== 200) {
                throw new \Exception("CoinGecko API Error (BTC): HTTP Code " . $httpCode . " - Response: " . $response);
            }

            $data = json_decode($response, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception("Error decoding CoinGecko JSON response (BTC): " . json_last_error_msg());
            }

            if (!isset($data['bitcoin'][strtolower($targetCurrency)])) {
                throw new \Exception("Exchange rate not found in CoinGecko response for bitcoin to " . $targetCurrency);
            }

            return (float) $data['bitcoin'][strtolower($targetCurrency)];

        } catch (\Exception $e) {
            error_log("Error in ExchangeRateService (getBtcToTargetCurrencyRate): " . $e->getMessage());
            // Rethrow the exception to be handled by the calling code (e.g., in index.php)
            throw $e; 
        }
    }

    /**
     * Fetches the current exchange rate for Tether (USDT) against the target currency (e.g., USD)
     * from the CoinGecko API.
     * This is useful if the primary portfolio value is to be displayed in USDT, and conversions
     * from other assets (like BTC) are first made to a common currency (like USD).
     *
     * @return float The exchange rate (e.g., price of 1 USDT in the target currency).
     * @throws \Exception If the API call fails, data is not found, or JSON decoding fails.
     */
    public function getUsdtToTargetCurrencyRate(): float
    {
        $apiKey = defined('COINGECKO_API_KEY') && !empty(COINGECKO_API_KEY) ? '&x_cg_demo_api_key=' . COINGECKO_API_KEY : '';
        $targetCurrency = defined('TARGET_CURRENCY') ? TARGET_CURRENCY : 'usd'; // Default to 'usd' if not set
        
        // Using CoinGecko ID for Tether: 'tether'
        $url = self::COINGECKO_API_BASE_URL . '/simple/price?ids=tether&vs_currencies=' . strtolower($targetCurrency) . $apiKey;

        try {
            $curl = curl_init();
            curl_setopt_array($curl, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10, // 10-second timeout
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json'
                ],
            ]);

            $response = curl_exec($curl);
            $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            $curlError = curl_error($curl);
            curl_close($curl);

            if ($curlError) {
                throw new \Exception("cURL Error fetching USDT exchange rate: " . $curlError);
            }

            if ($httpCode !== 200) {
                throw new \Exception("CoinGecko API Error (USDT): HTTP Code " . $httpCode . " - Response: " . $response);
            }

            $data = json_decode($response, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception("Error decoding CoinGecko JSON response (USDT): " . json_last_error_msg());
            }

            if (!isset($data['tether'][strtolower($targetCurrency)])) {
                throw new \Exception("Exchange rate not found in CoinGecko response for tether to " . $targetCurrency);
            }

            return (float) $data['tether'][strtolower($targetCurrency)];

        } catch (\Exception $e) {
            error_log("Error in ExchangeRateService (getUsdtToTargetCurrencyRate): " . $e->getMessage());
            throw $e; // Rethrow to be handled by caller
        }
    }
} 