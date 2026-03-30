# SmartLog — AI-Powered Log Analysis for Magento 2

Semantic & keyword search across `var/log/**` and `var/report/**` using LLPhant embeddings + OpenSearch vector storage.

## Features

- **Hybrid search** — keyword (exact IDs, error codes) + semantic (natural language queries)
- **Multiple AI providers** — OpenAI, Google Gemini, Voyage AI
- **Admin UI** — search page at System > SmartLog
- **CLI** — `bin/magento smartlog:index` with progress bar
- **Recursive log scanning** — reads all `var/log/**/*.log` and `var/report/**/*`
- **Isolated dependencies** — LLPhant runs in a separate PHP process to avoid PSR/autoloading conflicts with Magento 2

## Requirements

- Magento 2.4.x (Commerce or Open Source)
- PHP 8.1+
- OpenSearch (uses Magento's native catalog/search connection)
- API key for one of: OpenAI, Google Gemini, or Voyage AI

## Installation

### Via Composer (recommended)

```bash
composer require perspective/module-smartlog
bin/magento module:enable Perspective_SmartLog
bin/magento setup:upgrade
bin/magento setup:di:compile
```

Then install the isolated worker dependencies:

```bash
cd vendor/perspective/module-smartlog/Worker && bash install.sh
```

### Manual (app/code)

1. Copy the module to `app/code/Perspective/SmartLog/`
2. Install worker dependencies:
   ```bash
   cd app/code/Perspective/SmartLog/Worker && bash install.sh
   ```
3. Enable the module:
   ```bash
   bin/magento module:enable Perspective_SmartLog
   bin/magento setup:upgrade
   bin/magento setup:di:compile
   ```

## Configuration

Go to **Stores > Configuration > Perspective > SmartLog**:

| Setting | Description |
|---------|-------------|
| Enable | Enable/disable the module |
| Embedding Provider | OpenAI / Google Gemini / Anthropic (Voyage AI) |
| API Key | Your provider API key |
| Model | Embedding model (per provider) |
| Chunk Size | Number of log entries per chunk (default: 15) |
| Batch Size | Chunks per embedding API call (default: 20) |
| OpenSearch Index | Index name for vectors (default: `smartlog_vectors`) |

OpenSearch connection settings are read from Magento's native **Catalog > Search** configuration.

## Usage

### CLI Indexing

```bash
# Index all logs (respects enabled/disabled setting)
bin/magento smartlog:index

# Force index even if disabled
bin/magento smartlog:index --force
```

### Admin Search

Navigate to **System > SmartLog** — search with:
- Natural language: *"was there a shipping address problem recently?"*
- Exact values: *"40915"* (order ID), *"SQLSTATE"* (error code)
- Date filtering available

## Architecture

```
Perspective/SmartLog/
├── Block/Adminhtml/          # Admin UI block
├── Composer/                 # Composer post-install script
├── Console/Command/          # CLI command (smartlog:index)
├── Controller/Adminhtml/     # Admin controllers (search, reindex)
├── Model/
│   ├── Config.php            # System configuration reader
│   ├── Config/Source/        # Admin dropdown source models
│   ├── Indexer.php           # Orchestrates log reading + worker calls
│   ├── LogReader.php         # Recursive log file reader + chunker
│   ├── SearchService.php     # Search orchestration + date filtering
│   └── WorkerBridge.php      # proc_open bridge to worker.php
├── Worker/
│   ├── composer.json         # Isolated LLPhant + OpenSearch deps
│   ├── install.sh            # Dependency installer
│   └── worker.php            # Standalone process (embeddings + vector ops)
├── etc/                      # Magento XML configs
└── view/adminhtml/           # Admin layout, templates, CSS
```

### Why a separate worker process?

LLPhant requires `psr/log` v3 and `psr/http-message` v2, which conflict with Magento 2's v1 copies. Instead of fighting autoloader isolation, the module runs LLPhant in a completely separate PHP process (`Worker/worker.php`), communicating via JSON over stdin/stdout.

## License

MIT License. See [LICENSE](LICENSE) for details.
