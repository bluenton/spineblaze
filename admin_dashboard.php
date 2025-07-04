<?php
session_start(); // Start session for potential future use (though no longer for DB selection here)

header('Content-Type: text/html; charset=utf-8'); // Ensure proper HTML rendering

// --- IMPORTANT: ADMIN AUTHENTICATION PLACEHOLDER ---
// In a real application, you MUST implement robust authentication here.
// This is a critical security vulnerability if left unprotected.
// Example: Check for a valid admin session, token, or user role.
// if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
//      header('Location: admin_login.php'); // Redirect to admin login page
//      exit();
// }
// For now, this script runs without authentication. DO NOT DEPLOY WITHOUT IT.
// ----------------------------------------------------

// Database Configurations
// Define all your database connections here. Each array key will be used as an identifier.
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
        // Log error and provide a user-friendly message without exiting for other DBs
        error_log("Database Connection Failed for DB '{$config['db']}': " . $e->getMessage());
        // You might want to unset this config or mark it as unavailable
        unset($db_configs[$key]);
        $page_error = ($page_error ?? "") . "Could not connect to database '{$config['label']}'. Some data might be missing. Please check configuration. ";
    }
}

// Sanitize function - for outputting data to HTML safely (prevents XSS)
function sanitize($data) {
    if (is_array($data)) {
        return array_map('sanitize', $data);
    }
    return htmlspecialchars(trim((string)$data), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}


// AJAX Handler for fetching pending deposits (or search results)
if (isset($_GET['fetch_deposits']) && $_GET['fetch_deposits'] === 'true') {
    header('Content-Type: application/json'); // Set content type to JSON for the response

    $utr_query = isset($_GET['utr_query']) ? trim($_GET['utr_query']) : '';
    $status_filter = isset($_GET['status_filter']) ? $_GET['status_filter'] : 'pending';

    $all_deposits = [];
    foreach ($pdo_connections as $db_key => $pdo) {
        try {
            $sql = "SELECT dr.*, u.name as user_name, u.email as user_email FROM add_money_requests dr JOIN users u ON dr.user_id = u.id";
            $params = [];

            $where_clauses = [];
            if (!empty($utr_query)) {
                $where_clauses[] = "dr.utr LIKE ?";
                $params[] = '%' . $utr_query . '%';
            }

            if ($status_filter === 'pending') {
                $where_clauses[] = "dr.status = 'pending'";
            }

            if (!empty($where_clauses)) {
                $sql .= " WHERE " . implode(" AND ", $where_clauses);
            }

            $sql .= " ORDER BY dr.id DESC";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $deposits_from_db = $stmt->fetchAll();

            // Add the database origin and bonus logic type to each fetched deposit
            foreach ($deposits_from_db as &$deposit) {
                $deposit['db_origin'] = $db_key;
                $deposit['bonus_logic_type'] = $db_configs[$db_key]['bonus_logic_type'];
                $deposit['db_label'] = $db_configs[$db_key]['label']; // Add label for JS
            }
            $all_deposits = array_merge($all_deposits, $deposits_from_db);

        } catch (\PDOException $e) {
            error_log("AJAX Error fetching deposits from '{$db_key}': " . $e->getMessage());
            // Continue fetching from other databases even if one fails
        }
    }
    // Sort all deposits by ID DESC again after merging (optional, depends on preference for combined list)
    usort($all_deposits, function($a, $b) {
        return $b['id'] <=> $a['id'];
    });

    echo json_encode(['success' => true, 'deposits' => $all_deposits]);
    exit;
}


// Handle deposit approval
if (isset($_GET['approve_deposit']) && isset($_GET['db_origin'])) {
    $id = sanitize($_GET['approve_deposit']);
    $db_origin = sanitize($_GET['db_origin']);

    if (!filter_var($id, FILTER_VALIDATE_INT) || !isset($pdo_connections[$db_origin])) {
        header("Location: " . basename(__FILE__) . "?status=invalid_deposit_id_or_db");
        exit;
    }
    
    $redirect_url = basename(__FILE__);
    $pdo = $pdo_connections[$db_origin]; // Use the correct PDO connection
    $bonus_logic_type = $db_configs[$db_origin]['bonus_logic_type']; // Get bonus logic for the specific DB

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("SELECT * FROM add_money_requests WHERE id = ? AND status = 'pending' FOR UPDATE");
        $stmt->execute([$id]);
        $request = $stmt->fetch();

        if ($request) {
            $deposit_amount = (float)$request['amount'];
            $bonus_amount = 0;

            // Apply bonus logic based on the originating database
            if ($bonus_logic_type === 'fixed_bonus') {
                if ($deposit_amount == 500) {
                    $bonus_amount = 5000;
                } elseif ($deposit_amount == 250) {
                    $bonus_amount = 2500;
                }
            } elseif ($bonus_logic_type === '20x_multiplier') {
                $bonus_amount = $deposit_amount * 19; // 19x bonus (1x original + 19x bonus = 20x total)
            }

            $total_credit_amount = $deposit_amount + $bonus_amount;

            $updateDepositStmt = $pdo->prepare("UPDATE add_money_requests SET status = 'approved', processed_at = NOW() WHERE id = ?");
            $updateDepositStmt->execute([$id]);

            $updateUserStmt = $pdo->prepare("UPDATE users SET main_balance = main_balance + ? WHERE id = ?");
            $updateUserStmt->execute([$total_credit_amount, $request['user_id']]);
            
            $pdo->commit();
            header("Location: " . $redirect_url . "?status=deposit_approved_successfully&id=" . urlencode($id) . "&db=" . urlencode($db_origin));
        } else {
            $pdo->rollBack();
            header("Location: " . $redirect_url . "?status=deposit_not_found_or_already_processed&id=" . urlencode($id) . "&db=" . urlencode($db_origin));
        }
    } catch (\PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Error approving deposit ID $id from '$db_origin': " . $e->getMessage());
        header("Location: " . $redirect_url . "?status=deposit_approval_failed_error&id=" . urlencode($id) . "&db=" . urlencode($db_origin));
    }
    exit;
}

// Handle deposit deletion
if (isset($_GET['delete_deposit']) && isset($_GET['db_origin'])) {
    $id = sanitize($_GET['delete_deposit']);
    $db_origin = sanitize($_GET['db_origin']);

    if (!filter_var($id, FILTER_VALIDATE_INT) || !isset($pdo_connections[$db_origin])) {
        header("Location: " . basename(__FILE__) . "?status=invalid_deposit_id_for_deletion_or_db");
        exit;
    }

    $redirect_url = basename(__FILE__);
    $pdo = $pdo_connections[$db_origin]; // Use the correct PDO connection

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("SELECT id, status FROM add_money_requests WHERE id = ? FOR UPDATE");
        $stmt->execute([$id]);
        $request = $stmt->fetch();

        if ($request && $request['status'] === 'pending') {
            $deleteStmt = $pdo->prepare("DELETE FROM add_money_requests WHERE id = ?");
            $deleteStmt->execute([$id]);
            $pdo->commit();
            header("Location: " . $redirect_url . "?status=deposit_request_deleted_successfully&id=" . urlencode($id) . "&db=" . urlencode($db_origin));
        } else {
            $pdo->rollBack();
            $status_message = "deposit_not_found_or_not_pending_for_deletion";
            if ($request && $request['status'] !== 'pending') {
                $status_message = "deposit_request_cannot_be_deleted_as_it_is_not_pending";
            }
            header("Location: " . $redirect_url . "?status=" . $status_message . "&id=" . urlencode($id) . "&db=" . urlencode($db_origin));
        }
    } catch (\PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Error deleting deposit ID $id from '$db_origin': " . $e->getMessage());
        header("Location: " . $redirect_url . "?status=deposit_deletion_failed_error&id=" . urlencode($id) . "&db=" . urlencode($db_origin));
    }
    exit;
}

// Fetch data for initial page load from ALL active databases
$initial_all_deposits = [];
$combined_pending_count = 0; // To count total pending deposits across all DBs
$page_error = null;

foreach ($pdo_connections as $db_key => $pdo) {
    try {
        $deposits_from_db = $pdo->query("SELECT dr.*, u.name as user_name, u.email as user_email FROM add_money_requests dr JOIN users u ON dr.user_id = u.id WHERE dr.status = 'pending' ORDER BY dr.id DESC")->fetchAll();
        foreach ($deposits_from_db as &$deposit) {
            $deposit['db_origin'] = $db_key;
            $deposit['bonus_logic_type'] = $db_configs[$db_key]['bonus_logic_type'];
            $deposit['db_label'] = $db_configs[$db_key]['label'];
            $combined_pending_count++;
        }
        $initial_all_deposits = array_merge($initial_all_deposits, $deposits_from_db);
    } catch (\PDOException $e) {
        // Collect errors for display later but don't stop execution
        error_log("Error fetching initial dashboard data from '{$db_key}': " . $e->getMessage());
        $page_error = ($page_error ? $page_error . " <br> " : "") . "Could not load data from '{$db_configs[$db_key]['label']}' database. Please try refreshing or contact support.";
    }
}

// Sort the combined deposits by ID DESC for consistent display of latest requests at top
usort($initial_all_deposits, function($a, $b) {
    return $b['id'] <=> $a['id'];
});


// Prepare bonus logic info for JavaScript
$js_bonus_logic_info = [];
foreach ($db_configs as $key => $config) {
    $js_bonus_logic_info[$key] = [
        'bonus_logic_type' => $config['bonus_logic_type'],
        'label' => $config['label']
    ];
}
$js_bonus_logic_info_json = json_encode($js_bonus_logic_info);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel </title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        /* Base styles for App-like UI - Razorpay inspired */
        body {
            font-family: 'Inter', sans-serif;
            background-color: #F8FAFC; /* Very light, almost white background */
            color: #212121; /* Standard dark text */
            margin: 0;
            padding: 0;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }

        /* App Header - Razorpay style */
        .app-header {
            background: linear-gradient(135deg, #305eff 0%, #082489 100%); /* Razorpay primary dark blue/purple */
            color: white;
            padding: 1.5rem 1rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1); /* Subtle shadow */
            position: sticky;
            top: 0;
            z-index: 500;
        }
        .app-header .header-content {
            max-width: 1400px;
            margin: 0 auto;
            display: flex;
            flex-wrap: wrap; /* Allow wrapping for smaller screens */
            align-items: center;
            justify-content: center; /* Center the title now */
            gap: 0.75rem;
        }
        .app-header .header-title {
            font-size: 2rem;
            font-weight: 700;
            letter-spacing: -0.025em;
        }
        .app-header .header-icon {
            font-size: 2.25rem;
            color: #ffffff;
        }

        /* Main Content Area */
        .main-content {
            flex-grow: 1;
            max-width: 768px; /* Optimized width for app-like feel */
            width: 100%;
            margin: 2.5rem auto; /* More vertical margin */
            padding: 0 1.25rem; /* Consistent padding */
            display: flex;
            flex-direction: column;
            gap: 1.75rem; /* Increased space between sections */
        }
        @media (min-width: 768px) {
            .main-content {
                padding: 0 1.5rem;
            }
        }

        /* Section Styling - Primary Card */
        .data-section {
            background-color: white;
            border-radius: 0.875rem; /* Consistent rounding */
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.06); /* Refined shadow */
            overflow: hidden;
            border: 1px solid #ECEFF1; /* Very light grey border */
            display: flex;
            flex-direction: column;
            transition: all 0.2s ease-in-out;
            position: relative; /* For loading spinner positioning */
        }
        .data-section:hover {
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.09);
            transform: translateY(-3px);
        }

        .section-header {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid #ECEFF1;
            display: flex;
            align-items: center;
            justify-content: space-between;
            background-color: #FAFAFA; /* Subtle header background */
            font-size: 1rem;
            font-weight: 600;
            color: #4A4A4A;
        }
        .section-header-title {
            font-size: 1.15rem; /* Slightly refined title size */
            font-weight: 700;
            color: #1A1A1A;
            display: flex;
            align-items: center;
        }
        .section-header-title .fas {
            margin-right: 0.6rem;
            color: #305EFF; /* Razorpay primary blue */
        }
        .section-content {
            padding: 1.5rem;
            flex-grow: 1;
            overflow-y: auto;
            max-height: calc(100vh - 250px); /* Adjusted max-height */
            -webkit-overflow-scrolling: touch;
        }
        @media (max-width: 767px) {
            .section-content {
                max-height: none;
            }
        }

        /* Data List */
        .data-list {
            display: flex;
            flex-direction: column;
            gap: 1.25rem; /* Spacing between cards */
        }

        /* Payment Card (Razorpay App-like) */
        .data-card.deposit-card {
            background-color: #FFFFFF;
            border-radius: 0.75rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.03); /* Subtle card shadow */
            padding: 1.25rem;
            display: flex;
            flex-direction: column;
            gap: 0.6rem;
            border: 1px solid #F0F4F7; /* Very light card border */
            position: relative;
            overflow: hidden;
            transition: all 0.2s ease-in-out;
        }
        .data-card.deposit-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
        }

        /* NEW: Database Origin Label on Card */
        .data-card .db-origin-label {
            position: absolute;
            top: 0.75rem;
            right: 0.75rem;
            padding: 0.25rem 0.6rem;
            border-radius: 9999px;
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.025em;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .data-card .db-origin-label.spinblaze2 { /* Updated to match the key 'spinblaze2' */
            background-color: #305EFF; /* Razorpay Primary Blue (Stronger Blue) */
            color: white;
        }
        .data-card .db-origin-label.spin_trading { /* Updated to match the key 'spin_trading' */
            background-color: #673AB7; /* Deeper Purple */
            color: white;
        }


        .deposit-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.75rem;
            color: #78909C; /* Cooler gray for subtle info */
            font-weight: 500;
            margin-bottom: 0.4rem;
        }
        .deposit-card-header .id {
            opacity: 0.9;
        }
        .deposit-card-header .time {
            opacity: 0.9;
        }
        
        .deposit-card-center {
            text-align: center;
            margin: 0.8rem 0;
        }
        .deposit-card-center .data-card-amount-wrapper {
            display: flex;
            justify-content: center;
            align-items: baseline;
            gap: 0.4rem;
            margin-bottom: 0.4rem;
            flex-wrap: wrap; /* ADDED for responsiveness */
        }
        .deposit-card-center .data-card-amount {
            font-size: 2.25rem;
            font-weight: 800;
            color: #4CAF50; /* Standard success green */
            text-shadow: none;
            line-height: 1;
        }
        .deposit-card-center .data-card-amount.original {
            font-size: 1.3rem;
            color: #4A4A4A;
            font-weight: 600;
            margin-right: 0.4rem;
        }
        .deposit-card-center .data-card-amount.total-credit {
            font-size: 2.25rem;
            color: #1A1A1A;
            font-weight: 800;
            text-shadow: none;
        }

        .deposit-card-center .data-card-bonus-info {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 0.4rem;
            margin-bottom: 0.4rem;
            font-size: 1rem;
            color: #305EFF; /* Razorpay primary blue for bonus */
            font-weight: 600;
        }
        .deposit-card-center .data-card-bonus-info .fas {
            color: #305EFF;
        }


        .deposit-card-center .data-card-user-info {
            font-size: 1rem;
            color: #333333;
            font-weight: 600;
            margin-bottom: 0.6rem;
            word-break: break-all;
        }
        .deposit-card-center .data-card-utr-wrapper {
            background-color: #E8F5E9; /* Light green for UTR */
            border-radius: 0.4rem;
            padding: 0.5rem 0.9rem;
            font-size: 1.1rem;
            font-weight: 900;
            color: #4CAF50;
            text-align: center;
            word-break: break-all;
            box-shadow: none;
            display: inline-block;
            margin-top: 0.4rem;
            cursor: pointer; /* Indicate it's clickable */
            transition: background-color 0.2s;
        }
        .deposit-card-center .data-card-utr-wrapper:hover {
            background-color: #D4EDC9;
        }
        .deposit-card-center .data-card-utr-label {
            font-size: 0.75rem;
            color: #757575;
            font-weight: 500;
            display: block;
            margin-bottom: 0.2rem;
        }
        
        .data-card.deposit-card .data-card-actions {
            display: flex;
            justify-content: center;
            gap: 0.6rem;
            margin-top: 1.25rem;
            flex-wrap: wrap; /* ADDED for responsiveness */
        }
        /* Buttons inside deposit card */
        .data-card.deposit-card .btn-success {
            background: linear-gradient(135deg, #4CAF50 0%, #43A047 100%); /* Standard green */
            box-shadow: 0 2px 6px rgba(76, 175, 80, 0.2);
            color: white;
            font-size: 0.9rem;
            padding: 0.7rem 1.6rem;
            border-radius: 0.35rem; /* Matching main button radius */
        }
        .data-card.deposit-card .btn-success:hover {
            background: linear-gradient(135deg, #43A047 0%, #4CAF50 100%);
            box-shadow: 0 3px 8px rgba(76, 175, 80, 0.3);
            transform: translateY(-1px);
        }

        .data-card.deposit-card .btn-danger {
            background: linear-gradient(135deg, #EF5350 0%, #E53935 100%); /* Standard red */
            box-shadow: 0 2px 6px rgba(239, 83, 80, 0.2);
            color: white;
            font-size: 0.9rem;
            padding: 0.7rem 1.6rem;
            border-radius: 0.35rem; /* Matching main button radius */
        }
        .data-card.deposit-card .btn-danger:hover {
            background: linear-gradient(135deg, #E53935 0%, #EF5350 100%);
            box-shadow: 0 3px 8px rgba(239, 83, 80, 0.3);
            transform: translateY(-1px);
        }

        /* NEW: Force buttons to full width on small screens to stack cleanly */
        @media (max-width: 639px) { /* Corresponds to Tailwind's 'sm' breakpoint */
            .data-card.deposit-card .btn-success,
            .data-card.deposit-card .btn-danger {
                width: 100%;
            }
        }


        .empty-list-message {
            text-align: center;
            padding: 1.5rem;
            color: #9E9E9E;
            font-style: italic;
            border: 1px dashed #CFD8DC;
            border-radius: 0.6rem;
            margin-top: 0.8rem;
            background-color: #FFFFFF;
        }


        /* Global Button Styling */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0.7rem 1.4rem;
            background-color: #305EFF; /* Razorpay Primary Blue */
            color: white;
            border: none;
            border-radius: 0.375rem; /* Razorpay's typical slightly less rounded buttons */
            cursor: pointer;
            transition: background-color 0.2s ease-in-out, transform 0.1s ease-in-out, box-shadow 0.2s ease;
            font-weight: 600;
            text-decoration: none;
            box-shadow: 0 2px 6px rgba(48, 94, 255, 0.2);
            white-space: nowrap;
        }
        .btn:hover {
            background-color: #2A51CC; /* Darker shade of primary blue */
            transform: translateY(-1px);
            box-shadow: 0 3px 8px rgba(48, 94, 255, 0.3);
        }
        .btn:active {
            transform: translateY(0px);
            box-shadow: inset 0 1px 3px rgba(0,0,0,0.2);
        }
        .btn .fas {
            margin-right: 0.4rem;
            font-size: 0.85em;
        }
        .btn-success { background-color: #4CAF50; }
        .btn-success:hover { background-color: #43A047; }
        .btn-danger { background-color: #EF5350; }
        .btn-danger:hover { background-color: #E53935; }
        .btn-neutral { background-color: #B0BEC5; color: #333333; } /* Added color for neutral button */
        .btn-neutral:hover { background-color: #90A4AE; }
        .btn-sm {
            padding: 0.4rem 0.9rem;
            font-size: 0.8rem;
            border-radius: 0.35rem;
        }

        /* Input Field Styling for Razorpay look */
        input[type="text"] {
            border-radius: 0.375rem; /* Matching button border-radius */
            border: 1px solid #CFD8DC; /* Subtle light gray border */
            padding: 0.65rem 1rem; /* Slightly more padding for better touch targets */
            font-size: 0.95rem; /* Readable font size */
            color: #333333;
            transition: border-color 0.2s ease-in-out, box-shadow 0.2s ease-in-out;
        }
        input[type="text"]:focus {
            outline: none;
            border-color: #305EFF; /* Razorpay primary blue on focus */
            box-shadow: 0 0 0 3px rgba(48, 94, 255, 0.2); /* Soft blue glow */
        }
        input[type="text"]::placeholder {
            color: #90A4AE; /* Lighter placeholder text */
            opacity: 1; /* Ensure placeholder is visible */
        }


        /* Modal styles */
        .modal-overlay {
            position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            background-color: rgba(0, 0, 0, 0.6);
            display: flex; justify-content: center; align-items: center;
            z-index: 1000;
            opacity: 0; visibility: hidden;
            transition: opacity 0.3s ease-out;
        }
        .modal-overlay.show {
            opacity: 1; visibility: visible;
        }
        .modal-content {
            background-color: white;
            padding: 1.8rem;
            border-radius: 0.8rem;
            box-shadow: 0 8px 25px rgba(0,0,0,0.2);
            max-width: 450px;
            width: calc(100% - 2rem);
            transform: translateY(-20px) scale(0.97);
            transition: transform 0.3s cubic-bezier(0.68, -0.55, 0.27, 1.55), opacity 0.3s ease-out;
            opacity: 0;
            text-align: center;
        }
        .modal-overlay.show .modal-content {
            transform: translateY(0) scale(1);
            opacity: 1;
        }
        .modal-title {
            font-size: 1.4rem;
            font-weight: 700;
            color: #1A1A1A;
            margin-bottom: 0.9rem;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .modal-title .fas {
            margin-right: 0.6rem;
            color: #305EFF;
        }
        .modal-text {
            color: #4A4A4A;
            margin-bottom: 1.3rem;
            font-size: 0.95rem;
            line-height: 1.5;
        }
        .modal-text strong {
            color: #1A1A1A;
            font-weight: 700;
        }
        .modal-actions {
            display: flex;
            justify-content: center;
            gap: 0.8rem;
        }


        /* Toast Notification */
        .toast-notification {
            position: fixed;
            bottom: 18px;
            right: 18px;
            background-color: #333333;
            color: white;
            padding: 0.9rem 1.4rem;
            border-radius: 0.4rem;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
            z-index: 2000;
            opacity: 0;
            transform: translateY(20px);
            transition: opacity 0.3s ease-out, transform 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            min-width: 220px;
        }
        .toast-notification .fas {
            margin-right: 0.6rem;
            font-size: 1.1rem;
        }
        .toast-notification.show {
            opacity: 1;
            transform: translateY(0);
        }
        .toast-notification.success { background-color: #4CAF50; }
        .toast-notification.success .fas { color: white; }
        .toast-notification.error { background-color: #EF5350; }
        .toast-notification.error .fas { color: white; }
        .toast-notification.info { background-color: #305EFF; }
        .toast-notification.info .fas { color: white; }


        /* Status Alert Boxes (for PHP generated messages) */
        .status-alert {
            padding: 0.9rem 1.4rem;
            margin-bottom: 1.2rem;
            border-radius: 0.6rem;
            font-size: 0.9rem;
            border-left-width: 4px;
            display: flex;
            align-items: center;
            box-shadow: 0 1px 5px rgba(0,0,0,0.04);
        }
        .status-alert .fas {
            margin-right: 0.8rem;
            font-size: 1.2rem;
        }
        .status-alert-success {
            background-color: #DCF8E0;
            border-color: #4CAF50;
            color: #388E3C;
        }
        .status-alert-success .fas { color: #4CAF50; }
        .status-alert-error {
            background-color: #FFEBEE;
            border-color: #EF5350;
            color: #D32F2F;
        }
        .status-alert-error .fas { color: #EF5350; }
        .status-alert-info {
            background-color: #E6F0FF;
            border-color: #305EFF;
            color: #244DB3;
        }
        .status-alert-info .fas { color: #305EFF; }


        /* Utility classes */
        .capitalize { text-transform: capitalize; }
        .app-badge {
            font-size: 0.75rem;
            font-weight: 700;
            padding: 0.25rem 0.6rem;
            border-radius: 9999px;
            background-color: #E6F0FF;
            color: #2A51CC;
            box-shadow: none;
        }

        /* Yellow Label Styling for Total Credit (from previous version) */
        .app-label-yellow {
            background-color: #FFF3E0; /* Very light orange/yellow */
            color: #E65100; /* Dark orange text */
            padding: 0.25rem 0.6rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            box-shadow: 0 1px 3px rgba(255, 160, 0, 0.1);
            margin-left: 0.5rem; /* Space from "Total Credit" text */
        }
        .app-label-yellow .fas {
            font-size: 0.85em; /* Smaller icon within label */
        }


        /* App Footer */
        .app-footer {
            text-align: center;
            padding: 1.2rem;
            color: #888888;
            font-size: 0.8rem;
            border-top: 1px solid #EEEEEE;
            background-color: white;
            box-shadow: 0 -1px 8px rgba(0,0,0,0.03);
        }

        /* Loading Spinner Styles */
        .loading-overlay {
            position: absolute; /* Changed from fixed to absolute within data-section */
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(255, 255, 255, 0.8);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 600; /* Above content, below modal */
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.3s ease-in-out;
            border-radius: 0.875rem; /* Match parent */
        }
        .loading-overlay.show {
            opacity: 1;
            visibility: visible;
        }
        .spinner {
            border: 4px solid rgba(0, 0, 0, 0.1);
            border-left-color: #305EFF; /* Razorpay primary blue */
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>
</head>
<body>

<div class="app-container">
    <header class="app-header">
        <div class="header-content">
            <i class="fas fa-shield-alt header-icon"></i>
            <h1 class="header-title">Admin Panel </h1>
        </div>
    </header>

    <main class="main-content">

        <?php if ($page_error): ?>
            <div class="status-alert status-alert-error">
                <i class="fas fa-times-circle"></i>
                <div>
                    <strong class="font-semibold">Error:</strong>
                    <p><?= sanitize($page_error); ?></p>
                </div>
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['status'])): 
            $status_message = sanitize($_GET['status']);
            $status_id = isset($_GET['id']) ? " (ID: " . sanitize($_GET['id']) . ")" : "";
            $status_db_label = isset($_GET['db']) && isset($db_configs[$_GET['db']]) ? " from " . sanitize($db_configs[$_GET['db']]['label']) : "";
            $alert_class = 'status-alert-info';
            $icon_class = 'fas fa-info-circle';
            $status_title = "Notice";

            if (strpos($status_message, 'approved') !== false || strpos($status_message, 'success') !== false) {
                $alert_class = 'status-alert-success';
                $icon_class = 'fas fa-check-circle';
                $status_title = "Success";
            } elseif (strpos($status_message, 'error') !== false || strpos(strtolower($status_message), 'failed') !== false || strpos(strtolower($status_message), 'not found') !== false || strpos(strtolower($status_message), 'invalid') !== false || strpos(strtolower($status_message), 'cannot be deleted') !== false) {
                $alert_class = 'status-alert-error';
                $icon_class = 'fas fa-exclamation-triangle';
                $status_title = "Error";
            }
        ?>
            <div class="status-alert <?= $alert_class ?>">
                <i class="<?= $icon_class ?>"></i>
                <div>
                    <strong class="font-semibold"><?= $status_title ?>:</strong>
                    <p><?= ucfirst(str_replace("_", " ", $status_message)) . $status_id . $status_db_label; ?></p>
                </div>
            </div>
        <?php endif; ?>

        <!-- UTR Search Bar -->
        <div class="search-bar flex flex-col sm:flex-row items-center gap-3 p-4 bg-white rounded-xl shadow-md border border-gray-200">
            <input type="text" id="utrSearchInput" placeholder="Search by UTR across all databases..." class="flex-grow w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent transition duration-200 text-base" />
            <div class="flex gap-2 w-full sm:w-auto">
                <button id="searchUtrBtn" class="btn btn-primary w-1/2 sm:w-auto text-sm"><i class="fas fa-search"></i> Search</button>
                <button id="clearSearchBtn" class="btn btn-neutral btn-sm w-1/2 sm:w-auto text-sm" style="display:none;"><i class="fas fa-times"></i> Clear</button>
            </div>
        </div>

        <!-- Pending Deposit Requests - Main Display Area -->
        <section class="data-section">
            <div class="section-header">
                <h2 class="section-header-title" id="depositListHeader"><i class="fas fa-money-check-alt"></i>New Payments Received</h2>
                <span id="pendingDepositsCount" class="app-badge">
                    <?= $combined_pending_count ?> Pending
                </span>
            </div>
            <div class="loading-overlay" id="loadingOverlay">
                <div class="spinner"></div>
            </div>
            <div class="section-content">
                <div id="pendingDepositsList" class="data-list">
                    <?php /* Initial content will be populated by JavaScript after page load */ ?>
                </div>
                <?php if (empty($initial_all_deposits) && empty($page_error)): // Show empty message only if no initial data and no DB errors ?>
                    <p class="empty-list-message" id="initialEmptyMessage">No new payment requests at the moment across all databases.</p>
                <?php endif; ?>
            </div>
        </section>

    </main>

    <footer class="app-footer">
        &copy; <?= date("Y") ?> Eagle Trading Admin Panel. All rights reserved.
    </footer>

</div> <!-- End .app-container -->

<!-- Custom Confirmation Modal -->
<div id="confirmationModal" class="modal-overlay">
    <div class="modal-content">
        <h3 class="modal-title" id="modalTitle"><i class="fas fa-question-circle"></i> Confirm Action</h3>
        <p class="modal-text" id="modalMessage">Are you sure you want to proceed with this action?</p>
        <div class="modal-actions">
            <button id="modalConfirmBtn" class="btn btn-success"><i class="fas fa-check"></i> Confirm</button>
            <button id="modalCancelBtn" class="btn btn-neutral"><i class="fas fa-times"></i> Cancel</button>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // DOM elements
    const pendingDepositsList = document.getElementById('pendingDepositsList');
    const pendingDepositsCountSpan = document.getElementById('pendingDepositsCount');
    const depositListHeader = document.getElementById('depositListHeader');
    const initialEmptyMessage = document.getElementById('initialEmptyMessage');
    const utrSearchInput = document.getElementById('utrSearchInput');
    const searchUtrBtn = document.getElementById('searchUtrBtn');
    const clearSearchBtn = document.getElementById('clearSearchBtn');
    const loadingOverlay = document.getElementById('loadingOverlay');

    // Modal elements
    const confirmationModal = document.getElementById('confirmationModal');
    const modalTitle = document.getElementById('modalTitle');
    const modalMessage = document.getElementById('modalMessage');
    const modalConfirmBtn = document.getElementById('modalConfirmBtn');
    const modalCancelBtn = document.getElementById('modalCancelBtn');

    let initialLoadComplete = false;
    let lastKnownHighestDepositId = 0; // This will track the highest ID across ALL loaded deposits
    const SCRIPT_NAME = "<?= basename(__FILE__) ?>"; // admin_dashboard.php

    // Global variable to hold bonus logic and labels for each database
    const DB_INFO = <?= $js_bonus_logic_info_json ?>;

    // --- Utility Functions ---

    // Function to escape HTML entities for safe display
    function escapeHTML(str) {
        if (str === null || str === undefined) return '';
        return String(str).replace(/[&<>"']/g, function (match) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[match];
        });
    }
    
    // Function to format date strings for display
    function formatDate(dateString) {
        if (!dateString) return 'N/A';
        try {
            const date = new Date(dateString);
            return date.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) + ', ' +
                   date.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', hour12: true });
        } catch (e) {
            console.warn("Could not format date:", dateString, e);
            return dateString;      
        }
    }

    // Function to show a temporary toast notification
    function showToast(message, type = 'success') {
        const toast = document.createElement('div');
        toast.className = 'toast-notification';
        let iconClass = 'fas fa-check-circle';      
        let toastTypeClass = 'success';

        if (type === 'error') {
            iconClass = 'fas fa-times-circle';
            toastTypeClass = 'error';
        } else if (type === 'info') {
            iconClass = 'fas fa-info-circle';
            toastTypeClass = 'info';
        }
        toast.classList.add(toastTypeClass);
        
        toast.innerHTML = `<i class="${iconClass}"></i> ${escapeHTML(message)}`;
        document.body.appendChild(toast);
        
        // Trigger reflow to enable CSS transition
        toast.offsetHeight;      
        toast.classList.add('show');      

        setTimeout(() => {
            toast.classList.remove('show');
            setTimeout(() => {
                if (document.body.contains(toast)) {
                    document.body.removeChild(toast);
                }
            }, 350); // Allow time for fade out transition
        }, 3500); // Display duration
    }

    // Function to copy text to clipboard
    function copyToClipboard(text) {
        try {
            const tempInput = document.createElement('textarea');
            tempInput.value = text;
            document.body.appendChild(tempInput);
            tempInput.select();
            document.execCommand('copy');
            document.body.removeChild(tempInput);
            showToast('Copied to clipboard!', 'success');
        } catch (err) {
            console.error('Failed to copy text: ', err);
            showToast('Failed to copy. Please try manually.', 'error');
        }
    }

    // Show/Hide Loading Overlay
    function showLoading() {
        if (loadingOverlay) {
            loadingOverlay.classList.add('show');
        }
    }

    function hideLoading() {
        if (loadingOverlay) {
            loadingOverlay.classList.remove('show');
        }
    }

    // Show Custom Confirmation Modal
    function showConfirmationModal(title, message, onConfirmCallback) {
        modalTitle.innerHTML = title; // innerHTML for icon support
        modalMessage.textContent = message;
        confirmationModal.classList.add('show');

        // Clear previous event listeners to prevent multiple calls
        modalConfirmBtn.onclick = null;
        modalCancelBtn.onclick = null;

        modalConfirmBtn.onclick = () => {
            hideConfirmationModal();
            onConfirmCallback();
        };
        modalCancelBtn.onclick = hideConfirmationModal;
    }

    // Hide Custom Confirmation Modal
    function hideConfirmationModal() {
        confirmationModal.classList.remove('show');
    }

    // --- Data Rendering ---

    // Function to render deposit data into HTML cards
    function renderDataList(containerElement, data, isSearch = false) {
        if (!containerElement) {
            console.warn(`Container element not found for rendering data.`);
            return;
        }
        let newCardsHtml = '';
        if (data.length === 0) {
            newCardsHtml = `<p class="empty-list-message" id="currentEmptyMessage">${isSearch ? 'No matching requests found.' : 'No new payment requests at the moment across all databases.'}</p>`;
        } else {
            data.forEach(item => {
                const originalAmount = parseFloat(item.amount);
                let bonusAmount = 0;
                // Safely get bonus_logic_type from DB_INFO
                const bonusLogicType = DB_INFO[item.db_origin] ? DB_INFO[item.db_origin].bonus_logic_type : 'unknown';

                // Apply bonus logic based on the originating database's type
                if (bonusLogicType === 'fixed_bonus') {
                    if (originalAmount === 500) {
                        bonusAmount = 5000;
                    } else if (originalAmount === 250) {
                        bonusAmount = 2500;
                    }
                } else if (bonusLogicType === '20x_multiplier') {
                    bonusAmount = originalAmount * 19; // 19x bonus (1x original + 19x bonus = 20x total)
                }

                const totalCreditAmount = originalAmount + bonusAmount;

                const formattedOriginalAmount = `₹${originalAmount.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
                const formattedBonusAmount = `₹${bonusAmount.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
                const formattedTotalCreditAmount = `₹${totalCreditAmount.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
                const formattedDate = formatDate(item.created_at || item.requested_at);
                const dbLabel = DB_INFO[item.db_origin] ? DB_INFO[item.db_origin].label : 'Unknown DB';

                const cardClasses = 'data-card deposit-card';
                const showDeleteButton = (item.status === 'pending');

                newCardsHtml += `
                    <div class="${cardClasses}">
                        <span class="db-origin-label ${escapeHTML(item.db_origin)}">${escapeHTML(dbLabel)}</span>
                        <div class="deposit-card-header">
                            <span class="id">Deposit ID: ${escapeHTML(item.id)}</span>
                            <span class="time">${escapeHTML(formattedDate)}</span>
                        </div>
                        <div class="deposit-card-center">
                            <div class="data-card-amount-wrapper">
                                <span class="data-card-value data-card-amount original">${formattedOriginalAmount}</span>
                                ${bonusAmount > 0 ? `<i class="fas fa-plus text-gray-500"></i> <span class="data-card-bonus-info"><i class="fas fa-gift"></i> Bonus: ${formattedBonusAmount}</span>` : ''}
                            </div>
                            <div class="data-card-amount-wrapper">
                                <span class="text-gray-600 font-semibold text-lg">Total Credit:</span>
                                <span class="data-card-value data-card-amount total-credit">${formattedTotalCreditAmount}</span>
                                <span class="app-label-yellow"><i class="fas fa-tag"></i> TOTAL</span>
                            </div>
                            <div class="data-card-user-info wrap-text">From: ${escapeHTML(item.user_name || item.user_email)}</div>
                            <div class="data-card-utr-wrapper" data-utr="${escapeHTML(item.utr)}">UTR: ${escapeHTML(item.utr)}</div>
                        </div>
                        <div class="data-card-actions">
                            <button class="btn btn-success btn-sm approve-deposit-btn" data-id="${escapeHTML(item.id)}" data-db-origin="${escapeHTML(item.db_origin)}">
                                <i class="fas fa-check-circle"></i> Approve
                            </button>
                            ${showDeleteButton ? `
                            <button class="btn btn-danger btn-sm delete-deposit-btn" data-id="${escapeHTML(item.id)}" data-db-origin="${escapeHTML(item.db_origin)}">
                                <i class="fas fa-trash"></i> Delete
                            </button>
                            ` : `<span class="text-gray-500 text-sm px-4 py-2 border border-gray-200 rounded-md bg-gray-50">Status: <span class="capitalize font-semibold text-gray-700">${escapeHTML(item.status)}</span></span>`}
                        </div>
                    </div>
                `;
            });
        }
        containerElement.innerHTML = newCardsHtml;

        // Hide the initial empty message if content is rendered
        const currentEmptyMessage = document.getElementById('currentEmptyMessage');
        if (currentEmptyMessage) {
            currentEmptyMessage.remove(); // Remove if it exists
        }
        if (initialEmptyMessage) { // Original empty message from PHP
            initialEmptyMessage.style.display = data.length === 0 && !isSearch ? 'block' : 'none';
        }
    }


    // Initial render of pending deposits using PHP-provided data
    const initialDepositsData = <?= json_encode($initial_all_deposits) ?>;
    renderDataList(pendingDepositsList, initialDepositsData);

    if (initialDepositsData.length > 0) {
        // Find the maximum ID among all initial deposits loaded
        lastKnownHighestDepositId = Math.max(...initialDepositsData.map(d => parseInt(d.id, 10)));
    }
    initialLoadComplete = true;


    // Function to fetch and update deposits via AJAX
    async function fetchAndUpdateDeposits(isInitialCall = false, utrQuery = null) {
        // Only fetch if initial load is complete or if it's an initial call for setup, and not already in search mode
        if (!initialLoadComplete && !isInitialCall && utrSearchInput.value.trim() === '') return;      

        showLoading(); // Show loading spinner

        let apiUrl = `${SCRIPT_NAME}?fetch_deposits=true&_=${new Date().getTime()}`;
        let headerTitle = `<i class="fas fa-money-check-alt"></i>New Payments Received`;
        let totalPendingCount = 0;

        if (utrQuery) {
            apiUrl += `&utr_query=${encodeURIComponent(utrQuery)}&status_filter=all`; // Search all statuses for UTR
            headerTitle = `<i class="fas fa-search"></i>Search Results for "${escapeHTML(utrQuery)}"`;
            if (clearSearchBtn) clearSearchBtn.style.display = 'inline-flex';
        } else {
            apiUrl += `&status_filter=pending`;
            if (clearSearchBtn) clearSearchBtn.style.display = 'none';
        }

        if(depositListHeader) depositListHeader.innerHTML = headerTitle;

        try {
            const response = await fetch(apiUrl);      
            if (!response.ok) {
                console.error('Network error fetching deposits:', response.status, response.statusText);
                if (!isInitialCall) showToast('Network error: Could not update payments list.', 'error');
                return;
            }
            const data = await response.json();

            if (!data.success || !data.deposits) {
                console.error('API Error fetching deposits:', data.error || 'Unknown API error');
                if (!isInitialCall) showToast(data.error || 'API error: Could not update payments list.', 'error');
                return;
            }

            const depositsData = data.deposits;
            renderDataList(pendingDepositsList, depositsData, utrQuery !== null);

            if (pendingDepositsCountSpan) {
                if (utrQuery) {
                    pendingDepositsCountSpan.textContent = `${depositsData.length} Results`;
                    pendingDepositsCountSpan.classList.add('app-badge'); // Reuse existing badge styling
                    pendingDepositsCountSpan.classList.add('bg-blue-100', 'text-blue-700'); // Add specific styling for search results
                    pendingDepositsCountSpan.classList.remove('bg-red-100', 'text-red-700'); // Remove previous styling
                } else {
                    // Recalculate pending count for combined data
                    totalPendingCount = depositsData.filter(item => item.status === 'pending').length;
                    pendingDepositsCountSpan.textContent = `${totalPendingCount} Pending`;
                    pendingDepositsCountSpan.classList.remove('bg-blue-100', 'text-blue-700');
                    pendingDepositsCountSpan.classList.add('app-badge');
                    // Add conditional styling for the badge based on count
                    if (totalPendingCount > 0) {
                        pendingDepositsCountSpan.classList.add('bg-red-100', 'text-red-700'); // Red for pending if any
                    } else {
                        pendingDepositsCountSpan.classList.remove('bg-red-100', 'text-red-700');
                        pendingDepositsCountSpan.classList.add('bg-green-100', 'text-green-700'); // Green if none
                    }
                }
            }

            if (!utrQuery) { // Only check for new notifications if not in search mode
                let currentHighestIdInResponse = 0;
                if (depositsData.length > 0) {
                    currentHighestIdInResponse = Math.max(...depositsData.map(d => parseInt(d.id, 10)));
                }

                if (!isInitialCall && initialLoadComplete) {
                    if (currentHighestIdInResponse > lastKnownHighestDepositId) {
                        const newRequestsCount = depositsData.filter(d => parseInt(d.id, 10) > lastKnownHighestDepositId && d.status === 'pending').length;
                        if (newRequestsCount > 0) {
                            showToast(`🚀 ${newRequestsCount} new payment(s) received across databases!`, 'info');
                        }
                    }
                }
                lastKnownHighestDepositId = currentHighestIdInResponse;
            }

        } catch (error) {
            console.error('JavaScript Error fetching/updating deposits:', error);
            if (!isInitialCall) showToast('Client error: Could not update payments list.', 'error');
        } finally {
            hideLoading(); // Hide loading spinner regardless of success or failure
        }
    }

    // --- Event Listeners ---

    // Delegation for dynamic buttons (Approve, Delete, UTR Copy)
    document.addEventListener('click', function(event) {
        const approveButton = event.target.closest('.approve-deposit-btn');
        const deleteButton = event.target.closest('.delete-deposit-btn');
        const utrElement = event.target.closest('.data-card-utr-wrapper');

        // Approve Button
        if (approveButton && pendingDepositsList.contains(approveButton)) {
            event.preventDefault();      
            const depositId = approveButton.dataset.id;
            const dbOrigin = approveButton.dataset.dbOrigin;
            const dbLabel = DB_INFO[dbOrigin] ? DB_INFO[dbOrigin].label : 'Unknown DB';

            showConfirmationModal(
                `<i class="fas fa-check-circle text-green-500"></i> Approve Payment?`,
                `Are you sure you want to approve deposit ID ${depositId} from ${dbLabel}? This action cannot be undone.`,
                () => {
                    window.location.href = `${SCRIPT_NAME}?approve_deposit=${depositId}&db_origin=${dbOrigin}`;
                    showToast(`Approving deposit ID ${depositId} from ${dbLabel}...`, 'info');
                }
            );
        }

        // Delete Button
        if (deleteButton && pendingDepositsList.contains(deleteButton)) {
            event.preventDefault();
            const depositId = deleteButton.dataset.id;
            const dbOrigin = deleteButton.dataset.dbOrigin;
            const dbLabel = DB_INFO[dbOrigin] ? DB_INFO[dbOrigin].label : 'Unknown DB';

            showConfirmationModal(
                `<i class="fas fa-trash-alt text-red-500"></i> Delete Payment?`,
                `Are you sure you want to delete deposit ID ${depositId} from ${dbLabel}? This action cannot be undone.`,
                () => {
                    window.location.href = `${SCRIPT_NAME}?delete_deposit=${depositId}&db_origin=${dbOrigin}`;
                    showToast(`Deleting deposit ID ${depositId} from ${dbLabel}...`, 'info');
                }
            );
        }

        // UTR Copy
        if (utrElement && utrElement.dataset.utr) {
            copyToClipboard(utrElement.dataset.utr);
        }
    });

    // UTR Search Button Handler
    if (searchUtrBtn) {
        searchUtrBtn.addEventListener('click', function() {
            const utr = utrSearchInput.value.trim();
            fetchAndUpdateDeposits(false, utr || null); // Pass null if empty to revert to pending view
            if (!utr) {
                showToast('Search cleared. Showing all pending payments.', 'info');
            } else {
                showToast('Searching for UTR: ' + utr, 'info');
            }
        });
    }

    // Clear Search Button Handler
    if (clearSearchBtn) {
        clearSearchBtn.addEventListener('click', function() {
            utrSearchInput.value = '';
            fetchAndUpdateDeposits(false, null); // Revert to showing pending deposits
            showToast('Search cleared. Showing all pending payments.', 'info');
        });
    }

    // Initial fetch and periodic polling
    setTimeout(() => {
        fetchAndUpdateDeposits(true, null); // Initial fetch for combined pending deposits
        setInterval(() => {
            // Only poll for new requests if no UTR search is active
            if (utrSearchInput.value.trim() === '') {
                fetchAndUpdateDeposits(false, null);
            }
        }, 15000); // Poll every 15 seconds
    }, 500); // Small delay to ensure DOM is ready
});
</script>

</body>
</html>
