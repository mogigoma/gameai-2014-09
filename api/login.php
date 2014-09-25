<?php

$method = "login";
$format = [
  "name" => "string",
  "password" => "string"
];

require_once("shared.php");

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

// Retrieve the password hash for the bot.
$res = pg_query_params('SELECT id,password FROM bots WHERE name=$1;', [$req["name"]]);
if ($res === FALSE)
  failure("Failed to query the database for the existence of your name.");
if (pg_affected_rows($res) !== 1)
  failure("The 'name' you provided, '{$req['name']}', has not been registered.");
$row = pg_fetch_assoc($res);
$id = (int)$row["id"];
$pass = $row["password"];
pg_free_result($res);

// Validate the password from the request against the database.
if (!password_verify($req["password"], $pass))
  failure("The 'password' you provided did not match the one given during registration.");

// Generate a shoddy but workable session identifier.
$sess = md5($req["name"] . openssl_random_pseudo_bytes(128));

// Record the session in the database.
$res = pg_query_params('UPDATE bots SET session=$1, session_timeout=now()+\'30 minutes\'::interval WHERE name=$2;', [$sess, $req["name"]]);
if ($res === FALSE || pg_affected_rows($res) !== 1)
  failure("Failed to record your session in the database.");
pg_free_result($res);

// Extract the full session information from the database.
$bot = get_session($sess);

// Be sure that we don't have an active game.
forfeit();

// Return successfully.
reset_timeout("session");
success(["session" => $sess, "id" => $id]);

?>
