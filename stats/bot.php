<?php

require_once("shared.php");

// Require either an ID xor a name to work with.
if (!array_key_exists("id", $_GET) && !array_key_exists("name", $_GET))
  failure("No name or ID was given.");

if (array_key_exists("id", $_GET) && array_key_exists("name", $_GET))
  failure("Both name and ID were given, but they're mutually exclusive.");

// Validate and retrieve the bot's info based on the search criteria.
if (array_key_exists("id", $_GET))
{
  $id = $_GET["id"];

  // Ensure that the ID looks like an integer.
  if (preg_match("/^\d+$/", $id) !== 1)
    failure("The ID you gave is not composed of decimal digits.");

  // Ensure that the ID is in the valid range.
  $id = (int)$id;
  if ($id < 0 || $id > 999999999)
    failure("The ID you provided is outside of the expected range.");

  // Retrieve the bot's information from the database.
  $res = pg_query_params(
    'SELECT id, name, game, (select session_timeout > now() WHERE id=$1) AS online FROM bots WHERE id=$1;',
    [$id]
  );
}
else
{
  $name = $_GET["name"];

  // Ensure that the name looks like an bot's name.
  if (preg_match("/^[-_a-zA-Z0-9]+$/", $name) !== 1)
    failure("The name you gave contains invalid characters.");

  // Retrieve the bot's information from the database.
  $res = pg_query_params(
    'SELECT id, name, game, (select session_timeout > now() WHERE name=$1) AS online FROM bots WHERE name=$1;',
    [$name]
  );
}

// We should now have the user's information.
if ($res === FALSE || pg_affected_rows($res) !== 1)
  failure("Unable to find any bots matching your search criteria.");
$bot = pg_fetch_assoc($res);
pg_free_result($res);

// Find the ten people you play against the most.
$res = pg_query_params(
  'SELECT m.opponent AS id, (SELECT name FROM bots WHERE id=m.opponent) AS name, COUNT(*) AS games FROM (SELECT CASE WHEN bot1=$1 THEN bot2 ELSE bot1 END AS opponent FROM games WHERE bot1=$1 OR bot2=$1) AS m GROUP BY m.opponent ORDER BY games DESC LIMIT 10;',
  [$bot["id"]]
);
if ($res === FALSE)
  failure("Unable to query the database for the list of bots you play against most frequently.");
$rows = pg_fetch_all($res);
if ($rows === FALSE)
  $rows = [];
pg_free_result($res);

$rivals = "";
foreach ($rows as $rival)
{
  $rivals .=
    "<tr>\n" .
    "<td><a href=\"/stats/bot/by-name/{$bot['name']}/versus/{$rival['name']}/\">{$rival['name']}</a></td>\n" .
    "<td>{$rival['games']}</td>\n" .
    "</tr>\n";
}

// Find every game that the user has ever played, excluding current games.
$res = pg_query_params(
  'SELECT g.id, g.bot1, g.bot2, g.score1, g.score2, g.started, g.finished, (select name from bots where id=g.bot1) AS name1, (select name from bots where id=g.bot2) AS name2 FROM games AS g WHERE NOT EXISTS (SELECT b.game FROM bots AS b WHERE g.id=b.game) AND (g.bot1=$1 OR g.bot2=$1) ORDER BY finished DESC LIMIT 50;',
  [$bot["id"]]
);
if ($res === FALSE)
  failure("Unable to query the database for the list of games this bot has played.");
$rows = pg_fetch_all($res);
if ($rows === FALSE)
  $rows = [];
pg_free_result($res);

$games = "";
foreach ($rows as $game)
{
  $class = "warning";
  if ($game["bot1"] == $bot["id"])
  {
    if ($game["score1"] > $game["score2"])
      $class = "success";
    elseif ($game["score1"] < $game["score2"])
      $class = "error";
    elseif ($game["score1"] = $game["score2"])
      $class = "info";
  }
  else
  {
    if ($game["score2"] > $game["score1"])
      $class = "success";
    elseif ($game["score2"] < $game["score1"])
      $class = "error";
    elseif ($game["score2"] = $game["score1"])
      $class = "info";
  }

  if ($class === "success")
    $result = "Win";
  elseif ($class === "error")
    $result = "Loss";
  elseif ($class === "info")
    $result = "Tie";
  else
    $result = "Forfeit";

  $games .=
    "<tr class=\"{$class}\">\n" .
    "<td><a href=\"/stats/game/by-id/{$game['id']}/\">{$game['id']}</a></td>\n" .
    "<td>{$result}</td>\n" .
    "<td><a href=\"/stats/bot/by-name/{$game['name1']}/\">{$game['name1']}</a></td>\n" .
    "<td><a href=\"/stats/bot/by-name/{$game['name2']}/\">{$game['name2']}</a></td>\n" .
    "<td>{$game['started']}</td>\n" .
    "<td>{$game['finished']}</td>\n" .
    "</tr>\n";
}

if ($bot["online"] === "t")
{
  if (!is_null($bot["game"]))
    $status = "<p class=\"alert alert-success\">This bot is current online, and playing game <a href=\"/stats/game/by-id/{$bot['game']}/\">{$bot['game']}</a>.</p>";
  else
    $status = "<p class=\"alert alert-success\">This bot is current online.</p>";
}
else
  $status = "<p class=\"alert alert-danger\">This bot is currently offline.</p>";

// Find game statistics.
$res = pg_query_params(
  'SELECT
     (SELECT COUNT(*) FROM games WHERE ((bot1=$1 OR bot2=$1)) AND ((bot1=$1 AND score1>score2) OR (bot2=$1 AND score2>score1))) AS wins,
     (SELECT COUNT(*) FROM games WHERE ((bot1=$1 OR bot2=$1)) AND ((bot1=$1 AND score1<score2) OR (bot2=$1 AND score2<score1))) AS losses,
     (SELECT COUNT(*) FROM games WHERE ((bot1=$1 OR bot2=$1)) AND ((bot1=$1 AND score1=score2) OR (bot2=$1 AND score2=score1))) AS ties,
     (SELECT COUNT(*) FROM games WHERE ((bot1=$1 OR bot2=$1)) AND forfeited_by IS NOT NULL) AS forfeits,
     (SELECT COUNT(*) FROM games WHERE ((bot1=$1 OR bot2=$1))) AS total;',
  [$bot["id"]]
);
if ($res === FALSE)
  failure("Unable to query the database for the statistics on the games {$bot['name']} has played.");
$row = pg_fetch_assoc($res);
pg_free_result($res);

function pct($m, $n)
{
  if ($n == 0)
    $p = 0;
  else
    $p = ($m / $n) * 100;

  return number_format($p, 2, '.', '');
}

$html = file_get_contents("bot.html");
$html = str_replace("{{bot-name}}", $bot["name"], $html);
$html = str_replace("{{bot-id}}", $bot["id"], $html);
$html = str_replace("{{game}}", $bot["game"], $html);
$html = str_replace("{{status}}", $status, $html);
$html = str_replace("{{rivals}}", $rivals, $html);
$html = str_replace("{{games}}", $games, $html);
$html = str_replace("{{total}}", $row["total"], $html);
$html = str_replace("{{wins}}", $row["wins"], $html);
$html = str_replace("{{wins-pct}}", pct($row["wins"], $row["total"]), $html);
$html = str_replace("{{losses}}", $row["losses"], $html);
$html = str_replace("{{losses-pct}}", pct($row["losses"], $row["total"]), $html);
$html = str_replace("{{ties}}", $row["ties"], $html);
$html = str_replace("{{ties-pct}}", pct($row["ties"], $row["total"]), $html);
$html = str_replace("{{forfeits}}", $row["forfeits"], $html);
$html = str_replace("{{forfeits-pct}}", pct($row["forfeits"], $row["total"]), $html);

// Remove any unused replacements.
$html = preg_replace("/\{\{[-_a-z0-9]*\}\}/i", "", $html);

// Dump the filled-in template to the browser.
header("Content-type: text/html");
echo $html;
exit();

?>
