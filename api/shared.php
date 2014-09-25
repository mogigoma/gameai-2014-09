<?php

//###############################################################################
// Settings
//###############################################################################

// Database
$db_database = "game";
$db_username = "game";
$db_password = "[REDACTED]";

// Timeouts
$timeouts = [
  "session" => 5,
  "game" => 2
];

//###############################################################################
// Functions
//###############################################################################

function success($arr)
{
  pg_query("COMMIT;");

  $arr["result"] = "success";
  echo json_encode($arr);

  exit();
}

function retry($msg)
{
  echo json_encode([
    "result" => "retry",
    "reason" => $msg
  ]);

  exit();
}

function failure($msg)
{
  global $db;

  if (isset($db))
    pg_query("ROLLBACK;");

  echo json_encode([
    "result" => "failure",
    "reason" => $msg
  ]);

  exit();
}

function panic($msg)
{
  failure("Something is *seriously* wrong, let Mak know: " . $msg);
}

function forfeit()
{
  global $bot;

  // If this bot is not in a game, there's nothing to do.
  if (is_null($bot["game"]))
    return;

  // Otherwise, forfeit the game that this bot is currently playing, starting by
  // finding out who our opponent is.
  $res = pg_query_params('SELECT bot1, bot2, forfeited_by FROM games WHERE id=$1;', [$bot["game"]]);
  if ($res === FALSE || pg_affected_rows($res) !== 1)
    fail("Couldn't find our opponent in game #{$bot['game']}.");
  $row = pg_fetch_assoc($res);
  pg_free_result($res);

  // Determine which of the bots in this game was our opponent, and which was us.
  if ($row["bot1"] === $bot["id"])
    $opponent = $row["bot2"];
  else
    $opponent = $row["bot1"];

  // Release ourselves and our opponent from the game.
  $res = pg_query_params('UPDATE bots SET game=NULL, game_timeout=NULL, cards=NULL WHERE id=$1 OR id=$2;', [$bot["id"], $opponent]);
  if ($res === FALSE || pg_affected_rows($res) !== 2)
    panic("Failed to update the database to release bots {$bot['id']} and $opponent from game {$bot['game']}.");
  pg_free_result($res);

  // Forfeit the game.
  $res = pg_query_params('UPDATE games SET forfeited_by=$1, finished=now() WHERE id=$2;', [$bot["id"], $bot["game"]]);
  if ($res === FALSE || pg_affected_rows($res) !== 1)
    panic("Failed to forfeit game #{$bot['game']} we're playing.");
  pg_free_result($res);
}

function reset_timeout($name)
{
  global $bot;
  global $timeouts;

  // Ignore NULL.
  if (is_null($name))
    return;

  // Update the requested timeout to a value in the future.
  $mins = $timeouts[$name];
  $res = pg_query_params('UPDATE bots SET ' . $name . '_timeout=now()+\'' . $mins . ' minutes\'::interval WHERE id=$1;', [$bot["id"]]);
  if ($res === FALSE || pg_affected_rows($res) !== 1)
    panic("Failed to update your $name timeout to $mins minute(s) from now.");
  pg_free_result($res);
}

function parse_card($card)
{
  return [
    $card[0],
    (int)substr($card, 1)
  ];
}

function card_cmp($card1, $card2)
{
  $card1 = parse_card($card1);
  $card2 = parse_card($card2);

  // The rules are:
  // 1) If the suits are the same, the highest value won.
  // 2) If the suits are different:
  // 2a) If the second suit is a trump, it won.
  // 2b) Otherwise it lost.
  if ($card1[0] === $card2[0])
  {
    if ($card1[1] > $card2[1])
      return 1;
    return 2;
  }
  elseif ($card2[0] === "S")
  {
    return 2;
  }
  else
  {
    return 1;
  }
}

function get_session($sess) {
  $res = pg_query_params(
    'SELECT
       id,
       name,
       password,
       session,
       EXTRACT(EPOCH FROM (now() - session_timeout)) AS session_timeout,
       game,
       EXTRACT(EPOCH FROM (now() - game_timeout)) AS game_timeout,
       cards
     FROM bots
     WHERE session=$1
     LIMIT 1;',
    [$sess]
  );
  if ($res === FALSE)
    panic("Failed to query the database for the existence of your session.");
  if (pg_affected_rows($res) !== 1)
    failure("The session you provided, '$sess', is not currently active.");
  $row = pg_fetch_assoc($res);
  pg_free_result($res);

  return $row;
}

//###############################################################################
// THIS SECTION EXECUTES UNCONDITIONALLY, NOT JUST DEFINITIONS
//###############################################################################

// Hide my shame at using PHP.
header_remove("X-Powered-By");

// Check if this file is being called directly.
if (is_null($method))
{
  header($_SERVER["SERVER_PROTOCOL"] . " 403 Forbidden", TRUE, 403);
  echo("This file may not be addressed directly.\n");
  exit();
}

// Print usage information if the request method is wrong.
if ($_SERVER["REQUEST_METHOD"] === "GET")
{
  header("Content-type: text/html");
  echo file_get_contents($method . ".html");
  exit();
}

// Error out if the request method is wacky.
if ($_SERVER["REQUEST_METHOD"] !== "POST")
{
  header($_SERVER["SERVER_PROTOCOL"] . " 501 Not Implemented", TRUE, 500);
  echo("Only GET and POST are supported.\n");
  exit();
}

// Anything from this point on is guaranteed to be encoded as JSON.
header("Content-type: application/json");

// Decode the JSON in the HTTP request body.
$req = file_get_contents("php://input");
if (is_null($req) || $req === "")
  failure("The HTTP request body was empty.");

// Decode the JSON from the HTTP request body.
$req = json_decode($req, TRUE);
if (is_null($req))
  failure("Unable to decode the JSON found in the HTTP request body.");

// Validate that the JSON looks like we expect for this method.
foreach ($format as $name => $type_wanted)
{
  // Confirm the key exists in the request.
  if (!array_key_exists($name, $req))
    failure("The key '$name' is missing from the JSON found in the HTTP request body.");

  // Confirm the key's type.
  $type_found = gettype($req[$name]);
  if ($type_found === $type_wanted)
    continue;

  // If the key type is an integer, Perl may have bungled the conversion, so
  // force it to a string, likely causing all sorts of unintended
  // consequences. FUCK PERL.
  if ($type_wanted === "integer")
  {
    $req[$name] = (int)$req[$name];
    continue;
  }

  failure("The key '$name' must be of type '$type_wanted', not '$type_found'.");
}

// From this point onward, we're going to need access to the database.
$db = pg_connect("user=$db_username password=$db_password");
if ($db === FALSE)
  panic("Failed to connect to the database.");

// Start a transaction, bitches love transactions.
$res = pg_query('BEGIN;');
if ($res === FALSE)
  panic("Failed to start a transaction.");
pg_free_result($res);

// If this method uses the session key, validate it.
if (array_key_exists("session", $format))
{
  // Validate our session.
  $bot = get_session($req["session"]);

  // Check whether the session has timed out.
  if ($bot["session_timeout"] >= 0)
  {
    forfeit();
    failure("The session you provided, '{$req['session']}', expired {$bot['session_timeout']} second(s) ago. Please log in again.");
  }
}

?>
