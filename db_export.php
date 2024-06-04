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

      // Get table structure
      $structureStmt = $pdo->query("SHOW CREATE TABLE `$table`");
      $structure = $structureStmt->fetch(PDO::FETCH_ASSOC);
      $sqlDumpContent .= str_replace(" NOT NULL DEFAULT '0000-00-00 00:00:00' ", " NULL DEFAULT NULL ", $structure['Create Table']) . ";\n\n";

      // Check if the table should exclude records
      if (!in_array($table, $excludeRecordsFor)) {
        // Get table data
        $condition = isset($GetDataByConditiontables[$table]) ? " WHERE {$GetDataByConditiontables[$table]}" : "";
        $stmt = $pdo->query("SELECT * FROM `$table`" . $condition);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($rows)) {
          $sqlDumpContent .= "-- Dump for table $table\n";
          foreach ($rows as $row) {
            if( isset($row['updated_on']) && $row['updated_on'] == '0000-00-00 00:00:00' ){
              $row['updated_on'] = NULL;
            }
            // Generate INSERT statement for each row
            $values = array_map(function ($value) use ($pdo) {
              return $value === null ? 'NULL' : $pdo->quote($value);
            }, $row);
            $sqlDumpContent .= "INSERT INTO `$table` VALUES (" . implode(", ", $values) . ");\n";
          }
          $sqlDumpContent .= "\n";
        }
      }
    }

    // Write the SQL dump content to a file
    file_put_contents($sqlFilePath, $sqlDumpContent);

    // Log success message
    logMessage("Database backup '{$fileName}.sql' successfully created: $sqlFilePath");

    // Compress the SQL file with gzip
    $gzipFilePath = $exportTempFolderPath . $fileName . '.sql.gz';
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

    // Remove the local GZIP file
    if (!unlink($gzipFilePath)) {
      throw new Exception("Failed to delete created Gzip file: $gzipFilePath");
    }

    // Get current directory on FTP server
    $defaultPath = ftp_pwd($ftpConn);
    
    // Function to sort and limit files
    $sortAndLimitFiles = function (&$files, $limit) use ($ftpConn) {
      usort($files, function ($a, $b) use ($ftpConn) {
        return ftp_mdtm($ftpConn, $a) > ftp_mdtm($ftpConn, $b) ? -1 : 1;
      });
      foreach (array_slice($files, $limit) as $file) {
        ftp_delete($ftpConn, $file);
      }
    };

    // Get file list from FTP server
    $fileList = ftp_nlist($ftpConn, '.');
    if (!empty($fileList)) {
      // Separate daily and weekly backups
      $dailyFiles = $weeklyFiles = [];
      foreach ($fileList as $file) {
        if (strpos($file, 'wf_db_daily_') !== false) {
          $dailyFiles[] = $file;
        } elseif (strpos($file, 'wf_db_weekly_') !== false) {
          $weeklyFiles[] = $file;
        }
      }

      // Keep only the latest four daily and weekly files
      $sortAndLimitFiles($dailyFiles, $retainMaxNoOfBackups);
      $sortAndLimitFiles($weeklyFiles, $retainMaxNoOfBackups);
    } else {
      $errorLogMessage = "Failed to get file list from FTP server, unable to delete older backups";
      logMessage($errorLogMessage);
    }
    
    // Close FTP connection
    ftp_close($ftpConn);

    // Log success message
    $successLogMessage = "Database backup '{$fileName}.sql.gz' successfully created and uploaded to folder: {$defaultPath}, server: {$ftpServer}";
    logMessage($successLogMessage);
  } catch (Exception $e) {
    $errorLogMessage = "Error: " . $e->getMessage();
    logMessage($errorLogMessage);
    echo $errorLogMessage;
  }

?>