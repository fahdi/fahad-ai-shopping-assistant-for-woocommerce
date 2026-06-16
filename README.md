# Fahad AI Shopping Assistant for WooCommerce

An AI-powered shopping assistant for WooCommerce. Customers can search products, get recommendations, and manage their cart through a natural chat interface — without leaving the page.

Supports **Anthropic Claude** and **Moonshot AI (Kimi K2)** with real-time streaming responses.

---

## Features

- **Natural language shopping** — customers describe what they want, the AI finds it
- **Full cart control** — add, view, and remove items through conversation
- **Two AI providers** — Anthropic Claude or Moonshot AI (Kimi K2), switchable from the admin
- **Global & China Moonshot platforms** — pick the region your Moonshot key was issued on (`api.moonshot.ai` or `api.moonshot.cn`)
- **Real-time streaming** — responses appear word-by-word (Moonshot provider)
- **Agentic reasoning** — multi-step tool use: the AI can search, evaluate, and add to cart in a single message
- **Customisable widget** — bot name, greeting message, and accent colour
- **Optional system prompt** — inject store policies, tone guidelines, or FAQs

---

## Supported Models

| Provider | Models |
|---|---|
| **Anthropic** | claude-haiku-4-5, claude-sonnet-4-6, claude-opus-4-6 |
| **Moonshot AI** | kimi-k2.6 *(recommended)*, kimi-k2.5, kimi-k2-thinking-turbo, kimi-k2-thinking, moonshot-v1-auto, moonshot-v1-8k/32k/128k |

> The exact models available depend on your region (Global vs China) and the permissions on your key. If a model returns a "model not found" error, choose another from the dropdown.

---

## Requirements

- WordPress 6.0+
- WooCommerce 7.0+
- PHP 8.0+
- An API key from [console.anthropic.com](https://console.anthropic.com), [platform.moonshot.ai](https://platform.moonshot.ai) (Global), or [platform.moonshot.cn](https://platform.moonshot.cn) (China)

---

## Installation

1. Download `fahad-ai-shopping-assistant-for-woocommerce-1.0.7.zip` from [Releases](https://github.com/fahdi/fahad-ai-shopping-assistant-for-woocommerce/releases)
2. In WordPress admin go to **Plugins → Add New → Upload Plugin**
3. Upload the zip and activate
4. Go to **Settings → Fahad AI Assistant**
5. Select your provider, enter your API key, save
6. The chat widget appears on all frontend pages automatically

---

## Configuration

| Setting | Description |
|---|---|
| AI Provider | Anthropic (Claude) or Moonshot AI (Kimi) |
| Moonshot Region | Global (`api.moonshot.ai`) or China (`api.moonshot.cn`) — must match the platform your key was issued on |
| API Key | Provider-specific key — never exposed to the frontend |
| Model | Choose speed vs capability tradeoff per provider |
| Bot Name | Displayed in the chat header |
| Greeting Message | First message shown when the widget opens |
| Accent Color | Widget header and button color |
| System Prompt | Optional extra instructions injected before every conversation |

---

## How It Works

The plugin registers two REST endpoints:

- `POST /wp-json/fahad-ai/v1/message` — standard request/response (Anthropic)
- `POST /wp-json/fahad-ai/v1/stream` — Server-Sent Events streaming (Moonshot)

Each request runs an **agentic loop**: the AI can call WooCommerce tools multiple times before returning a final response. Tool results feed back into the next API call automatically.

The Moonshot streaming endpoint drives a dedicated cURL handle so Server-Sent Events reach the browser intact. The base host (`api.moonshot.ai` or `api.moonshot.cn`) is chosen from the **Moonshot Region** setting.

### Available Tools

| Tool | What it does |
|---|---|
| `search_products` | Searches by keyword, category, and price range |
| `get_product_details` | Returns full product data including variations and stock |
| `add_to_cart` | Adds a product to the current session cart |
| `view_cart` | Returns current cart items and totals |
| `remove_from_cart` | Removes an item by cart item key |

---

## Development

```bash
# Install dev dependencies
composer install

# Run tests
vendor/bin/phpunit --testdox
```

The test suite uses [PHPUnit 10](https://phpunit.de), [Brain\Monkey](https://brain-wp.github.io/BrainMonkey/) for WordPress function mocking, and [Mockery](http://docs.mockery.io) for WooCommerce object mocking.

```
39 tests, 120 assertions — all passing
```

---

## External Services

This plugin transmits conversation data to third-party APIs:

- **Anthropic** (`api.anthropic.com`) — [Privacy Policy](https://www.anthropic.com/legal/privacy)
- **Moonshot AI** (`api.moonshot.ai` for Global, `api.moonshot.cn` for China) — [Privacy Policy](https://www.moonshot.ai/privacy)

Only the current session's conversation history and relevant product data are sent. No personal customer data is transmitted unless the customer types it into the chat.

---

## License

GPLv2 or later — see [https://www.gnu.org/licenses/gpl-2.0.html](https://www.gnu.org/licenses/gpl-2.0.html)
