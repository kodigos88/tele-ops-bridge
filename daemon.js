/**
 * TeleOps Daemon - Terminal Node Listener & Heartbeat Ping
 *
 * Runs locally on your workstation or remote terminal.
 * - Dispatches heartbeat pings every N seconds to keep node status alive.
 * - Polls remote queue for pending CLI instructions (/do, /create, /run).
 * - Fires native desktop alerts and audio chimes upon receiving tasks.
 *
 * @license MIT
 */

const https = require('https');
const http = require('http');
const { exec } = require('child_process');

// Configuration
const GATEWAY_URL = process.env.GATEWAY_URL || 'https://your-cpanel-domain.com/webhook.php';
const SECRET_AUTH_KEY = process.env.SECRET_AUTH_KEY || 'YOUR_AUTH_SECRET_KEY';
const PING_INTERVAL_MS = parseInt(process.env.PING_INTERVAL_MS, 10) || 45000;

const processedQueue = new Set();

function dispatchHeartbeat() {
  const targetUrl = `${GATEWAY_URL}?action=heartbeat&key=${encodeURIComponent(SECRET_AUTH_KEY)}`;
  const client = targetUrl.startsWith('https') ? https : http;

  client.get(targetUrl, (res) => {
    let raw = '';
    res.on('data', (chunk) => { raw += chunk; });
    res.on('end', () => {
      try {
        const payload = JSON.parse(raw);
        if (payload.status === 'success') {
          const timestamp = new Date().toLocaleTimeString();
          console.log(`[${timestamp}] 🟢 Heartbeat dispatched: Terminal Node Online.`);
          pollPendingQueue();
        }
      } catch (e) {}
    });
  }).on('error', (err) => {
    console.error('[Heartbeat Error]:', err.message);
  });
}

function pollPendingQueue() {
  const targetUrl = `${GATEWAY_URL}?action=get_tasks&key=${encodeURIComponent(SECRET_AUTH_KEY)}`;
  const client = targetUrl.startsWith('https') ? https : http;

  client.get(targetUrl, (res) => {
    let raw = '';
    res.on('data', (chunk) => { raw += chunk; });
    res.on('end', () => {
      try {
        const payload = JSON.parse(raw);
        if (payload.status === 'success' && Array.isArray(payload.tasks)) {
          payload.tasks.forEach((t) => {
            const taskId = (t.timestamp || '') + (t.command || t.payload || '');
            if (t.type === 'cli_dispatch' && !processedQueue.has(taskId)) {
              processedQueue.add(taskId);
              triggerTaskAlert(t);
            }
          });
        }
      } catch (e) {}
    });
  }).on('error', () => {});
}

function triggerTaskAlert(task) {
  const timestamp = new Date().toLocaleTimeString();
  console.log('\n' + '='.repeat(60));
  console.log(`🚨 [${timestamp}] REMOTE INSTRUCTION DISPATCHED`);
  console.log(`📌 Action: /${task.action} ${task.command}`);
  console.log(`🕒 Timestamp: ${task.timestamp}`);
  console.log('='.repeat(60) + '\n');

  // Trigger cross-platform alert / audio bell
  process.stdout.write('\x07');

  if (process.platform === 'win32') {
    const escapedMsg = `Action: /${task.action} ${task.command}`.replace(/'/g, "''");
    const psCommand = `powershell -Command "[reflection.assembly]::loadwithpartialname('System.Windows.Forms'); [System.Windows.Forms.MessageBox]::Show('${escapedMsg}', 'TeleOps Daemon')"` ;
    exec(psCommand, () => {});
  }
}

// Initial ping
dispatchHeartbeat();

// Recurring heartbeat schedule
setInterval(dispatchHeartbeat, PING_INTERVAL_MS);
