<?php
// api/approve_deposit.php
require_once __DIR__ . '/config.php'; // Include the shared configuration and functions

authenticate_api_request(); // Authenticate the incoming request

// Ensure the request method is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json_response(false, [], 'Invalid request method. Only POST is allowed.');
}

// Get raw POST data from the request body (expected to be JSON)
$input = file_get_contents('php://input');
$data = json_decode($input, true); // Decode JSON into an associative array

// Extract 'id' and 'db_origin' from the decoded data
$id = $data['id'] ?? null;
$db_origin = $data['db_origin'] ?? null;

// Validate inputs: 'id' must be an integer and 'db_origin' must exist in our configurations
if (!filter_var($id, FILTER_VALIDATE_INT) || !isset($pdo_connections[$db_origin])) {
    send_json_response(false, [], 'Invalid deposit ID or database origin provided.');
}

// Get the specific PDO connection and bonus logic type for the given database origin
$pdo = $pdo_connections[$db_origin];
$bonus_logic_type = $db_configs[$db_origin]['bonus_logic_type'];

try {
    // Start a database transaction to ensure atomicity (all or nothing)
    $pdo->beginTransaction();

    // Select the deposit request and lock the row for update to prevent race conditions
    // This ensures no other process can modify this row until the transaction is complete.
    $stmt = $pdo->prepare("SELECT * FROM add_money_requests WHERE id = ? AND status = 'pending' FOR UPDATE");
    $stmt->execute([$id]);
    $request = $stmt->fetch();

    if ($request) {
        $deposit_amount = (float)$request['amount'];
        // Calculate bonus using the shared function from config.php
        $bonus_amount = calculate_bonus($deposit_amount, $bonus_logic_type);
        $total_credit_amount = $deposit_amount + $bonus_amount;

        // Update the deposit request status to 'approved'
        $updateDepositStmt = $pdo->prepare("UPDATE add_money_requests SET status = 'approved', processed_at = NOW() WHERE id = ?");
        $updateDepositStmt->execute([$id]);

        // Update the user's main balance by adding the total credited amount
        $updateUserStmt = $pdo->prepare("UPDATE users SET main_balance = main_balance + ? WHERE id = ?");
        $updateUserStmt->execute([$total_credit_amount, $request['user_id']]);
        
        // Commit the transaction if all operations were successful
        $pdo->commit();
        send_json_response(true, ['message' => "Deposit ID $id from $db_origin approved successfully.", 'credited_amount' => $total_credit_amount]);
    } else {
        // If the request was not found or was not pending, rollback the transaction
        $pdo->rollBack();
        send_json_response(false, [], "Deposit ID $id from $db_origin not found or already processed.");
    }
} catch (\PDOException $e) {
    // Catch PDO exceptions (database errors)
    if ($pdo->inTransaction()) {
        $pdo->rollBack(); // Rollback the transaction on error
    }
    error_log("API Error approving deposit ID $id from '$db_origin': " . $e->getMessage());
    send_json_response(false, [], 'Database error during approval: ' . $e->getMessage());
} catch (Exception $e) {
    // Catch any other general exceptions
    error_log("API General Error approving deposit ID $id from '$db_origin': " . $e->getMessage());
    send_json_response(false, [], 'An unexpected error occurred: ' . $e->getMessage());
}

?>
