<?php

// #############################################################################
// # Settings
// #############################################################################

$db_database = "game";
$db_username = "timekeeper";
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
$db = pg_connect("user=$db_username password=$db_password dbname=game");
if ($db === FALSE)
  fail("Failed to connect to the database.");
info("Connected to the database.");

// #############################################################################
// # Loop
// #############################################################################

while (TRUE)
{
  // Find all bots that have timed-out on their games.
  pg_query("BEGIN;");
  $res2 = pg_query(
    'SELECT id, game
     FROM bots
     WHERE game IS NOT NULL AND EXTRACT(EPOCH FROM (now() - game_timeout)) >= 0;'
  );
  if ($res2 === FALSE)
    fail("Failed to query the database to for the bots that have timed out.");

  // Time out each bot appropriately.
  while (TRUE)
  {
    // Get the next row from the query.
    $bot = pg_fetch_assoc($res2);
    if ($bot === FALSE)
      break;
    info("Forfeiting game {$bot['game']} for bot {$bot['id']}...");

    // Forfeit the game that this bot is currently playing, starting by finding
    // out who the bot's opponent is.
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

    // Determine who to forfeit the game on behalf of. If someone else has
    // already taken responsibility, then null it.
    if (is_null($row["forfeited_by"]))
      $responsible = $bot["id"];
    else
      $responsible = NULL;

    // Release both the bot and its opponent from the game.
    $res = pg_query_params('UPDATE bots SET game=NULL, game_timeout=NULL, cards=NULL WHERE id=$1 OR id=$2;', [$bot["id"], $opponent]);
    if ($res === FALSE || pg_affected_rows($res) !== 2)
      fail("Failed to update the database to release bots {$bot['id']} and $opponent from game {$bot['game']}.");
    pg_free_result($res);

    // Forfeit the game on behalf of the bot.
    $res = pg_query_params('UPDATE games SET forfeited_by=$1, finished=now() WHERE id=$2;', [$responsible, $bot["game"]]);
    if ($res === FALSE || pg_affected_rows($res) !== 1)
      fail("Failed to forfeit game #{$bot['game']} that {$bot['id']} was playing.");
    pg_free_result($res);

    info("Successfully forfeited game.");
  }

  pg_free_result($res2);
  pg_query("COMMIT;");

  sleep(1);
}

?>
