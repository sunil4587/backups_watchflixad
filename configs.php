<?php
  // Set time and memory limits
  ini_set('max_execution_time', '-1');
  set_time_limit(-1);
  ini_set('memory_limit', '20000M');
  
  // Enable error reporting if debugging is enabled
  if (isset($_GET["debug"])) {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
  }

  // Autoload vendor dependencies
  require 'vendor/autoload.php';

  // Database connection settings
  $exportFromDB = [
    'database' => 'watchfil_movie',
    'username' => 'watchfil_admin',
    'password' => '#ids@335#',
    'host' => 'localhost',
  ];

  $importToDB = [
    'watchfil_img_db_backup_test', // database
    'watchfil_admin', // username
    '#ids@335#', // password
    'localhost', // hostname
  ];

  // FTP connection settings
  $ftpServer = 'ftp.plumbr.pro';
  $ftpUsername = 'wf_img_backups@plumbr.pro';
  $ftpPassword = '#ids@335335#';
  $backupType = "processed_data";

  // Define directories
  $exportTempFolderPath = __DIR__ . '/wf_db_temp_backup/';
  $downloadTempFilesPath = __DIR__ . '/backup_files/';
  $tempExtractTo = $downloadTempFilesPath . '/extract_zip/';

  // Debugging function
  function debug($var){
    echo "<pre>";
      print_r($var);
    echo "</pre>";
  }

  // Log message function
  function logMessage($message){
    global $logFilePath;
    file_put_contents($logFilePath, date('Y-m-d H:i:s') . ' - ' . $message . PHP_EOL, FILE_APPEND);
  }
