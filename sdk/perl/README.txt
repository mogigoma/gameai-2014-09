################################################################################
# Usage
################################################################################

*** This bot was only tested with Perl 5.16.3. ***

To run this bot, ensure that you have the appropriate libraries installed, see
the Development section for details. The bot can be run from the command line
with the bot's name and password. If the name and password are not given, the
bot will prompt the user to enter them.

The bot will connect to the server, attempt to register the bot, and then login.
Once logged in, the bot will endlessly play the game until the user terminates
the bot manually.

################################################################################
# Development
################################################################################

Due to Perl no including support for JSON or HTTP in its core, the following
libraries are required:

    - JSON-PP-2.272.0
    - libwww-6.50.0

Both of these libraries are commonly avaliable in the package manager for your
system.

The game's basic rules and the sequence of API calls for interacting with the
server are all written already. Each time the bot gets to make a decision, it
decides randomly among all valid choices available to it. It's your job to
improve this algorithm.
