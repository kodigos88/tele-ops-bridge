# TeleOps Bridge 🚀

> Lightweight, event-driven webhook gateway & terminal controller bridging messaging interfaces with cPanel/VPS hosting and local workstation terminals.

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](https://opensource.org/licenses/MIT)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B%20%7C%208.x-purple.svg)](https://www.php.net/)
[![NodeJS](https://img.shields.io/badge/NodeJS-18%2B-green.svg)](https://nodejs.org/)

---

## 🌟 Overview

**TeleOps Bridge** allows DevOps engineers, sysadmins, and developers to manage, query, and dispatch tasks between a mobile messaging bot (Telegram) and their local or remote machines without requiring:
- ❌ Public IP addresses or dynamic DNS on your local workstation.
- ❌ Opening inbound firewall ports or complex VPNs.
- ❌ Heavy background daemons or Docker orchestration.

It uses a dual-plane architecture:
1. **Cloud Gateway (PHP / cPanel / VPS):** Listens for incoming webhook requests, validates security signatures, and manages an asynchronous instruction queue.
2. **Terminal Daemon (Node.js):** Runs locally on your workstation, sending periodic heartbeat pings and consuming queued commands in real-time.

---

## 🏗️ Architecture

```
[ Telegram App ]
       │
       ▼ (HTTPS Webhook)
┌────────────────────────────────────────┐
│  Cloud Gateway (cPanel / VPS)          │
│  - webhook.php                         │
│  - Ingests commands (/do, /status)     │
│  - Queues tasks & manages heartbeat    │
│  - High-availability NLP engine        │
└───────────────────▲────────────────────┘
                    │ (Outbound HTTPS Heartbeat & Poll)
┌───────────────────┴────────────────────┐
│  Workstation / CLI Daemon              │
│  - daemon.js                           │
│  - Sends periodic keepalive ping       │
│  - Executes / Alerts local developer   │
└────────────────────────────────────────┘
```

---

## 🚀 Features

- **Real-Time Heartbeat & Node Awareness:** Knows instantly if your workstation is online or asleep.
- **Failover Natural Language Engine:** Dual-model fallback architecture ensuring zero-downtime responses.
- **Bi-directional Multimodal Support:** Accepts audio/voice messages and text instructions, converting responses into synthesized audio streams.
- **Cross-Platform Local Alerts:** Pops native OS dialogs and terminal bell rings when a remote task arrives.
- **Zero Inbound Ports Needed:** All local daemon communication is outbound HTTPS polling.

---

## 📦 Quick Start

### 1. Cloud Gateway Setup (cPanel or VPS)
1. Upload `webhook.php` to your web server (e.g., `public_html/teleops/`).
2. Copy `config.sample.php` to `config.php`:
   ```bash
   cp config.sample.php config.php
   ```
3. Edit `config.php` with your bot token and desired secret authentication key:
   ```php
   define('GATEWAY_TOKEN', 'YOUR_TELEGRAM_BOT_TOKEN');
   define('OPERATOR_CHAT_ID', 'YOUR_CHAT_ID');
   define('SECRET_AUTH_KEY', 'CREATE_A_SECURE_KEY');
   ```
4. Register your webhook:
   ```text
   https://api.telegram.org/bot<YOUR_TOKEN>/setWebhook?url=https://your-domain.com/teleops/webhook.php
   ```

### 2. Local Terminal Daemon Setup
1. Open your local terminal and configure your environment variables (or update default values):
   ```bash
   export GATEWAY_URL="https://your-domain.com/teleops/webhook.php"
   export SECRET_AUTH_KEY="CREATE_A_SECURE_KEY"
   ```
2. Launch the daemon:
   ```bash
   node daemon.js
   ```

---

## ⌨️ Command Reference

| Command | Description |
| :--- | :--- |
| `/do <command>` | Dispatches an instruction directly to your local terminal queue |
| `/create <file>` | Requests creation or scaffolding routine |
| `/status` | Displays real-time connectivity of both Cloud Gateway and Terminal Node |
| `/clear` | Flushes session memory and chat history |

---

## 🔒 Security Best Practices

- **Token Isolation:** The `config.php` file is excluded in `.gitignore` to prevent credential exposure.
- **Operator Whitelist:** Only the authorized `OPERATOR_CHAT_ID` can trigger gateway routines.
- **HMAC / Secret Key Auth:** The control plane rejects all heartbeat and query actions that don't match `SECRET_AUTH_KEY`.

---

## 📄 License

Distributed under the MIT License. See `LICENSE` for details.

Developed with precision by [Arthur (@kodigos88)](https://github.com/kodigos88).
