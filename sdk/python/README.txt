################################################################################
# Usage
################################################################################

*** This bot was only tested with Python 2.7.6. ***

The bot can be run from the command line with the bot's name and password. If
the name and password are not given, the bot will prompt the user to enter them.

The bot will connect to the server, attempt to register the bot, and then login.
Once logged in, the bot will endlessly play the game until the user terminates
the bot manually.

################################################################################
# Development
################################################################################

This bot uses only built-in libraries, no additional Python Eggs should be
needed.

The game's basic rules and the sequence of API calls for interacting with the
server are all written already. Each time the bot gets to make a decision, it
decides randomly among all valid choices available to it. It's your job to
improve this algorithm.
