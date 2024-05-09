<?php
  //include configs
  require_once "configs.php";
  // Import SimpleBackup class
  use Coderatio\SimpleBackup\SimpleBackup;

  try {
    // Define log file path
    $logFilePath = 'export_db_log.txt';

    // Create the backup directory if it doesn't exist
    if (!file_exists($exportTempFolderPath) && !mkdir($exportTempFolderPath, 0777, true)) {
      throw new Exception("Failed to create backup directory");
    }

    // Define table conditions and limits
    $GetDataByConditiontables = [
      'user' => 'type != 2',
    ];
    $tableRecordsLimit = [
      'addons' => 1,
      'archive_user' => 1,
      'ci_sessions' => 1,
      'cron_status' => 1,
      'iptv_cron_logs' => 1,
      'movie_screenshots' => 1,
      'referral_report' => 1,
      'track_users_data' => 1,
      'track_users_data_4_april_2023' => 1,
      'episode_screenshots' => 1,
      'movie_screenshots' => 1,
      'logs' => 1
    ];

    // Initialize SimpleBackup instance
    $simpleBackup = SimpleBackup::start()
                      ->setDatabase($exportFromDB)
                      ->setDbName($exportFromDB['database'])
                      ->setDbUser($exportFromDB['username'])
                      ->setDbPassword($exportFromDB['password']);

    // Include specific tables based on backup type
    if (isset($_GET['backup_type']) && $_GET['backup_type'] === 'daily') {
      $tablesToInclude = ['user','subscription','watched_history','invites','requested_movies'];
      $simpleBackup->includeOnly($tablesToInclude);
      $backupType = "daily";
    }else{
      $tableRecordsLimit = $tableRecordsLimit + [
        'watched_history' => 1
      ];
    }

    // Set file names and paths
    $fileName = "wf_db_{$backupType}_" . date('YmdHis');
    $sqlFilePath = $exportTempFolderPath . $fileName . '.sql';
    $zipFilePath = $exportTempFolderPath . $fileName . '.zip';

    // Export SQL data to a file
    $simpleBackup->setTableConditions($GetDataByConditiontables)
      ->setTableLimitsOn($tableRecordsLimit)->then()
      ->storeAfterExportTo($exportTempFolderPath, $fileName);

    // Create a ZIP archive and add the SQL file
    $zip = new ZipArchive();
    if ($zip->open($zipFilePath, ZipArchive::CREATE) !== true || !$zip->addFile($sqlFilePath, $fileName . '.sql')) {
      throw new Exception("Failed to create or add files to ZIP archive");
    }
    $zip->close();

    // Remove the original SQL file
    if (!unlink($sqlFilePath)) {
      throw new Exception("Failed to delete original SQL file");
    }

    // Connect to FTP server
    $ftpConn = ftp_connect($ftpServer);
    if (!$ftpConn || !ftp_login($ftpConn, $ftpUsername, $ftpPassword)) {
      throw new Exception("Failed to connect or login to FTP server");
    }

    // Upload ZIP file to FTP server
    if (!ftp_put($ftpConn, $fileName . '.zip', $zipFilePath, FTP_BINARY)) {
      throw new Exception("Failed to upload ZIP file to FTP server");
    }
    unlink($zipFilePath);

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
      file_put_contents($logFilePath, date('Y-m-d H:i:s') . ' - ' . $errorLogMessage . PHP_EOL, FILE_APPEND);
    }

    // Close FTP connection
    ftp_close($ftpConn);

    // Log success message
    $successLogMessage = "Database backup successfully created and uploaded to server: {$defaultPath}/{$fileName}.zip";
    file_put_contents($logFilePath, date('Y-m-d H:i:s') . ' - ' . $successLogMessage . PHP_EOL, FILE_APPEND);
  } catch (Exception $e) {
    logMessage("Error: " . $e->getMessage());
    echo "Error: " . $e->getMessage();
  }
