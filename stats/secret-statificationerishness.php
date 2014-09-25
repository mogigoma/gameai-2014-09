<?php

// #############################################################################
// # Settings
// #############################################################################

$db_database = "game";
$db_username = "game";
$db_password = "[REDACTED]";

// #############################################################################
// # Functions
// #############################################################################
function fail($msg)
{
  pg_query("ROLLBACK;");
  echo "!! " . $msg . "\n";
  exit();
}

function info($msg)
{
  echo "** " . $msg . "\n";
}

// #############################################################################
// # Setup
// #############################################################################

// Open a connection to the database.
$db = pg_connect("user=$db_username password=$db_password");
if ($db === FALSE)
  fail("Failed to connect to the database.");
info("Connected to the database.");

// #############################################################################
// # Loop
// #############################################################################

while (TRUE)
{
	// ###########################################################################
	// # Do the rounds per minute
	// ###########################################################################
  $stats = [];
	for ($i = 59; $i >= 0; $i--)
	{
		// Yup, totally.
    $upper = $i;
		$lower = $upper + 1;

		// Yup, 60 queries per run of this daemon.
		$res = pg_query(
			"SELECT COUNT(1)
			 FROM rounds
			 WHERE created >  now() - interval '$lower minutes'
			   AND created <= now() - interval '$upper minutes';
			"
		);
		if ($res === FALSE)
			fail("Failed to query the database to for the stats.");
		$rows = pg_fetch_all($res);
		pg_free_result($res);


    array_push($stats, intval($rows[0]["count"]));
	}

	$json = json_encode($stats);
	echo($json . "\n");

  file_put_contents("/www/stats/bots.json", $json);
	info("Wrote to JSON file, sleeping now.");


  sleep(30);
}

?>
