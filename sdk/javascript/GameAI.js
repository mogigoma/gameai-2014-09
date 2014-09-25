$(function () {
    // *************************************************************************
    // * Global Variables
    // *************************************************************************

    var _base = "http://gameai.skullspace.ca/api/";
    var _paused = false;

    // *************************************************************************
    // * Card Parsing
    // *************************************************************************

    var parse_card = function (abbr) {
        var card = {"abbr": abbr};

        switch (abbr[0]) {
        case "C":
            card["suit"] = "CLUBS";
            break;
        case "D":
            card["suit"] = "DIAMONDS";
            break;
        case "H":
            card["suit"] = "HEARTS";
            break;
        default:
            card["suit"] = "SPADES";
        }

        card["value"] = parseInt(abbr.substr(1, 2), 10);

        return card;
    };

    // *************************************************************************
    // * Logging
    // *************************************************************************

    var log = function (msg, cls) {
        var pad_to_2digits = function (n) {
            if (n >= 10) {
                return n;
            }

            return "0" + n;
        };

        var now = new Date();
        var hh = pad_to_2digits(now.getHours());
        var mm = pad_to_2digits(now.getMinutes());
        var ss = pad_to_2digits(now.getSeconds());

        if (cls === undefined) {
            cls = "info";
        }

        $("#log").prepend(
            '<p class="' + cls + '">' +
                "[" + hh + ":" + mm + ":" + ss + "] " +
                msg +
                "</p>"
        );
    };

    var failure = function (msg) {
        log(msg, "fail");
    };

    var warn = function (msg) {
        log(msg, "warn");
    };

    var info = function (msg) {
        log(msg, "info");
    };

    // *************************************************************************
    // * API
    // *************************************************************************

    var rawapi = function (method, params, cb) {
        // Add the method name into the parameters.
        params.method = method;

        // Create the URL of the endpoint, being sure to include the special
        // notation that JSONP and jQuery need to pass data back to our
        // dynamically-named function.
        var url = _base + "/jsonp/?callback=?";

        // Send the HTTP request to the JSONP endpoint.
        $.getJSON(url, params, function (json) {
            // Pass the JSON data returned from the server to our callback
            // function.
	    warn(JSON.stringify(json));
            return cb(json);
        });
    };

    var api = function (die, method, params, cb) {
        rawapi(method, params, function (json) {
            if (json["result"] === "failure") {
                // Use the async.js value to halt execution with an error.
                die(json["reason"]);
            }

            return cb(json);
        });
    };

    // *************************************************************************
    // * User Interface Bindings
    // *************************************************************************

    $("#pause").click(function () {
        if (_paused) {
            $("#pause").val("Pause");
            paused = false;
        }
        else {
            $("#pause").val("Resume");
            _paused = true;
        }
    });

    $("#go").click(function () {
        // Ensure we've been given a name and a password.
        var name = $("#name").val();
        var password = $("#password").val();

        // These variables will be shared by all the anonymous functions below.
        var cards = null;
        var session = null;

        // Due to JavaScipt's asynchronous behaviour when using JSONP as a
        // communication method with external sites, we use the async library
        // here to allow us to think synchronously while it handles the details.
        //
        // To put another way, ignore all the anonymous functions below that
        // look like "function (cb) {...}" and pretend that everything you see
        // from here on out executes in order from top to bottom as you'd hope.
        async.series([
            function (cb) {
                // Calling cb with a null parameter means that there was no
                // error and that we should continue to the next anonymous
                // function in the sequence.
                return cb(null);
            },
            function (cb) {
                // Register the name, which will have no effect if you've already done it.
                rawapi("register", {"name": name, "password": password}, function (json) {
                    return cb(null);
                });
            },
            function (cb) {
                // Login with the name and password.
                info("Logging in to the server...");
                return api(cb, "login", {"name": name, "password": password}, function (json) {
                    info("Logged in.");

                    // Store the session from the login for future use.
                    session = json["session"];
                    info("Received session '" + session + "'.");

                    return cb(null);
                });
            },
            function (cb) {
                var newgame_loop = function (cb) {
                    // If we're paused...
                    if (_paused) {
                        // Start again at the top of the loop in one second.
                        setTimeout(function () { newgame_loop(cb); }, 1000);

                        // Skip the remainder of this iteration.
                        return;
                    }

                    // Ask to be given an opponent to play against.
                    info("Attempting to start a new game...");
                    return api(cb, "new-game", {"session": session}, function (json) {
                        // If there's nobody to play against...
                        if (json["result"] === "retry") {
                            // Let the user know what's going on.
                            warn(json["reason"]);

                            // Start again at the top of the loop in five
                            // seconds.
                            setTimeout(function () { newgame_loop(cb); }, 5000);

                            // Skip the remainder of this iteration.
                            return;
                        }

                        // Create an object to represent the cards we have been dealt.
                        var cards = json["cards"];
                        info("We have started a new game, and have been dealt: " + cards.join(", ") + ".");

                        // Run the game AI.
                        new_game(cb, session, cards);

                        // Cleanup from our game.
                        info("Our role in this game is over, but we need to be sure the server has ended the game before we start a new one.");
                        info("If we try to start a new game without the old one being done, the server will reject our request.");
                        var status_loop = function (cb) {
                            info("Waiting for our game to be over...");
                            return api(cb, "status", {"session": session}, function (json) {
                                if (json["game"] !== null) {
                                    // Start again at the top of the loop in one
                                    // second..
                                    setTimeout(function () { status_loop(cb); }, 1000);
                                }
                            });
                        };
                        info("The server has ended our game.");

                        // Now that we're done this game, go to the top of the
                        // loop to get another game.
                        newgame_loop(cb);
                    });
                };

                // We want to go forever, playing games until we're manually
                // paused by the user.
                newgame_loop(cb);
            }],

            // This is the error function that is called if any of the anonymous
            // functions above calls cb without a null parameter.
            //
            // We ignore the res parameter, it's not necessary for how we've
            // structured our program.
            function (err, res) {
                failure(err);
            }
        );
    });

    var new_game = function (cb, session, hand) {
        // Due to JavaScipt's asynchronous behaviour when using JSONP as a
        // communication method with external sites, we use the async library
        // here to allow us to think synchronously while it handles the details.
        //
        // To put another way, ignore all the anonymous functions below that
        // look like "function (cb) {...}" and pretend that everything you see
        // from here on out executes in order from top to bottom as you'd hope.
        async.series([
            function (cb) {
                // Make a bid, which we'll do randomly, by choosing a number
                // between 1 and 13.
                var bid = Math.floor(1 + (Math.random() * 13));

                // Register our bid with the server.
                info("Attempting to bid " + bid + ".");
                return api(cb, "bid", {"session": session, "bid": bid}, function (json) {
                    info("Our bid has been accepted.");
                    return cb(null);
                });
            },
            function (cb) {
                // Check the status repeatedly, and if it's our turn play a
                // card, until all cards have been played and the game ends.
                var play_loop = function (cb, delayed) {
		    warn("hand: " + hand.join(", "));
                    // Check if we've played all of our cards, in which case the
                    // game is over.
                    if (hand.length === 0) {
                        return cb(null);
                    }

                    // Always wait 1 second, it may not seem like much but it
                    // helps avoid pinning the client's CPU and flooding the
                    // server.
                    //
                    // This only occurs if we aren't being called on a delay, to
                    // prevent infinite timeout loops.
                    if (delayed !== false) {
                        setTimeout(function () { play_loop(cb, false); }, 1000);
                    }

                    // Request the game's status from the server.
                    info("Requesting the status of our game...");
                    return api(cb, "status", {"session": session}, function (json) {
                        info("Status received.");

                        // If the game has ended prematurely, due to a forfeit
                        // from your opponent or some other reason, rejoice and
                        // find a new opponent.
                        if (json["game"] === null) {
                            info("Our game appears to have ended.");
                            play_loop(cb);
                            return;
                        }

                        // If we're still in the bidding process, it's nobody's
                        // turn.
                        if (json["your-turn"] === null) {
                            info("Our game is still in the bidding phase, we need to wait for our opponent.");
                            play_loop(cb);
                            return;
                        }

                        // If not it's not our turn yet, jump back to the top of
                        // the loop to check the status again.
                        if (json["your-turn"] === false) {
                            info("It is currently our opponent's turn, we need to wait for our opponent.");
                            play_loop(cb);
                            return;
                        }

                        // Finally, it's our turn. First, we have to determine
                        // if another card was played first in this round. If
                        // so, it restricts our choices.
                        var allowed_cards;
                        if (json["opponent-current-card"] === null) {
                            // We can play any card we want, since we're going
                            // first in this round. So all the cards in our hand
                            // are allowed.
                            allowed_cards = hand;
                            info("We have the lead this round, so we may choose any card.");
                        }
                        else {
                            // We can only play cards that match the suit of the
                            // lead card, since we're going second in this
                            // round. Gather together all the cards in our hand
                            // that have the appropriate suit.
                            var lead_card = parse_card(json["opponent-current-card"]);
                            info("Our opponent has lead this round, so we must try to play a card that matches the lead card's suit: " + lead_card["suit"] + ".");

                            allowed_cards = [];
                            for (var i = 0; i < hand.length; i++) {
                                card = parse_card(hand[i]);
                                if (card["suit"] === lead_card["suit"]) {
                                    allowed_cards.push(hand[i]);
                                }
                            }

                            // Check if we actually found any cards in our hand with
                            // the appropriate suit. If we don't have any, there are
                            // no restrictions on the card we can then play.
                            if (allowed_cards.length === 0) {
				info("We have no " + lead_card["suit"] + " in our hand, so we can play any suit we choose.");
				allowed_cards = hand;
                            }
			}

                        // Among the cards that we have determined are valid,
                        // according to the rules, choose one to play at random.
                        var idx = Math.floor(Math.random() * allowed_cards.length);
			warn("{len:" + allowed_cards.length + ", idx:" + idx + "}");
                        var card = allowed_cards[idx];
                        info("We have randomly chosen " + card + ".");

                        // Now that the card has been chosen, play it.
                        info("Attempting to play " + card + "...");
                        return api(cb, "play", {"session": session, "card": card}, function (json) {
                            info("Card has been played.");

                            // Remove the card from our hand.
                            var new_hand = [];
                            for (var i = 0; i < hand.length; i++) {
                                if (hand[i] !== card) {
                                    new_hand.push(hand[i]);
                                }
                            }
                            hand = new_hand;

                            // Continue executing the loop.
                            play_loop(cb);
                        });
                    });
                };

                // Start executing the loop.
                play_loop(cb);
            }],

            // This is the error function that is called if any of the anonymous
            // functions above calls cb without a null as the first parameter.
            //
            // We ignore the res parameter, it's not necessary for how we've
            // structured our program.
            function (err, res) {
                failure(err);
            }
        );
    };
});
