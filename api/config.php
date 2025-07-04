<?php
// api/config.php - Centralized configuration for API endpoints
// This file should be included by all API endpoints.

// Database Configurations
// Define all your database connections here. Each array key will be used as an identifier.
// IMPORTANT: In a real-world scenario, consider moving sensitive credentials
// outside of the web root or using environment variables for better security.
$db_configs = [
    'spin_trading' => [
        'label' => 'Spinblaze', // Label for display on frontend
        'host' => 'localhost',
        'db' => 'u888982375_Spin_Trading', // Replace with your actual DB name
        'user' => 'u888982375_Spin_Trading', // Replace with your actual DB user
        'pass' => '6:fPYIAEm', // Replace with your actual DB password
        'charset' => 'utf8mb4',
        'bonus_logic_type' => 'fixed_bonus' // Custom identifier for bonus logic
    ],
    'spinblaze2' => [
        'label' => 'Version 2.0', // Label for display on frontend
        'host' => 'localhost',
        'db' => 'u888982375_spinblaze2', // Replace with your actual DB name
        'user' => 'u888982375_spinblaze2', // Replace with your actual DB user
        'pass' => 'F4~r]Hd@]GA', // Replace with your actual DB password
        'charset' => 'utf8mb4',
        'bonus_logic_type' => '20x_multiplier' // Custom identifier for bonus logic
    ]
];

// Initialize PDO connections for all configured databases
$pdo_connections = [];
foreach ($db_configs as $key => $config) {
    $dsn = "mysql:host={$config['host']};dbname={$config['db']};charset={$config['charset']}";
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    try {
        $pdo_connections[$key] = new PDO($dsn, $config['user'], $config['pass'], $options);
    } catch (\PDOException $e) {
        error_log("API Database Connection Failed for DB '{$config['db']}': " . $e->getMessage());
        // If a database connection fails, remove its configuration so it's not attempted later
        unset($db_configs[$key]);
    }
}

// --- API Key Authentication ---
// Define a strong, secret API key.
// IMPORTANT: This should ideally be stored in an environment variable or a secure
// configuration management system, NOT directly in the code for production.
// For this example, we're placing it here for demonstration.
define('API_SECRET_KEY', 'YOUR_SUPER_SECRET_API_KEY_HERE_GENERATE_A_LONG_RANDOM_ONE'); // !!! CHANGE THIS TO A STRONG, UNIQUE KEY !!!

/**
 * Authenticates the incoming API request by checking the 'X-API-Key' header.
 * If the key is missing or invalid, it sends an unauthorized response and exits.
 */
function authenticate_api_request() {
    $headers = getallheaders();
    $apiKey = $headers['X-API-Key'] ?? '';

    if ($apiKey !== API_SECRET_KEY) {
        http_response_code(401); // Unauthorized
        echo json_encode(['success' => false, 'error' => 'Unauthorized: Invalid API Key']);
        exit();
    }
}

/**
 * Calculates the bonus amount based on the deposit amount and bonus logic type.
 * This logic is copied directly from your admin_dashboard.php for consistency.
 *
 * @param float $amount The original deposit amount.
 * @param string $bonus_logic_type The type of bonus logic ('fixed_bonus' or '20x_multiplier').
 * @return float The calculated bonus amount.
 */
function calculate_bonus($amount, $bonus_logic_type) {
    $bonus_amount = 0;
    if ($bonus_logic_type === 'fixed_bonus') {
        if ($amount == 500) {
            $bonus_amount = 5000;
        } elseif ($amount == 250) {
            $bonus_amount = 2500;
        }
    } elseif ($bonus_logic_type === '20x_multiplier') {
        $bonus_amount = $amount * 19; // 19x bonus (1x original + 19x bonus = 20x total)
    }
    return $bonus_amount;
}

/**
 * Sends a JSON response and terminates the script execution.
 *
 * @param bool $success Indicates if the operation was successful.
 * @param array $data Optional data to include in the response.
 * @param string|null $error Optional error message.
 */
function send_json_response($success, $data = [], $error = null) {
    header('Content-Type: application/json');
    echo json_encode(['success' => $success, 'data' => $data, 'error' => $error]);
    exit();
}

?>
