<?php

namespace App;

class MyfxbookService {
    private const API_BASE_URL = 'https://www.myfxbook.com/api/';

    private $email;
    private $password;
    private $sessionKey; // Stores the active Myfxbook session key

    public function __construct(?string $email = null, ?string $password = null, ?string $sessionKey = null) {
        $this->email = $email ?? (defined('MYFXBOOK_EMAIL') ? constant('MYFXBOOK_EMAIL') : null);
        $this->password = $password ?? (defined('MYFXBOOK_PASSWORD') ? constant('MYFXBOOK_PASSWORD') : null);
        $this->sessionKey = $sessionKey ?? (defined('MYFXBOOK_SESSION_KEY') ? constant('MYFXBOOK_SESSION_KEY') : null);
    }

    /**
     * Makes a generic API request to Myfxbook.
     *
     * @param string $method The API method to call (e.g., 'login', 'get-my-accounts').
     * @param array $params Associative array of parameters for the API call.
     * @param bool $isLogin Indicates if this is a login request (to include email/password).
     * @return array The decoded JSON response from the API.
     * @throws \Exception If cURL error, HTTP error, JSON decoding error, or Myfxbook API error occurs.
     */
    private function makeApiRequest(string $method, array $params = [], bool $isLogin = false): array {
        $url = self::API_BASE_URL . $method . '.json';
        
        if ($isLogin) {
            if (!$this->email || !$this->password) {
                throw new \Exception("Myfxbook email or password not configured for login attempt.");
            }
            $params['email'] = $this->email;
            $params['password'] = $this->password;
        } elseif (isset($params['session']) && empty($params['session']) && $this->sessionKey) {
            // If session parameter is expected but empty, and we have an instance session key, use it.
            $params['session'] = $this->sessionKey;
        }

        // For non-login requests, a session key must be present, either from instance or passed in params.
        if (!$isLogin && empty($params['session'])) {
            throw new \Exception("Myfxbook session key is required for the '{$method}' API request.");
        }

        $queryString = http_build_query($params);
        $fullUrl = $url . '?' . $queryString;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $fullUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true); // Enable SSL peer verification in production
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);    // Enable SSL host verification in production

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            throw new \Exception("cURL Error calling Myfxbook API method '{$method}': " . $curlError);
        }

        if ($httpCode !== 200) {
            throw new \Exception("Myfxbook API request for '{$method}' failed with HTTP code " . $httpCode . ". Response: " . $response);
        }

        $decodedResponse = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception("Failed to decode JSON response from Myfxbook for '{$method}': " . json_last_error_msg() . ". Response: " . $response);
        }

        if (isset($decodedResponse['error']) && $decodedResponse['error'] === true) {
            $errorMessage = $decodedResponse['message'] ?? 'Unknown Myfxbook API error';
            if ($method === 'login' && $errorMessage === 'Invalid session.'){
                 // Myfxbook API returns "Invalid session." for failed login attempts (e.g., wrong credentials).
                 $errorMessage = "Login failed. Please check your Myfxbook email and password in config.php.";
            } elseif ($errorMessage === 'Invalid session.') {
                 // If an existing session key becomes invalid, log it. The user should update config.php.
                 error_log("Myfxbook session key '{$this->sessionKey}' was invalid. It should be cleared from config.php or updated with a new one.");
                 // Clearing the instance session key to force re-login on next attempt if applicable.
                 $this->sessionKey = null;
            }
            throw new \Exception("Myfxbook API Error (method: {$method}): " . $errorMessage);
        }
        return $decodedResponse;
    }

    /**
     * Attempts to log in to Myfxbook using the configured email and password.
     * If successful, stores the new session key in the instance and returns it.
     *
     * @return string|null The new session key if login is successful, null otherwise.
     * @throws \Exception If login fails or API error occurs.
     */
    public function login(): ?string {
        try {
            $response = $this->makeApiRequest('login', [], true);
            if (isset($response['session']) && !empty($response['session'])) {
                $this->sessionKey = $response['session'];
                // IMPORTANT: This service class does NOT directly write to config.php for security reasons.
                // The calling code (e.g., index.php) is responsible for notifying the user to persist this new session key.
                error_log("Myfxbook Login Successful. New Session Key obtained: " . $this->sessionKey . ". This should be updated in config.php.");
                return $this->sessionKey;
            }
        } catch (\Exception $e) {
            error_log("Myfxbook Login Failed: " . $e->getMessage());
            throw $e; // Re-throw the exception to be handled by the caller
        }
        return null; // Should not be reached if an exception isn't thrown on failure
    }

    /**
     * Retrieves the list of Myfxbook accounts associated with the given session key.
     *
     * @param string $sessionKeyToUse The Myfxbook session key.
     * @return array The list of accounts from the API response.
     * @throws \Exception If the session key is empty or an API error occurs.
     */
    public function getAccounts(string $sessionKeyToUse): array {
        if (empty($sessionKeyToUse)) {
            throw new \Exception("A valid Myfxbook session key is required to get accounts.");
        }
        return $this->makeApiRequest('get-my-accounts', ['session' => $sessionKeyToUse]);
    }
    
    /**
     * Returns the current session key stored in the service instance.
     *
     * @return string|null The current session key, or null if not set.
     */
    public function getSessionKey(): ?string {
        return $this->sessionKey;
    }

    /**
     * Fetches the balance for a specific Myfxbook account by its name.
     * Handles login if the session key is missing, invalid, or if login is forced.
     *
     * @param string $targetAccountName The name of the Myfxbook account.
     * @param bool $forceLogin If true, forces a new login attempt even if a session key exists.
     * @return float|null The account balance if found, null otherwise.
     * @throws \Exception If configuration is missing, login fails, account not found, or other API errors occur.
     */
    public function fetchBalanceForAccount(string $targetAccountName, bool $forceLogin = false): ?float {
        if (empty($this->email) || empty($this->password)) {
             error_log("Myfxbook email or password not configured in MyfxbookService instance.");
             throw new \Exception("Myfxbook email and password must be configured in config.php to fetch balance.");
        }

        $currentSession = $this->sessionKey;

        // Attempt login if forcing, or if session is missing/placeholder.
        if ($forceLogin || empty($currentSession) || $currentSession === 'YOUR_MYFXBOOK_SESSION_KEY_HERE' || $currentSession === '') {
            error_log("Attempting Myfxbook login: Force login is " . ($forceLogin ? 'true' : 'false') . " or session key is missing/invalid.");
            try {
                $currentSession = $this->login(); // This updates $this->sessionKey internally upon success
                if (!$currentSession) {
                    // This case should ideally be covered by an exception from login() method.
                    throw new \Exception("Failed to obtain a new Myfxbook session after login attempt.");
                }
                // The new session key is now in $this->sessionKey and $currentSession.
                // The user will be notified in index.php to update config.php.
                // Logging for server-side record:
                error_log("New Myfxbook session obtained via fetchBalanceForAccount: " . $currentSession . ". User should update config.php.");

            } catch (\Exception $e) {
                 error_log("Myfxbook login attempt during fetchBalanceForAccount failed: " . $e->getMessage());
                 throw $e; // Propagate the exception (e.g., to index.php for display)
            }
        }
        
        if (empty($currentSession)) {
            // This check is a safeguard, as login() should throw an exception if it fails to get a session.
            throw new \Exception("Myfxbook session key is not available after login attempt(s).");
        }

        try {
            $apiResponse = $this->getAccounts($currentSession);
            $accounts = $apiResponse['accounts'] ?? [];

            foreach ($accounts as $account) {
                if (isset($account['name']) && $account['name'] === $targetAccountName) {
                    if (isset($account['balance'])) {
                        return (float)$account['balance'];
                    } else {
                        throw new \Exception("'balance' field not found for account '{$targetAccountName}'. Account data: " . json_encode($account));
                    }
                }
            }
            // Prepare a list of available account names for a more helpful error message.
            $availableAccountNames = array_map(fn($acc) => $acc['name'] ?? '[Unknown Name]', $accounts);
            throw new \Exception("Account named '{$targetAccountName}' not found in your Myfxbook accounts. Available accounts: " . (!empty($availableAccountNames) ? implode(', ', $availableAccountNames) : 'No accounts found or names unavailable.'));

        } catch (\Exception $e) {
            // If the API call with the current session failed due to "Invalid session", 
            // and we haven't already forced a login in this specific call, try re-logging in once.
            if (strpos($e->getMessage(), 'Invalid session') !== false && !$forceLogin) {
                error_log("Myfxbook session '{$currentSession}' was invalid when fetching accounts. Attempting to re-login. Original error: " . $e->getMessage());
                $this->sessionKey = null; // Clear the invalid session from the instance before retrying
                return $this->fetchBalanceForAccount($targetAccountName, true); // Force login on this retry
            }
            error_log("Error fetching Myfxbook account balance for '{$targetAccountName}': " . $e->getMessage());
            throw $e; // Re-throw other types of exceptions
        }
    }
}

?> 