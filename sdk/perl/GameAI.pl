#!/usr/bin/env perl

use strict;
use warnings;

use LWP::UserAgent;
use JSON::PP;

my $base = "http://gameai.skullspace.ca/api/";

sub failure {
    print("!! " . shift(@_) . "\n");
    exit(1);
}

sub info {
    print("** " . shift(@_) . "\n");
}

sub parse_card {
    my $abbr = shift(@_);
    my %card = ("abbr" => $abbr);
    my $suit = substr($abbr, 0, 1);

    if ($suit eq "C") {
        $card{"suit"} = "CLUBS";
    }
    elsif ($suit eq "D") {
        $card{"suit"} = "DIAMONDS";
    }
    elsif ($suit eq "H") {
        $card{"suit"} = "HEARTS";
    }
    else {
        $card{"suit"} = "SPADES";
    }

    $card{"value"} = int(substr($abbr, 1, 2));

    return %card;
}

sub rawapi {
    my $method = shift(@_);
    my (%params) = @_;

    # Collect parameters into a JSON object.
    my $json = JSON::PP->new;
    my $json_params = $json->encode(\%params);

    # Create the URL of the endpoint.
    my $url = $base . $method . "/";

    # Create a new HTTP request to the endpoint.
    my $req = HTTP::Request->new(POST => $url);

    # Set up the HTTP request's properties.
    $req->header("Content-type" => "application/json");

    # Set the HTTP request's body.
    $req->content($json_params);

    # Send the HTTP request.
    my $con = LWP::UserAgent->new;
    my $res = $con->request($req);

    # Check for errors.
    if (!$res->is_success) {
        failure("An unknown error occurred during the API call.");
    }

    # Read the HTTP response code.
    if ($res->code != 200) {
        failure("The server responded with a status code other than 200.");
    }

    # Read the HTTP response body.
    return $json->decode($res->decoded_content);
}

sub api {
    my $method = shift(@_);
    my (%params) = @_;

    my $json = rawapi($method, %params);
    if ($json->{"result"} eq "failure") {
        failure($json->{"reason"});
    }

    return $json;
}

sub main {
    my (@argv) = @_;

    # Ensure we've been given a name and a password.
    my $name;
    my $pass;
    if (@argv != 2) {
        print("Enter your bot's name: ");
        $name = <STDIN>;
        chomp($name);

        print("Enter your bot's password: ");
        $pass = <STDIN>;
        chomp($pass);
    }
    else {
        $name = $argv[0];
        $pass = $argv[1];
    }

    # Register the name, which will have no effect if you've already done it.
    rawapi("register", ("name" => $name, "password" => $pass));

    # Login with the name and password.
    info("Logging in to the server...");
    my $json = api("login", ("name" => $name, "password" => $pass));
    info("Logged in.");

    # Store the session from the login for future use.
    my $session = $json->{"session"};
    info("Received session '$session'.");

    while (1) {
        # Ask to be given an opponent to play against.
        info("Attempting to start a new game...");
        $json = api("new-game", ("session" => $session));

        # If there's nobody to play against, start the loop from the top after
        # waiting 5 seconds.
        if ($json->{"result"} eq "retry") {
            print("?? " . $json->{"reason"} . "\n");
            sleep(5);
            next;
        }

        # Create an object to represent the cards we have been dealt.
        my @cards = @{$json->{"cards"}};
        info("We have started a new game, and have been dealt: " . join(", ", @cards) . ".");

        # Run the game AI.
        new_game($session, @cards);

        # Cleanup from our game.
        info("Our role in this game is over, but we need to be sure the server has ended the game before we start a new one.");
        info("If we try to start a new game without the old one being done, the server will reject our request.");
        while (1) {
            info("Waiting for our game to be over...");
            $json = api("status", ("session" => $session));
            if (!defined $json->{"game"}) {
                last;
            }
            sleep(1);
        }
        info("The server has ended our game.");
    }
}

sub new_game {
    my $session = shift(@_);
    my @hand = @_;

    # Make a bid, which we'll do randomly, by choosing a number between 1 and
    # 13.
    my $bid = int(1 + rand(13));

    # Register our bid with the server.
    info("Attempting to bid " . $bid . ".");
    api("bid", ("session" => $session, "bid" => $bid));
    info("Our bid has been accepted.");

    # Check the status repeatedly, and if it's our turn play a card, until all
    # cards have been played and the game ends.
    while (@hand) {
        # Always wait 1 second, it may not seem like much but it helps avoid
        # pinning the client's CPU and flooding the server.
        sleep(1);

        # Request the game's status from the server.
        info("Requesting the status of our game...");
        my $json = api("status", ("session" => $session));
        info("Status received.");

        # If the game has ended prematurely, due to a forfeit from your opponent
        # or some other reason, rejoice and find a new opponent.
        if (!defined $json->{"game"}) {
            info("Our game appears to have ended.");
            return;
        }

        # If we're still in the bidding process, it's nobody's turn.
        if (!defined $json->{"your-turn"}) {
            info("Our game is still in the bidding phase, we need to wait for our opponent.");
            next;
        }

        # If not it's not our turn yet, jump back to the top of the loop to
        # check the status again.
        if (not $json->{"your-turn"}) {
            info("It is currently our opponent's turn, we need to wait for our opponent.");
            next;
        }

        # Finally, it's our turn. First, we have to determine if another card
        # was played first in this round. If so, it restricts our choices.
        my @allowed_cards;
        if (!defined $json->{"opponent-current-card"}) {
            # We can play any card we want, since we're going first in this
            # round. So all the cards in our hand are allowed.
            @allowed_cards = @hand;
            info("We have the lead this round, so we may choose any card.");
        }
        else {
            # We can only play cards that match the suit of the lead card, since
            # we're going second in this round. Gather together all the cards in
            # our hand that have the appropriate suit.
            @allowed_cards = ();
            my %lead_card = parse_card($json->{"opponent-current-card"});
            info("Our opponent has lead this round, so we must try to play a card that matches the lead card's suit: " . $lead_card{"suit"} . ".");

            foreach my $card (@hand) {
                my %card = parse_card($card);
                if ($card{"suit"} eq $lead_card{"suit"}) {
                    push(@allowed_cards, $card{"abbr"});
                }
            }

            # Check if we actually found any cards in our hand with the
            # appropriate suit. If we don't have any, there are no restrictions
            # on the card we can then play.
            if (!@allowed_cards) {
                info("We have no " . $lead_card{"suit"} . " in our hand, so we can play any suit we choose.");
                @allowed_cards = @hand;
            }
        }

        # Among the cards that we have determined are valid, according to the
        # rules, choose one to play at random.
        my $idx = int(rand(@allowed_cards));
        my $card = $allowed_cards[$idx];
        info("We have randomly chosen " . $card . ".");

        # Now that the card has been chosen, play it.
        info("Attempting to play " . $card . "...");
        api("play", ("session" => $session, "card" => $card));
        info("Card has been played.");

        # Remove the card from our hand.
        my @new_hand = ();
        foreach my $card_in_hand (@hand) {
            if ($card_in_hand ne $card) {
                push(@new_hand, $card_in_hand);
            }
        }
        @hand = @new_hand;
    }
}

main(@ARGV);
