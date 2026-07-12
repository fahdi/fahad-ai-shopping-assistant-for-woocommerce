# RAG / Semantic Product Retrieval, Design Document

Status: **Research + Design (no code yet).** This document is for review, not implementation.
Plugin: Fahad AI Shopping Assistant for WooCommerce, `v2.4.0` (canonical repo, branch `main`).
Scope: a retrieval-augmented-generation layer over the store's **product catalog**, backed by a vector index, so the assistant retrieves semantically relevant products as agent context instead of relying only on WooCommerce keyword search. Closes ROADMAP issue **#60 (Semantic / vector search)**.

> This document is decision-oriented. Section 7 states a concrete **recommended default** and the **2-3 decisions the client must make**. Everything before it is the reasoning, with comparison tables and cited sources.

---

## 1. Why this, and what "good" looks like

### 1.1 The current retrieval path (where we are today)

All product discovery currently flows through one mechanism: WooCommerce's literal keyword search.

- `Fahad_AI_Tools::search_products()` (`includes/class-tools.php`) calls `wc_get_products()` with `'s' => $query` and `'orderby' => 'relevance'`. WooCommerce's `'s'` search is a **substring `LIKE` match against post title + excerpt/content**, it is not stemmed, not synonym-aware, not semantic.
- The code already acknowledges the weakness: when an exact search returns nothing it runs `relax_query()` (drops sizes/colours/filler stop-words, de-pluralises) and then `token_search()` (an OR-of-terms scored by hit count). These are hand-rolled patches over the fact that the underlying match is literal.
- `get_recommendations()`'s free-text `need` fallback (`includes/tools/class-recommendation-tools.php`) does the **same** `wc_get_products( 's' => $need )` search, so "something for a rainy hike" only matches products whose text literally contains those words.

**Symptom (the pain point the client named):** a shopper asking for "something warm for winter", "a gift for a 6-year-old", or "rain jacket" gets nothing unless those exact words appear in the product text. Keyword search misses synonyms, paraphrases, and intent. This is the classic case for semantic retrieval.

### 1.2 What we keep (these are strengths, not to be thrown away)

- **Tool registry seam.** `Fahad_AI_Tool_Registry` already layers tools: built-ins → first-party packs (`register_pack()`) → the `fahad_ai_register_tools` filter. A semantic retriever drops in as **one new file under `includes/tools/`** with zero bootstrap edits, the same drop-in pattern as `class-catalog-tools.php`. This is the cleanest possible integration point.
- **Card emission is convention-based, not name-based.** `Fahad_AI_API_Handler::tool_result_cards()` emits product cards from the **shape** of a tool result (`{ found, products[] }` where each product is a `format_product_summary()` shape). A new retrieval tool that returns that shape renders as cards **for free** across all three agent paths (Anthropic non-stream, Moonshot non-stream, Moonshot SSE).
- **Live truth stays live.** `format_product_summary()` reads price/stock/sale/rating straight from `WC_Product` at call time. We must preserve this: **never embed or cache price/stock**, they change and must stay live (see §5.4).
- **Eval harness.** `tests/eval/` drives the real agent loop against scripted LLM turns + real tool execution, with deterministic checkers (grounding, scarcity, budget). This is the natural home for **relevance@k** golden queries (§6).

### 1.3 Provider reality (important constraint)

The plugin uses **Anthropic (Claude)** and **Moonshot (Kimi)** as chat/agent providers (`call_anthropic`, `call_moonshot`). **Neither offers a text-embeddings endpoint**, Anthropic explicitly does not ship an embeddings model and points users to third parties (Voyage). So an **embeddings provider must be chosen and configured separately from the chat provider.** This is a new external dependency and a new API key. The design must make the embeddings provider swappable (`EmbeddingProvider` interface, §7.4) and must degrade to keyword search when no embeddings key is set.

### 1.4 Deployment reality (the hardest constraint)

This is a **distributed WordPress plugin** sold to run on commodity hosting. We **cannot assume**: Postgres/pgvector, Docker, a background daemon, a Python runtime, shell access, a specific MySQL/MariaDB version, or that the host will install a PHP extension. The realistic floor is **PHP 8.1+ and MySQL 5.7/8.0 or MariaDB 10.x**, plus whatever Action Scheduler (bundled with WooCommerce) can do. Every architecture choice below is judged first against "does this work on a $5/month shared host with no special privileges?"

---

## 2. Vector store options (the core decision)

The five realistic options, scored against the deployment reality.

### 2.1 (a) In-MySQL brute force, embeddings as BLOB, similarity in PHP

Store one row per product (or per chunk) in a custom table: `product_id`, `model`, `dim`, `embedding LONGBLOB` (packed float32 via `pack('g*', ...)`), plus metadata. At query time, load the candidate vectors and compute cosine similarity in PHP, sort, take top-k.

- **Host compatibility:** Universal. Works on any MySQL/MariaDB + PHP. No extensions, no privileges, no external service. **This is the only option that works everywhere.**
- **Scale ceiling:** It is an O(n) linear scan, fine for hundreds to a few thousand products; degrades as the catalog grows because every query touches every vector. With 1536-dim float32, a brute-force query over ~1M vectors is ~1.5B float ops and is too slow for real time; even 10M flat search is not real-time ([sarthakai](https://sarthakai.substack.com/p/a-vectordb-doesnt-actually-work-the-way-you-think-it-does)). In **PHP specifically** the ceiling is lower than in a compiled language, so the practical comfortable ceiling is roughly **a few thousand to ~10-20k products** with two mitigations: (1) **reduce dimensions** (use a 256-512-dim embedding or OpenAI's `dimensions` shortening, see §3) to cut per-vector work ~3-6x; (2) **pre-filter by SQL** (category/price/in-stock) so the scan runs over a subset, not the whole catalog.
- **Latency:** Dominated by loading BLOBs from MySQL + the PHP loop. Acceptable (tens to low-hundreds of ms) at the few-thousand scale with reduced dimensions; we will not block the shopper, retrieval happens inside a tool call that already costs an LLM round-trip.
- **Cost:** Zero infra cost. Only the one-time + incremental embedding API cost (§5, §8).
- **Privacy / data-egress:** Vectors and catalog text stay in the site's own DB. The only egress is product **text** sent to the embeddings API at index time (§9). No per-query egress to a vector DB.
- **Operational complexity:** Lowest. One table, created on activation. No moving parts.

**Verdict:** the **default backend** for the sellable plugin. It is the only universally-deployable option, and most WooCommerce stores have small/medium catalogs that fit comfortably within its ceiling.

### 2.2 (b) Native DB VECTOR type (MySQL 9 / MariaDB 11.8)

Use the database's own vector type + ANN index instead of a PHP scan.

- **MariaDB:** Native `VECTOR(N)` column + `VECTOR INDEX` (modified HNSW), `VEC_DISTANCE_COSINE()` / `VEC_DISTANCE_EUCLIDEAN()`, up to 16,383 dims. **GA in MariaDB 11.8 LTS (2025)**, no extension, in Community Server, available on Amazon RDS for MariaDB ([MariaDB.org](https://mariadb.org/projects/mariadb-vector/), [MariaDB blog](https://mariadb.com/resources/blog/announcing-mariadb-community-server-11-7-ga-with-vector-search-and-mariadb-community-server-11-8-rc/)).
- **MySQL:** `VECTOR` type and `DISTANCE()` exist, **but vector indexing is only in the proprietary HeatWave service (OCI)**, not in MySQL Community or Commercial server ([MySQL 9.1 manual](https://dev.mysql.com/doc/refman/9.1/en/vector-functions.html)). So mainstream self-hosted MySQL gets, at best, the type + a non-indexed distance function (still a scan).
- **Host compatibility:** **Low today.** The overwhelming majority of WordPress hosts run MySQL 5.7/8.0 or MariaDB 10.x. MariaDB 11.8 and MySQL 9 are rare in shared hosting in 2026.
- **Scale ceiling / latency:** Excellent where available (real ANN index, sub-ms distance), this is the path that scales in-database to large catalogs without an external service.
- **Cost / privacy:** Same as (a), in your own DB, no egress beyond index-time embedding.
- **Operational complexity:** Low IF the version is present; otherwise impossible.

**Verdict:** a **progressive enhancement**, not a baseline. The `VectorStore` interface (§7.4) should detect MariaDB ≥ 11.7 (or MySQL HeatWave) at runtime and, if present, use a `MariaDbVectorStore` that pushes the search into SQL. Otherwise fall back to (a). Do not require it.

### 2.3 (c) SQLite-based local vector index

Ship a SQLite file (optionally with the `sqlite-vec` extension) as a co-located index.

- **Host compatibility:** Mixed. PHP's `pdo_sqlite` is common, but **loadable SQLite extensions (`sqlite-vec`) are usually disabled** on shared hosting, and WordPress itself runs on MySQL, adding a parallel SQLite store is an odd, surprising dependency for a WP plugin. Without the extension it is just brute force again, but in a second datastore.
- **Verdict:** **Rejected.** It adds a datastore without solving the scan problem on the hosts that matter, and complicates backups/migrations. No advantage over (a) in a WP context.

### 2.4 (d) External managed vector DB (Pinecone / Qdrant / Weaviate / Milvus / pgvector-as-a-service)

Push vectors to a hosted ANN service; query it over HTTPS per request.

- **Host compatibility:** Universal (it is just HTTPS), works regardless of the WP host.
- **Scale ceiling / latency:** Best in class. Real ANN at millions of vectors, low-ms queries, metadata filtering server-side.
- **Cost (representative, 2025-2026):**

  | Service | Free tier | Paid floor | At ~10M vectors |
  |---|---|---|---|
  | **Qdrant Cloud** | 1 GB free cluster, no card | ~$0.014/hr per node, no per-query fee | ~$65/mo |
  | **Pinecone (serverless)** | free, 2 GB storage | Standard $50/mo min | ~$70/mo |
  | **Weaviate Cloud** | free sandbox | Serverless ~$25/mo | ~$135/mo |

  Sources: [Pinecone/Qdrant/Weaviate comparison (buildmvpfast)](https://www.buildmvpfast.com/api-costs/vector-db), [ranksquire 2026](https://ranksquire.com/2026/03/04/vector-database-pricing-comparison-2026/), [xenoss](https://xenoss.io/blog/vector-database-comparison-pinecone-qdrant-weaviate).
- **Privacy / data-egress:** Product text/embeddings **and** every query leave the site to a third party. A privacy disclosure and an admin opt-in are mandatory (§9). Some merchants (EU, regulated) will refuse this.
- **Operational complexity:** High for a distributed plugin: a second API key + account the merchant must create, network failure handling, index lifecycle (create/delete on uninstall), region selection, and ongoing cost the merchant pays. Self-hosted Qdrant/Milvus is free but needs Docker/a server, out of reach for the target host.

**Verdict:** the **optional "Scale Tier"** for large catalogs (tens of thousands+ of products) or merchants who want best-in-class relevance and will pay/configure for it. Behind the same `VectorStore` interface, off by default. **Recommend Qdrant** as the reference external backend (cheapest small/medium, generous free tier, self-host option for privacy-sensitive merchants).

### 2.5 (e) Provider-native retrieval (OpenAI Vector Store / File Search)

Upload the catalog as files; let OpenAI chunk, embed, store, and retrieve via the File Search tool.

- **Host compatibility:** Universal (HTTPS).
- **Cost:** $0.10/GB/day of vector storage, first GB free, no per-retrieval fee ([OpenAI File Search docs](https://developers.openai.com/api/docs/assistants/tools/file-search), [OpenAI pricing](https://platform.openai.com/docs/pricing/)). Cheap for a catalog (a product catalog is well under 1 GB of vectors, i.e. often **free**).
- **Fit problem:** It is **document RAG, not commerce retrieval.** It returns text chunks, not product IDs with live price/stock; keeping it in sync with create/update/delete/price changes is awkward (re-upload files); and it couples retrieval to **OpenAI as the chat provider**, but this plugin's chat is Anthropic/Moonshot. We would be adding OpenAI purely for retrieval and getting back chunks we then have to map to products anyway.
- **Verdict:** **Rejected as the retrieval backend.** It solves a different problem. (We will still likely use OpenAI's *embeddings* endpoint, §3, which is unrelated to File Search.)

### 2.6 Vector store decision matrix

| Option | Host compat | Scale ceiling | Latency | Infra cost | Egress / privacy | Ops complexity | Role |
|---|---|---|---|---|---|---|---|
| **(a) MySQL brute force (BLOB)** | Universal | ~few k-~10-20k products (PHP) | OK at default scale | $0 | Index-time text only | Lowest | **Default** |
| (b) Native VECTOR (MariaDB 11.8 / MySQL HeatWave) | Low (version-gated) | Large | Excellent | $0 | Index-time text only | Low if present | Progressive enhancement |
| (c) SQLite + sqlite-vec | Mixed/low | Same as (a) w/o ext | OK | $0 | None extra | Medium, odd for WP | Rejected |
| (d) External (Qdrant/Pinecone/Weaviate) | Universal | Millions | Best | $25-$135+/mo | Text + every query egress | High | **Scale Tier (opt-in)** |
| (e) OpenAI File Search | Universal | Large | Good | ~free for a catalog | Text egress, OpenAI-coupled | Medium | Rejected (wrong shape) |

---

## 3. Embedding models

Anthropic/Moonshot give us no embeddings, so we choose an embeddings provider. Selection criteria for a sellable plugin: **cheap, low-dimensional enough for the MySQL brute-force default, multilingual (this store serves Urdu + English), and easy for a merchant to get a key.**

| Model | Dims (native) | Price / 1M tokens (input) | Multilingual | Notes |
|---|---|---|---|---|
| **OpenAI `text-embedding-3-small`** | 1536 (shortenable via `dimensions`) | **$0.02** ($0.01 batch) | Yes (decent) | Cheapest, ubiquitous, supports MRL dimension shortening to 256/512 |
| OpenAI `text-embedding-3-large` | 3072 (shortenable) | $0.13 ($0.065 batch) | Yes | Higher quality, 6.5x cost, 3072 dims heavy for PHP scan |
| **Cohere `embed-multilingual-v3.0`** | 1024 (Matryoshka: 256/512/1024/1536) | $0.10 | **100+ langs, strong on non-Latin** (Arabic/Hindi ~15-20% better than OpenAI) | Best multilingual; native 1024 dims is scan-friendly |
| Cohere `embed-english-light-v3.0` | 384 | $0.02 | English | Tiny + cheap, English-only |
| **Voyage `voyage-3-large`** | flexible (256-2048) | ~mid | Best overall (beats OpenAI-large ~9.7%, Cohere-en ~20.7%) | Highest quality; Anthropic's recommended embeddings partner |
| Open-source **`bge-small-en-v1.5`** / **`e5-small`** | 384 | $0 (self-host) | English (multilingual e5 variants exist) | 33M params, CPU-friendly, but needs a Python/inference runtime the WP host won't have |

Sources: [OpenAI embedding pricing 2026 (TokenMix)](https://tokenmix.ai/blog/openai-embedding-pricing) / [CloudZero](https://www.cloudzero.com/blog/openai-pricing/); [Cohere & Voyage comparison (reintech)](https://reintech.io/blog/embedding-models-comparison-2026-openai-cohere-voyage-bge) / [Voyage-3-large announcement](https://blog.voyageai.com/2025/01/07/voyage-3-large/); [bge/e5 dims (HF, BAAI)](https://huggingface.co/BAAI/bge-small-en-v1.5) / [Multilingual E5 report](https://arxiv.org/pdf/2402.05672); [model specs table (pecollective)](https://pecollective.com/tools/text-embedding-models-compared/).

**Why open-source (bge/e5) is not the default:** they are free and good, but they require a model-inference runtime (Python/ONNX/llama.cpp) that a commodity WP host cannot run. Embedding-via-HTTP from a hosted provider is the only realistic option for a distributed plugin. (We can leave the `EmbeddingProvider` interface open so a power user *can* point it at a self-hosted endpoint.)

**Recommendation:**
- **Default: OpenAI `text-embedding-3-small` with `dimensions: 512`.** Cheapest ($0.02/1M), trivially keyed, and the 512-dim shortening (Matryoshka) makes the MySQL brute-force scan ~3x lighter than full 1536 with negligible quality loss. Good-enough multilingual.
- **Configurable alternative for multilingual-first stores (incl. this Urdu/English store): Cohere `embed-multilingual-v3.0` at 512 dims**, materially better on non-Latin scripts.
- **Quality tier: Voyage `voyage-3-large`** for merchants who want the best retrieval and don't mind the cost.

A hard rule: the **embedding model id + dimensions are stored alongside every vector** so a model change triggers a clean re-index and we never compare vectors across models (§5.5).

---

## 4. RAG retrieval design

### 4.1 Granularity: one vector per product (not per variant)

Embed **one vector per parent product.** Variants (size/colour) share the same descriptive text; embedding each variant triples storage and the scan cost for no semantic gain. Variant-level concerns (which size is in stock, the variation price) are **live-data filters applied after retrieval** (§5.4), not embedding concerns. (Exception worth noting for the client: if a store sells products where variants differ *semantically*, e.g. flavours, scents, materials with distinct descriptions, per-variant embedding could help; this is rare and out of MVP scope.)

### 4.2 What we embed, and how we compose it

Embed a single composed document per product, ordered most- to least-salient:

```
{title}
Categories: {category names}
{short_description}
{long_description (stripped, truncated)}
Attributes: {attribute name: value, ...}   // e.g. Material: wool, Season: winter
Tags: {product tags}
```

- **SKU is deliberately excluded from the embedding text**, SKUs are opaque tokens that pollute the semantic signal. SKU matching is handled by the keyword/BM25 side of hybrid search (§4.3), which is exactly what literal tokens are good at.
- **Price and stock are deliberately excluded**, they change and must stay live (§5.4).
- Strip HTML (`wp_strip_all_tags`), collapse whitespace, and **truncate the long description** (e.g. first ~1500 chars) to bound token cost; product titles + categories + attributes carry most of the retrieval signal anyway.

### 4.3 Hybrid search, vector + keyword, not pure vector

**Pure vector retrieval is insufficient for commerce.** Vector search is great for intent/synonyms but fumbles exact tokens, SKUs, model numbers, brand names, error codes. Keyword/BM25 nails exact tokens but misses semantics. The well-established answer is **hybrid: run both, fuse with Reciprocal Rank Fusion (RRF).** Reported recall@10 jumps from ~78% (vector alone) / ~65% (BM25 alone) to ~91% with RRF fusion ([Digital Applied](https://www.digitalapplied.com/blog/hybrid-search-bm25-vector-reranking-reference-2026), [Weaviate](https://weaviate.io/blog/hybrid-search-explained)). RRF operates on **ranks, not scores**, so it sidesteps the "cosine vs BM25 scores aren't comparable" problem with no normalization.

Concretely:
1. **Keyword leg:** reuse the existing `wc_get_products( 's' => $query )` path (and its relaxation/token fallback), this is already built and already handles SKU/exact matches.
2. **Vector leg:** embed the query, retrieve top-N by cosine from the `VectorStore`.
3. **Fuse with RRF:** `score(d) = Σ 1/(k + rank_i(d))` across the two ranked lists (k≈60, standard). Take fused top-k.
4. **(Optional) rerank** the fused top-N (e.g. 20→5) with a cross-encoder reranker (Cohere Rerank / Voyage rerank) for a quality tier. **Out of MVP scope**, adds another API call/cost; revisit if eval shows fusion alone is insufficient.

This also means **we never regress**: if embeddings are unavailable (no key, API down), hybrid degrades to keyword-only, exactly today's behaviour. The plugin's existing graceful-degradation ethos (`degraded_response`) extends naturally.

### 4.4 Live filters that must NOT be embedded

Apply as SQL/PHP filters **before** (pre-filter) and **after** (post-filter) the vector step, against live `WC_Product` data:
- **Price range** (`min_price`/`max_price`), the budget guardrail depends on this being live and correct.
- **Stock / availability** (`is_in_stock`, per-variant stock), never surface an unbuyable product.
- **Category**, when the shopper or tool scopes to a category, restrict the candidate set (this also shrinks the brute-force scan).
- **Visibility / publish status.**

Pre-filtering by category/stock/price before the scan is also the primary scale mitigation for the MySQL brute-force backend (§2.1).

### 4.5 How retrieved products reach the model (integration with the agent loop)

Add **one new tool**, `semantic_search` (or fold semantic retrieval into `search_products` behind a setting, see §7.3), implemented in a new `includes/tools/class-semantic-tools.php` that self-registers via `Fahad_AI_Tool_Registry::register_pack()`.

- The tool returns the **canonical `{ found, products[] }` shape** built from `Fahad_AI_Tools::format_product_summary()`. Because card emission is convention-based (`tool_result_cards()` keys off result shape, not tool name), retrieved products render as cards in all three agent paths **with no API-handler changes**.
- Live price/stock are read by `format_product_summary()` at call time, so the model and the cards always see current data even though retrieval used embeddings.
- The model receives the trimmed copy (`trim_tool_result()` keeps id/name/price/in_stock/on_sale) and grounds its prose in it, the existing grounding checker still applies.

No changes to `class-api-handler.php` are required for MVP. (A later refinement could let the API handler inject a small "top related products" block via the existing `fahad_ai_system_prompt` filter, but the tool path is cleaner and keeps retrieval model-driven.)

---

## 5. Indexing & sync

### 5.1 Storage schema (default MySQL backend)

A single custom table created on activation (`dbDelta`), prefixed `{$wpdb->prefix}fahad_ai_embeddings`:

| Column | Type | Purpose |
|---|---|---|
| `product_id` | BIGINT UNSIGNED (PK) | WooCommerce product ID |
| `model` | VARCHAR(64) | Embedding model id (e.g. `text-embedding-3-small`) |
| `dim` | SMALLINT UNSIGNED | Vector dimension (e.g. 512) |
| `embedding` | LONGBLOB | Packed float32 (`pack('g*', ...)`) |
| `content_hash` | CHAR(40) | SHA-1 of the composed text, skip re-embed if unchanged |
| `updated_at` | DATETIME | Last embed time |

Index on `(model)` so a model change can be batch-invalidated. On the MariaDB-vector backend the same logical row uses a `VECTOR(dim)` column + `VECTOR INDEX` instead of `LONGBLOB`.

**Storage estimate:** 512-dim float32 = 2 KB/product; +~100 B metadata. A 5,000-product catalog ≈ **~10 MB**; 50,000 products ≈ ~100 MB. Trivial for MySQL. (1536-dim would be 3x this, another reason to default to 512.)

### 5.2 Initial bulk embed

On enable (or via an admin "Build index" button), enqueue an Action Scheduler **recurring/batched backfill**:
- Page product IDs in batches of ~25-50 (Action Scheduler's default claim is 25 actions; each action embeds a batch). Action Scheduler is designed exactly for "background processing large queues in WP plugins, no server access required" and processes a batch until ~90% memory or ~30s ([actionscheduler.org](https://actionscheduler.org/)).
- Each batch: compose text → call embeddings API (use the provider's **batch endpoint** where available to halve cost) → `pack()` → upsert rows.
- Idempotent: skip products whose `content_hash` is unchanged. Safe to re-run.

### 5.3 Incremental sync (keep the index fresh)

Hook WooCommerce/WP product lifecycle events and enqueue an **async** re-embed (never inline, embedding is a network call and must not block a save):

```php
// Re-embed on create/update; remove on delete/trash. Async via Action Scheduler.
add_action( 'woocommerce_update_product', $enqueue_reembed );   // fires on product save (WC CRUD)
add_action( 'woocommerce_new_product',    $enqueue_reembed );
add_action( 'before_delete_post',         $enqueue_delete );     // and 'wp_trash_post'
// enqueue:
as_enqueue_async_action( 'fahad_ai_embed_product', [ 'product_id' => $id ], 'fahad-ai-embeddings', true /* unique */ );
```

The `unique => true` flag coalesces rapid repeated saves of the same product. A `content_hash` check inside the handler means a save that didn't change the embedded fields (e.g. a price-only edit) is a no-op, **price changes never trigger a re-embed** because price isn't embedded.

### 5.4 Live data is never embedded (restated as an invariant)

Price, stock, sale status, ratings, read live from `WC_Product` at retrieval time via `format_product_summary()`. The vector index holds only the semantic/text fingerprint. This is both a correctness invariant (no stale prices) and a cost control (price/stock edits don't churn the index).

### 5.5 Versioning embeddings on model change

The active model + dimensions are an option (`fahad_ai_embedding_model`, `fahad_ai_embedding_dims`). Each row stores the `model`/`dim` it was built with. When the admin changes the model:
- Mark the index **stale** (don't silently mix models, comparing vectors from different models is meaningless).
- Offer a "Rebuild index" action that backfills under the new model, then flips a pointer. Until rebuilt, **fall back to keyword search** so the assistant never errors.

### 5.6 Rate limits & cost control

- Respect the embeddings provider's rate limits with batch sizing + Action Scheduler's natural throttling (one batch per queue tick); back off on 429.
- Prefer **batch embedding endpoints** (OpenAI batch halves the price: $0.01 vs $0.02 /1M for `3-small`).
- Cache query embeddings for repeated identical queries (short transient) to avoid re-embedding the same shopper phrase.
- Hard cap: a per-day embedding token ceiling option to protect the merchant's bill during a bulk import.

---

## 6. Evaluation & guardrails

### 6.1 Relevance@k via the existing eval harness

The eval harness (`tests/eval/`) already drives the real agent loop against scripted LLM turns + real tool execution and asserts deterministically. Extend it for retrieval quality:
- **Golden queries:** a fixture set of `(query → expected product IDs)` pairs reflecting real intent ("warm winter jacket" → the wool coat, the fleece; NOT the swimsuit).
- **Metric: relevance@k / recall@k**, fraction of expected products appearing in the retriever's top-k. Run against a fixed mock catalog so it is deterministic and offline (the harness already stubs `wc_get_products`/`wc_get_product`). Embeddings in the test are themselves stubbed/canned so no live API call is made (consistent with the harness's "never a live call" rule).
- **Regression gate:** relevance@k must not drop below a threshold; hybrid (RRF) must beat keyword-only on the golden set, or the feature isn't earning its complexity.

### 6.2 Guardrails (unchanged, and reinforced)

- **Grounding:** the existing grounding checker still applies, the model may only state facts present in tool results. Semantic retrieval changes *which* products surface, not the no-hallucination rule.
- **Budget/scarcity:** unchanged; budget filtering happens on live price (§4.4) before products reach the model, and the budget checker guards the prose.
- **Empty-result honesty:** if hybrid retrieval finds nothing, return the same `found: 0` empty state so the model abstains (the `abstains` checker covers this) rather than inventing products.
- **Degradation:** no embeddings key / API failure ⇒ silent fall back to keyword search. Never surface an embeddings error to the shopper.

---

## 7. Recommendation, trade-offs, and phased plan

### 7.1 Recommended default (the headline)

**A drop-in `semantic_search` tool backed by hybrid retrieval (existing keyword leg + a new vector leg fused with RRF), with the vector index stored as float32 BLOBs in a custom MySQL table and searched by a PHP brute-force cosine scan. Embeddings via OpenAI `text-embedding-3-small` at 512 dimensions, configurable to Cohere multilingual / Voyage. Indexing is bulk-backfilled and incrementally synced via Action Scheduler. The `VectorStore` is an interface with two more backends behind it, a `MariaDbVectorStore` (auto-used when MariaDB ≥ 11.7 is detected) and an `ExternalVectorStore` (opt-in Scale Tier, Qdrant reference), so the same retrieval logic scales without re-architecture.**

This is chosen because it is the **only design that runs on every WooCommerce host out of the box** (the brute-force MySQL default needs nothing special), **never regresses** (degrades to today's keyword search), and **scales by swapping one interface implementation** when a merchant has a big catalog or premium DB/host.

### 7.2 Trade-offs made explicit (there is no single right answer)

| Decision | We chose | We gave up | Why it's right for *this* product |
|---|---|---|---|
| Default backend | MySQL BLOB brute force | Sub-ms ANN at large scale | Universality > peak performance for a distributed plugin; most catalogs are small |
| Granularity | One vector per product | Per-variant nuance | 3x less storage/scan; variant concerns are live filters anyway |
| Search type | Hybrid (vector + keyword, RRF) | Simplicity of pure vector | Pure vector misses SKUs/exact tokens, fatal for commerce |
| Embeddings provider | Hosted (OpenAI default) | Free self-hosted bge/e5 | WP hosts can't run a model runtime; HTTP is the only portable path |
| Dimensions | 512 (shortened) | Marginal quality of 1536/3072 | 3-6x lighter PHP scan; negligible quality loss with MRL |
| Reranking | Deferred | A few % more precision | Extra API call/cost; add only if eval demands it |
| External vector DB | Opt-in only | Best relevance by default | Cost + privacy egress + merchant must configure an account |

### 7.3 Two open product questions baked into the design

- **New tool vs. upgrade `search_products`?** Option A: ship a distinct `semantic_search` tool (model decides when to use it; clearest, easiest to eval/disable via merchant tool-gating). Option B: make `search_products` hybrid internally behind a setting (zero new tool surface, automatically better, but couples two behaviours and is harder to A/B). **Recommendation: A for MVP** (clean, gateable, evaluable), with a setting to later promote it into `search_products`.

### 7.4 Proposed interfaces (sketch, for review, not final)

```php
interface Fahad_AI_Embedding_Provider {
    /** @param string[] $texts @return float[][] one vector per input, same order */
    public function embed( array $texts ): array;
    public function model(): string;   // e.g. 'text-embedding-3-small'
    public function dimensions(): int; // e.g. 512
}

interface Fahad_AI_Vector_Store {
    public function upsert( int $product_id, array $vector, string $model, string $content_hash ): void;
    public function delete( int $product_id ): void;
    /** @return int[] product IDs ranked by similarity, after $filters (category/price/stock) */
    public function query( array $query_vector, int $k, array $filters = [] ): array;
    public function is_available(): bool;     // backend usable right now?
    public function rebuild_required(): bool; // model/dim changed since last build?
}

// Backends: Fahad_AI_MySQL_Vector_Store (default), Fahad_AI_MariaDb_Vector_Store
//           (auto when VEC_DISTANCE_COSINE present), Fahad_AI_External_Vector_Store (Qdrant).

final class Fahad_AI_Indexer {       // compose text, hash, batch-embed, upsert; AS-driven
    public function backfill(): void;            // bulk
    public function reindex_product( int $id ): void;  // incremental (async handler)
}

final class Fahad_AI_Retriever {     // the hybrid brain
    /** keyword leg (reuse Fahad_AI_Tools search) + vector leg + RRF fuse + live filters */
    public function search( string $query, array $filters, int $k ): array; // -> product summaries
}
```

The `EmbeddingProvider` is selected by a `fahad_ai_embedding_provider` factory + a `fahad_ai_embedding_provider` filter (mirroring the existing provider pattern). The `VectorStore` is selected by capability detection with an override filter. Both default to "off / keyword-only" until a key is configured.

### 7.5 Phased implementation plan

- **Phase 0, Spike (no ship).** Prototype `text-embedding-3-small`@512 + the BLOB table + PHP cosine on a real demo catalog; measure scan latency at the store's product count; build the relevance@k golden set. Decision gate: does hybrid beat keyword on the golden set, and is latency acceptable?
- **Phase 1, MVP.** `EmbeddingProvider` (OpenAI) + `MySQL_Vector_Store` + `Indexer` (AS backfill + incremental) + `Retriever` (hybrid + RRF) + `semantic_search` drop-in tool + admin settings (key, model, dims, build/rebuild buttons, on/off) + eval harness relevance@k + graceful degradation. Privacy disclosure for index-time egress.
- **Phase 2, Hardening.** Cost caps + rate-limit backoff, query-embedding cache, content-hash skip, model-change rebuild flow, multilingual provider option (Cohere), observability (last index time, vector count, failures in admin), Action Scheduler health surfacing.
- **Phase 3, Scale Tier.** `MariaDb_Vector_Store` (auto-detected) and `External_Vector_Store` (Qdrant reference, opt-in) behind the same interface. Optional cross-encoder reranking if eval shows precision headroom.

---

## 8. Cost model (worked examples)

Embedding cost is dominated by the one-time backfill; incremental sync is negligible. Assume ~150 tokens per composed product document (title + categories + short desc + attributes, truncated).

| Catalog | Tokens (backfill) | OpenAI `3-small` ($0.02/1M, $0.01 batch) | Cohere multilingual ($0.10/1M) | Storage @512-dim |
|---|---|---|---|---|
| 1,000 products | ~150k | ~$0.003 (batch ~$0.0015) | ~$0.015 | ~2 MB |
| 5,000 products | ~750k | ~$0.015 | ~$0.075 | ~10 MB |
| 50,000 products | ~7.5M | ~$0.15 | ~$0.75 | ~100 MB |

Query-time embedding: each shopper search embeds one short query (~10-30 tokens) ⇒ effectively **fractions of a cent per thousand searches** with `3-small`. Incremental re-embeds: one product's tokens per changed product. **The embedding bill is trivial for typical stores**; the real "cost" of the external Scale Tier is the **monthly vector-DB subscription** ($25-$135+/mo), which is why it's opt-in. Sources: pricing as cited in §3 and §2.4.

---

## 9. Risks & open questions

**Risks**
- **Index freshness / drift:** a missed hook or failed AS job leaves a product unindexed (it still surfaces via the keyword leg, hybrid is the safety net). Mitigate with a periodic reconciliation sweep + an admin "stale count" indicator.
- **Brute-force ceiling:** large catalogs on the default backend degrade. Mitigate with dimension reduction + category/stock pre-filtering, and the documented upgrade path to the MariaDB/external backends.
- **Privacy / data egress:** product **text** leaves the site to the embeddings provider at index time (and queries leave to an external vector DB if the Scale Tier is on). Requires a clear admin disclosure + opt-in; matters for EU/regulated merchants. The default (MySQL backend) has **no per-query egress**, only index-time text, which is the privacy-friendly posture.
- **New external dependency + key:** the merchant must obtain an embeddings API key (separate from the chat key). Onboarding friction; mitigate with clear admin copy and keyword-only fallback so the plugin still works with no key.
- **WP.org review:** an external service + a new outbound API call needs the same disclosure/settings rigor the reviewers already applied to the chat providers (privacy, opt-in, no silent data transmission).
- **Provider lock-in / model churn:** mitigated by the `EmbeddingProvider` interface + stored model/dim per row + rebuild flow.

**Open questions for the client**
1. **Embeddings provider & budget:** OK to add OpenAI as the default embeddings provider (a second key), or prefer Cohere multilingual given the Urdu/English store? Is a hosted embeddings API acceptable at all, or must everything stay on-server (which would force the rejected self-hosted route)?
2. **Privacy posture:** is sending product *text* to a third-party embeddings API at index time acceptable to disclose-and-opt-in? Any merchant segments (EU/regulated) where we must keep the default fully on-MySQL (no external vector DB ever)?
3. **Scale Tier appetite:** do we build the external vector DB backend now (for large-catalog clients) or defer until a real large-catalog customer asks? It's pure cost/complexity until then.
4. **Surface choice:** ship a distinct `semantic_search` tool (recommended) or transparently upgrade `search_products`?
5. **Target catalog sizes:** what's the largest catalog we must support out of the box? This sets where the brute-force ceiling has to hold and whether Phase 3 is urgent.

---

## 10. Sources

- OpenAI embedding pricing/dims: <https://tokenmix.ai/blog/openai-embedding-pricing>, <https://www.cloudzero.com/blog/openai-pricing/>, <https://pecollective.com/tools/text-embedding-models-compared/>
- Cohere / Voyage / bge / e5: <https://reintech.io/blog/embedding-models-comparison-2026-openai-cohere-voyage-bge>, <https://blog.voyageai.com/2025/01/07/voyage-3-large/>, <https://huggingface.co/BAAI/bge-small-en-v1.5>, <https://arxiv.org/pdf/2402.05672>
- MariaDB / MySQL vector: <https://mariadb.org/projects/mariadb-vector/>, <https://mariadb.com/resources/blog/announcing-mariadb-community-server-11-7-ga-with-vector-search-and-mariadb-community-server-11-8-rc/>, <https://dev.mysql.com/doc/refman/9.1/en/vector-functions.html>
- Managed vector DB pricing: <https://www.buildmvpfast.com/api-costs/vector-db>, <https://ranksquire.com/2026/03/04/vector-database-pricing-comparison-2026/>, <https://xenoss.io/blog/vector-database-comparison-pinecone-qdrant-weaviate>
- OpenAI File Search (rejected): <https://developers.openai.com/api/docs/assistants/tools/file-search>, <https://platform.openai.com/docs/pricing/>
- Hybrid search / RRF / reranking: <https://www.digitalapplied.com/blog/hybrid-search-bm25-vector-reranking-reference-2026>, <https://weaviate.io/blog/hybrid-search-explained>
- Brute-force scale ceiling: <https://sarthakai.substack.com/p/a-vectordb-doesnt-actually-work-the-way-you-think-it-does>
- Action Scheduler: <https://actionscheduler.org/>, <https://github.com/woocommerce/action-scheduler/blob/trunk/docs/api.md>
