################################################################################
# Usage
################################################################################

To run this bot, use javac to compile GameAI.java to bytecode with the following
command line invocation:

    javac -cp org.json-20131017.jar GameAI.java

The -cp argument is necessary to include the JSON library, due to Java not
having one included. To run the bot, you use the following command line:

    java -cp .:org.json-20131017.jar GameAI

The call to the java JVM above may be different on Windows, owing to semicolons
being the preferred separator. The command line above may include the bot's name
and password. If the name and password are not given, the bot will prompt the
user to enter them.

The bot will connect to the server, attempt to register the bot, and then login.
Once logged in, the bot will endlessly play the game until the user terminates
the bot manually.

################################################################################
# Development
################################################################################

This bot uses the JSON library recommended by http://www.json.org/java/.

The game's basic rules and the sequence of API calls for interacting with the
server are all written already. Each time the bot gets to make a decision, it
decides randomly among all valid choices available to it. It's your job to
improve this algorithm.
