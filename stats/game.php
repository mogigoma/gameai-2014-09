<?php

require_once("shared.php");

# Require an ID to work with.
$id = $_GET["id"];

if (is_null($id))
  failure("No ID was given.");

# Ensure that the ID looks like an integer.
if (preg_match("/^\d+$/", $id) !== 1)
  failure("The ID you gave is not composed of decimal digits.");

# Ensure that the ID is in the valid range.
$id = (int)$id;
if ($id < 0 || $id > 999999999)
  failure("The ID you provided is outside of the expected range.");

// If this game is attached to any bot then it is still in progress,
// so watch for that in the SQL query.
$res = pg_query_params(
  'SELECT g.*, (select name from bots where id=g.bot1) AS name1, (select name from bots where id=g.bot2) AS name2 FROM games AS g WHERE g.id=$1 AND NOT EXISTS (SELECT b.game FROM bots AS b WHERE g.id=b.game);',
  [$id]
);
if ($res === FALSE || pg_affected_rows($res) === 0)
  failure("Game $id is either in progress or does not exist. If you suspect this game is in progress, wait a minute and then refresh, most games are quite short!");
$game = pg_fetch_assoc($res);
pg_free_result($res);

// Parse the cards the bots were dealt into an array.
$game["hand1"] = explode(",", substr($game["hand1"], 1, -1));
$game["hand2"] = explode(",", substr($game["hand2"], 1, -1));

// Convert the keys to integers where appropriate.
$keys = ["bot1", "bot2", "bid1", "bid2", "score1", "score2", "round", "tricks1", "tricks2", "forfeited_by"];
foreach ($keys as $key)
{
  if (!is_null($game[$key]))
    $game[$key] = (int)$game[$key];
}

// Add in the information on each round.
$res = pg_query_params(
  'SELECT r.round, (select name from bots where id=r.bot1) AS name1, (select name from bots where id=r.bot2) AS name2, r.card1, r.card2 FROM rounds AS r WHERE r.game=$1;',
  [$id]
);
if ($res === FALSE || pg_affected_rows($res) === 0)
  failure("Failed to fetch rounds for game $id.");
$rows = pg_fetch_all($res);
if ($rows === FALSE)
  $rows = [];
pg_free_result($res);

// Fill a template.
$html = file_get_contents("game.html");
$html = str_replace("{{game-id}}", $game["id"], $html);
$html = str_replace("{{bot1}}", $game["bot1"], $html);
$html = str_replace("{{bot2}}", $game["bot2"], $html);
$html = str_replace("{{name1}}", $game["name1"], $html);
$html = str_replace("{{name2}}", $game["name2"], $html);

$html = str_replace("{{bid1}}", $game["bid1"], $html);
$html = str_replace("{{bid2}}", $game["bid2"], $html);
$html = str_replace("{{hand1}}", implode(", ", $game["hand1"]), $html);
$html = str_replace("{{hand2}}", implode(", ", $game["hand2"]), $html);
$html = str_replace("{{tricks1}}", $game["tricks1"], $html);
$html = str_replace("{{tricks2}}", $game["tricks2"], $html);
$html = str_replace("{{score1}}", $game["score1"], $html);
$html = str_replace("{{score2}}", $game["score2"], $html);

$rounds = "";
foreach ($rows as $round)
{
  $rounds .=
    "<tr>\n" .
    "  <td>{$round['round']}</td>\n" .
    "  <td><a href=\"/stats/bot/by-name/{$round['name1']}/\">{$round['name1']}</a></td>\n" .
    "  <td><a href=\"/stats/bot/by-name/{$round['name2']}/\">{$round['name2']}</a></td>\n" .
    "  <td>{$round['card1']}</td>\n" .
    "  <td>{$round['card2']}</td>\n" .
    "</tr>\n";
}
$html = str_replace("{{rounds}}", $rounds, $html);

$forfeit = "";
if (!is_null($game["forfeited_by"]))
{
  if ($game["forfeited_by"] === $game["bot1"])
    $forfeit = $game["name1"];
  else
    $forfeit = $game["name2"];

  $html = str_replace("{{forfeit}}", "<p class=\"alert alert-danger\">This game was forfeited by <a href=\"/stats/bot/by-name/$forfeit/\">{$forfeit}</a>, so it will not have all fields filled.</p>", $html);
}

$html = str_replace("{{forfeit}}", "", $html);

// Remove any unused replacements.
$html = preg_replace("/\{\{[-_a-z0-9]*\}\}/i", "", $html);

// Dump the filled-in template to the browser.
header("Content-type: text/html");
echo $html;

?>
