--
-- PostgreSQL database dump
--

SET statement_timeout = 0;
SET lock_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SET check_function_bodies = false;
SET client_min_messages = warning;

--
-- Name: plpgsql; Type: EXTENSION; Schema: -; Owner: 
--

CREATE EXTENSION IF NOT EXISTS plpgsql WITH SCHEMA pg_catalog;


--
-- Name: EXTENSION plpgsql; Type: COMMENT; Schema: -; Owner: 
--

COMMENT ON EXTENSION plpgsql IS 'PL/pgSQL procedural language';


SET search_path = public, pg_catalog;

--
-- Name: card; Type: TYPE; Schema: public; Owner: game
--

CREATE TYPE card AS ENUM (
    'C01',
    'C02',
    'C03',
    'C04',
    'C05',
    'C06',
    'C07',
    'C08',
    'C09',
    'C10',
    'C11',
    'C12',
    'C13',
    'D01',
    'D02',
    'D03',
    'D04',
    'D05',
    'D06',
    'D07',
    'D08',
    'D09',
    'D10',
    'D11',
    'D12',
    'D13',
    'H01',
    'H02',
    'H03',
    'H04',
    'H05',
    'H06',
    'H07',
    'H08',
    'H09',
    'H10',
    'H11',
    'H12',
    'H13',
    'S01',
    'S02',
    'S03',
    'S04',
    'S05',
    'S06',
    'S07',
    'S08',
    'S09',
    'S10',
    'S11',
    'S12',
    'S13'
);


ALTER TYPE public.card OWNER TO game;

SET default_tablespace = '';

SET default_with_oids = false;

--
-- Name: bots; Type: TABLE; Schema: public; Owner: game; Tablespace: 
--

CREATE TABLE bots (
    id integer NOT NULL,
    name character varying(32) NOT NULL,
    password character varying(255) NOT NULL,
    session character varying(255),
    session_timeout timestamp without time zone,
    game integer,
    game_timeout timestamp without time zone,
    cards card[],
    CONSTRAINT bots_name_check CHECK (((name)::text <> ''::text)),
    CONSTRAINT bots_password_check CHECK (((password)::text <> ''::text)),
    CONSTRAINT game_assoc CHECK (((((game IS NULL) AND (game_timeout IS NULL)) AND (cards IS NULL)) OR (((game IS NOT NULL) AND (game_timeout IS NOT NULL)) AND (cards IS NOT NULL)))),
    CONSTRAINT session_assoc CHECK ((((session IS NULL) AND (session_timeout IS NULL)) OR ((session IS NOT NULL) AND (session_timeout IS NOT NULL))))
);


ALTER TABLE public.bots OWNER TO game;

--
-- Name: bots_id_seq; Type: SEQUENCE; Schema: public; Owner: game
--

CREATE SEQUENCE bots_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public.bots_id_seq OWNER TO game;

--
-- Name: bots_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: game
--

ALTER SEQUENCE bots_id_seq OWNED BY bots.id;


--
-- Name: games; Type: TABLE; Schema: public; Owner: game; Tablespace: 
--

CREATE TABLE games (
    id integer NOT NULL,
    bot1 integer NOT NULL,
    bot2 integer NOT NULL,
    bid1 integer,
    bid2 integer,
    score1 integer,
    score2 integer,
    hand1 card[] NOT NULL,
    hand2 card[] NOT NULL,
    started timestamp without time zone DEFAULT now(),
    finished timestamp without time zone,
    round integer DEFAULT 1,
    tricks1 integer DEFAULT 0,
    tricks2 integer DEFAULT 0,
    forfeited_by integer,
    CONSTRAINT games_bid1_check CHECK (((bid1 >= 1) AND (bid1 <= 13))),
    CONSTRAINT games_bid2_check CHECK (((bid2 >= 1) AND (bid2 <= 13))),
    CONSTRAINT games_round_check CHECK (((round >= 1) AND (round <= 13))),
    CONSTRAINT games_score1_check CHECK (((score1 >= (-130)) AND (score1 <= 130))),
    CONSTRAINT games_score2_check CHECK (((score2 >= (-130)) AND (score2 <= 130))),
    CONSTRAINT games_tricks1_check CHECK (((tricks1 >= 0) AND (tricks1 <= 13))),
    CONSTRAINT games_tricks2_check CHECK (((tricks2 >= 0) AND (tricks2 <= 13)))
);


ALTER TABLE public.games OWNER TO game;

--
-- Name: games_id_seq; Type: SEQUENCE; Schema: public; Owner: game
--

CREATE SEQUENCE games_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public.games_id_seq OWNER TO game;

--
-- Name: games_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: game
--

ALTER SEQUENCE games_id_seq OWNED BY games.id;


--
-- Name: rounds; Type: TABLE; Schema: public; Owner: game; Tablespace: 
--

CREATE TABLE rounds (
    game integer NOT NULL,
    round integer NOT NULL,
    bot1 integer NOT NULL,
    bot2 integer NOT NULL,
    card1 card,
    card2 card,
    created timestamp without time zone DEFAULT now(),
    CONSTRAINT rounds_round_check CHECK (((round >= 1) AND (round <= 13)))
);


ALTER TABLE public.rounds OWNER TO game;

--
-- Name: id; Type: DEFAULT; Schema: public; Owner: game
--

ALTER TABLE ONLY bots ALTER COLUMN id SET DEFAULT nextval('bots_id_seq'::regclass);


--
-- Name: id; Type: DEFAULT; Schema: public; Owner: game
--

ALTER TABLE ONLY games ALTER COLUMN id SET DEFAULT nextval('games_id_seq'::regclass);


--
-- Name: bots_pkey; Type: CONSTRAINT; Schema: public; Owner: game; Tablespace: 
--

ALTER TABLE ONLY bots
    ADD CONSTRAINT bots_pkey PRIMARY KEY (id);


--
-- Name: games_pkey; Type: CONSTRAINT; Schema: public; Owner: game; Tablespace: 
--

ALTER TABLE ONLY games
    ADD CONSTRAINT games_pkey PRIMARY KEY (id);


--
-- Name: rounds_pkey; Type: CONSTRAINT; Schema: public; Owner: game; Tablespace: 
--

ALTER TABLE ONLY rounds
    ADD CONSTRAINT rounds_pkey PRIMARY KEY (game, round);


--
-- Name: unique_name; Type: INDEX; Schema: public; Owner: game; Tablespace: 
--

CREATE UNIQUE INDEX unique_name ON bots USING btree (name);


--
-- Name: unique_session; Type: INDEX; Schema: public; Owner: game; Tablespace: 
--

CREATE UNIQUE INDEX unique_session ON bots USING btree (session);


--
-- Name: games_bot1_fkey; Type: FK CONSTRAINT; Schema: public; Owner: game
--

ALTER TABLE ONLY games
    ADD CONSTRAINT games_bot1_fkey FOREIGN KEY (bot1) REFERENCES bots(id);


--
-- Name: games_bot2_fkey; Type: FK CONSTRAINT; Schema: public; Owner: game
--

ALTER TABLE ONLY games
    ADD CONSTRAINT games_bot2_fkey FOREIGN KEY (bot2) REFERENCES bots(id);


--
-- Name: games_forfeited_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: game
--

ALTER TABLE ONLY games
    ADD CONSTRAINT games_forfeited_by_fkey FOREIGN KEY (forfeited_by) REFERENCES bots(id);


--
-- Name: rounds_bot1_fkey; Type: FK CONSTRAINT; Schema: public; Owner: game
--

ALTER TABLE ONLY rounds
    ADD CONSTRAINT rounds_bot1_fkey FOREIGN KEY (bot1) REFERENCES bots(id);


--
-- Name: rounds_bot2_fkey; Type: FK CONSTRAINT; Schema: public; Owner: game
--

ALTER TABLE ONLY rounds
    ADD CONSTRAINT rounds_bot2_fkey FOREIGN KEY (bot2) REFERENCES bots(id);


--
-- Name: rounds_game_fkey; Type: FK CONSTRAINT; Schema: public; Owner: game
--

ALTER TABLE ONLY rounds
    ADD CONSTRAINT rounds_game_fkey FOREIGN KEY (game) REFERENCES games(id);


--
-- Name: public; Type: ACL; Schema: -; Owner: postgres
--

REVOKE ALL ON SCHEMA public FROM PUBLIC;
REVOKE ALL ON SCHEMA public FROM postgres;
GRANT ALL ON SCHEMA public TO postgres;
GRANT ALL ON SCHEMA public TO PUBLIC;


--
-- Name: bots; Type: ACL; Schema: public; Owner: game
--

REVOKE ALL ON TABLE bots FROM PUBLIC;
REVOKE ALL ON TABLE bots FROM game;
GRANT ALL ON TABLE bots TO game;
GRANT ALL ON TABLE bots TO matchmaker;
GRANT ALL ON TABLE bots TO timekeeper;


--
-- Name: games; Type: ACL; Schema: public; Owner: game
--

REVOKE ALL ON TABLE games FROM PUBLIC;
REVOKE ALL ON TABLE games FROM game;
GRANT ALL ON TABLE games TO game;
GRANT ALL ON TABLE games TO matchmaker;
GRANT ALL ON TABLE games TO timekeeper;


--
-- Name: rounds; Type: ACL; Schema: public; Owner: game
--

REVOKE ALL ON TABLE rounds FROM PUBLIC;
REVOKE ALL ON TABLE rounds FROM game;
GRANT ALL ON TABLE rounds TO game;
GRANT ALL ON TABLE rounds TO matchmaker;
GRANT ALL ON TABLE rounds TO timekeeper;


--
-- PostgreSQL database dump complete
--

