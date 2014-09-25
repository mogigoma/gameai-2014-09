#!/usr/bin/env ruby

require "json"
require "net/http"
require "set"
require "uri"

$base = "http://gameai.skullspace.ca/api/"

class Card
  attr_reader :abbr, :suit, :value

  def initialize(abbr)
    # Store the abbreviated string used to identify the card, as it's needed
    # when interfacing with the server.
    @abbr = abbr

    # Parse out the card's suit.
    @suit = {
      "C" => :CLUBS,
      "D" => :DIAMONDS,
      "H" => :HEARTS,
      "S" => :SPADES
    }[@abbr[0]]

    # Parse out the card's value.
    @value = @abbr[1..2].to_i
  end

  def to_s
    return @abbr
  end
end

def failure(msg)
  puts("!! #{msg}")
  exit(1)
end

def info(msg)
  puts("** #{msg}")
end

def rawapi(method, params={})
  # Collect parameters into a JSON object.
  json = JSON.generate(params)

  # Create the URL of the endpoint.
  url = URI($base + method + "/")

  # Create a new HTTP request to the endpoint.
  con = Net::HTTP.new(url.host, url.port)
  req = Net::HTTP::Post.new(url.path)
  req["Content-Type"] = "application/json"
  req.body = json

  # Send the HTTP request.
  res = con.request(req)

  # Read the HTTP response code.
  if res.code != "200"
    failure("The server responded with a status code other than 200.")
  end

  # Read the HTTP response body.
  body = res.body()

  return JSON.parse(body)
end

def api(method, params={})
  json = rawapi(method, params)
  if json["result"] == "failure"
    failure(json["reason"])
  end

  return json
end

def main(argv)
  # Ensure we've been given a name and a password.
  if argv.length != 2
    print("Enter your bot's name: ")
    name = $stdin.gets.chomp()
    print("Enter your bot's password: ")
    password = $stdin.gets.chomp()
  else
    name = argv[0]
    password = argv[1]
  end

  # Register the name, which will have no effect if you've already done it.
  rawapi("register", {:name => name, :password => password})

  # Login with the name and password.
  info("Logging in to the server...")
  json = api("login", {:name => name, :password => password})
  info("Logged in.")

  # Store the session from the login for future use.
  session = json["session"]
  info("Received session '#{session}'.")

  while true
    # Ask to be given an opponent to play against.
    info("Attempting to start a new game...")
    json = api("new-game", {:session => session})

    # If there's nobody to play against, start the loop from the top after
    # waiting 5 seconds.
    if json["result"] == "retry"
      puts("?? #{json['reason']}")
      sleep(5)
      next
    end

    # Create an object to represent the cards we have been dealt.
    cards = json["cards"]
    info("We have started a new game, and have been dealt: #{cards.join(', ')}.")
    hand = Set.new(cards.map { |c| Card.new(c) })

    # Run the game AI.
    new_game(session, hand)

    # Cleanup from our game.
    info("Our role in this game is over, but we need to be sure the server has ended the game before we start a new one.")
    info("If we try to start a new game without the old one being done, the server will reject our request.")
    while true
      info("Waiting for our game to be over...")
      json = api("status", {:session => session})
      if json["game"].nil?
        break
      end
      sleep(1)
      info("The server has ended our game.")
    end
  end
end

def new_game(session, hand)
  # Make a bid, which we'll do randomly, by choosing a number between 1 and 13.
  bid = Random.new.rand(1..13)

  # Register our bid with the server.
  info("Attempting to bid #{bid}.")
  api("bid", {:session => session, :bid => bid})
  info("Our bid has been accepted.")

  # Check the status repeatedly, and if it's our turn play a card, until all
  # cards have been played and the game ends.
  while not hand.empty?
    # Always wait 1 second, it may not seem like much but it helps avoid pinning
    # the client's CPU and flooding the server.
    sleep(1)

    # Request the game's status from the server.
    info("Requesting the status of our game...")
    json = api("status", {:session => session})
    info("Status received.")

    # If the game has ended prematurely, due to a forfeit from your opponent or
    # some other reason, rejoice and find a new opponent.
    if json["game"].nil?
      info("Our game appears to have ended.")
      return
    end

    # If we're still in the bidding process, it's nobody's turn.
    if json["your-turn"].nil?
      info("Our game is still in the bidding phase, we need to wait for our opponent.")
      next
    end

    # If not it's not our turn yet, jump back to the top of the loop to check
    # the status again.
    if json["your-turn"] == false
      info("It is currently our opponent's turn, we need to wait for our opponent.")
      next
    end

    # Finally, it's our turn. First, we have to determine if another card was
    # played first in this round. If so, it restricts our choices.
    if json["opponent-current-card"].nil?
      # We can play any card we want, since we're going first in this round. So
      # all the cards in our hand are allowed.
      allowed_cards = hand
      info("We have the lead this round, so we may choose any card.")
    else
      # We can only play cards that match the suit of the lead card, since we're
      # going second in this round. Gather together all the cards in our hand
      # that have the appropriate suit.
      allowed_cards = Set.new()
      lead_card = Card.new(json["opponent-current-card"])
      info("Our opponent has lead this round, so we must try to play a card that matches the lead card's suit: #{lead_card.suit}.")

      for card in hand
        if card.suit == lead_card.suit
          allowed_cards.add(card)
        end
      end

      # Check if we actually found any cards in our hand with the appropriate
      # suit. If we don't have any, there are no restrictions on the card we can
      # then play.
      if allowed_cards.empty?
        info("We have no #{lead_card.suit} in our hand, so we can play any suit we choose.")
        allowed_cards = hand
      end
    end

    # Among the cards that we have determined are valid, according to the rules,
    # choose one to play at random.
    idx = Random.new.rand(0...allowed_cards.length)
    card = allowed_cards.to_a()[idx]
    info("We have randomly chosen #{card}.")

    # Now that the card has been chosen, play it.
    info("Attempting to play #{card}...")
    api("play", {:session => session, :card => card.to_s()})
    info("Card has been played.")

    # Remove the card from our hand.
    hand.delete(card)
  end
end

main(ARGV)
