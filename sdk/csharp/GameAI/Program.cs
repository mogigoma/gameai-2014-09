using System;
using System.Collections;
using System.Collections.Generic;
using System.IO;
using System.Linq;
using System.Net;
using System.Text;
using System.Threading.Tasks;
using System.Web.Script.Serialization;

namespace GameAI
{
    class Card
    {
        public string Abbr;
        public string Suit;
        public int Value;

        public Card(string s)
        {
            Abbr = s;

            switch (s[0])
            {
            case 'C':
                Suit = "CLUBS";
                break;
            case 'D':
                Suit = "DIAMONDS";
                break;
            case 'H':
                Suit = "HEARTS";
                break;
            case 'S':
                Suit = "SPADES";
                break;
            }

            Value = Convert.ToInt32(s.Substring(1, 2));
        }

        public override string ToString()
        {
            return Abbr;
        }
    }

    class Program
    {
        static string base_url = "http://gameai.skullspace.ca/api/";

        static void Failure(string msg)
        {
            Console.WriteLine("!! " + msg);
            Console.ReadKey();
            Environment.Exit(1);
        }

        static void Info(string msg)
        {
            Console.WriteLine("** " + msg);
        }

        static Dictionary<string, object> RawApi(string method, params object[] parameters)
        {
            // Collect parameters into a JSON object.
            var pairs = new Dictionary<string, object>();
            for (int i = 0; i < parameters.Length; i += 2)
            {
                pairs.Add((string) parameters[i], parameters[i + 1]);
            }

            var serializer = new JavaScriptSerializer();
            var json = serializer.Serialize(pairs);

            // Create the URL of the endpoint.
            string url = base_url + method + "/";

            // Create a new HTTP request to the endpoint.
            WebRequest req = WebRequest.Create(url);

            // Set up the HTTP request's properties.
            req.Method = "POST";
            req.ContentType = "application/json";

            // Place the JSON in the request's body.
            byte[] body = Encoding.UTF8.GetBytes(json);
            req.ContentLength = body.Length;
            Stream stream = req.GetRequestStream();
            stream.Write(body, 0, body.Length);
            stream.Close();

            // Read the HTTP response body.
            WebResponse res = req.GetResponse();
            stream = res.GetResponseStream();
            StreamReader read = new StreamReader(stream);
            json = read.ReadToEnd();
            read.Close();
            stream.Close();
            res.Close();

            return serializer.Deserialize<Dictionary<string, object>>(json);
        }

        static Dictionary<string, object> Api(string method, params object[] parameters)
        {
            var json = RawApi(method, parameters);

            if ((string)json["result"] == "failure")
            {
                Failure((string)json["reason"]);
            }

            return json;
        }

        static void Main(string[] args)
        {
            // Ensure we've been given a name and a password.
            string name;
            string pass;
            if (args.Length != 2)
            {
                Console.Write("Enter your bot's name: ");
                name = Console.ReadLine();

                Console.Write("Enter your bot's password: ");
                pass = Console.ReadLine();
            }
            else
            {
                name = args[0];
                pass = args[1];
            }

            // Register the name, which will have no effect if you've already
            // done it.
            RawApi("register", "name", name, "password", pass);

            // Login with the name and password.
            Info("Logging in to the server...");
            var json = Api("login", "name", name, "password", pass);
            Info("Logged in.");

            // Store the session from the login for future use.
            string session = (string)json["session"];

            while (true)
            {
                // Ask to be given an opponent to play against.
                Info("Attempting to start a new game...");
                json = Api("new-game", "session", session);

                // If there's nobody to play against, start the loop from the
                // top after waiting 5 seconds.
                if ((string)json["result"] == "retry")
                {
                    Console.WriteLine("?? " + (string)json["reason"]);
                    System.Threading.Thread.Sleep(5000);
                    continue;
                }

                // Create an object to represent the cards we have been dealt.
                string[] cards = (string[])((ArrayList)json["cards"]).ToArray(typeof(string));

                Info("We have started a new game, and have been dealt: " + string.Join(", ", cards) + ".");
                HashSet<Card> hand = new HashSet<Card>();
                foreach (string card in cards)
                {
                    hand.Add(new Card(card));
                }

                // Run the game AI.
                NewGame(session, hand);

                // Cleanup from our game.
                Info("Our role in this game is over, but we need to be sure the server has ended the game before we start a new one.");
                Info("If we try to start a new game without the old one being done, the server will reject our request.");
                while (true)
                {
                    Info("Waiting for our game to be over...");
                    json = Api("status", "session", session);
                    if (json["game"] == null)
                    {
                        break;
                    }
                    System.Threading.Thread.Sleep(1000);
                }
                Info("The server has ended our game.");
            }
        }

        public static void NewGame(string session, HashSet<Card> hand)
        {
            // Make a bid, which we'll do randomly, by choosing a number between
            // 1 and 13.
            Random random = new Random();
            int bid = random.Next(1, 14);

            // Register our bid with the server.
            Info("Attempting to bid " + bid + ".");
            var json = Api("bid", "session", session, "bid", bid);
            Info("Our bid has been accepted.");

            // Check the status repeatedly, and if it's our turn play a card,
            // until all cards have been played and the game ends.
            while (hand.Any())
            {
                // Always wait 1 second, it may not seem like much but it helps
                // avoid pinning the client's CPU and flooding the server.
                System.Threading.Thread.Sleep(1000);

                // Request the game's status from the server.
                Info("Requesting the status of our game...");
                json = Api("status", "session", session);
                Info("Status received.");

                // If the game has ended prematurely, due to a forfeit from your
                // opponent or some other reason, rejoice and find a new
                // opponent.
                if (json["game"] == null)
                {
                    Info("Our game appears to have ended.");
                    return;
                }

                // If we're still in the bidding process, it's nobody's turn.
                if (json["your-turn"] == null)
                {
                    Info("Our game is still in the bidding phase, we need to wait for our opponent.");
                    continue;
                }

                // If not it's not our turn yet, jump back to the top of the
                // loop to check the status again.
                if ((bool)json["your-turn"] == false)
                {
                    Info("It is currently our opponent's turn, we need to wait for our opponent.");
                    continue;
                }

                // Finally, it's our turn. First, we have to determine if
                // another card was played first in this round. If so, it
                // restricts our choices.
                HashSet<Card> allowed_cards;
                if (json["opponent-current-card"] == null)
                {
                    // We can play any card we want, since we're going first in
                    // this round. So all the cards in our hand are allowed.
                    allowed_cards = hand;
                    Info("We have the lead this round, so we may choose any card.");
                }
                else
                {
                    // We can only play cards that match the suit of the lead
                    // card, since we're going second in this round. Gather
                    // together all the cards in our hand that have the
                    // appropriate suit.
                    allowed_cards = new HashSet<Card>();
                    Card lead_card = new Card((string)json["opponent-current-card"]);
                    Info("Our opponent has lead this round, so we must try to play a card that matches the lead card's suit: " + lead_card.Suit + ".");

                    foreach (Card card in hand)
                    {
                        if (card.Suit == lead_card.Suit)
                        {
                            allowed_cards.Add(card);
                        }
                    }

                    // Check if we actually found any cards in our hand with the
                    // appropriate suit. If we don't have any, there are no
                    // restrictions on the card we can then play.
                    if (allowed_cards.Any() == false)
                    {
                        Info("We have no " + lead_card.Suit + " in our hand, so we can play any suit we choose.");
                        allowed_cards = hand;
                    }
                }

                // Among the cards that we have determined are valid, according
                // to the rules, choose one to play at random.
                Card chosen_card = allowed_cards.ElementAt(random.Next(0, allowed_cards.Count - 1));
                Info("We have randomly chosen " + chosen_card + ".");

                // Now that the card has been chosen, play it.
                Info("Attempting to play " + chosen_card + "...");
                json = Api("play", "session", session, "card", chosen_card.ToString());
                Info("Card has been played.");

                // Remove the card from our hand.
                hand.Remove(chosen_card);
            }
        }
    }
}
