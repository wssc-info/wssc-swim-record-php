<?php
/**
 * db_config.example.php — Template for database connection constants.
 *
 * Copy this file to api/db_config.php and fill in the correct values for
 * your environment.  db_config.php is gitignored so each server keeps its
 * own credentials without touching source control.
 */

define('DB_HOST', '127.0.0.1');   // e.g. 'localhost' or a remote host
define('DB_PORT', '3306');         // default MariaDB/MySQL port
define('DB_NAME', 'westside_records');
define('DB_USER', 'your_db_user');
define('DB_PASS', 'your_db_password');
