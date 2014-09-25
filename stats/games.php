<?php

require_once("shared.php");

$games_per_page = 50;

// Allow an optional offset.
if (array_key_exists("page", $_GET))
    $page = $_GET["page"];
else
  $page = 1;

// Ensure that the page looks like an integer.
if (preg_match("/^\d+$/", $page) !== 1)
  failure("The page you gave is not composed of decimal digits.");

// Ensure that the page is in the valid range.
$page = (int)$page;
if ($page < 1 || $page > 999999999)
  failure("The page you provided is outside of the expected range.");

$res = pg_query_params(
  'SELECT g.id, g.started, g.finished, (select name from bots where id=g.bot1) AS name1, (select name from bots where id=g.bot2) AS name2 FROM games AS g WHERE NOT EXISTS (SELECT b.game FROM bots AS b WHERE g.id=b.game) AND g.finished IS NOT NULL ORDER BY finished DESC LIMIT $1 OFFSET $2;',
  [$games_per_page, $games_per_page * ($page - 1)]
);
if ($res === FALSE || pg_affected_rows($res) === 0)
  failure("Page $page does not exist, try a smaller number.");
$rows = pg_fetch_all($res);
pg_free_result($res);

$games = "";
foreach ($rows as $game)
{
  $games .=
    "<tr>\n" .
    "  <td><a href=\"/stats/game/by-id/{$game['id']}/\">{$game['id']}</a></td>\n" .
    "  <td><a href=\"/stats/bot/by-name/{$game['name1']}/\">{$game['name1']}</a></td>\n" .
    "  <td><a href=\"/stats/bot/by-name/{$game['name2']}/\">{$game['name2']}</a></td>\n" .
    "  <td>{$game['started']}</td>\n" .
    "  <td>{$game['finished']}</td>\n" .
    "</tr>\n";
}

$prev = "";
if ($page != 1)
{
  $off = $page - 1;
  $prev = "<a href=\"/stats/games/page/{$off}/\">&laquo; prev</a>";
}

$off = $page + 1;
$next = "<a href=\"/stats/games/page/{$off}/\">next &raquo;</a>";

// Fill a template.
$html = file_get_contents("games.html");
$html = str_replace("{{games}}", $games, $html);
$html = str_replace("{{prev}}", $prev, $html);
$html = str_replace("{{next}}", $next, $html);

// Remove any unused replacements.
$html = preg_replace("/\{\{[-_a-z0-9]*\}\}/i", "", $html);

// Dump the filled-in template to the browser.
header("Content-type: text/html");
echo $html;

?>
