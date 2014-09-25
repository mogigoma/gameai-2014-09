<?php

$method = "new-game";
$format = [
  "session" => "string"
];

require_once("shared.php");

// End the current transaction, as listen/notify operate only after
// transactions are committed.
pg_query("COMMIT;");

function check_game()
{
  global $bot;

  // Check if we have now been assigned a game.
  $res = pg_query_params(
    'SELECT
       b.game,
       b.cards,

       g.bot1,
       g.bot2,
       g.bid1,
       g.bid2
     FROM
       bots AS b JOIN games AS g
     ON
       b.game=g.id
     WHERE
       b.id=$1;',
    [$bot["id"]]
  );
  if ($res === FALSE)
    failure("Failed to query the database to check whether you have been assigned a game.");
  if (pg_affected_rows($res) !== 1)
    return;
  $row = pg_fetch_assoc($res);
  pg_free_result($res);

  // If our bid is null, we can return successfully and not confuse the bot.
  if (
    ($row["bot1"] === $bot["id"] && !is_null($row["bid1"])) ||
    ($row["bot2"] === $bot["id"] && !is_null($row["bid2"]))
  ) failure("You have already started playing a game, #{$row['game']}, and must finish it before attempting to start a new game.");

  // Parse the cards the bot has been dealt into an array.
  $cards = explode(",", substr($row["cards"], 1, -1));

  // Determine our opponent.
  if ($row["bot1"] === $bot["id"])
    $opponent = (int)$row["bot2"];
  else
    $opponent = (int)$row["bot1"];

  // Return the game information to the client.
  pg_query("BEGIN;");
  reset_timeout("session");
  success(["cards" => $cards, "game" => (int)$row["game"], "opponent" => $opponent]);
}

// Check if we we've already been assigned a game.
check_game();

// Listen for a notification that we have been assigned an opponent.
// Note that this uses some dynamic data in the query, due to the
// limitations of PostgreSQL's listen syntax.
$res = pg_query("LISTEN bot_{$bot['id']};");
if ($res === FALSE)
  failure("Failed to listen for matchmaking events.");
pg_free_result($res);

// Try every second for thirty seconds as to be assigned an opponent.
for ($i = 0; $i < 30; $i++)
{
  // Add this bot to the matchmaking queue. Note that this uses some
  // dynamic data in the query, due to the limitations of PostgreSQL's
  // notify syntax.
  $res = pg_query("NOTIFY matchmaker, '{$bot['id']}';");
  if ($res === FALSE)
    failure("Failed to notify the matchmaker that this bot is ready for a new game.");
  pg_free_result($res);

  $res = pg_get_notify($db, PGSQL_ASSOC);
  if ($res !== FALSE)
    break;

  sleep(1);
}

// Check whether we're in a game, now that we're out of the loop.
check_game();

// If we haven't gotten a notification by now, fail encouragingly.
pg_query("BEGIN;");
reset_timeout("session");
retry("Failed to find an opponent to play against within 30 seconds, please try again.", "session");

?>
