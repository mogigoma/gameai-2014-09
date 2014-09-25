<?php

require_once("shared.php");

if (!array_key_exists("id1", $_GET) && !array_key_exists("id2", $_GET) && !array_key_exists("name1", $_GET) && !array_key_exists("name2", $_GET))
  failure("No names or IDs were given.");

if ((array_key_exists("id1", $_GET) || array_key_exists("id2", $_GET)) && (array_key_exists("name1", $_GET) || array_key_exists("name2", $_GET)))
  failure("Both names and IDs were given, but they're mutually exclusive.");

if ((array_key_exists("id1", $_GET) && !array_key_exists("id2", $_GET)) || (!array_key_exists("id1", $_GET) && array_key_exists("id2", $_GET)))
  failure("Only one ID was given, but they're both required.");

if ((array_key_exists("name1", $_GET) && !array_key_exists("name2", $_GET)) || (!array_key_exists("name1", $_GET) && array_key_exists("name2", $_GET)))
  failure("Only one name was given, but they're both required.");

// Validate and retrieve the bot's info based on the search criteria.
if (array_key_exists("id1", $_GET))
{
  $id1 = $_GET["id1"];
  $id2 = $_GET["id2"];

  // Ensure that the ID looks like an integer.
  if (preg_match("/^\d+$/", $id1) !== 1)
    failure("The first ID you gave is not composed of decimal digits.");
  if (preg_match("/^\d+$/", $id2) !== 1)
    failure("The second ID you gave is not composed of decimal digits.");

  // Ensure that the ID is in the valid range.
  $id1 = (int)$id1;
  $id2 = (int)$id2;
  if ($id1 < 0 || $id1 > 999999999)
    failure("The first ID you provided is outside of the expected range.");
  if ($id2 < 0 || $id2 > 999999999)
    failure("The second ID you provided is outside of the expected range.");

  // Ensure they're different IDs.
  if ($id1 === $id2)
    failure("The IDs you gave were the same, but need to be different.");

  // Retrieve the bot's information from the database.
  $res = pg_query_params(
    'SELECT id, name, game, (select session_timeout > now() WHERE id=b.id) AS online FROM bots as b WHERE b.id=$1 OR b.id=$2;',
    [$id1, $id2]
  );
  if ($res === FALSE || pg_affected_rows($res) !== 2)
    failure("Unable to find any bots matching your search criteria.");
  $bots = pg_fetch_all($res);
  pg_free_result($res);

  if ($bots[0]["id"] === $id)
  {
    $bot1 = $bots[0];
    $bot2 = $bots[1];
  }
  else
  {
    $bot1 = $bots[1];
    $bot2 = $bots[0];
  }
}
else
{
  $name1 = $_GET["name1"];
  $name2 = $_GET["name2"];

  // Ensure that the name looks like an bot's name.
  if (preg_match("/^[-_a-zA-Z0-9]+$/", $name1) !== 1)
    failure("The first name you gave contains invalid characters.");
  if (preg_match("/^[-_a-zA-Z0-9]+$/", $name2) !== 1)
    failure("The second name you gave contains invalid characters.");

  // Ensure they're different names.
  if ($name1 === $name2)
    failure("The names you gave were the same, but need to be different.");

  // Retrieve the bot's information from the database.
  $res = pg_query_params(
    'SELECT id, name, game, (select session_timeout > now() WHERE id=b.id) AS online FROM bots as b WHERE name=$1 OR name=$2;',
    [$name1, $name2]
  );
  if ($res === FALSE || pg_affected_rows($res) !== 2)
    failure("Unable to find any bots matching your search criteria.");
  $bots = pg_fetch_all($res);
  pg_free_result($res);

  if ($bots[0]["name"] === $name1)
  {
    $bot1 = $bots[0];
    $bot2 = $bots[1];
  }
  else
  {
    $bot1 = $bots[1];
    $bot2 = $bots[0];
  }
}

// Find every game that these user has ever played together, excluding current games.
$res = pg_query_params(
  'SELECT
     g.id,
     g.bot1,
     g.bot2,
     (SELECT name FROM bots WHERE id=g.bot1) AS name1,
     (SELECT name FROM bots WHERE id=g.bot2) AS name2,
     g.score1,
     g.score2,
     g.started,
     g.finished
   FROM games AS g
   WHERE
     NOT EXISTS (SELECT b.game FROM bots AS b WHERE g.id=b.game) AND
     ((g.bot1=$1 AND g.bot2=$2) OR (g.bot1=$2 AND g.bot2=$1))
     ORDER BY finished DESC LIMIT 50;',
  [$bot1["id"], $bot2["id"]]
);
if ($res === FALSE)
  failure("Unable to query the database for the list of games {$bot1['name']} and {$bot2['name']} have played together.");
$rows = pg_fetch_all($res);
if ($rows === FALSE)
  $rows = [];
pg_free_result($res);

$games = "";
foreach ($rows as $game)
{
  $class = "warning";
  if ($game["bot1"] == $bot1["id"])
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

if ($bot1["online"] === "t")
{
  if (!is_null($bot1["game"]))
    $status1 = "<p class=\"alert alert-success\">The bot named '{$bot1['name']}' is current online, and playing game <a href=\"/stats/game/by-id/{$bot1['game']}/\">{$bot1['game']}</a>.</p>";
  else
    $status1 = "<p class=\"alert alert-success\">The bot named '{$bot1['name']}' is current online.</p>";
}
else
  $status1 = "<p class=\"alert alert-danger\">The bot named '{$bot1['name']}' is currently offline.</p>";

if ($bot2["online"] === "t")
{
  if (!is_null($bot2["game"]))
    $status2 = "<p class=\"alert alert-success\">The bot named '{$bot2['name']}' is current online, and playing game <a href=\"/stats/game/by-id/{$bot2['game']}/\">{$bot2['game']}</a>.</p>";
  else
    $status2 = "<p class=\"alert alert-success\">The bot named '{$bot2['name']}' is current online.</p>";
}
else
  $status2 = "<p class=\"alert alert-danger\">The bot named '{$bot2['name']}' is currently offline.</p>";

// Find game statistics.
$res = pg_query_params(
  'SELECT
     (SELECT COUNT(*) FROM games WHERE ((bot1=$1 AND bot2=$2) OR (bot1=$2 AND bot2=$1)) AND ((bot1=$1 AND score1>score2) OR (bot2=$1 AND score2>score1))) AS wins,
     (SELECT COUNT(*) FROM games WHERE ((bot1=$1 AND bot2=$2) OR (bot1=$2 AND bot2=$1)) AND ((bot1=$1 AND score1<score2) OR (bot2=$1 AND score2<score1))) AS losses,
     (SELECT COUNT(*) FROM games WHERE ((bot1=$1 AND bot2=$2) OR (bot1=$2 AND bot2=$1)) AND ((bot1=$1 AND score1=score2) OR (bot2=$1 AND score2=score1))) AS ties,
     (SELECT COUNT(*) FROM games WHERE ((bot1=$1 AND bot2=$2) OR (bot1=$2 AND bot2=$1)) AND forfeited_by IS NOT NULL) AS forfeits,
     (SELECT COUNT(*) FROM games WHERE ((bot1=$1 AND bot2=$2) OR (bot1=$2 AND bot2=$1))) AS total;',
  [$bot1["id"], $bot2["id"]]
);
if ($res === FALSE || pg_affected_rows($res) === 0)
  failure("Unable to query the database for the statistics on the games {$bot1['name']} and {$bot2['name']} have played together.");
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

$html = file_get_contents("versus.html");
$html = str_replace("{{name1}}", $bot1["name"], $html);
$html = str_replace("{{name2}}", $bot2["name"], $html);
$html = str_replace("{{id1}}", $bot1["id"], $html);
$html = str_replace("{{id2}}", $bot2["id"], $html);
$html = str_replace("{{status1}}", $status1, $html);
$html = str_replace("{{status2}}", $status2, $html);
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
