<?php
// api/get_pending_deposits.php
require_once __DIR__ . '/config.php'; // Include the shared configuration and functions

authenticate_api_request(); // Authenticate the incoming request

// Ensure the request method is GET
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    send_json_response(false, [], 'Invalid request method. Only GET is allowed.');
}

$all_pending_deposits = [];

// Iterate through all configured database connections
foreach ($pdo_connections as $db_key => $pdo) {
    try {
        // Fetch only pending deposits, ordered by ID ascending (or descending, depending on processing preference)
        // Joining with 'users' table to get user name and email
        $stmt = $pdo->prepare("SELECT dr.id, dr.user_id, dr.amount, dr.utr, dr.created_at, u.name as user_name, u.email as user_email FROM add_money_requests dr JOIN users u ON dr.user_id = u.id WHERE dr.status = 'pending' ORDER BY dr.id ASC");
        $stmt->execute();
        $deposits_from_db = $stmt->fetchAll();

        // Add database origin and bonus logic type to each fetched deposit
        foreach ($deposits_from_db as &$deposit) {
            $deposit['db_origin'] = $db_key;
            // Safely get bonus_logic_type and label from $db_configs
            $deposit['bonus_logic_type'] = $db_configs[$db_key]['bonus_logic_type'] ?? 'unknown';
            $deposit['db_label'] = $db_configs[$db_key]['label'] ?? 'Unknown DB';
        }
        // Merge deposits from the current database into the overall list
        $all_pending_deposits = array_merge($all_pending_deposits, $deposits_from_db);

    } catch (\PDOException $e) {
        // Log the error but continue fetching from other databases if one fails
        error_log("API Error fetching pending deposits from '{$db_key}': " . $e->getMessage());
    }
}

// Sort all deposits by ID DESC to process newest first (optional, but good practice for combined list)
// This ensures that the most recent requests across all databases are at the top.
usort($all_pending_deposits, function($a, $b) {
    return $b['id'] <=> $a['id'];
});

// Send the combined list of pending deposits as a JSON response
send_json_response(true, ['deposits' => $all_pending_deposits]);

?>
