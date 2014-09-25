<?php

// #############################################################################
// # Settings
// #############################################################################

$db_database = "game";
$db_username = "matchmaker";
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

function deal()
{
  $cards = [
    "C01", "C02", "C03", "C04", "C05", "C06", "C07",
    "C08", "C09", "C10", "C11", "C12", "C13",
    "D01", "D02", "D03", "D04", "D05", "D06", "D07",
    "D08", "D09", "D10", "D11", "D12", "D13",
    "H01", "H02", "H03", "H04", "H05", "H06", "H07",
    "H08", "H09", "H10", "H11", "H12", "H13",
    "S01", "S02", "S03", "S04", "S05", "S06", "S07",
    "S08", "S09", "S10", "S11", "S12", "S13"
  ];

  // Shuffle the cards *very* carefully, the people that we expect to
  // participate are the sort that will analyze our shuffling algorithm.
  //
  // We are going to use the following algorithm:
  //     https://en.wikipedia.org/wiki/Fisher-Yates_shuffle
  //
  // Based on recommendations found here:
  //     http://www.cigital.com/papers/download/developer_gambling.php
  //
  // Using the following method to eliminate bias:
  //     https://security.stackexchange.com/questions/39268/correct-way-to-get-a-number-from-0-9-from-a-random-byte
  for ($i = 0; $i < 51; $i++)
  {
    // Determine the range for the random number, which can be either the
    // current card or any subsequent card.
    $r = 51 - $i;

    // Churn through random numbers until we have one in the correct range.
    while (TRUE)
    {
      $o = ord(openssl_random_pseudo_bytes(1)[0]);
      if ($o <= $r)
	break;
    }

    // Pick any of the unchosen cards remaining.
    $j = $i + $o;

    // Swap the chosen card with the current card.
    $tmp = $cards[$i];
    $cards[$i] = $cards[$j];
    $cards[$j] = $tmp;
  }

  // Partition the array into two hands, leaving the other two hands to be
  // discarded.
  $hand1 = [];
  $hand2 = [];
  for ($i = 0; $i < 52; $i += 4)
  {
    array_push($hand1, $cards[$i]);
    array_push($hand2, $cards[$i + 1]);
  }

  // Sort the array to avoid giving any information about the original
  // shuffle order.
  sort($hand1);
  sort($hand2);

  return [$hand1, $hand2];
}

function hand_to_array($hand)
{
  return "{" . implode(",", $hand) . "}";
}

function assign_game($game, $bot, $hand)
{
  // Add the bot to the game, and give it two minutes to complete the bidding stage.
  $res = pg_query_params(
    'UPDATE bots SET game=$1, cards=$2, game_timeout=now()+\'2 minutes\'::interval WHERE id=$3;',
    [$game, $hand, $bot]
  );
  if ($res === FALSE || pg_affected_rows($res) !== 1)
    fail("Failed to update the database to assign game ID '$game' to bot with ID '$bot'.");
  pg_free_result($res);

  // Notify the bot that it has been assigned a game.
  // This will notification will not be delivered until the transaction is committed.
  pg_query("NOTIFY bot_$bot, 'GAME::$game';");

  info("Assigned bot $bot to game $game.");
}

function create_game($bot1, $bot2, $hand1, $hand2)
{
  // Create the game and get it's automatically-generated ID.
  $res = pg_query_params(
    'INSERT INTO games (bot1, bot2, hand1, hand2) VALUES ($1, $2, $3, $4) RETURNING id;',
    [$bot1, $bot2, $hand1, $hand2]
  );
  if ($res === FALSE || pg_affected_rows($res) !== 1)
    fail("Failed to update the database to create game a new game between bot '$bot1' and '$bot2'.");
  $row = pg_fetch_assoc($res);
  $game = $row["id"];
  pg_free_result($res);

  // Create the first round of the game.
  $res = pg_query_params(
    'INSERT INTO rounds (game, round, bot1, bot2) VALUES ($1, $2, $3, $4);',
    [$game, 1, $bot1, $bot2]
  );
  if ($res === FALSE || pg_affected_rows($res) !== 1)
    fail("Failed to update the database to create a record for round 1 of a new game between bot '$bot1' and '$bot2'.");
  pg_free_result($res);

  info("Created game $game.");

  return $game;
}

function new_game($bot1, $bot2)
{
  // Shuffle the deck and deal two hands.
  $hands = deal();
  $hand1 = hand_to_array($hands[0]);
  $hand2 = hand_to_array($hands[1]);

  // Create the game.
  $game = create_game($bot1, $bot2, $hand1, $hand2);

  // Assign the game to both players.
  assign_game($game, $bot1, $hand1);
  assign_game($game, $bot2, $hand2);
}

// #############################################################################
// # Setup
// #############################################################################

// #############################################################################
// # Loop
// #############################################################################

$db = NULL;
$bots = [];
while (TRUE)
{
  // Close the connection to the database.
  if (!is_null($db))
  {
    if (pg_close($db) === FALSE)
      fail("Failed to close database connection.");
    info("Closed database connection.");
  }

  // Indicate the start of a new matchmaking round.
  echo "--\n";

  // Open a connection to the database.
  $db = pg_connect("user=$db_username password=$db_password dbname=game");
  if ($db === FALSE)
    fail("Failed to connect to the database.");
  info("Connected to the database.");

  // Start listening on the matchmaker channel.
  $res = pg_query("LISTEN matchmaker;");
  if ($res === FALSE)
    fail("Failed to listen for matchmaking events.");
  pg_free_result($res);
  info("Listening on the matchmaking channel.");

  // Choose a random number of seconds to wait for matchmaking events, to get a
  // mix of bots.
  $wait = 1 + ord(openssl_random_pseudo_bytes(1)) % 10;
  info("Waiting $wait seconds for matchmaking events.");
  sleep($wait);
  info("Waiting completed.");

  // Collect all of the notifications that were sent during our nap, and any new
  // ones that crop up while we're at it.
  while (TRUE)
  {
    // Try to read a notification from the channel.
    $res = pg_get_notify($db, PGSQL_ASSOC);
    if ($res === FALSE)
      break;

    // Interpret the payload as a bot's ID.
    $bot = (int)$res["payload"];
    info("Received notification from bot with ID '$bot'.");

    array_push($bots, $bot);
  }

  // Ensure that there are no duplicate IDs in our array.
  $bots = array_unique($bots);

  // Don't go any further if there are no bots.
  if (count($bots) === 0)
    continue;

  pg_query("BEGIN;");

  // Query the status of all the bots we received notifications from.
  $res = pg_query('SELECT id, game FROM bots WHERE id IN (' . implode(",", $bots) . ');');
  if ($res === FALSE)
    fail("Failed to query the database to for the status of the bots we received notifications from.");

  // Validate that each bot is not currently engaged in a game.
  $tmp = [];
  while (TRUE)
  {
    // Get the next row from the query.
    $row = pg_fetch_assoc($res);
    if ($row === FALSE)
      break;

    // If the bot is in a game, toss them.
    if (!is_null($row["game"]))
    {
      info("The bot with ID '{$row['id']}' is already in game '{$row['game']}', removing from the matchmaking queue.");
      continue;
    }

    // Otherwise, keep them around.
    array_push($tmp, $row["id"]);
  }
  pg_free_result($res);

  // Replace the list of bots with the filtered list.
  $bots = $tmp;

  // Shuffle the array.
  shuffle($bots);

  // Take the bots two at a time and create matches for them.
  while (count($bots) > 1)
  {
    // Remove the two bots at the front of the array.
    // Taking from the front ensures any bot from the last round is prioritized
    $bot1 = array_shift($bots);
    $bot2 = array_shift($bots);

    // Create a game for these two bots.
    info("Creating game between '$bot1' and '$bot2'.");
    new_game($bot1, $bot2);
  }
  pg_query("SELECT 1;");
  pg_query("COMMIT;");
  pg_query("SELECT 1;");

  // One bot may be left unmatched in each round.
  if (count($bots) !== 0)
    info("Unable to match " . implode(" and ", $bots) . " in this round.");
}

?>
