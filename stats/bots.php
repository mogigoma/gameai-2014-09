<?php

require_once("shared.php");

function bot_has_played_games($bot)
{
  return !is_null($bot["count"]);
}

// This is bots sorted by session_timeout.
// 'SELECT b.name, (SELECT COUNT(*) FROM games WHERE bot1=b.id OR bot2=b.id GROUP BY b.id) AS count FROM (SELECT id, name FROM bots) as b ORDER BY count DESC LIMIT 50;'
$res = pg_query(
   'SELECT name, id, game, (select session_timeout > now() WHERE id=b.id) AS online FROM bots AS b WHERE session IS NOT NULL ORDER BY session_timeout DESC;'
);
if ($res === FALSE || pg_affected_rows($res) === 0)
  failure("Failed to get list of bots.");
$rows = pg_fetch_all($res);
pg_free_result($res);

$bots = "";
foreach ($rows as $bot)
{
  if ($bot["online"] === "t")
    $status = "success";
  else
    $status = "danger";

  $designation = "";
  if ($bot["name"] === "monty" || $bot["name"] === "carlos")
    $designation = ' (<abbr title="This bot is provided by the server, it is not run by a participant.">NPC</abbr>)';

  $bots .=
    "<tr class=\"alert alert-$status\">\n" .
    "  <td><a href=\"/stats/bot/by-name/{$bot['name']}/\">{$bot['name']}</a>$designation</td>\n" .
    "  <td><a href=\"/stats/bot/by-id/{$bot['id']}/\">{$bot['id']}</a></td>\n" .
    "  <td><a href=\"/stats/game/by-id/{$bot['game']}/\">{$bot['game']}</a></td>\n" .
    "</tr>\n";
}

// Fill a template.
$html = file_get_contents("bots.html");
$html = str_replace("{{bots}}", $bots, $html);

// Remove any unused replacements.
$html = preg_replace("/\{\{[-_a-z0-9]*\}\}/i", "", $html);

// Dump the filled-in template to the browser.
header("Content-type: text/html");
echo $html;

?>
