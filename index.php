<?php

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/src/BitcoinService.php';
require_once __DIR__ . '/src/ExchangeRateService.php';
require_once __DIR__ . '/src/MyfxbookService.php';

use App\BitcoinService;
use App\ExchangeRateService;
use App\MyfxbookService;

$btcBalance = 0.0;
$btcValueInUsd = 0.0;
$usdtRateVsUsd = 1.0; // Assuming 1 USDT = 1 USD if not fetched or if TARGET_CURRENCY is already USDT
$btcValueInUsdt = 0.0;
$error = null;
$myfxbookBalance = null;
$myfxbookError = null;
$newMyfxbookSessionKey = null;

// Rebalancing Variables
$totalPortfolioValue = 0;
$currentBtcRatio = 0;
$currentMyfxbookRatio = 0;
$targetBtcValue = 0;
$targetMyfxbookValue = 0;
$adjustBtcValue = 0;
$adjustMyfxbookValue = 0;
$rebalanceMessage = null;

// --- Read Ratio from User Input (or use default values) ---
$defaultRatioBtc = 2;
$defaultRatioMyfxbook = 1;

$userRatioBtc = isset($_GET['ratio_btc']) && is_numeric($_GET['ratio_btc']) && $_GET['ratio_btc'] > 0 ? (float)$_GET['ratio_btc'] : $defaultRatioBtc;
$userRatioMyfxbook = isset($_GET['ratio_myfxbook']) && is_numeric($_GET['ratio_myfxbook']) && $_GET['ratio_myfxbook'] > 0 ? (float)$_GET['ratio_myfxbook'] : $defaultRatioMyfxbook;

// Ensure at least one part of the ratio is greater than 0 to prevent division by zero in totalParts calculation
if ($userRatioBtc <= 0 && $userRatioMyfxbook <= 0) {
    $userRatioBtc = $defaultRatioBtc; // Fallback to default if both are invalid or zero
    $userRatioMyfxbook = $defaultRatioMyfxbook;
}

$ratioTotalParts = $userRatioBtc + $userRatioMyfxbook;

// --- ntfy.sh Notification Function ---
function sendNtfyNotification(string $topic, string $message, string $title = "Portfolio Alert") {
    $ntfyUrl = "https://ntfy.sh/" . $topic;
    $ch = curl_init($ntfyUrl);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $message);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Title: ' . $title,
        'Priority: high', // Or 'default', 'min', 'max'
        'Tags: warning'  // Example tags, see ntfy.sh documentation for more options
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10); // Timeout in seconds
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        error_log("ntfy.sh notification cURL Error for topic '{$topic}': " . $curlError);
        return false;
    }
    if ($httpCode >= 200 && $httpCode < 300) {
        error_log("ntfy.sh notification sent successfully to topic '{$topic}'. Response code: " . $httpCode);
        return true;
    } else {
        error_log("ntfy.sh notification failed for topic '{$topic}'. HTTP code: " . $httpCode . ". Response: " . $response);
        return false;
    }
}

// Check if ZPUB_KEY is configured before proceeding
if (!defined('ZPUB_KEY') || ZPUB_KEY === 'YOUR_ZPUB_KEY_HERE' || empty(ZPUB_KEY)) {
    $error = "Please configure your ZPUB_KEY in config.php.";
} else {
    try {
        $bitcoinService = new BitcoinService();
        $btcBalance = $bitcoinService->getTotalBalanceFromXpub(); // Method name retained for now

        $exchangeRateService = new ExchangeRateService();
        // Fetch BTC to TARGET_CURRENCY (e.g., USD) exchange rate
        $btcToTargetRate = $exchangeRateService->getBtcToTargetCurrencyRate(); 

        // Fetch USDT to TARGET_CURRENCY (e.g., USD) exchange rate
        $usdtToTargetRate = $exchangeRateService->getUsdtToTargetCurrencyRate();

        if ($usdtToTargetRate == 0) { // Avoid division by zero error
            throw new \Exception("USDT to Target Currency exchange rate is zero, cannot calculate USDT value.");
        }
        
        // Calculate BTC value in USDT: (BTC Balance * BTC_Price_in_TargetCurrency) / USDT_Price_in_TargetCurrency
        $btcValueInTargetCurrency = $btcBalance * $btcToTargetRate;
        $btcValueInUsdt = $btcValueInTargetCurrency / $usdtToTargetRate;

    } catch (\Exception $e) {
        $error = "Bitcoin/Exchange Rate Error: " . $e->getMessage();
        error_log($error . " Stack Trace: " . $e->getTraceAsString());
    }
}

// Fetch Myfxbook Balance
if (!defined('MYFXBOOK_EMAIL') || constant('MYFXBOOK_EMAIL') === 'YOUR_MYFXBOOK_EMAIL' || empty(constant('MYFXBOOK_EMAIL')) ||
    !defined('MYFXBOOK_PASSWORD') || constant('MYFXBOOK_PASSWORD') === 'YOUR_MYFXBOOK_PASSWORD' || empty(constant('MYFXBOOK_PASSWORD'))) {
    $myfxbookError = "Please configure your MYFXBOOK_EMAIL and MYFXBOOK_PASSWORD in config.php.";
} elseif (!defined('MYFXBOOK_TARGET_ACCOUNT_NAME') || empty(constant('MYFXBOOK_TARGET_ACCOUNT_NAME'))) {
    $myfxbookError = "Please configure MYFXBOOK_TARGET_ACCOUNT_NAME in config.php.";
} else {
    try {
        $myfxbookService = new MyfxbookService();
        // Attempt to fetch balance. This might trigger a login if the session is invalid or not set.
        $myfxbookBalance = $myfxbookService->fetchBalanceForAccount(constant('MYFXBOOK_TARGET_ACCOUNT_NAME'));
        
        // Check if a new session key was obtained and is different from the one stored in config.php
        $newSessionFromService = $myfxbookService->getSessionKey();
        if ($newSessionFromService && (!defined('MYFXBOOK_SESSION_KEY') || (defined('MYFXBOOK_SESSION_KEY') && constant('MYFXBOOK_SESSION_KEY') !== $newSessionFromService))) {
            $newMyfxbookSessionKey = $newSessionFromService;
            // The service itself logs this event, but we can add a notice for the user on the page too.
            // We will not attempt to write to config.php automatically for security reasons.
        }

    } catch (\Exception $e) {
        $myfxbookError = "Myfxbook Error: " . $e->getMessage();
        error_log($myfxbookError . " Stack Trace: " . $e->getTraceAsString());
    }
}

// --- Rebalance Calculations ---
if (!$error && !$myfxbookError && $btcValueInUsdt !== null && $myfxbookBalance !== null) {
    if ($btcValueInUsdt >= 0 && $myfxbookBalance > 0) { // Ensure Myfxbook balance is positive to avoid division by zero when calculating ratio
        $totalPortfolioValue = $btcValueInUsdt + $myfxbookBalance;

        // --- ntfy.sh Notification Check ---
        // Check if NTFY_TOPIC is defined and not empty, and NTFY_THRESHOLD is defined
        if (defined('NTFY_TOPIC') && !empty(NTFY_TOPIC) && defined('NTFY_THRESHOLD')) {
            $actualBtcToMyfxbookRatio = $btcValueInUsdt / $myfxbookBalance; // $myfxbookBalance > 0 is already checked
            $ntfyThreshold = (float) NTFY_THRESHOLD;

            if ($actualBtcToMyfxbookRatio < $ntfyThreshold) {
                $notificationTitle = "Portfolio Ratio Alert!";
                $notificationMessage = sprintf(
                    "BTC:Myfxbook ratio is %.2f:1 (%.2f USDT : %.2f USDT), which is below the threshold of %.1f:1.",
                    $actualBtcToMyfxbookRatio,
                    $btcValueInUsdt,
                    $myfxbookBalance,
                    $ntfyThreshold
                );
                // To prevent spamming, you might want to implement a check here to see if a notification was sent recently.
                // For now, it sends a notification every time the condition is met on page load.
                sendNtfyNotification(NTFY_TOPIC, $notificationMessage, $notificationTitle);
            }
        }
        // --- End ntfy.sh Notification Check ---

        if ($totalPortfolioValue > 0) {
            // Current Ratios (as fractions of 1.0)
            $currentBtcFraction = $btcValueInUsdt / $totalPortfolioValue;
            $currentMyfxbookFraction = $myfxbookBalance / $totalPortfolioValue;

            // Current Ratio Display (e.g., BTC:MyFxBook)
            $displayRatioBtcPart = $myfxbookBalance > 0 ? number_format($btcValueInUsdt / $myfxbookBalance, 2) : 'N/A';
            $displayRatioMyfxbookPart = 1; // Normalized against Myfxbook as 1 part
            $currentRatioDisplay = "{$displayRatioBtcPart} : {$displayRatioMyfxbookPart}";

            // Target Ratios based on user input or default values
            if ($ratioTotalParts > 0) {
                $targetBtcFraction = $userRatioBtc / $ratioTotalParts;
                $targetMyfxbookFraction = $userRatioMyfxbook / $ratioTotalParts;
            } else { // This case should not be reached due to earlier checks, but included as a fallback
                $targetBtcFraction = $defaultRatioBtc / ($defaultRatioBtc + $defaultRatioMyfxbook);
                $targetMyfxbookFraction = $defaultRatioMyfxbook / ($defaultRatioBtc + $defaultRatioMyfxbook);
            }

            // Target Values in USDT
            $targetBtcValue = $totalPortfolioValue * $targetBtcFraction;
            $targetMyfxbookValue = $totalPortfolioValue * $targetMyfxbookFraction;

            // Adjustment Amounts in USDT
            $adjustBtcValue = $targetBtcValue - $btcValueInUsdt;
            $adjustMyfxbookValue = $targetMyfxbookValue - $myfxbookBalance;
            
            $rebalanceMessage = "Rebalancing calculations complete.";
        } else {
            $rebalanceMessage = "Total portfolio value is zero. Cannot calculate rebalancing ratios.";
        }
    } elseif ($btcValueInUsdt >= 0 && $myfxbookBalance == 0) {
        $totalPortfolioValue = $btcValueInUsdt;
        $currentBtcFraction = 1.0;
        $currentMyfxbookFraction = 0.0;
        $currentRatioDisplay = "BTC Only (Myfxbook balance is 0)";
        
        $targetBtcFraction = $userRatioBtc / $ratioTotalParts;
        $targetMyfxbookFraction = $userRatioMyfxbook / $ratioTotalParts;
        $targetBtcValue = $totalPortfolioValue * $targetBtcFraction;
        $targetMyfxbookValue = $totalPortfolioValue * $targetMyfxbookFraction;
        $adjustBtcValue = $targetBtcValue - $btcValueInUsdt;
        $adjustMyfxbookValue = $targetMyfxbookValue; // Need to add this full amount to Myfxbook account
        $rebalanceMessage = "Myfxbook balance is zero. Rebalancing requires adding funds to Myfxbook.";

    } else {
        $rebalanceMessage = "Cannot perform rebalance calculations due to invalid input values (e.g., negative Myfxbook balance or BTC value not available).";
    }
} elseif ($error || $myfxbookError) {
    $rebalanceMessage = "Cannot perform rebalance calculations due to errors in fetching data.";
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Portfolio Dashboard & Rebalancer</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background-color: #f4f4f4; color: #333; }
        .container { background-color: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 0 10px rgba(0,0,0,0.1); max-width: 800px; margin: auto; }
        h1, h2 { color: #0056b3; }
        .error { color: red; border: 1px solid red; padding: 10px; margin-bottom: 15px; border-radius: 4px; }
        .balance-info { margin-top: 15px; padding: 10px; background-color: #e7f3ff; border-left: 5px solid #0056b3; border-radius: 4px; }
        .balance-info p, .balance-info ul li { margin: 8px 0; }
        .balance-info strong { color: #004085; }
        .config-notice { color: #856404; background-color: #fff3cd; border: 1px solid #ffeeba; padding: 10px; margin-bottom: 15px; border-radius: 4px;}
        .form-group { margin-bottom: 10px; }
        .form-group label { display: inline-block; width: 100px; }
        .form-group input[type="number"] { width: 80px; padding: 5px; border-radius: 4px; border: 1px solid #ccc; }
        .form-group input[type="submit"] { padding: 8px 15px; background-color: #0056b3; color: white; border: none; border-radius: 4px; cursor: pointer; }
        .form-group input[type="submit"]:hover { background-color: #004085; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Portfolio Dashboard & Rebalancer</h1>

        <?php if ($error && (!defined('ZPUB_KEY') || ZPUB_KEY === 'YOUR_ZPUB_KEY_HERE' || empty(ZPUB_KEY))): ?>
            <div class="config-notice">
                <p><?php echo htmlspecialchars($error); ?></p>
                <p>Please make sure to update <code>ZPUB_KEY</code> in your <code>config.php</code> file with your actual zPub key.</p>
            </div>
        <?php elseif ($error): ?>
            <div class="error">
                <p><?php echo htmlspecialchars($error); ?></p>
            </div>
        <?php else: ?>
            <div class="balance-info">
                <p><strong>Total Bitcoin (BTC) Balance:</strong> <?php echo htmlspecialchars(number_format($btcBalance, 8)); ?> BTC</p>
                <p><strong>Current BTC/<?php echo strtoupper(htmlspecialchars(TARGET_CURRENCY)); ?> Rate (from CoinGecko):</strong> 1 BTC = <?php echo htmlspecialchars(isset($btcToTargetRate) ? number_format($btcToTargetRate, 2) : 'N/A'); ?> <?php echo strtoupper(htmlspecialchars(TARGET_CURRENCY)); ?></p>
                <p><strong>Current USDT/<?php echo strtoupper(htmlspecialchars(TARGET_CURRENCY)); ?> Rate (from CoinGecko):</strong> 1 USDT = <?php echo htmlspecialchars(isset($usdtToTargetRate) ? number_format($usdtToTargetRate, 4) : 'N/A'); ?> <?php echo strtoupper(htmlspecialchars(TARGET_CURRENCY)); ?></p>
                <p><strong>Estimated Value in USDT:</strong> <?php echo htmlspecialchars(number_format($btcValueInUsdt, 2)); ?> USDT</p>
            </div>
        <?php endif; ?>

        <?php if ($newMyfxbookSessionKey): ?>
            <div class="config-notice" style="border-color: #155724; background-color: #d4edda; color: #155724;">
                <p><strong>Myfxbook Update:</strong> A new session key was obtained: <code><?php echo htmlspecialchars($newMyfxbookSessionKey); ?></code></p>
                <p>Please update <code>MYFXBOOK_SESSION_KEY</code> in your <code>config.php</code> file with this new key to avoid logging in repeatedly.</p>
            </div>
        <?php endif; ?>

        <?php if ($myfxbookError && (!defined('MYFXBOOK_EMAIL') || (defined('MYFXBOOK_EMAIL') && constant('MYFXBOOK_EMAIL') === 'YOUR_MYFXBOOK_EMAIL') || (defined('MYFXBOOK_EMAIL') && empty(constant('MYFXBOOK_EMAIL'))))): ?>
             <div class="config-notice">
                <p><?php echo htmlspecialchars($myfxbookError); ?></p>
             </div>
        <?php elseif ($myfxbookError): ?>
            <div class="error">
                 <p><?php echo htmlspecialchars($myfxbookError); ?></p>
            </div>
        <?php elseif ($myfxbookBalance !== null): ?>
            <div class="balance-info" style="border-left-color: #007bff;">
                <p><strong>Myfxbook Account (<?php echo htmlspecialchars(defined('MYFXBOOK_TARGET_ACCOUNT_NAME') ? constant('MYFXBOOK_TARGET_ACCOUNT_NAME') : 'N/A'); ?>) Balance:</strong> <?php echo htmlspecialchars(number_format($myfxbookBalance, 2)); ?> USDT <?php /* Assuming Myfxbook balance is denominated in USDT */ ?></p>
            </div>
        <?php endif; ?>

        <?php if ($rebalanceMessage): ?>
            <hr style="margin-top: 20px; margin-bottom: 20px;">
            <h2>Portfolio Rebalancing (Target BTC : Myfxbook = <?php echo htmlspecialchars($userRatioBtc); ?> : <?php echo htmlspecialchars($userRatioMyfxbook); ?>)</h2>
            
            <form method="GET" action="index.php" style="margin-bottom: 20px;">
                <div class="form-group">
                    <label for="ratio_btc">BTC Ratio:</label>
                    <input type="number" id="ratio_btc" name="ratio_btc" value="<?php echo htmlspecialchars($userRatioBtc); ?>" step="0.1" min="0">
                </div>
                <div class="form-group">
                    <label for="ratio_myfxbook">Myfxbook Ratio:</label>
                    <input type="number" id="ratio_myfxbook" name="ratio_myfxbook" value="<?php echo htmlspecialchars($userRatioMyfxbook); ?>" step="0.1" min="0">
                </div>
                <div class="form-group">
                    <input type="submit" value="Update Ratio & Rebalance">
                </div>
            </form>

            <?php if ($totalPortfolioValue > 0 && !$error && !$myfxbookError && $myfxbookBalance !== null && $btcValueInUsdt !== null): ?>
                <div class="balance-info" style="border-left-color: #28a745;">
                    <p><strong>Total Portfolio Value:</strong> <?php echo htmlspecialchars(number_format($totalPortfolioValue, 2)); ?> USDT</p>
                    <p><strong>Current BTC Value:</strong> <?php echo htmlspecialchars(number_format($btcValueInUsdt, 2)); ?> USDT (<?php echo htmlspecialchars(number_format($currentBtcFraction * 100, 2)); ?>%)</p>
                    <p><strong>Current Myfxbook Value:</strong> <?php echo htmlspecialchars(number_format($myfxbookBalance, 2)); ?> USDT (<?php echo htmlspecialchars(number_format($currentMyfxbookFraction * 100, 2)); ?>%)</p>
                    <p><strong>Current Ratio (BTC : Myfxbook):</strong> <?php echo $currentRatioDisplay; ?></p>
                    <hr>
                    <p><strong>Target BTC Value (<?php echo htmlspecialchars($userRatioBtc); ?>/<?php echo htmlspecialchars($ratioTotalParts); ?>):</strong> <?php echo htmlspecialchars(number_format($targetBtcValue, 2)); ?> USDT</p>
                    <p><strong>Target Myfxbook Value (<?php echo htmlspecialchars($userRatioMyfxbook); ?>/<?php echo htmlspecialchars($ratioTotalParts); ?>):</strong> <?php echo htmlspecialchars(number_format($targetMyfxbookValue, 2)); ?> USDT</p>
                    <hr>
                    <p><strong>To Rebalance:</strong></p>
                    <ul style="list-style-type: none; padding-left: 0;">
                        <li>
                            <strong>BTC Adjustment:</strong> 
                            <?php if ($adjustBtcValue > 0): ?>
                                <span style="color: green;">Add <?php echo htmlspecialchars(number_format($adjustBtcValue, 2)); ?> USDT to BTC</span>
                            <?php elseif ($adjustBtcValue < 0): ?>
                                <span style="color: red;">Remove <?php echo htmlspecialchars(number_format(abs($adjustBtcValue), 2)); ?> USDT from BTC</span>
                            <?php else: ?>
                                <span>No change</span>
                            <?php endif; ?>
                        </li>
                        <li>
                            <strong>Myfxbook Adjustment:</strong> 
                            <?php if ($adjustMyfxbookValue > 0): ?>
                                <span style="color: green;">Add <?php echo htmlspecialchars(number_format($adjustMyfxbookValue, 2)); ?> USDT to Myfxbook</span>
                            <?php elseif ($adjustMyfxbookValue < 0): ?>
                                <span style="color: red;">Remove <?php echo htmlspecialchars(number_format(abs($adjustMyfxbookValue), 2)); ?> USDT from Myfxbook</span>
                            <?php else: ?>
                                <span>No change</span>
                            <?php endif; ?>
                        </li>
                    </ul>
                </div>
            <?php else: ?>
                <div class="config-notice"><p><?php echo htmlspecialchars($rebalanceMessage); ?></p></div>
            <?php endif; ?>
        <?php endif; ?>

        <hr style="margin-top: 20px; margin-bottom: 20px;">
        <p><small><strong>Note:</strong> Address discovery from zPub uses a discovery limit of <?php echo defined('XPUB_ADDRESS_DISCOVERY_LIMIT') ? XPUB_ADDRESS_DISCOVERY_LIMIT : 'N/A'; ?> addresses per chain (receive/change). The gap limit of <?php echo defined('XPUB_GAP_LIMIT') ? XPUB_GAP_LIMIT : 'N/A'; ?> is noted, but its strict enforcement is simplified in the current balance check version. Ensure these values in <code>config.php</code> are appropriate for your wallet.</small></p>
        <p><small>Balances are fetched from Blockchain.info. Exchange rates are fetched from CoinGecko.</small></p>
    </div>
</body>
</html>
