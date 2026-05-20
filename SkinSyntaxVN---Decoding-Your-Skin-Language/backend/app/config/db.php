<?php
$host = "localhost";
$db = "skinsyntax";
$user = "postgres";
$pass = "123456";  // 

try {
    $pdo = new PDO("pgsql:host=$host;dbname=$db", $user, $pass);
} catch (PDOException $e) {
    die("Lỗi kết nối DB: " . $e->getMessage());
}
