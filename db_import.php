<?php

  require_once "configs.php";
  // Define log file path
  $logFilePath = 'import_db_log.txt';

  try {
    // Create the backup directory if it doesn't exist
    if (!file_exists($downloadTempFilesPath) && !mkdir($downloadTempFilesPath, 0777, true)) {
      throw new Exception("Failed to create download directory");
    }

    // Create the extract directory if it doesn't exist
    if (!file_exists($tempExtractTo) && !mkdir($tempExtractTo, 0777, true)) {
      throw new Exception("Failed to create extract directory");
    }
    
    //Connect to FTP server
    $ftpConn = ftp_connect($ftpServer);
    if (!$ftpConn) {
      throw new Exception("Failed to connect to FTP server");
    }

    // Login to FTP
    $ftpLogin = ftp_login($ftpConn, $ftpUsername, $ftpPassword);
    if (!$ftpLogin) {
      throw new Exception("FTP login failed");
    }

    // Check FTP passive mode
    ftp_pasv($ftpConn, true);

    // Determine the backup type
    $backupType = isset($_GET['backup_type']) && $_GET['backup_type'] === 'daily' ? 'daily' : $backupType;
    $backupFilenamePrefix = $backupType === 'daily' ? 'wf_db_daily_' : 'wf_db_weekly_';

    // Get the latest backup file based on the backup type
    $files = ftp_nlist($ftpConn, '.');

    if ($files === false) {
      throw new Exception("Unable to get file list from FTP.");
    }

    $sortAndLimitFiles = function (&$files, $limit) use ($ftpConn) {
      usort($files, function ($a, $b) use ($ftpConn) {
        return ftp_mdtm($ftpConn, $a) > ftp_mdtm($ftpConn, $b) ? -1 : 1;
      });
      return array_slice($files, 0, $limit);
    };

    $dailyBackupFiles = [];
    foreach ($files as $file) {
      if (pathinfo($file, PATHINFO_EXTENSION) !== 'gz') {
        continue;
      }

      if (strpos($file, $backupFilenamePrefix) !== false) {
        $dailyBackupFiles[] = $file;
      }
    }

    // If no backup file found, log and exit
    if (empty($dailyBackupFiles)) {
      ftp_close($ftpConn);
      throw new Exception("No backup file found for $backupType");
    }

    // Sort files and get the latest one
    $latestBackupFile = $sortAndLimitFiles($dailyBackupFiles, 1)[0];

    // Specify the local path for the downloaded file
    $localFile = $downloadTempFilesPath . basename($latestBackupFile);

    // Download the latest backup file from FTP server
    if (ftp_get($ftpConn, $localFile, $latestBackupFile, FTP_BINARY)) {
      logMessage("Downloaded latest $backupType backup file: $latestBackupFile");
    } else {
      ftp_close($ftpConn);
      throw new Exception("Failed to download latest $backupType backup file: $latestBackupFile");
    }

    // Close FTP connection
    ftp_close($ftpConn);

    // Import DB connection
    $pdo = new PDO("mysql:host={$importToDB['host']};dbname={$importToDB['database']}", $importToDB['username'], $importToDB['password']);

    // Decompress the Gzip file
    $sqlFileName = decompressGzipFile($localFile);

    $sql = file_get_contents($sqlFileName);
    $statements = array_filter(array_map('trim', explode(';', $sql)));

    $multiRecords = [];
    $existsTable = [];
    foreach ($statements as $statement) {
      $statement = trim($statement);
      if (stripos($statement, 'CREATE TABLE') !== false) {
        preg_match('/CREATE TABLE `?(\w+)`?/', $statement, $matches);
        $tableName = $matches[1];

        if (empty($existsTable[$tableName])) {
          if (!tableExists($pdo, $tableName)) {
            $pdo->exec($statement);
          }
        } else {
          $pdo->query("TRUNCATE TABLE `{$tableName}`");
        }
        $existsTable[$tableName] = $tableName;
      } elseif (stripos($statement, 'INSERT INTO') !== false) {
        preg_match('/INSERT INTO `?(\w+)`?/', $statement, $matches);
        $tableName = $matches[1];

        if (empty($existsTable[$tableName])) {
          if (!tableExists($pdo, $tableName)) {
            logMessage("{$tableName} does not exist in the database.");
            throw new Exception("{$tableName} does not exist in the database.");
          }
        }

        $statement = utf8EncodeInsertStatement($statement);
        list($key, $value) = explode("VALUES", $statement);
        $multiRecords[$tableName]['values'][] = $value;
      }
    }

    foreach ($multiRecords as $tableName => $info) {
      $pdo->exec("INSERT INTO `{$tableName}` VALUES " . implode(" , ", $info['values']));
    }

    logMessage("Database import completed");
    unlink($localFile);
  } catch (Exception $e) {
    logMessage("Error: " . $e->getMessage());
    echo "Error: " . $e->getMessage();
  }

  function utf8EncodeInsertStatement($statement)
  {
    return preg_replace_callback(
      "/'((?:[^'\\\\]|\\\\.)*)'/",
      function ($match) {
        return "'" . utf8_encode($match[1]) . "'";
      },
      $statement
    );
  }

  function decompressGzipFile($fileName)
  {
    $out_file_name = str_replace('.gz', '', $fileName);
    $lines = gzfile($fileName);
    if ($lines === false) {
      throw new Exception("Error reading lines from the compressed file: $fileName.");
    }

    $out_file = fopen($out_file_name, 'wb');
    if (!$out_file) {
      throw new Exception("Could not open the output file: $out_file_name.");
    }
    foreach ($lines as $line) {
      if (fwrite($out_file, $line) === false) {
        fclose($out_file);
        throw new Exception("Error writing to the output file: $out_file_name.");
      }
    }

    fclose($out_file);
    return $out_file_name;
  }

  function tableExists($pdo, $tableName)
  {
    try {
      $result = $pdo->query("SELECT 1 FROM `{$tableName}` LIMIT 1");
    } catch (Exception $e) {
      return false;
    }
    return $result !== false;
  }

?>