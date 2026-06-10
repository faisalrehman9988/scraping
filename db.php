<?php

function getDBconnection() {
    $host = "localhost";
    $username = "root";
    $password = "";
    $dbname = "scraper"; 

    try {
        $pdo = new PDO(
            "mysql:host=$host;dbname=$dbname;charset=utf8mb4", 
            $username, 
            $password
        );
        
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    } 
    catch (PDOException $e) { 
        
        echo "Database connection failed: " . $e->getMessage() . "<br>";
        error_log("database connection failed: " . $e->getMessage());
        return false;

    }
}


?>