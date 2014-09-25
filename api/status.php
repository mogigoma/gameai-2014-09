<?php

$method = "status";
$format = [
  "session" => "string"
];

require_once("shared.php");

$stat = [
  "game" => null,
  "round" => null,
  "your-turn" => null,
  "opponent" => null,
  "opponent-bid" => null,
  "opponent-previous-card" => null,
  "opponent-current-card" => null,
  "you-won-previous-round" => null
];


function opponent_card($row)
{
  global $bot;

  if ($row["r_bot1"] === $bot["id"])
    return $row["card2"];

  return $row["card1"];
}

// If the bot isn't in a game right now, send the default response made mostly
// of nulls.
if (is_null($bot["game"]))
  success($stat);

// Otherwise, start working on pulling together the status.
$stat["game"] = (int)$bot["game"];

// Get the basics from the game table.
$res = pg_query_params(
  'SELECT
     r.round,

     g.bot1  AS g_bot1,
     g.bot2  AS g_bot2,
     g.bid1  AS bid1,
     g.bid2  AS bid2,

     r.bot1  AS r_bot1,
     r.bot2  AS r_bot2,
     r.card1 AS card1,
     r.card2 AS card2
   FROM
     games AS g JOIN rounds AS r
   ON
     g.id=r.game AND (g.round=r.round OR g.round-1=r.round)
   WHERE
     g.id=$1
   ORDER BY
     r.round DESC;',
  [$bot["game"]]
);
if ($res === FALSE || pg_affected_rows($res) === 0)
  panic("The 'game' attached to your session, '{$bot['game']}', could not be crossreferenced against the 'rounds' table.");
$rows = pg_fetch_all($res);
pg_free_result($res);

// First handle the current round.
$stat["round"] = (int)$rows[0]["round"];
$stat["opponent-current-card"] = opponent_card($rows[0]);

if ($rows[0]["g_bot1"] === $bot["id"])
{
  $stat["opponent"] = (int)$rows[0]["g_bot2"];
  // Only reveal the bid once you have bid.
  if (!is_null($rows[0]["bid1"]))
    $stat["opponent-bid"] = (int)$rows[0]["bid2"];
}
else
{
  $stat["opponent"] = (int)$rows[0]["g_bot1"];
  // Only reveal the bid once you have bid.
  if (!is_null($rows[0]["bid2"]))
    $stat["opponent-bid"] = (int)$rows[0]["bid1"];
}

$stat["your-turn"] = (
  // It's your turn if you lead the round and haven't played yet...
  ($rows[0]["r_bot1"] === $bot["id"] && is_null($rows[0]["card1"])) ||
  // Or you don't lead the round and your opponent has played.
  ($rows[0]["r_bot1"] !== $bot["id"] && !is_null($rows[0]["card1"]))
);

// Next handle the previous round.
if (sizeof($rows) > 1)
{
  // If we get to go first in this round, we won the last one.
  $stat["you-won-previous-round"] = ($rows[0]["r_bot1"] === $bot["id"]);

  $stat["opponent-previous-card"] = opponent_card($rows[1]);
}

// Return successfully.
reset_timeout("session");
success($stat);

?>
