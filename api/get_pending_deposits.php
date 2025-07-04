<?php
// api/get_pending_deposits.php
require_once __DIR__ . '/config.php'; // Include the shared configuration and functions

authenticate_api_request(); // Authenticate the incoming request

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    send_json_response(false, [], 'Invalid request method. Only GET is allowed.');
}

$all_pending_deposits = [];

foreach ($pdo_connections as $db_key => $pdo) {
    try {
        // Fetch only pending deposits
        $stmt = $pdo->prepare("SELECT dr.id, dr.user_id, dr.amount, dr.utr, dr.created_at, u.name as user_name, u.email as user_email FROM add_money_requests dr JOIN users u ON dr.user_id = u.id WHERE dr.status = 'pending' ORDER BY dr.id ASC");
        $stmt->execute();
        $deposits_from_db = $stmt->fetchAll();

        foreach ($deposits_from_db as &$deposit) {
            $deposit['db_origin'] = $db_key;
            // Add bonus logic type and label for the client to understand
            $deposit['bonus_logic_type'] = $db_configs[$db_key]['bonus_logic_type'];
            $deposit['db_label'] = $db_configs[$db_key]['label'];
        }
        $all_pending_deposits = array_merge($all_pending_deposits, $deposits_from_db);

    } catch (\PDOException $e) {
        error_log("API Error fetching pending deposits from '{$db_key}': " . $e->getMessage());
        // Continue to other databases even if one fails
    }
}

// Sort all deposits by ID DESC to process newest first (optional, but good practice)
usort($all_pending_deposits, function($a, $b) {
    return $b['id'] <=> $a['id'];
});

send_json_response(true, ['deposits' => $all_pending_deposits]);

?>
```php
<?php
// api/approve_deposit.php
require_once __DIR__ . '/config.php'; // Include the shared configuration and functions

authenticate_api_request(); // Authenticate the incoming request

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json_response(false, [], 'Invalid request method. Only POST is allowed.');
}

// Get raw POST data
$input = file_get_contents('php://input');
$data = json_decode($input, true);

$id = $data['id'] ?? null;
$db_origin = $data['db_origin'] ?? null;

if (!filter_var($id, FILTER_VALIDATE_INT) || !isset($pdo_connections[$db_origin])) {
    send_json_response(false, [], 'Invalid deposit ID or database origin provided.');
}

$pdo = $pdo_connections[$db_origin];
$bonus_logic_type = $db_configs[$db_origin]['bonus_logic_type'];

try {
    $pdo->beginTransaction();

    // Lock the row for update to prevent race conditions
    $stmt = $pdo->prepare("SELECT * FROM add_money_requests WHERE id = ? AND status = 'pending' FOR UPDATE");
    $stmt->execute([$id]);
    $request = $stmt->fetch();

    if ($request) {
        $deposit_amount = (float)$request['amount'];
        $bonus_amount = calculate_bonus($deposit_amount, $bonus_logic_type);
        $total_credit_amount = $deposit_amount + $bonus_amount;

        // Update deposit status
        $updateDepositStmt = $pdo->prepare("UPDATE add_money_requests SET status = 'approved', processed_at = NOW() WHERE id = ?");
        $updateDepositStmt->execute([$id]);

        // Update user balance
        $updateUserStmt = $pdo->prepare("UPDATE users SET main_balance = main_balance + ? WHERE id = ?");
        $updateUserStmt->execute([$total_credit_amount, $request['user_id']]);
        
        $pdo->commit();
        send_json_response(true, ['message' => "Deposit ID $id from $db_origin approved successfully.", 'credited_amount' => $total_credit_amount]);
    } else {
        $pdo->rollBack();
        send_json_response(false, [], "Deposit ID $id from $db_origin not found or already processed.");
    }
} catch (\PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("API Error approving deposit ID $id from '$db_origin': " . $e->getMessage());
    send_json_response(false, [], 'Database error during approval: ' . $e->getMessage());
} catch (Exception $e) {
    error_log("API General Error approving deposit ID $id from '$db_origin': " . $e->getMessage());
    send_json_response(false, [], 'An unexpected error occurred: ' . $e->getMessage());
}

?>
