# MSc Exam Companion (1248)

A very light, phone-friendly revision site for four MSc modules, plus a small PHP chatbot.

- **DAA** - Design & Analysis of Algorithms
- **CSC 711** - Advanced Operating Systems
- **CSC 715** - Software Engineering
- **CSC 718** - Internet Systems

Each module has three sets of pages:

| Set | What it is | Use it |
|-----|-----------|--------|
| **Cheat** | the whole topic, distilled | to learn / revise |
| **Cram** | formulas, numbers, one-line contrasts | in the last hour |
| **Answers** | coded model answers (e.g. `DAA-Q14`, `OS-Q5b`, `SE-L4Q2`, `IS-Q3a`) | to memorise full answers |

Pages are plain HTML only - **no CSS, no JavaScript**, and every page is kept under **5 KB** so it loads fast on a basic 2G phone. Navigation is at the top and bottom of every page, plus `Prev` / `Next` / `Contents` / `Top` links.

## The chatbot

`chat.php` is a raw PHP + basic HTML form chatbot:

- Calls the **Groq** API using the key in `.env`.
- Gives short, exam-ready answers at MSc level.
- Keeps the full **conversation history** in **SQLite** (`data/chat.sqlite`).
- **History navigation**: `history.php` lists every saved chat; within a chat, jump links go straight to any message, and `Prev chat` / `Next chat` move between saved chats.

### Setup

1. Copy `.env.example` to `.env` and set your key:
   ```
   GROQ_API_KEY=your_key_here
   ```
2. Make sure the `data/` directory is writable. The SQLite database is created automatically on first use.
3. Run it. Either drop the folder in XAMPP's `htdocs` (open `http://localhost/1248/`), or use PHP's built-in server:
   ```
   php -S localhost:8000
   ```

`.env` and the SQLite database are ignored by git so your key and chats are never committed.

> Note: the static pages work on any host (including GitHub Pages), but the chatbot needs a **PHP-capable** host because GitHub Pages only serves static files.

## Files

```
index.html        home / navigation hub
daa-*.html        Algorithms: cheat, cram, answers (contents + a1..a11)
os-*.html         CSC 711: cheat, cram, answers (contents + a1..a5)
se-*.html         CSC 715: cheat, cram, answers (contents + a1..a5)
is-*.html         CSC 718: cheat, cram, answers (contents + a1..a7)
chat.php          chatbot form + Groq request
history.php       saved-conversation browser
lib.php           .env loader, SQLite helpers, Groq client
.env.example      configuration template
data/             SQLite database (created at runtime, not committed)
sources/          raw notes used to build the site
```
