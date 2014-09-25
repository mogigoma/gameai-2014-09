<?php

$method = "register";
$format = [
  "name" => "string",
  "password" => "string"
];

require_once("shared.php");

//###############################################################################
// TRANSACTION
//###############################################################################

// Confirm that the name is acceptable length.
$len = strlen($req["name"]);
if ($len < 1 || $len > 40)
  failure("The 'name' must be between 1 and 40 characters, inclusive, but a name of length $len was given.");

// Confirm that the password is acceptable length.
$len = strlen($req["password"]);
if ($len < 1 || $len > 255)
  failure("The 'password' must be between 1 and 255 characters, inclusive, but a name of length $len was given.");

// Confirm that the name contains acceptable characters.
if (preg_match("/[^-_a-z0-9]/i", $req["name"]))
  failure("The 'name' must be composed of only letters, numbers, hyphens, and underscores.");

// Check that the name is available.
$res = pg_query_params('SELECT name FROM bots WHERE name=$1 LIMIT 1;', [$req["name"]]);
if ($res === FALSE)
  failure("Failed to query the database for the existence of your name.");
if (pg_affected_rows($res) !== 0)
  failure("The 'name' you requested, '{$req['name']}', has already been registered.");
pg_free_result($res);

// Hash the password.
$pass = password_hash($req["password"], PASSWORD_DEFAULT);

// Register the bot.
$res = pg_query_params('INSERT into bots (name, password) values ($1, $2) RETURNING id;', [$req["name"], $pass]);
if ($res === FALSE || pg_affected_rows($res) !== 1)
  failure("Failed to register your bot into the database.");
$row = pg_fetch_assoc($res);
pg_free_result($res);

// Return successfully.
success(["id" => (int)$row["id"]]);

?>
