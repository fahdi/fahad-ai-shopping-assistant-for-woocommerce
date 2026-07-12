# RAG / Semantic Product Retrieval, Implementation Plan

Companion to [RAG-DESIGN.md](RAG-DESIGN.md). The design answers *what to build and why*; this plan answers *how we ship it*, the phases, the actionable stories, their acceptance criteria, and the order of work. It is the basis for the GitHub epic and its child issues.

The decision to commit beyond a prototype is **gated**: Phase 0 must prove that hybrid retrieval (vector + keyword, RRF-fused) beats keyword-only on a golden relevance set at acceptable latency on the demo catalog, with real embeddings, before Phase 1 ships anything user-facing.

## Definition of done, applies to EVERY story

Mirrors the project's standing bar (CLAUDE.md "Release workflow", epic #47):

1. **TDD**, tests first (red → green). Unit tests always; an eval/golden case when retrieval behaviour or tool calls change. No code without tests.
2. **Acceptance criteria**, each story below lists explicit, checkable criteria; work is judged against them.
3. **Hardening**, security (nonce/capability where admin-facing), privacy/PII (index-time egress disclosed), guardrails (grounding, budget, empty-result honesty, graceful degradation), performance/cost, edge cases.
4. **Release per PR** (Phase 1+), semver bump in all four version locations, readme changelog + upgrade notice, branch → PR → merge, build the zip, publish a GitHub release. **Phase 0 is a spike (no release)**: its deliverable is tested primitives + a measured decision report.
5. Full suite green; no new PHPCS / PHP-deprecation noise (the `phpcs.xml.dist` gate added in #98).

## Invariants carried from the design (must hold in every phase)

- **Live data is never embedded.** Price, stock, sale, rating are read live via `format_product_summary()` at retrieval time. The vector index holds only the semantic text fingerprint. (RAG-DESIGN §4.4, §5.4)
- **Never regress.** No key / API down / stale index ⇒ silently fall back to today's keyword search. Embeddings errors never reach the shopper. (§4.3, §5.5, §6.2)
- **Model + dimensions are stored beside every vector.** A model change marks the index stale and never mixes vectors across models. (§3, §5.5)
- **Grounding unchanged.** Semantic retrieval changes *which* products surface, not the no-hallucination / budget / abstain rules. (§6.2)
- **Backend is swappable behind an interface.** `Fahad_AI_Vector_Store` has MySQL (default), MariaDB-native, and external implementations; retrieval logic does not change when the backend does. (§7.1, §7.4)

---

## Phase 0, Spike (no ship). Prove the gate.

**Goal:** build the reusable retrieval primitives test-first, assemble a deterministic relevance@k harness + a golden set over the demo catalog, and produce a measured report answering: *does hybrid beat keyword, and is brute-force scan latency acceptable at this catalog size?* No user-facing feature, no release.

The primitives built here are **not throwaway**, they are the exact units Phase 1 composes. The "spike" framing means: gated decision, no shipped tool/UI, deterministic/canned embeddings in tests.

### S0.1, Vector math + float32 BLOB codec
Pure helper for packing/unpacking `float32` vectors to/from the `LONGBLOB` storage form and computing similarity.
- **AC1** `pack_vector(float[]) : string` and `unpack_vector(string) : float[]` round-trip a vector losslessly within float32 precision (`pack('g*', …)` little-endian).
- **AC2** `cosine(a, b) : float` returns 1.0 for identical vectors, ~0 for orthogonal, −1 for opposite; returns 0.0 for a zero vector (no divide-by-zero).
- **AC3** Mismatched-length inputs throw `InvalidArgumentException` (never silently truncate).
- **AC4** Unit tests cover round-trip, known cosine values, zero/orthogonal/opposite, and the length-mismatch guard.

### S0.2, Reciprocal Rank Fusion (RRF)
Pure utility that fuses N ranked id-lists into one fused ranking.
- **AC1** `fuse(array $rankedLists, int $k = 60) : int[]` returns ids ordered by `Σ 1/(k + rank_i)` (rank 1-based).
- **AC2** An id present in multiple lists outranks one present in a single list at the same rank (fusion reward).
- **AC3** Order is stable and deterministic for ties (e.g. by id) so tests are reproducible.
- **AC4** Empty lists and single-list input are handled (single list ⇒ that list's order).
- **AC5** Unit tests cover the worked example from RAG-DESIGN §4.3 and the multi-list reward.

### S0.3, Product → embedding-document composer
Builds the single composed text embedded per product (RAG-DESIGN §4.2).
- **AC1** Composes `title / Categories / short desc / long desc / Attributes / Tags` in that salience order.
- **AC2** **Excludes** SKU, price, and stock (asserted explicitly, these are keyword/live concerns).
- **AC3** Strips HTML (`wp_strip_all_tags`), collapses whitespace, truncates the long description to a bounded length (~1500 chars).
- **AC4** `content_hash` = SHA-1 of the composed text is stable for unchanged input and changes when an embedded field changes (and does **not** change when only price/stock change).
- **AC5** Unit tests over a mock product cover composition order, the SKU/price/stock exclusion, truncation, and hash stability/sensitivity.

### S0.4, Relevance@k scorer + golden set
A deterministic, offline retrieval-quality harness under `tests/eval/`.
- **AC1** A golden fixture of `(query → expected product id[])` over the 7-product demo catalog, reflecting real intent (e.g. "something to keep me warm" → hoodie/jeans, not the water bottle).
- **AC2** `recall_at_k`, `precision_at_k`, and `ndcg_at_k` scorers with known-value unit tests.
- **AC3** A comparison runner that scores three retrievers, keyword-only, vector-only, hybrid(RRF), on the golden set, using **canned/stubbed embeddings** (no live API; consistent with the harness rule).
- **AC4** Test asserts the harness *discriminates*: on the constructed fixture, hybrid ≥ vector and hybrid ≥ keyword for recall@k (proves the measurement is meaningful before real embeddings are wired).

### S0.5, Spike runner + decision report
A runnable path to close the real gate, plus a written verdict.
- **AC1** A WP-CLI command (`wp fahad-ai rag-spike`) that, given a configured embeddings key, embeds the live demo catalog into a temporary BLOB table, runs the golden set through keyword/vector/hybrid, and prints recall@k + p50/p95 scan latency at the catalog's product count.
- **AC2** Runs offline with canned embeddings when no key is set, so the command and output format are verifiable without spend.
- **AC3** Writes `docs/RAG-SPIKE-REPORT.md` with the numbers and an explicit **GO / NO-GO** recommendation against the gate (hybrid beats keyword; latency acceptable).
- **AC4** No shipped plugin surface (no new tool, no admin UI, no release). Primitives live where Phase 1 will reuse them.

**Phase 0 exit gate:** report shows hybrid > keyword on the golden set and acceptable latency ⇒ proceed to Phase 1. Otherwise, stop and revisit the design.

---

## Phase 1, MVP (ships). The real feature, default backend.

Gated on Phase 0 GO. Each story is its own per-PR release.

### S1.1, Embedding provider abstraction + OpenAI implementation
- **AC1** `Fahad_AI_Embedding_Provider` interface: `embed(string[]) : float[][]`, `model() : string`, `dimensions() : int` (RAG-DESIGN §7.4).
- **AC2** OpenAI implementation (`text-embedding-3-small`, `dimensions: 512` default) using the existing HTTP layer; selected via a `fahad_ai_embedding_provider` factory + filter (mirrors the chat-provider pattern).
- **AC3** Defaults to **off / keyword-only** until a key is configured; a missing key is not an error.
- **AC4** Batch endpoint used where available; 429/5xx surface as a typed failure the caller degrades on (never to the shopper).
- **AC5** Tests stub HTTP and assert request shape (model, dims, batched inputs) and failure handling.

### S1.2, Vector store interface + MySQL backend + schema
- **AC1** `Fahad_AI_Vector_Store` interface: `upsert/delete/query/is_available/rebuild_required` (RAG-DESIGN §7.4).
- **AC2** MySQL backend creates `{$wpdb->prefix}fahad_ai_embeddings` via `dbDelta` on activation (schema per §5.1: product_id PK, model, dim, embedding LONGBLOB, content_hash, updated_at; index on model).
- **AC3** `query(vector, k, filters)` runs the PHP brute-force cosine scan with category/price/stock/visibility **pre-filtering** before the scan (§4.4).
- **AC4** Uninstall cleanup drops the table; activation/upgrade is idempotent.
- **AC5** Integration tests cover upsert→query ranking, pre-filter correctness, and the model/dim columns.

### S1.3, Indexer + Action Scheduler sync
- **AC1** `Fahad_AI_Indexer`: `backfill()` (batched bulk embed via Action Scheduler) and `reindex_product(id)` (async).
- **AC2** Incremental hooks: `woocommerce_update_product`/`woocommerce_new_product` enqueue async re-embed (unique-coalesced); `before_delete_post`/`wp_trash_post` enqueue delete (§5.3).
- **AC3** `content_hash` skip: a price-only edit triggers **no** re-embed (asserted).
- **AC4** Idempotent and safe to re-run; respects a per-day token cap option.
- **AC5** Tests assert enqueue-on-save, no-op on unchanged hash, delete-on-trash, batch sizing.

### S1.4, Hybrid retriever + `semantic_search` tool
- **AC1** `Fahad_AI_Retriever::search(query, filters, k)` runs keyword leg (reuse existing `wc_get_products` search) + vector leg, fuses with RRF (S0.2), applies live filters, returns product summaries.
- **AC2** New `includes/tools/class-semantic-tools.php` registers a `semantic_search` tool via `register_pack()`, returning the canonical `{ found, products[] }` shape so cards render with **no api-handler change** (§4.5). Gateable via existing tool-disable settings.
- **AC3** Live price/stock read at call time via `format_product_summary()` (no stale data).
- **AC4** No key / empty index ⇒ returns keyword-only results (degradation), never an error.
- **AC5** Eval: relevance@k on the golden set with the real retriever path; hybrid ≥ keyword (regression gate from §6.1).

### S1.5, Admin settings + index controls + eval gate
- **AC1** Settings (under the existing page): embeddings on/off, provider, model, dimensions, per-day token cap.
- **AC2** "Build index" / "Rebuild index" buttons (nonce + capability gated) that enqueue the backfill; show last-index time + vector count + failures.
- **AC3** Privacy disclosure: index-time text egress to the embeddings provider documented in `readme.txt` external-services + admin copy.
- **AC4** Suite green; relevance@k regression gate wired into `tests/eval/`.

---

## Phase 2, Hardening

### S2.1, Cost & rate-limit controls
- **AC1** 429 backoff + retry with jitter; batch sizing tuned to provider limits.
- **AC2** Query-embedding transient cache for repeated identical shopper phrases.
- **AC3** Per-day token ceiling enforced (hard stop + admin notice) to protect the bill during bulk import.
- **AC4** Tests cover backoff, cache hit/miss, and the ceiling stop.

### S2.2, Model-change rebuild flow + observability
- **AC1** Changing model/dims marks the index stale and falls back to keyword until rebuilt (§5.5).
- **AC2** Rebuild backfills under the new model then flips the active pointer atomically.
- **AC3** Admin observability: last index time, vector count, stale flag, recent failures; Action Scheduler health surfaced.
- **AC4** Tests cover stale-on-change, fallback-while-stale, and pointer flip.

### S2.3, Multilingual provider option (Cohere)
- **AC1** Cohere `embed-multilingual-v3.0` provider behind the same interface (better on Urdu/non-Latin).
- **AC2** Selectable in settings; switching triggers the S2.2 rebuild flow.
- **AC3** A golden-set case in a non-Latin script demonstrates the multilingual provider's lift over the default.

---

## Phase 3, Scale tier (opt-in, no default change)

### S3.1, MariaDB-native vector backend
- **AC1** `Fahad_AI_MariaDb_Vector_Store` using `VECTOR(dim)` + `VECTOR INDEX`, auto-detected when `VEC_DISTANCE_COSINE`/MariaDB ≥ 11.7 is present (§2.2).
- **AC2** Same interface; identical retrieval results vs the MySQL backend on the golden set (parity test).
- **AC3** Default behaviour unchanged on hosts without the capability.

### S3.2, External vector store (Qdrant reference) + optional rerank
- **AC1** `Fahad_AI_External_Vector_Store` (Qdrant reference), opt-in only; credentials via settings; egress/privacy disclosed.
- **AC2** Optional cross-encoder rerank (Cohere/Voyage) behind a setting, added only if Phase 1 eval shows precision headroom (§4.3 step 4).
- **AC3** Falls back to the default backend if the external store is unreachable.

---

## Issue map

- **Epic:** RAG / semantic product retrieval.
- **Phase 0:** S0.1-S0.5 (one spike issue with these as acceptance criteria, or split if useful).
- **Phase 1:** S1.1, S1.2, S1.3, S1.4, S1.5.
- **Phase 2:** S2.1, S2.2, S2.3.
- **Phase 3:** S3.1, S3.2.

Sequencing: **Phase 0 gate first.** Within Phase 1: S1.1 → S1.2 → S1.3 → S1.4 → S1.5 (each builds on the prior). Phases 2 and 3 follow Phase 1 GA.
