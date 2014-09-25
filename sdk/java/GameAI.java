import java.io.BufferedReader;
import java.io.DataOutputStream;
import java.io.InputStreamReader;
import java.net.HttpURLConnection;
import java.net.URL;
import java.util.HashSet;
import java.util.Iterator;
import java.util.Random;
import java.util.Set;

import org.json.*;

public class GameAI {
	public enum Suit {
		CLUBS,
		DIAMONDS,
		HEARTS,
		SPADES
	}

	public static class Card {
		public String abbr;
		public Suit suit;
		public int value;

		public Card(String s) {
			// Store the abbreviated string used to identify the
			// card, as it's needed when interfacing with the server.
			abbr = s;

			// Parse out the card's suit.
			switch (s.charAt(0)) {
			case 'C':
				suit = Suit.CLUBS;
				break;
			case 'D':
				suit = Suit.DIAMONDS;
				break;
			case 'H':
				suit = Suit.HEARTS;
				break;
			case 'S':
				suit = Suit.SPADES;
				break;
			}

			// Parse out the card's value.
			value = Integer.parseInt(s.substring(1, 3));
		}

		public String toString() {
			return abbr;
		}
	}

	public static String base = "http://gameai.skullspace.ca/api/";

	public static void failure(String message) {
		System.err.println("!! " + message);
		System.exit(1);
	}

	public static void info(String message) {
		System.err.println("** " + message);
	}

	public static JSONObject rawapi(String method, Object ... params) throws Exception {
		// Collect parameters into a JSON object.
		JSONObject json = new JSONObject();
		for (int i = 0; i < params.length - 1; i += 2) {
			json.put((String) params[i], params[i + 1]);
		}

		// Create the URL of the endpoint.
		URL url = new URL(base + method + "/");

		// Create a new HTTP request to the endpoint.
		HttpURLConnection con = (HttpURLConnection) url.openConnection();

		// Set up the HTTP request's properties.
		con.setRequestMethod("POST");
		con.setRequestProperty("Content-type", "application/json");

		// Place the JSON in the request's body.
		con.setDoOutput(true);
		DataOutputStream wr = new DataOutputStream(con.getOutputStream());
		wr.writeBytes(json.toString());
		wr.flush();
		wr.close();

		// Read the HTTP response code.
		if (con.getResponseCode() != 200) {
			failure("The server responded with a status code other than 200.");
		}

		// Read the HTTP response body.
		BufferedReader in = new BufferedReader(new InputStreamReader(con.getInputStream()));
		StringBuffer response = new StringBuffer();
		String inputLine;
		while ((inputLine = in.readLine()) != null) {
			response.append(inputLine);
		}
		in.close();

		return new JSONObject(response.toString());
	}

	public static JSONObject api(String method, Object ... params) throws Exception {
		JSONObject json = rawapi(method, params);
		if (json.getString("result").equals("failure") == true) {
			failure(json.getString("reason"));
		}

		return json;
	}

	public static void main(String[] args) throws Exception {
		// Ensure we've been given a name and a password.
		String name;
		String pass;
		if (args.length != 2) {
			System.out.print("Enter your bot's name: ");
			name = System.console().readLine();

			System.out.print("Enter your bot's password: ");
			pass = System.console().readLine();
		}
		else
		{
			name = args[0];
			pass = args[1];
		}

		// Register the name, which will have no effect if you've
		// already done it.
		rawapi("register", "name", name, "password", pass);

		// Login with the name and password.
		info("Logging in to the server...");
		JSONObject json = api("login", "name", name, "password", pass);
		info("Logged in.");

		// Store the session from the login for future use.
		String session = json.getString("session");
		info("Received session '" + session + "'.");

		while (true) {
			// Ask to be given an opponent to play against.
			info("Attempting to start a new game...");
			json = api("new-game", "session", session);

			// If there's nobody to play against, start the loop
			// from the top after waiting 5 seconds.
			if (json.getString("result").equals("retry") == true) {
				System.out.println("?? " + json.getString("reason"));
				Thread.sleep(5000);
				continue;
			}

			// Create an object to represent the cards we have been dealt.
			JSONArray cards = (JSONArray) json.get("cards");
			info("We have started a new game, and have been dealt: " + cards + ".");
			Set<Card> hand = new HashSet<Card>();
			for (int i = 0; i < cards.length(); i++) {
				hand.add(new Card(cards.getString(i)));
			}

			// Run the game AI.
			new_game(session, hand);

			// Cleanup from our game.
			info("Our role in this game is over, but we need to be sure the server has ended the game before we start a new one.");
			info("If we try to start a new game without the old one being done, the server will reject our request.");
			while (true) {
				info("Waiting for our game to be over...");
				json = api("status", "session", session);
				if (json.isNull("game") == true) {
					break;
				}
				Thread.sleep(1000);
			}
			info("The server has ended our game.");
		}
	}

	public static void new_game(String session, Set<Card> hand) throws Exception {
		// Make a bid, which we'll do randomly, by choosing a number
		// between 1 and 13.
		Random random = new Random();
		int bid = 1 + random.nextInt(13);

		// Register our bid with the server.
		info("Attempting to bid " + bid + ".");
		api("bid", "session", session, "bid", bid);
		info("Our bid has been accepted.");

		// Check the status repeatedly, and if it's our turn play a
		// card, until all cards have been played and the game ends.
		while (hand.isEmpty() == false) {
			// Always wait 1 second, it may not seem like much but
			// it helps avoid pinning the client's CPU and flooding
			// the server.
			Thread.sleep(1000);

			// Request the game's status from the server.
			info("Requesting the status of our game...");
			JSONObject json = api("status", "session", session);
			info("Status received.");

			// If the game has ended prematurely, due to a forfeit
			// from your opponent or some other reason, rejoice and
			// find a new opponent.
			if (json.isNull("game") == true) {
				info("Our game appears to have ended.");
				return;
			}

			// If we're still in the bidding process, it's nobody's
			// turn.
			if (json.isNull("your-turn") == true) {
				info("Our game is still in the bidding phase, we need to wait for our opponent.");
				continue;
			}

			// If not it's not our turn yet, jump back to the top of
			// the loop to check the status again.
			if (json.getBoolean("your-turn") == false) {
				info("It is currently our opponent's turn, we need to wait for our opponent.");
				continue;
			}

			// Finally, it's our turn. First, we have to determine
			// if another card was played first in this round. If
			// so, it restricts our choices.
			Set<Card> allowed_cards;
			if (json.isNull("opponent-current-card") == true) {
				// We can play any card we want, since we're
				// going first in this round. So all the cards
				// in our hand are allowed.
				allowed_cards = hand;
				info("We have the lead this round, so we may choose any card.");
			}
			else {
				// We can only play cards that match the suit of
				// the lead card, since we're going second in
				// this round. Gather together all the cards in
				// our hand that have the appropriate suit.
				allowed_cards = new HashSet<Card>();
				Card lead_card = new Card(json.getString("opponent-current-card"));
				info("Our opponent has lead this round, so we must try to play a card that matches the lead card's suit: " + lead_card.suit + ".");

				for (Card card : hand) {
					if (card.suit == lead_card.suit) {
						allowed_cards.add(card);
					}
				}

				// Check if we actually found any cards in our
				// hand with the appropriate suit. If we don't
				// have any, there are no restrictions on the
				// card we can then play.
				if (allowed_cards.isEmpty() == true) {
					info("We have no " + lead_card.suit + " in our hand, so we can play any suit we choose.");
					allowed_cards = hand;
				}
			}

			// Among the cards that we have determined are valid,
			// according to the rules, choose one to play at random.
			Iterator<Card> it = allowed_cards.iterator();
			Card card = it.next();
			for (int i = 0; i < random.nextInt(allowed_cards.size()); i++) {
				card = it.next();
			}
			info("We have randomly chosen " + card + ".");

			// Now that the card has been chosen, play it.
			info("Attempting to play " + card + "...");
			api("play", "session", session, "card", card.toString());
			info("Card has been played.");

			// Remove the card from our hand.
			hand.remove(card);
		}
	}
}
