<?php
echo "PHP Timezone: " . date_default_timezone_get() . "<br>";
echo "PHP Current Time: " . date('Y-m-d H:i:s') . "<br>";

require_once 'config/database.php';
$result = $conn->query("SELECT NOW() AS mysql_time, @@session.time_zone AS mysql_tz");
$row = $result->fetch_assoc();
echo "MySQL Timezone: " . $row['mysql_tz'] . "<br>";
echo "MySQL Current Time: " . $row['mysql_time'] . "<br>";