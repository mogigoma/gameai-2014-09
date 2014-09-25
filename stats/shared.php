<?php

################################################################################
# Settings
################################################################################

# Database
$db_database = "game";
$db_username = "game";
$db_password = "[REDACTED]";

################################################################################
# Functions
################################################################################

function failure($msg)
{
  header($_SERVER["SERVER_PROTOCOL"] . " 404 Not Found", true, 404);
  echo("$msg\n");
  exit();
}

################################################################################
# THIS SECTION EXECUTES UNCONDITIONALLY, NOT JUST DEFINITIONS
################################################################################

# Hide my shame at using PHP.
header_remove("X-Powered-By");

# From this point onward, we're going to need access to the database.
$db = pg_connect("user=$db_username password=$db_password");
if ($db === FALSE)
  failure("Failed to connect to the database.");

?>
