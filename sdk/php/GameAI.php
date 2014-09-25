<?php

$base = "http://gameai.skullspace.ca/api/";

function failure($msg) {
    echo("!! $msg\n");
    exit(1);
}

function info($msg) {
    echo("** $msg\n");
}

function parse_card($abbr) {
    $card = ["abbr" => $abbr];
    $suit = substr($abbr, 0, 1);

    if ($suit === "C") {
        $card["suit"] = "CLUBS";
    }
    elseif ($suit === "D") {
        $card["suit"] = "DIAMONDS";
    }
    elseif ($suit === "H") {
        $card["suit"] = "HEARTS";
    }
    else {
        $card["suit"] = "SPADES";
    }

    $card["value"] = (int)substr($abbr, 1, 2);

    return $card;
}

function rawapi($method, $params) {
    global $base;

    # Create the URL of the endpoint.
    $url = $base . $method . "/";

    # Create a stream context, with our options embedded.
    $ctx = stream_context_create([
        "http" => [
            "method" => "POST",
            "header" => "Content-type: application/json",
            "content" => json_encode($params)
        ]
    ]);

    # Send the HTTP request.
    $json = file_get_contents($url, false, $ctx);

    # Check for errors.
    if ($json === FALSE) {
        failure("An unknown error occurred during the API call.");
    }

    # Read the HTTP response body.
    return json_decode($json, TRUE);
}

function api($method, $params) {
    $json = rawapi($method, $params);
    if ($json["result"] === "failure") {
        failure($json["reason"]);
    }

    return $json;
}

function main($argv) {
    # Ensure we've been given a name and a password.
    if (count($argv) !== 3) {
        $name = readline("Enter your bot's name: ");
        $pass = readline("Enter your bot's password: ");
    }
    else {
        $name = $argv[1];
        $pass = $argv[2];
    }

    # Register the name, which will have no effect if you've already done it.
    rawapi("register", ["name" => $name, "password" => $pass]);

    # Login with the name and password.
    info("Logging in to the server...");
    $json = api("login", ["name" => $name, "password" => $pass]);
    info("Logged in.");

    # Store the session from the login for future use.
    $session = $json["session"];
    info("Received session '$session'.");

    while (TRUE) {
        # Ask to be given an opponent to play against.
        info("Attempting to start a new game...");
        $json = api("new-game", ["session" => $session]);

        # If there's nobody to play against, start the loop from the top after
        # waiting 5 seconds.
        if ($json["result"] === "retry") {
            echo("?? " . $json["reason"] . "\n");
            sleep(5);
            continue;
        }

        # Create an object to represent the cards we have been dealt.
        $cards = $json["cards"];
        info("We have started a new game, and have been dealt: " . implode(", ", $cards) . ".");

        # Run the game AI.
        new_game($session, $cards);

        # Cleanup from our game.
        info("Our role in this game is over, but we need to be sure the server has ended the game before we start a new one.");
        info("If we try to start a new game without the old one being done, the server will reject our request.");
        while (TRUE) {
            info("Waiting for our game to be over...");
            $json = api("status", ["session" => $session]);
            if (is_null($json["game"])) {
                break;
            }
            sleep(1);
        }
        info("The server has ended our game.");
    }
}

function new_game($session, $hand) {
    # Make a bid, which we'll do randomly, by choosing a number between 1 and
    # 13.
    $bid = rand(1, 13);

    # Register our bid with the server.
    info("Attempting to bid " . $bid . ".");
    api("bid", ["session" => $session, "bid" => $bid]);
    info("Our bid has been accepted.");

    # Check the status repeatedly, and if it's our turn play a card, until all
    # cards have been played and the game ends.
    while (count($hand) !== 0) {
        # Always wait 1 second, it may not seem like much but it helps avoid
        # pinning the client's CPU and flooding the server.
        sleep(1);

        # Request the game's status from the server.
        info("Requesting the status of our game...");
        $json = api("status", ["session" => $session]);
        info("Status received.");

        # If the game has ended prematurely, due to a forfeit from your opponent
        # or some other reason, rejoice and find a new opponent.
        if (is_null($json["game"])) {
            info("Our game appears to have ended.");
            return;
        }

        # If we're still in the bidding process, it's nobody's turn.
        if (is_null($json["your-turn"])) {
            info("Our game is still in the bidding phase, we need to wait for our opponent.");
            next;
        }

        # If not it's not our turn yet, jump back to the top of the loop to
        # check the status again.
        if (!$json["your-turn"]) {
            info("It is currently our opponent's turn, we need to wait for our opponent.");
            continue;
        }

        # Finally, it's our turn. First, we have to determine if another card
        # was played first in this round. If so, it restricts our choices.
        $allowed_cards;
        if (is_null($json["opponent-current-card"])) {
            # We can play any card we want, since we're going first in this
            # round. So all the cards in our hand are allowed.
            $allowed_cards = $hand;
            info("We have the lead this round, so we may choose any card.");
        }
        else {
            # We can only play cards that match the suit of the lead card, since
            # we're going second in this round. Gather together all the cards in
            # our hand that have the appropriate suit.
            $allowed_cards = [];
            $lead_card = parse_card($json["opponent-current-card"]);
            info("Our opponent has lead this round, so we must try to play a card that matches the lead card's suit: " . $lead_card["suit"] . ".");

            foreach ($hand as $card) {
                $card = parse_card($card);
                if ($card["suit"] === $lead_card["suit"]) {
                    array_push($allowed_cards, $card["abbr"]);
                }
            }

            # Check if we actually found any cards in our hand with the
            # appropriate suit. If we don't have any, there are no restrictions
            # on the card we can then play.
            if (count($allowed_cards) === 0) {
                info("We have no " . $lead_card["suit"] . " in our hand, so we can play any suit we choose.");
                $allowed_cards = $hand;
            }
        }

        # Among the cards that we have determined are valid, according to the
        # rules, choose one to play at random.
        $idx = rand(0, count($allowed_cards) - 1);
        $card = $allowed_cards[$idx];
        info("We have randomly chosen " . $card . ".");

        # Now that the card has been chosen, play it.
        info("Attempting to play " . $card . "...");
        api("play", ["session" => $session, "card" => $card]);
        info("Card has been played.");

        # Remove the card from our hand.
        $new_hand = [];
        foreach ($hand as $card_in_hand) {
            if ($card_in_hand !== $card) {
                array_push($new_hand, $card_in_hand);
            }
        }
        $hand = $new_hand;
    }
}

main($argv);

?>
