<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'cibil_db');

define('FINPAY_API_KEY', '8d8fd1-efeaa9-928494-24a4fd-0c7dd1');
define('FINPAY_API_URL', 'https://api.finpayultra.com/api/credit-report.php');

function getDBConnection()
{
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($conn->connect_error) {
        echo json_encode(["status" => "error", "message" => "Database Connection Failed: " . $conn->connect_error]);
        exit;
    }
    return $conn;
}
