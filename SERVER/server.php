<?php
$logFile = '/home/moriarty/Desktop/Server/Logs.log';
$expectedParams = ['ip', 'module', 'action', 'status', 'password', 'device'];

$logDetails = [];
foreach ($expectedParams as $param) {
    if (isset($_POST[$param])) {
        $cleanValue = preg_replace('/\s+/', ' ', $_POST[$param]); 
        $logDetails[] = "$param=$cleanValue";
    }
}

if (!empty($logDetails)) {
    $timestamp = date('Y-m-d H:i:s');
    $requesterIP = $_SERVER['REMOTE_ADDR']; 
    $logString = "[$timestamp] [ReqIP: $requesterIP] " . implode(', ', $logDetails) . PHP_EOL;
    file_put_contents($logFile, $logString, FILE_APPEND);
}

$password = $_POST['password'] ?? '';
$isAuthenticated = ($password === 'pict');

$module    = $_POST['module'] ?? '';
$action    = $_POST['action'] ?? '';
$rawStatus = $_POST['status'] ?? '';
$device    = $_POST['device'] ?? '';
$ip        = $_POST['ip'] ?? $_SERVER['REMOTE_ADDR'];

$conn = @mysqli_connect('localhost', 'exam', 'exam', 'Labguard');
if (!$conn) {
    $conn = @mysqli_connect('localhost', 'exam', 'exam', 'Labguard');
}

if ($conn) {
    if ($module === 'network' && !empty($ip)) {
        $dbStatus = '';

        if ($rawStatus === 'isolated') {
            $dbStatus = 'isolated';
        } elseif ($rawStatus === 'authenticated' || ($action === 'auth' && $isAuthenticated)) {
            $dbStatus = 'offline';
        }

        if (!empty($dbStatus)) {
            $stmt = mysqli_prepare($conn, "INSERT INTO internet (ip, status, time) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE status = VALUES(status), time = NOW()");
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, "ss", $ip, $dbStatus);
                if (!mysqli_stmt_execute($stmt)) {
                    file_put_contents($logFile, "[DB ERROR] Query Execute Failed: " . mysqli_stmt_error($stmt) . PHP_EOL, FILE_APPEND);
                }
                mysqli_stmt_close($stmt);
            } else {
                file_put_contents($logFile, "[DB ERROR] Query Prepare Failed: " . mysqli_error($conn) . PHP_EOL, FILE_APPEND);
            }
        }
    } elseif ($module === 'usb' && !empty($ip)) {
        $dbStatus = '';

        if (empty($rawStatus) || $rawStatus === 'idle') {
            $dbStatus = 'idle';
        } elseif ($rawStatus === 'blocked') {
            $dbStatus = 'blocked';
        } elseif ($rawStatus === 'authorized') {
            $dbStatus = 'authorized';
        } elseif ($action === 'auth' && $isAuthenticated) {
            $dbStatus = 'authorized';
        }

        if (!empty($dbStatus)) {
            $query = "INSERT INTO usb (ip, status, device, time) VALUES (?, ?, ?, NOW()) 
                      ON DUPLICATE KEY UPDATE status = VALUES(status), device = VALUES(device), time = NOW()";

            $stmt = mysqli_prepare($conn, $query);

            if ($stmt) {
                mysqli_stmt_bind_param($stmt, "sss", $ip, $dbStatus, $device);

                if (!mysqli_stmt_execute($stmt)) {
                    // Updated to match network module error log format
                    file_put_contents($logFile, "[DB ERROR] Query Execute Failed: " . mysqli_stmt_error($stmt) . PHP_EOL, FILE_APPEND);
                }

                mysqli_stmt_close($stmt);
            } else {
                // Updated to match network module error log format
                file_put_contents($logFile, "[DB ERROR] Query Prepare Failed: " . mysqli_error($conn) . PHP_EOL, FILE_APPEND);
            }
        }
    }

    mysqli_close($conn);
} else {
    file_put_contents($logFile, "[DB ERROR] MySQL Connection Failed: " . mysqli_connect_error() . PHP_EOL, FILE_APPEND);
}

if ($isAuthenticated) {
    echo 200;
} else {
    echo 300;
}
?>
