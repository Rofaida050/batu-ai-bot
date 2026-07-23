# 🎓 BATU AI BOOT — Intelligent University Chatbot

> **Developed by:** Rafida Khaled — 4th Year, IT Software Department  
> **Borg El Arab Technological University (BATU)**

---

## 📌 Project Overview

**BATU AI BOOT** is a full-stack AI-powered chatbot built as the official intelligent assistant for Borg El Arab Technological University. It answers student questions about faculties, programs, admissions, fees, and activities — using real data from [batechu.com](https://batechu.com).

The system implements a **RAG (Retrieval-Augmented Generation)** pipeline: instead of relying on the LLM's general knowledge (which causes hallucinations), it first retrieves the most relevant context from a structured knowledge base using **TF-IDF cosine similarity**, then passes that context to the LLM with a strict grounded prompt — ensuring every answer is factually accurate and university-specific.

---

## ✨ Key Features

| Feature | Description |
|---|---|
| 🤖 **AI Chat** | Conversational assistant powered by LLaMA 3.3 70B via Groq API |
| 🔍 **RAG Pipeline** | TF-IDF cosine similarity retrieval before every LLM call |
| ⚡ **FAQ Cache** | Frequent questions served instantly — no API call needed |
| 📜 **Chat History** | Full conversation stored in SQLite, viewable by user |
| 🎓 **Student Self-Registration** | Optional — name + national ID only |
| 👥 **Student Dashboard** | Admin-only panel to manage all student records |
| 📚 **Dynamic Knowledge Base** | Admin can add/delete university info at runtime |
| 🔐 **3-Level Access Control** | Guest → Registered Student → Admin |
| 📱 **Responsive UI** | Mobile full-screen + desktop card layout |
| 🛡️ **Production Security** | Rate limiting, .env secrets, .htaccess, bcrypt passwords |

---

## 🏗️ System Architecture

```
User Message
     │
     ▼
[Rate Limiter] ──► 429 if exceeded
     │
     ▼
[FAQ Cache] ──► Instant reply if cached
     │ miss
     ▼
[TF-IDF Retrieval]
  scores all knowledge base entries
  picks top 3 by cosine similarity
     │
     ▼
[Groq LLM — LLaMA 3.3 70B]
  system prompt + context + chat history
  temperature: 0.1 (grounded, no hallucination)
     │
     ▼
[Save to SQLite + Update Cache]
     │
     ▼
  JSON → Frontend
```

---

## 🛠️ Tech Stack

### Backend
| Technology | Role |
|---|---|
| **PHP 8.1+** | Server-side logic, routing, request handling |
| **SQLite 3 + WAL mode** | Knowledge base, students, chat history, rate logs |
| **PDO + Prepared Statements** | SQL injection prevention |
| **PHP Sessions** | Per-user chat history (httpOnly + SameSite: Strict) |
| **.env config file** | API key and secrets — never in source code |
| **cURL** | HTTP calls to Groq API |

### AI / NLP
| Technology | Role |
|---|---|
| **Groq API** | Ultra-fast LLM inference (free tier available) |
| **LLaMA 3.3 70B Versatile** | Arabic-capable large language model |
| **TF-IDF** | Term Frequency–Inverse Document Frequency scoring |
| **Cosine Similarity** | Vector-based relevance ranking |
| **Arabic Text Normalization** | Alef variants, teh marbuta, diacritics, stopwords |
| **RAG** | Retrieval-Augmented Generation to ground LLM answers |
| **FAQ Cache** | MD5-keyed JSON cache with TTL expiry |

### Frontend
| Technology | Role |
|---|---|
| **HTML5 / CSS3** | Responsive single-page UI |
| **Vanilla JavaScript (ES2020+)** | Async/await, fetch API, FormData — no framework |
| **Marked.js** | Real-time Markdown rendering in chat bubbles |
| **CSS Custom Properties** | Design token system for consistent theming |
| **CSS Grid + Flexbox** | Responsive layout — mobile & desktop |
| **localStorage** | Persist student session across page reloads |
| **Google Fonts — Cairo** | Arabic-optimized typeface |

### Security
| Measure | How |
|---|---|
| **Rate Limiting** | 40 req/min per IP tracked in SQLite |
| **Input Sanitization** | `htmlspecialchars` + `strip_tags` on all input |
| **Password Hashing** | `password_hash()` with bcrypt |
| **Secure Sessions** | httpOnly + SameSite: Strict cookie flags |
| **File Protection** | `.htaccess` blocks `.env`, `.db`, `.json` direct access |
| **Atomic File Writes** | Write to `.tmp` then `rename()` — no cache corruption |
| **WAL Mode** | SQLite concurrent-safe under load |

---

## 📁 Project Structure

```
batu-ai-boot/
├── index.html        # Full frontend — chat UI + admin dashboard
├── bot.php           # Full backend — router, NLP, RAG, DB, API
├── .env              # Secrets (never committed to git)
├── .env.example      # Template for deployment
├── .htaccess         # Apache protection rules
├── batu.db           # SQLite DB — auto-created on first run
└── faq_cache.json    # FAQ cache — auto-managed
```

---

## 🧠 Arabic NLP Normalization

```
Raw input: "أين الكليات؟"
     │
     ▼ normalize()
"اين الكليات؟"     ← unify alef variants (أ/إ/آ → ا)
     │
     ▼ strip diacritics (تشكيل)
     │
     ▼ normalize ة → ه, ى → ي
     │
     ▼ lowercase + split
tokens: ["كليات"]  ← stopwords removed (اين، ما، في ...)
     │
     ▼ TF-IDF vector → cosine similarity vs. knowledge base
     │
     ▼ top 3 matching entries → context for LLM
```

---

## 👥 Access Levels

```
┌─────────────────────────────────────────────┐
│  GUEST (anyone)                             │
│  → Chat freely, no login needed             │
├─────────────────────────────────────────────┤
│  REGISTERED STUDENT (optional)             │
│  → Name + National ID → saved to DB        │
│  → Personalized greeting, session memory   │
├─────────────────────────────────────────────┤
│  ADMIN (password-protected)                │
│  → Student dashboard (add/edit/delete)     │
│  → Knowledge base management              │
│  → FAQ cache monitor & clear              │
│  → Change admin password                  │
└─────────────────────────────────────────────┘
```

---

## 🗄️ Database Schema

```sql
knowledge     -- RAG entries (base from batechu.com + admin-added)
students      -- Student records (self-registered + admin-managed)
chat_history  -- Conversation per session (max 20 messages)
config        -- Admin bcrypt password hash
rate_log      -- IP + timestamp for rate limiting
```

---

## 💡 Why This Project Is Interesting

- **Zero hallucination by design** — `temperature: 0.1` + strict system prompt that explicitly forbids any answer outside the retrieved context
- **No framework overhead** — pure PHP + vanilla JS, deployable on any shared hosting in minutes
- **Arabic-first NLP** — custom normalization handles Egyptian dialect + Modern Standard Arabic
- **Real university data** — knowledge base built from actual batechu.com content
- **Production patterns** — rate limiting, caching, atomic writes, WAL mode, secrets management
- **Extensible without code changes** — admin adds new university info through UI, bot learns instantly
