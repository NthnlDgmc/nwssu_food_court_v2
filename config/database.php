<?php
$servername = "localhost"; // database server
$username = "root";        // database username
$password = "";            // database password
$dbname = "nwssu_food_court"; // database name

// Create connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}