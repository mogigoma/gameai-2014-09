################################################################################
# Introduction
################################################################################

*** The example bots are fully-functional, go run one to get a feel for the  ***
*** the game, it'll help you understand the rest of this file.               ***

*** All communication and coordination for this event will be done via the   ***
*** #SkullSpace channel on the FreeNode IRC network and the news sidebar on  ***
*** the main page at http://gameai.skullspace.ca/.                           ***

This archive contains seven example bots, each capable of playing SkullWhist,
the game that has been created for this event. The bots are found in directories
that match the language they were programmed in, which are:

    - C#
    - Java
    - JavaScript
    - Perl
    - PHP
    - Python
    - Ruby

Each directory contains a README.txt file that covers any additional libraries
or command line options required to build or use the bot. These bots have been
programmed as directly as possible, meaning that they avoid any clever or fancy
features of each programming language.

Each of the example bots executes the same algorithm, and comments, control
flow, and identifiers are the same across the bots. This should make it easier
for you to tackle a new language for the event as a learning experience, if you
know one of the other languages.

################################################################################
# API
################################################################################

The API documentation can be found online, and is located at:

    http://gameai.skullspace.ca/api/

Each API endpoint has two modes. If triggered via a GET request, the endpoint
will return the documentation regarding how to communicate with the endpoint. If
triggered via a POST request, the endpoint will look for and process the JSON in
the request body, and respond with JSON in the response body.

The state machine that is illustrated in the API documentation represents the
most common interactions expected from bots, and is what the example bots all
do. Here is pseudocode for the interaction sequence:

    - Attempt to register a new bot
      - If the registration fails, don't worry about it
    - Attempt to login with a bot's name and password
      - If that doesn't work, exit
    - In a never ending loop, do the following:
      - Repeatedly try to join a new game
      - Once a game is joined, notify the server of your bid
      - For thirteen rounds:
        - Wait for it to be our turn
        - Pick a random card, as restricted by the game's rules
      - Wait for the game to end on the server

################################################################################
# SkullWhist
################################################################################

The game for this event is 'SkullWhist', a heavily modified form of Bid Whist.
It has been modified to be quicker (one round), simpler (static trump suit, no
kitty), and two-player (not four). The name SkullWhist was chosen due to its
undisputable awfulness.

To start a game, both bots must trigger the <new-game> endpoint. Once two bots
are matched, both will be given a hand consisting of 13 random cards from a
standard 52-card deck, and the remaining cards will be thrown out. Each card has
a suit, which is one of:

    - C for Clubs
    - D for Diamonds
    - H for Hearts
    - S for Spades

In addition, each card will have a value between 1 and 13, inclusive. Using
integers helps avoid the need to add conversion logic between face cards and
integer values in the bots.

Once each bot has been given their hand of cards for the game, they must submit
a bid to the server using the <bid> endpoint. The bid must be an integer between
1 and 13, inclusive, and represents the number of rounds (also called 'tricks')
the bot thinks it can win based on the cards in its hand. Once bidding has
completed, play begins.

Each round consists of two turns, one for each bot. In the first round, the
order of the turns is chosen randomly, and is indicated to the bot by the
'your-turn' key returned by the <status> endpoint. The bot that leads the round,
meaning having the first turn, may choose to play any card in its hand. If the
bot instead has the second turn in the round, it is restricted in the card it
may play. The card played in the first turn of a round determines the suit for
the round, and the bot with the second turn must play a card matching that suit
if it has any cards of that suit remaining in its hand. If the bot with the
second turn has no cards matching the suit of the card played in the first turn,
it may choose to play any card in its hand.

Once both turns have been taken, and both cards have been played, for a round,
the server determines the winner of the round and awards a trick to one of the
two bots, and grants the winning bot the lead turn on the next round. The winner
of a round is determined as follows:

    - If the cards both had the same suit, the card with the greatest value wins
    - If the cards had different suits:
      - If the second card's suit was Spades, the second card wins
      - Otherwise, the first card wins

The rules for awarding a trick are complicated by Spades being the trump suit,
which gives any card in the suit of Spades the following properties:

    - When comparing a card in the suit of Spades to a card of another suit, the
      card in the Suit of spades wins
    - When comparing two cards in the suit of Spades, the card with the greatest
      value wins

Once 13 rounds have been played, the server will score the game. Scoring gives
great weight to the accuracy of each bot's bid when compared to the tricks it
has been awarded. The score for each bot is calculated as follows:

    - If the number of tricks matches the bid:
      --> score = bid * +10
    - If the number of tricks is less than the bid:
      --> score = bid * -10 [NEGATIVE!]
    - If the number of tricks is greater than the bid:
      --> score = (bid * +10) + (tricks - bid)

Seeing the rules written out like this is more difficult to understand than an
example, so here are some:

    1) This shows that a bot with more accurate bidding logic can match the
       score of a bot with smarter playing logic:

       Bot 1 had bid = 1 and tricks = 11
       --> score = (1 * +10) + (11 - 1) = 10 + 10 = 20

       Bot 2 had bid = 2 and tricks = 2
       --> score = (2 * +10) = 20

    2) This shows that when two bots both play it safe on bidding, the bot with
       the smarter playing logic will win:

       Bot 1 had bid = 1 and tricks = 7
       --> score = (1 * +10) + (7 - 1) = 10 + 6 = 16

       Bot 2 had bid = 1 and tricks = 6
       --> score = (1 * +10) + (6 - 1) = 10 + 5 = 15

    3) This shows that when two bots both fail to make their bid, they both
       receive negative scores. As scores are compared numerically, Bot 2 would
       win by virtue of its lower bid:

       Bot 1 had bid = 8 and tricks = 7
       --> score = (8 * -10) = -80

       Bot 2 had bid = 5 and tricks = 6
       --> score = (5 * -10) = -50

The number of tricks awarded to each player in any completed game will add up to
13, the number of rounds.

After scoring has completed, both bots will be released from their game by the
server, and will be free to call the <new-game> endpoint again. Unlike regular
Bid Whist which accumulates scores across multiple rounds, ending when one
player has reached a certain score threshold, each game of SkullWhist consists
of a single, independent round.

################################################################################
# Glossary
################################################################################

Bid   :: An integer between 1-13, inclusive.

Round :: An interaction consisting of two turns.

Suit  :: One of Clubs, Diamonds, Hearts, and Spades.

Trick :: A round that has been scored

Trump :: A card in the suit of Spades.

Turn  :: The playing of a card by a bot.

Value :: An integer between 1-13, inclusive.

################################################################################
# AI
################################################################################

This game has 13 decisions in it. The decisions are as follows:

     1) What number of tricks to bid, from 1-13
     2) Which card to play in round  1, from 13 options
     3) Which card to play in round  2, from 12 options
     4) Which card to play in round  3, from 11 options
     5) Which card to play in round  4, from 10 options
     6) Which card to play in round  5, from  9 options
     7) Which card to play in round  6, from  8 options
     8) Which card to play in round  7, from  7 options
     9) Which card to play in round  8, from  6 options
    10) Which card to play in round  9, from  5 options
    11) Which card to play in round 10, from  4 options
    12) Which card to play in round 11, from  3 options
    13) Which card to play in round 12, from  2 options

In round 13, only one card is left, and therefore the bot has no choice in which
card is played. Currently, the example bots all choose randomly from the set of
all the valid choices allowed, leading to any two example bots to each win about
half the time in our server burn-in tests.

There are two main facets of the AI for a bot: bidding and playing.

The bidding algorithm gets to make choice 1. Careful bidding is important as
tricks awarded that are within the value of the bid are worth ten times more
than extra tricks above the bid, and bidding too high is catastrophic,
especially in this single-round version of the game.

The playing algorithm gets to make choices 2-13. The <status> endpoint offers
both the opponent's ID and its bid for this round, which can be used to tailor
your bot's strategy. The opponent's bid is made available to you only after your
bot has bid.

Some things to think about when designing your AI, in no particular order:

    - Does the bid your opponent makes tell you anything about the cards it has?
      The number of trump, or the number of cards above 10, say?

    - You know your opponent has 13 cards out of 52, and you also know what your
      13 cards are, so you have some idea of what they can and cannot have in
      their hand to start.

    - When an opponent plays an off-suit card in the second turn, you know that
      they no longer have any cards in the suit of the card played in the first
      turn.

    - When you have the opportunity to play an off-suit card, consider whether
      you want to:
      - Play one of your trump cards
      - Play a card that has no current strategic value
        - A card in a suit you know the opponent no longer has, and can
          therefore be trumped by them
        - A low-value card in a suit you know your opponent still has but will
          reduce the number of cards you have in that suit, so you can trump in
          that suit sooner.

One endpoint that is not used by the example bots is <old-game>, which lets you
look up all the information related to a game once it is over. This information
includes the starting hands dealt to each player, the bids, the tricks, the
scores, and the events of each round. You may also find the statistics and
records pages on the website useful, especially notably are the 'versus'
comparisons between bots if you are looking to make your AI opponent-specific.

That's all I have to say about your AI right now, other than a suggestion to
retain the basic interaction model provided in the example bots.

If another endpoint is needed to implement something for your bot, ask me (Mak)
on IRC and I'll consider it if I have time.

################################################################################
# Future
################################################################################

Assuming this event isn't a discouraging disaster, I hope to have another,
larger event in 2015. If that interests you, please sign up to our mailing list
to be contacted about future developments:

    https://groups.google.com/a/skullspace.ca/forum/#!forum/gameai
