################################################################################
# Usage
################################################################################

*** THIS BOT WAS ONLY TESTED ON WINDOWS 7 AND VISUAL STUDIO 2013 ***

To run this bot, load GameAI.sln in Visual Studio, and build the project. The
bot can be run from the command line with the bot's name and password. If the
name and password are not given, the bot will prompt the user to enter them.

The bot will connect to the server, attempt to register the bot, and then login.
Once logged in, the bot will endlessly play the game until the user terminates
the bot manually.

################################################################################
# Development
################################################################################

This bot uses only libraries that are included with .Net 4, to avoid the hassle
of installing external libraries.

The game's basic rules and the sequence of API calls for interacting with the
server are all written already. Each time the bot gets to make a decision, it
decides randomly among all valid choices available to it. It's your job to
improve this algorithm.
