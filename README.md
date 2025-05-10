# Portfolio Dashboard & Rebalancer

A PHP script to display your Bitcoin (from zPub) and Myfxbook account balances, calculate their total value in USDT, and suggest rebalancing actions based on a target ratio.

## Features

*   Fetches Bitcoin balance from a zPub key using the Blockchain.info API.
*   Fetches Myfxbook account balance using the Myfxbook API.
*   Fetches BTC/TARGET_CURRENCY and USDT/TARGET_CURRENCY exchange rates from the CoinGecko API.
*   Calculates the total portfolio value in USDT.
*   Displays current portfolio allocation.
*   Allows users to set a target ratio for BTC vs. Myfxbook holdings.
*   Calculates and suggests adjustments needed to reach the target ratio.
*   Sends ntfy.sh notifications if the BTC:Myfxbook ratio falls below a defined threshold.

## Requirements

*   **PHP:** Version 8.0 or later is recommended.
*   **Composer:** For managing PHP dependencies.
*   **PHP Extensions:**
    *   `curl`: Required for making HTTP requests to external APIs (CoinGecko, Blockchain.info, Myfxbook).
    *   `gmp`: (GNU Multiple Precision Arithmetic) **Required by the `nimiq/xpub` library** for cryptographic operations involved in deriving Bitcoin addresses from your zPub key. Ensure this extension is installed and enabled in your PHP environment.
    *   `json`: For handling JSON data from APIs. (Usually enabled by default)
    *   `mbstring`: (May be required by underlying dependencies for string manipulation).
*   **Web Server:** A web server like Apache or Nginx, configured to serve PHP files.

## Setup

1.  **Clone the repository:**
    ```bash
    git clone https://github.com/Moomak/xpub-myfxbook-rebalance.git
    cd xpub-myfxbook-rebalance
    ```

2.  **Install dependencies using Composer:**
    ```bash
    composer install
    ```
    This will download and install necessary PHP libraries (e.g., `nimiq/xpub`) defined in `composer.json`.

3.  **Configure the application:**
    *   Copy the example configuration file:
        ```bash
        cp config.php.example config.php
        ```
    *   Open `config.php` in a text editor and fill in your actual details:
        *   `ZPUB_KEY`: Your Bitcoin zPub key (Native SegWit - BIP84).
        *   `XPUB_ADDRESS_DISCOVERY_LIMIT`: (Advanced) Number of receive/change addresses to scan. Default: `100`.
        *   `XPUB_GAP_LIMIT`: (Advanced) Standard Bitcoin gap limit. Default: `20`. (Note: Current `BitcoinService` checks up to `XPUB_ADDRESS_DISCOVERY_LIMIT`.)
        *   `COINGECKO_API_KEY`: (Optional) Your CoinGecko API key.
        *   `TARGET_CURRENCY`: Target currency for intermediate conversions (e.g., 'usd'). Default: `'usd'`.
        *   `MYFXBOOK_EMAIL`: Your Myfxbook login email.
        *   `MYFXBOOK_PASSWORD`: Your Myfxbook login password.
        *   `MYFXBOOK_SESSION_KEY`: Leave blank initially. Update with the session key displayed on the webpage/logged after the first successful run to avoid repeated logins.
        *   `MYFXBOOK_TARGET_ACCOUNT_NAME`: The exact name of your Myfxbook account.

4.  **Set up ntfy.sh notifications (Optional):**
    *   Open `config.php`.
    *   Locate the `// --- ntfy.sh Notification Configuration ---` section.
    *   Set `NTFY_TOPIC` to your actual ntfy.sh topic. You can leave it blank (e.g., `define('NTFY_TOPIC', '');`) to disable notifications.
    *   Adjust `NTFY_THRESHOLD` to your desired notification threshold (e.g., `1.5` means notify if the BTC value is less than 1.5 times the Myfxbook value).

5.  **Web Server Configuration:**
    *   Ensure your web server can serve PHP files from the project directory.
    *   Access `index.php` via your browser (e.g., `http://localhost/xpub-myfxbook-rebalance/index.php`).

## Usage

*   Open `index.php` in your web browser.
*   The dashboard displays BTC balance, Myfxbook balance, exchange rates, total portfolio value in USDT, and current allocation.
*   Use the input fields to set your target BTC to Myfxbook ratio and click "Update Ratio & Rebalance" for suggestions.

## How Balances & Rates Are Fetched

*   **Bitcoin (zPub):** `BitcoinService.php` uses `nimiq/xpub` to derive addresses and fetches balances via the Blockchain.info API.
*   **Myfxbook:** `MyfxbookService.php` uses the Myfxbook JSON API, handling login and session management to retrieve account balances.
*   **Exchange Rates:** `ExchangeRateService.php` fetches rates from the CoinGecko API.

## Security: `.gitignore`

The `.gitignore` file prevents `config.php` (with your sensitive keys) and the `vendor/` directory from being committed to Git.

**Always keep `config.php` secure and never commit it to a public repository.**

## Disclaimer

*   This script is for informational and personal use. Use at your own risk.
*   You are responsible for the security of your API keys, zPub, and Myfxbook credentials.
*   Data accuracy depends on external APIs (Blockchain.info, CoinGecko, Myfxbook).
*   The script performs read-only operations on your accounts. 