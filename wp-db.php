#!/usr/bin/php -q
<? 
if ($_SERVER["argc"] > 4) {
    die("Too many parameters. Syntax: wp-db <import/backup> <path to sql> [path to wp-config.php]\n");
}

if ($_SERVER["argc"] < 3) {
    die("Too few parameters. Syntax: wp-db <import/backup> <path to sql> [path to wp-config.php]\n");
}

$command = $_SERVER["argv"][1];
$sql_path = $_SERVER["argv"][2];

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

include $config_path;

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
fwrite($passwd_file,"[mysqldump]\npassword=" . DB_PASSWORD . "\n");
fwrite($passwd_file,"[mysqlimport]\npassword=" . DB_PASSWORD . "\n");
fclose($passwd_file);

$descriptorspec = array(
    0 => array("pipe", "r"),  // stdin is a pipe that the child will read from
    1 => array("pipe", "w"),  // stdout is a pipe that the child will write to
    2 => array("file", "/tmp/wp-db-stderr.txt", "a") // stderr is a file to write to
    );

if (strtolower($command) == "backup") {
// $cwd is left null so it will pick the actual current working dir
    $cwd = NULL;
    $env = NULL;
    $process = proc_open("mysqldump --defaults-extra-file=" . $passwd_file_path . 
        " --databases " . DB_NAME . " -u" . DB_USER . " --host " . $db_host . 
        " --port " . $db_port . " > " . $sql_path, $descriptorspec, $pipes, $cwd, $env);
    fclose($pipes[0]);
    fclose($pipes[1]);
    $return_value = proc_close($process);
} elseif (strtolower($command) == "import") {
    $cwd = NULL;
    $env = NULL;
    $process = proc_open("mysqlimport --defaults-extra-file=" . $passwd_file_path . 
        " -u" . DB_USER . " --host " . $db_host . " --port " . $db_port . 
        " " . $sql_path, $descriptorspec, $pipes, $cwd, $env);
    fclose($pipes[0]);
    fclose($pipes[1]);
    $return_value = proc_close($process);
} else {
    echo "Command " . $command . " not implemented.\n";
}


if ($settings_created) {
    unlink(dirname($config_path) . "/wp-settings.php");
}

unlink($passwd_file_path);

?>
