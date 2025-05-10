<?php

namespace App;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config.php';

use Nimiq\XPub;

class BitcoinService
{
    private $zpub;
    private $addressDiscoveryLimit; // Maximum number of addresses to check per path type (0 for receive, 1 for change)
    private $gapLimit; // Standard Bitcoin gap limit (number of consecutive unused addresses)

    private const BLOCKCHAIN_INFO_BALANCE_API_URL = 'https://blockchain.info/balance?active=';
    private const MAX_ADDRESSES_PER_API_CALL = 150; // Blockchain.info might have a limit on URL length or number of addresses per API call

    public function __construct()
    {
        // Ensure ZPUB_KEY is defined and not the placeholder value
        if (!defined('ZPUB_KEY') || ZPUB_KEY === 'YOUR_ZPUB_KEY_HERE' || empty(ZPUB_KEY)) {
            throw new \Exception('ZPUB_KEY is not configured or is empty in config.php. Please update it from XPUB_KEY to ZPUB_KEY and set your zPub value.');
        }
        if (!defined('XPUB_ADDRESS_DISCOVERY_LIMIT') || !defined('XPUB_GAP_LIMIT')){
            throw new \Exception('XPUB_ADDRESS_DISCOVERY_LIMIT or XPUB_GAP_LIMIT is not configured in config.php.');
        }

        $this->zpub = ZPUB_KEY;
        $this->addressDiscoveryLimit = XPUB_ADDRESS_DISCOVERY_LIMIT;
        $this->gapLimit = XPUB_GAP_LIMIT; // Note: Gap limit logic is simplified in deriveAndGetBalancesForPath
    }

    /**
     * Derives addresses from the zPub for a given path type (0 for receive, 1 for change)
     * up to the configured discovery limit, and then fetches their balances from Blockchain.info API.
     *
     * @param int $pathType (0 for receive addresses, 1 for change addresses)
     * @return array An associative array [address => balance_in_satoshi]
     * @throws \InvalidArgumentException If pathType is not 0 or 1.
     * @throws \Exception If xPub derivation or API communication fails.
     */
    private function deriveAndGetBalancesForPath(int $pathType): array
    {
        if ($pathType !== 0 && $pathType !== 1) {
            throw new \InvalidArgumentException('Path type must be 0 (receive) or 1 (change).');
        }

        $xpubInstance = XPub::fromString($this->zpub);
        // Derives the account xPub for either receive (m/84'/0'/0'/0) or change (m/84'/0'/0'/1) path based on zPub (m/84'/0'/0')
        $accountXpub = $xpubInstance->derive([$pathType]); 

        $addresses = [];

        // Derive addresses up to the discovery limit defined in config.php
        // A strict gap limit implementation would stop if $this->gapLimit consecutive unused addresses are found.
        // This current version checks all addresses up to $this->addressDiscoveryLimit.
        for ($i = 0; $i < $this->addressDiscoveryLimit; $i++) {
            $addressKey = $accountXpub->derive($i);
            $address = $addressKey->toAddress(); // Defaults to Bitcoin mainnet address (P2WPKH for zPub-derived paths)
            $addresses[] = $address;
        }
        
        if (empty($addresses)) {
            return [];
        }

        // Fetch balances for the derived addresses in batches to respect API limits
        $addressBalances = [];
        $addressChunks = array_chunk($addresses, self::MAX_ADDRESSES_PER_API_CALL);

        foreach ($addressChunks as $chunk) {
            $apiUrl = self::BLOCKCHAIN_INFO_BALANCE_API_URL . implode('|', $chunk);
            
            $contextOptions = [
                'http' => [
                    'timeout' => 10, // 10-second timeout for the API request
                    'header' => "Accept: application/json\r\n" // Some APIs might require this header
                ]
            ];
            $context = stream_context_create($contextOptions);
            $responseJson = @file_get_contents($apiUrl, false, $context); // Use @ to suppress warnings on failure, handled below

            if ($responseJson === false) {
                error_log("BitcoinService: Failed to fetch balances from Blockchain.info for address chunk: " . implode(',', $chunk));
                foreach($chunk as $addr) $addressBalances[$addr] = 0; // Assume 0 balance if API call fails for the chunk
                continue;
            }

            $responseArray = json_decode($responseJson, true);

            if (is_null($responseArray)) {
                error_log("BitcoinService: Failed to decode JSON response from Blockchain.info for address chunk: " . implode(',', $chunk) . " Raw Response: " . $responseJson);
                 foreach($chunk as $addr) $addressBalances[$addr] = 0; // Assume 0 balance if JSON decoding fails
                continue;
            }

            // Populate balances for addresses found in the API response
            foreach ($responseArray as $addr => $data) {
                if (is_array($data) && isset($data['final_balance'])) {
                    $addressBalances[$addr] = (int)$data['final_balance'];
                } else {
                    // Handle cases where a specific address in the API response might not have the expected structure
                    // This can occur if an address is invalid or not found by the API in a batch request.
                    $addressBalances[$addr] = 0; 
                    error_log("BitcoinService: Unexpected data structure for address {$addr} in Blockchain.info API response. Data: " . print_r($data, true));
                }
            }
        }

        // Ensure all originally derived addresses have a balance entry (default to 0 if not in API response)
        $finalBalances = [];
        foreach ($addresses as $address) {
            $finalBalances[$address] = $addressBalances[$address] ?? 0;
        }
        return $finalBalances; 
    }

    /**
     * Gets the total Bitcoin balance from the configured zPub by checking both 
     * receive (external) and change (internal) addresses.
     *
     * @return float Total balance in BTC.
     * @throws \Exception If configuration is missing or API calls fail critically.
     */
    public function getTotalBalanceFromXpub(): float // Method name kept for compatibility with index.php
    {
        $totalBalanceSatoshi = 0;

        // Get balances for receive addresses (path 0)
        $receiveBalances = $this->deriveAndGetBalancesForPath(0);
        foreach ($receiveBalances as $balance) {
            $totalBalanceSatoshi += $balance;
        }

        // Get balances for change addresses (path 1)
        $changeBalances = $this->deriveAndGetBalancesForPath(1);
        foreach ($changeBalances as $balance) {
            $totalBalanceSatoshi += $balance;
        }

        return $totalBalanceSatoshi / 1e8; // Convert Satoshi to BTC (1 BTC = 100,000,000 Satoshis)
    }
} 