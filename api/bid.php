<?php

$method = "bid";
$format = [
  "session" => "string",
  "bid" => "integer"
];

require_once("shared.php");

// Validate the bid's range.
if ($req["bid"] < 1 || $req["bid"] > 13)
  failure("Bids are required to be between 1 and 13 inclusive, but you bid '{$req["bid"]}'.");

// Check that the bot is actually in a game right now.
if (is_null($bot["game"]))
  failure("You are not currently playing any game, note that you only have $timeout_bid seconds to bid once a game starts.");

// Check that we haven't already bid in this game.
$res = pg_query_params('SELECT bot1, bot2, bid1, bid2 FROM games WHERE id=$1;', [$bot["game"]]);
if ($res === FALSE)
  failure("Failed to query the database to check whether you have bid in this game.");
if (pg_affected_rows($res) !== 1)
  failure("The 'game' attached to your session, '{$bot['game']}', was not found.");
$row = pg_fetch_assoc($res);
pg_free_result($res);

// Check which player the bot is in this game.
if ($row["bot1"] === $bot["id"])
  $col = "bid1";
elseif ($row["bot2"] === $bot["id"])
  $col = "bid2";
else
  failure("The 'game' attached to your session, '{$bot['game']}', does not have you recorded as a player. Contact Mak about this error.");

// Check if a bid has already been made.
if (!is_null($row[$col]))
  failure("You have already bid '{$row[$col]}' in this game, and changing your bid is not permitted.");

// Register our bid.
$res = pg_query_params('UPDATE games SET ' . $col . '=$1 WHERE id=$2;', [$req["bid"], $bot["game"]]);
if ($res === FALSE || pg_affected_rows($res) !== 1)
  failure("Failed to update the database to add your bid to this game.");
pg_free_result($res);

// Return successfully.
reset_timeout("game");
reset_timeout("session");
success([]);

?>
