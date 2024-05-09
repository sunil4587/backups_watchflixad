<?php

  require_once "configs.php";
  use Coderatio\SimpleBackup\SimpleBackup;

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
      // Connect to FTP server
    $ftpConn = ftp_connect($ftpServer);
    if (!$ftpConn) {
      throw new Exception("Failed to connect to FTP server");
    }

    // Login to FTP
    $ftpLogin = ftp_login($ftpConn, $ftpUsername, $ftpPassword);
    if (!$ftpLogin) {
      throw new Exception("FTP login failed");
    }

    // Determine the backup type
    $backupType = isset($_GET['backup_type']) && $_GET['backup_type'] !== "processed" ? $_GET['backup_type'] : $backupType;
    $backupFilenamePrefix = ($backupType === "processed_data") ? "wf_db_processed_data_" : "wf_db_raw_{$backupType}_";

    // Get the latest backup file based on the backup type
    $latestBackupFile = null;
    $latestBackupTimestamp = 0;
    $files = ftp_nlist($ftpConn, '.');

    $sortAndLimitFiles = function (&$files, $limit) use ($ftpConn) {
      usort($files, function ($a, $b) use ($ftpConn) {
        return ftp_mdtm($ftpConn, $a) > ftp_mdtm($ftpConn, $b) ? -1 : 1;
      });
      return array_slice($files, 0, $limit);
    };

    $pushedBackupFiles = [];
    foreach ($files as $file) {
      if (pathinfo($file, PATHINFO_EXTENSION) !== 'zip') {
        continue;
      }

      if (strpos($file, $backupFilenamePrefix) !== false) {
        $pushedBackupFiles[] = $file;
      }
    }

    // If no backup file found, log and exit
    if (!$pushedBackupFiles) {
      ftp_close($ftpConn);
      throw new Exception("No backup file found for $backupType");
    }

    // Sort files and get the latest one
    $latestBackupFile = $sortAndLimitFiles($pushedBackupFiles, 1)[0];

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

    // Unzip and import the downloaded backup file
    $zip = new ZipArchive();
    if ($zip->open($localFile) === TRUE) {
      // Extract the zip file
      $extractedPath = $tempExtractTo . pathinfo($localFile, PATHINFO_FILENAME) . '/';
      if ($zip->extractTo($extractedPath)) {
        logMessage("Extracted file: $localFile \n");

        // Check for SQL files in the extracted folder
        $sqlFiles = glob($extractedPath . '*.sql');
        if (!empty($sqlFiles)) {
          $sqlFile = reset($sqlFiles); // Get the first SQL file found
          logMessage("SQL file found: $sqlFile");

          // Import data to database using SimpleBackup
          $simpleBackup = SimpleBackup::setDatabase($importToDB)->importFrom($sqlFile);
          $response = $simpleBackup->getResponse();
          if ($response->status === 'success') {
            logMessage("Database imported successfully from: $sqlFile");
          } else {
            logMessage("Failed to import database from: $sqlFile");
          }
        } else {
          logMessage("No SQL files found in the extracted folder: $extractedPath");
        }

        // Delete the zip file and extracted contents
        unlink($localFile);
        array_map('unlink', glob("$extractedPath/*"));
        rmdir($extractedPath);

        logMessage("Deleted file and extracted contents: $localFile");
      } else {
        logMessage("Failed to extract file: $localFile");
      }
      $zip->close();
    } else {
      logMessage("Failed to open zip file: $localFile");
    }
  } catch (Exception $e) {
    logMessage("Error: " . $e->getMessage());
    echo "Error: " . $e->getMessage();
  }

