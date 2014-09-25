<?php

$method = "old-game";
$format = [
  "session" => "string",
  "game" => "integer"
];

require_once("shared.php");

// If this game is attached to any bot then it is still in progress,
// so watch for that in the SQL query.
$res = pg_query_params(
  'SELECT g.* from games AS g WHERE g.id=$1 AND NOT EXISTS (SELECT b.game FROM bots AS b WHERE g.id=b.game);',
  [$req["game"]]
);
if ($res === FALSE || pg_affected_rows($res) === 0)
  failure("The 'game' you requested the record for, '{$req['game']}', is either in progress or does not exist.");
$row = pg_fetch_assoc($res);
pg_free_result($res);

// This is redundant.
unset($row["id"]);

// Parse the cards the bots were dealt into an array.
$row["hand1"] = explode(",", substr($row["hand1"], 1, -1));
$row["hand2"] = explode(",", substr($row["hand2"], 1, -1));

// Convert the keys to integers where appropriate.
$keys = ["bot1", "bot2", "bid1", "bid2", "score1", "score2", "round", "tricks1", "tricks2", "forfeited_by"];
foreach ($keys as $key)
{
  if (!is_null($row[$key]))
    $row[$key] = (int)$row[$key];
}

// Return successfully.
reset_timeout("session");
success($row);

?>
