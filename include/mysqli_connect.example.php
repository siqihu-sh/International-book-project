<?php
declare(strict_types=1);

// Copy this file to mysqli_connect.php and enter your local password.
$db_host = '127.0.0.1';
$db_user = 'root';
$db_password = 'your_mysql_password';
$db_name = 'international_book_project';
$db_port = 3306;

$conn = new mysqli($db_host, $db_user, $db_password, $db_name, $db_port);

if ($conn->connect_errno) {
    die('Database connection failed.');
}

$conn->set_charset('utf8mb4');
