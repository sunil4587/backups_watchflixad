<?php

  // Include configs
  require_once "configs.php";

  try {
    // Define log file path
    $logFilePath = 'export_db_log.txt';

    // Create the backup directory if it doesn't exist
    if (!file_exists($exportTempFolderPath) && !mkdir($exportTempFolderPath, 0777, true)) {
      throw new Exception("Failed to create backup directory: $exportTempFolderPath");
    }

    // Define table conditions and limits
    $GetDataByConditiontables = [
      'user' => 'type != 2',
    ];

    $excludeRecordsFor = [
      'addons',
      'archive_user',
      'ci_sessions',
      'cron_status',
      'iptv_cron_logs',
      'movie_screenshots',
      'referral_report',
      'track_users_data',
      'track_users_data_4_april_2023',
      'episode_screenshots',
      'logs'
    ];

    // Include specific tables based on backup type
    $tablesToInclude = [];
    if (isset($_GET['backup_type']) && $_GET['backup_type'] === 'daily') {
      $tablesToInclude = ['user', 'subscription', 'watched_history', 'invites', 'requested_movies'];
      $backupType = "daily";
    }

    // Set file names and paths
    $fileName = "wf_db_{$backupType}_" . date('YmdHis');
    $sqlFilePath = $exportTempFolderPath . $fileName . '.sql';
    $gzipFilePath = $exportTempFolderPath . $fileName . '.sql.gz';

    // Get all table names from the database
    $pdo = new PDO("mysql:host={$exportFromDB['host']};dbname={$exportFromDB['database']}", $exportFromDB['username'], $exportFromDB['password']);
    $tablesStmt = $pdo->query("SHOW TABLES");
    $allTables = $tablesStmt->fetchAll(PDO::FETCH_COLUMN);

    // Prepare the SQL dump content
    $sqlDumpContent = '';
    foreach ($allTables as $table) {
      if ($backupType === "daily" && !in_array($table, $tablesToInclude)) {
        continue;
      }

      // Check if the table should exclude records
      if (in_array($table, $excludeRecordsFor)) {
        // Get table structure
        $structureStmt = $pdo->query("SHOW CREATE TABLE `$table`");
        $structure = $structureStmt->fetch(PDO::FETCH_ASSOC);
        $sqlDumpContent .= $structure['Create Table'] . ";\n\n";
      } else {
        // Get table structure and data
        $structureStmt = $pdo->query("SHOW CREATE TABLE `$table`");
        $structure = $structureStmt->fetch(PDO::FETCH_ASSOC);
        $sqlDumpContent .= $structure['Create Table'] . ";\n\n";

        $condition = isset($GetDataByConditiontables[$table]) ? " WHERE {$GetDataByConditiontables[$table]}" : "";
        $stmt = $pdo->query("SELECT * FROM `$table`" . $condition);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($rows)) {
          $sqlDumpContent .= "-- Dump for table $table\n";
          foreach ($rows as $row) {
            // Iterate through each row and handle JSON data
            foreach ($row as $column => $value) {
              if (is_array($value) || is_object($value)) {
                // Convert JSON data to a string representation
                $value = json_encode($value);
              }
              if ($value === null) {
                $escapedValue = 'NULL';
              } else {
                // Escape special characters in non-null values
                $escapedValue = $pdo->quote($value);
              }
              $row[$column] = $escapedValue;
            }
            $sqlDumpContent .= "INSERT INTO `$table` VALUES (" . implode(", ", $row) . ");\n";
          }
          $sqlDumpContent .= "\n";
        }
      }
    }

    // Write the SQL dump content to a file
    file_put_contents($sqlFilePath, $sqlDumpContent);

    // Compress the SQL file with gzip
    $command = sprintf('gzip -c %s > %s', escapeshellarg($sqlFilePath), escapeshellarg($gzipFilePath));
    exec($command, $output, $result);

    if ($result !== 0) {
      throw new Exception("Failed to compress SQL dump file.");
    }

    // Remove the original SQL file
    if (!unlink($sqlFilePath)) {
      throw new Exception("Failed to delete original SQL file: $sqlFilePath");
    }

    // Connect to FTP server
    $ftpConn = ftp_connect($ftpServer);
    if (!$ftpConn) {
      throw new Exception("Failed to connect to FTP server: $ftpServer");
    }

    // Login to FTP
    $ftpLogin = ftp_login($ftpConn, $ftpUsername, $ftpPassword);
    if (!$ftpLogin) {
      throw new Exception("FTP login failed for server: $ftpServer");
    }

    // Upload GZIP file to FTP server
    if (!ftp_put($ftpConn, $fileName . '.sql.gz', $gzipFilePath, FTP_BINARY)) {
      throw new Exception("Failed to upload GZIP file to FTP server: $ftpServer");
    }

    // Close FTP connection
    ftp_close($ftpConn);

    // Remove the local GZIP file
    if (!unlink($gzipFilePath)) {
      throw new Exception("Failed to delete created Gzip file: $gzipFilePath");
    }

    // Log success message
    $successLogMessage = "Database backup '{$fileName}.sql.gz' successfully created and uploaded to server: {$ftpServer}";
    file_put_contents($logFilePath, date('Y-m-d H:i:s') . ' - ' . $successLogMessage . PHP_EOL, FILE_APPEND);
  } catch (Exception $e) {
    $errorLogMessage = "Error: " . $e->getMessage();
    file_put_contents($logFilePath, date('Y-m-d H:i:s') . ' - ' . $errorLogMessage . PHP_EOL, FILE_APPEND);
    echo $errorLogMessage;
  }
  
?>