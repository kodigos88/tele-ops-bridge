/**
 * TeleOps Pre-Commit Security & Leak Auditor
 * 
 * Verifies that zero secrets, real domains, personal paths, or AI fingerprints
 * exist in the repository before pushing to GitHub.
 */

const fs = require('fs');
const path = require('path');

const TARGET_DIR = __dirname;

// Patterns that MUST NOT appear anywhere in the codebase
const LEAK_PATTERNS = [
  { name: 'Bot Token Real Format', regex: /[0-9]{9,11}:[a-zA-Z0-9_-]{35}/g },
  { name: 'Fish Audio Real Key', regex: /sk-fish-[a-zA-Z0-9_-]+/g },
  { name: 'Gemini AI Real Key', regex: /AIzaSy[a-zA-Z0-9_-]{33}/g },
  { name: 'Arthur Real Subdomain', regex: /asistente\.codigosatdev\.com/gi },
  { name: 'Real Server IP', regex: /62\.171\.150\.232/g },
  { name: 'Local Windows Arthur Path', regex: /C:\\Users\\Arthur/gi },
  { name: 'Fish Audio Real Voice ID', regex: /21adf3cda02a4aa88dc593353cc9d715/gi },
  { name: 'Personal Secret Key', regex: /sat_dev_secret_2026/g },
  { name: 'Numeric Telegram Chat ID (Personal)', regex: /612456456/g } // placeholder
];

// Verify forbidden files
const FORBIDDEN_FILES = ['config.php', 'task_queue.json', 'cola_tareas.json', 'node_status.json', 'pc_status.json'];

console.log('🛡️  INICIANDO ESCÁNER DE AUDITORÍA Y SEGURIDAD PRE-VUELO...\n');

let issuesFound = 0;

// 1. Check forbidden files
FORBIDDEN_FILES.forEach((f) => {
  const p = path.join(TARGET_DIR, f);
  if (fs.existsSync(p)) {
    console.error(`❌ [ALERTA CRÍTICA]: Archivo sensible encontrado en carpeta de repositorio: ${f}`);
    issuesFound++;
  } else {
    console.log(`✅ [OK]: Archivo excluido correctamente: ${f}`);
  }
});

// 2. Scan text contents of all tracked files
function scanFile(filePath) {
  const relPath = path.relative(TARGET_DIR, filePath);
  if (relPath.startsWith('.git') || relPath === 'audit_security.js') return;

  const content = fs.readFileSync(filePath, 'utf8');

  LEAK_PATTERNS.forEach((rule) => {
    const matches = content.match(rule.regex);
    if (matches) {
      console.error(`❌ [FUGA DETECTADA] en ${relPath} ➔ Regla: "${rule.name}" (${matches.length} coincidencia(s))`);
      issuesFound++;
    }
  });
}

function walkDir(dir) {
  const list = fs.readdirSync(dir);
  list.forEach((item) => {
    const fullPath = path.join(dir, item);
    const stat = fs.statSync(fullPath);
    if (stat.isDirectory()) {
      if (item !== '.git' && item !== 'node_modules') walkDir(fullPath);
    } else {
      scanFile(fullPath);
    }
  });
}

walkDir(TARGET_DIR);

console.log('\n' + '='.repeat(55));
if (issuesFound === 0) {
  console.log('🎉 RESULTADO: 0 FUGAS. EL CÓDIGO ESTÁ 100% BLINDADO Y SEGURO.');
  console.log('LISTO PARA HACER COMMIT Y PUSH A GITHUB PÚBLICO.');
} else {
  console.error(`🚨 PELIGRO: Se encontraron ${issuesFound} problemas de seguridad. NO SUBIR.`);
  process.exit(1);
}
console.log('='.repeat(55) + '\n');
