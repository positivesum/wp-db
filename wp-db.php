#!/usr/local/bin/php -q
<?php 
if ($_SERVER["argc"] > 4) {
    die("Too many parameters. Syntax: wp-db <import/backup/reset> <path to sql> [path to wp-config.php]\n");
}

if ($_SERVER["argc"] < 2) {
   die("Too few parameters. Syntax: wp-db <import/backup/reset> <path to sql> [path to wp-config.php]\n");
}

$command = $_SERVER["argv"][1];

switch ( $command ):
case('backup'):
case('import'):
    if ($_SERVER["argc"] < 3) {
        die("Too few parameters. Syntax: wp-db <import/backup/reset> <path to sql> [path to wp-config.php]\n");
    }
default:
endswitch;

if (array_key_exists(2, $_SERVER["argv"])) {
    $sql_path = $_SERVER["argv"][2];
} else {
    $sql_path = '';
}


$config_path = "";

if ($_SERVER["argc"] == 4) {
    $config_path = $_SERVER["argv"][3];
} else {
    // Find config_path in cwd or subdirectory

    $dirs = array(".");
    while (NULL !== ($dir = array_pop($dirs))) {
        if ($dirhandle = opendir($dir)) {
            while ($entry = readdir($dirhandle)) {
                if ($entry == "." || $entry == "..") {
                    continue; // Necessary to iterate properly
                }

                $path = "$dir/$entry";
                
                if ($entry == "wp-config.php") {
                    $config_path = $path;
                    $dirs[] = NULL;
                }
                elseif (is_dir($path)) {
                    $dirs[] = $path;
                }
            }
            closedir($dirhandle);
        }
    }
}

echo "Config path: " . $config_path . "\n";
echo "Command: " . $command . "\n";
$settings_created = false;

if (!file_exists(dirname($config_path) . "/wp-settings.php")) {
    // Create an empty wp-settings.php so the wp-config include does not fail
    $settings_created = touch(dirname($config_path) . "/wp-settings.php");
}

if (!file_exists($config_path)) {
    die("Cannot find specified configuration file: " . $config_path . "\n");
} 

# define WP_INSTALLING to prevent wp from aborting execution
define('WP_INSTALLING', 1);

try {
    @include $config_path;
}
catch (Exception $e) {
    echo 'Message: ' .$e->getMessage();
    die(1);
}

$db_host = explode(":", DB_HOST);
$db_port = 3306; // default mysql port
if (count($db_host) > 1) {
    $db_port = $db_host[1];
}
$db_host = $db_host[0];

// Okay, start the mysqldump
// Code swiped from php.net documentation

// Can't pass password in via stdin so create a temp file with the password
$passwd_file_path = tempnam(".", "passwd");
$passwd_file = fopen($passwd_file_path, "w");
fwrite($passwd_file,"[mysql]\npassword=". DB_PASSWORD ."\n");
fwrite($passwd_file,"[mysqldump]\npassword=" . DB_PASSWORD . "\n");
fwrite($passwd_file,"[mysqlimport]\npassword=" . DB_PASSWORD . "\n");
fclose($passwd_file);

function execute($cmd){
    $descriptorspec = array(
        0 => array("pipe", "r"),  // stdin is a pipe that the child will read from
        1 => array("pipe", "w"),  // stdout is a pipe that the child will write to
        2 => array("file", "/tmp/wp-db-stderr.txt", "a") // stderr is a file to write to
    );   
    $cwd = NULL;
    $env = NULL;
    $process = proc_open($cmd, $descriptorspec, $pipes, $cwd, $env);
    fclose($pipes[0]);
    fclose($pipes[1]);
    return proc_close($process);	
}

/**
 * Set specified user's password to equal the user's username.
 * If user does not exist, create it.
 * @param  $user
 * @return void
 */
function set_user_password($user, $role) {
    if ( username_exists($user) ) {
        $uObj = get_userdatabylogin($user);
        wp_update_user(array('ID'=>$uObj->ID, 'user_pass'=>$user, 'user_email'=>"dev+$user@localhost", 'role'=>$role));
    } else {
        wp_insert_user(array('user_login'=>$user, 'user_pass'=>$user, 'user_email'=>"dev+$user@localhost", 'role'=>$role));
    }
}

switch (strtolower($command)) :
case('backup'):
// $cwd is left null so it will pick the actual current working dir
    $cmd = sprintf('mysqldump --defaults-extra-file=%s -u %s --host=%s --port=%s > %s', $passwd_file_path, DB_USER, $db_host, $db_port, DB_NAME, $sql_path);
    break;
case('import'):
    $cmd = sprintf('mysql --defaults-extra-file=%s -u %s --host=%s --port=%s %s < %s', $passwd_file_path, DB_USER, $db_host, $db_port, DB_NAME, $sql_path);
    break;
case('reset'):
    require_once(ABSPATH . WPINC . '/registration.php');
    set_user_password('admin', 'administrator');
    set_user_password('editor', 'editor');
    break;
default:
    echo "Command " . $command . " not implemented.\n";
endswitch;

if ( isset($cmd) ) $return_code = execute($cmd);

if ($settings_created) {
    unlink(dirname($config_path) . "/wp-settings.php");
}

unlink($passwd_file_path);

?>
