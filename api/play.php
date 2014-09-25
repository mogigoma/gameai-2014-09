<?php

$method = "play";
$format = [
  "session" => "string",
  "card" => "string"
];

require_once("shared.php");

function play_card($col)
{
  global $bot;
  global $req;
  global $row;

  // Remove the card that the bot just played from its hand.
  $res = pg_query_params('UPDATE bots SET cards=array_remove(cards, $1) WHERE id=$2;', [$req["card"], $bot["id"]]);
  if ($res === FALSE || pg_affected_rows($res) !== 1)
    panic("Failed to remove the card '{$req['card']}' from the bot's hand which consists of '{$bot['cards']}'.");
  pg_free_result($res);

  // Play the card in the round.
  $res = pg_query_params('UPDATE rounds SET ' . $col . '=$1 WHERE game=$2 AND round=$3;', [$req["card"], $bot["game"], $row["round"]]);
  if ($res === FALSE || pg_affected_rows($res) !== 1)
    panic("Failed to play the card '{$req['card']}' in game '{$bot['game']}' round '{$row['round']}'.");
  pg_free_result($res);

  // Add the card to our local copy of the row.
  $row[$col] = $req["card"];
}

function calc_score($bid, $tricks)
{
  if ($bid <= $tricks)
    return ($bid * 10) + ($tricks - $bid);

  return ($bid * -10);
}

// Validate that the card is in the correct format.
if (strlen($req["card"]) !== 3)
  failure("All cards must be three characters, consisting of a letter followed by two digits, and '{$req['card']}' doesn't match that format.");
$card = parse_card($req["card"]);
$card_suit = $card[0];
$card_num = $card[1];

// Sanity check that the card actually exists in a deck.
if (preg_match("/[CDHS]/", $card_suit) !== 1)
  failure("The card's suit must be one of (C)lubs, (D)iamonds, (H)earts, or (S)pades, not '$card_suit'.");
if ($card_num < 1 || $card_num > 13)
  failure("The card's value must be between 1 and 13, inclusive, which does not include '$card_num'.");

// Check that the bot is actually in a game right now.
if (is_null($bot["game"]))
  failure("You are not currently playing any game, note that you only have {$timeouts['game']} minutes to bid once a game starts.");

// Sanity check that the card exists in our hand. Faking cards is one of the
// first things someone will attempt, because it's the first trick I'd try.
if (strpos($bot["cards"], $req['card']) === FALSE)
  failure("You attempted to play the card '{$req['card']}', but the cards in your hand are '{$bot['cards']}'.");

// Retrieve the game's information
$res = pg_query_params(
  'SELECT g.round, g.bid1, g.bid2, g.tricks1, g.tricks2, r.bot1, r.bot2, r.card1, r.card2, g.bot1 AS id1, g.bot2 AS id2
   FROM games AS g JOIN rounds AS r
   ON g.id=r.game AND g.round=r.round
   WHERE g.id=$1;',
  [$bot["game"]]
);
if ($res === FALSE || pg_affected_rows($res) !== 1)
  panic("The 'game' attached to your session, '{$bot['game']}', could not be crossreferenced against the 'rounds' table.");
$row = pg_fetch_assoc($res);
pg_free_result($res);

// Check if this bot is the leader.
$leader = ($row["bot1"] === $bot["id"]);

// Check if this bot has already played a card this round.
if ($leader && !is_null($row["card1"]))
  failure("You have already played a card, '{$row['card1']}', in this round.");

// Check if the second card has been played, which means this round should already be over.
if (!is_null($row["card2"]))
  panic("The logic that progresses games from round to round has busted.");

// If we're the leader, we can play any card in our hand without restriction.
if ($leader)
{
  play_card("card1");
  reset_timeout("game");
  reset_timeout("session");
  success([]);
}

// Check if the leader has played a card yet.
if (is_null($row["card1"]))
  failure("The leader, which isn't you, has not yet played a card in this round.");

// If we're the second person to play in this round, the leading suit restricts
// our choices. We need to match the suit if we can. We need to validate that the
// bot did not have any cards of the leading suit to play, or that the bot played
// the suit.
$card1 = parse_card($row["card1"]);
$card2 = parse_card($req["card"]);
if ($card1[0] !== $card2[0])
{
  // The cards were different suits, so now check if this bot has any of the suit
  // that the leader played.
  if (preg_match("/({$card1[0]}\d\d)/", $bot["cards"]) === 1)
    failure("You cannot play the card '{$req['card']}' since '{$row['card1']}' was lead and you have at least one card of that suit.");
}

// We have a valid card to play, so play it.
play_card("card2");

// The round is now over, so determine who won it.
$winner = "bot" . card_cmp($row["card1"], $req["card"]);

// Update the columns for the tricks and the round.
if ($winner === "bot1")
{
  // Figure out which bot is bot1 in this round.
  if ($row["bot1"] === $row["id1"])
    $row["tricks1"]++;
  else
    $row["tricks2"]++;
}
else
{
  // Figure out which bot is bot2 in this round.
  if ($row["bot2"] === $row["id2"])
    $row["tricks2"]++;
  else
    $row["tricks1"]++;
}

// If the game is over, shut it game down.
if ($row["round"] >= 13)
{
  // Calculate the score by comparing the bids to the tricks.
  $score1 = calc_score($row["bid1"], $row["tricks1"]);
  $score2 = calc_score($row["bid2"], $row["tricks2"]);

  // Record the scores.
  $res = pg_query_params(
    'UPDATE games SET score1=$1, score2=$2, tricks1=$3, tricks2=$4, finished=now() WHERE id=$5;',
    [$score1, $score2, $row["tricks1"], $row["tricks2"], $bot["game"]]
  );
  if ($res === FALSE || pg_affected_rows($res) !== 1)
    panic("Failed to update the database to record the final scores of game {$bot['game']}.");
  pg_free_result($res);

  // Free up the bots.
  $res = pg_query_params(
    'UPDATE bots SET game=NULL, game_timeout=NULL, cards=NULL WHERE id=$1 OR id=$2;',
    [$row["bot1"], $row["bot2"]]
  );
  if ($res === FALSE || pg_affected_rows($res) !== 2)
    panic("Failed to update the database to release bots {$row['bot1']} and {$row['bot2']} from game {$bot['game']}.");
  pg_free_result($res);
}

// If the game is not over, create the next round.
else
{
  // Increment the round counter.
  $row["round"]++;

  // Award the trick to the winner.
  $res = pg_query_params(
    'UPDATE games SET tricks1=$1, tricks2=$2, round=$3 WHERE id=$4;',
    [$row["tricks1"], $row["tricks2"], $row["round"], $bot["game"]]
  );
  if ($res === FALSE || pg_affected_rows($res) !== 1)
    panic("Failed to update the database to record the winner of round ${row['round']} of game {$bot['game']}.");
  pg_free_result($res);

  if ($winner === "bot1")
  {
    $bot1 = $row["bot1"];
    $bot2 = $row["bot2"];
  }
  else
  {
    $bot1 = $row["bot2"];
    $bot2 = $row["bot1"];
  }

  $res = pg_query_params(
    'INSERT INTO rounds (game, round, bot1, bot2) VALUES ($1, $2, $3, $4);',
    [$bot["game"], $row["round"], $bot1, $bot2]
  );
  if ($res === FALSE || pg_affected_rows($res) !== 1)
    panic("Failed to update the database to create a record for round ${row['round']} of game {$bot['game']}.");
  pg_free_result($res);

  reset_timeout("game");
}

// Return successfully.
reset_timeout("session");
success([]);

?>
