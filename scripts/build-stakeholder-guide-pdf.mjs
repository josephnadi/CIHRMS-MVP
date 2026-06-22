import { execFileSync, spawn } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(__dirname, '..');
const baseUrl = process.env.CIHRMS_BASE_URL || 'http://127.0.0.1:8010';
const chromePath = process.env.CHROME_PATH || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const outDir = path.join(root, 'docs', 'assets', 'stakeholder-guide');
const rawDir = path.join(outDir, 'screenshots');
const annotatedDir = path.join(outDir, 'annotated');
const buildDir = path.join(outDir, 'build');
const guideMd = path.join(root, 'docs', 'NON_TECHNICAL_APPLICATION_GUIDE.md');
const pdfPath = path.join(root, 'docs', 'NON_TECHNICAL_APPLICATION_GUIDE.pdf');
const htmlPath = path.join(buildDir, 'NON_TECHNICAL_APPLICATION_GUIDE_ILLUSTRATED.html');
const tempMdPath = path.join(buildDir, 'NON_TECHNICAL_APPLICATION_GUIDE_ILLUSTRATED.md');
const chromeProfileDir = path.join(buildDir, 'chrome-cli-profile');
const chromeAuthProfileDir = path.join(buildDir, 'chrome-auth-profile');
const viteHotPath = path.join(root, 'public', 'hot');
const viteHotBackupPath = path.join(root, 'public', 'hot.stakeholder-guide-backup');
const demoPasswordStatePath = path.join(buildDir, 'demo-password-state.json');
const demoAdminName = 'Stakeholder Guide Admin';
const demoAdminStaffId = 'DOCS-ADMIN-001';
const demoAdminEmail = 'stakeholder-guide-admin@cihrms.local';
const chromeDebugPort = Number(process.env.CIHRMS_CHROME_DEBUG_PORT || 9223);
const allowDemoAuthMutation = process.env.CIHRMS_ALLOW_DEMO_AUTH_MUTATION === '1';

let movedViteHotFile = false;
let changedDemoPasswordState = false;

function restoreViteHotFile() {
  if (!movedViteHotFile) return;
  if (fs.existsSync(viteHotBackupPath)) {
    fs.renameSync(viteHotBackupPath, viteHotPath);
  }
  movedViteHotFile = false;
}

if (fs.existsSync(viteHotPath)) {
  if (fs.existsSync(viteHotBackupPath)) {
    fs.rmSync(viteHotBackupPath, { force: true });
  }
  fs.renameSync(viteHotPath, viteHotBackupPath);
  movedViteHotFile = true;
  process.on('SIGINT', () => {
    restoreViteHotFile();
    restoreDemoAuthState();
    process.exit(130);
  });
  process.on('SIGTERM', () => {
    restoreViteHotFile();
    restoreDemoAuthState();
    process.exit(143);
  });
}

function runPhp(code) {
  execFileSync('php', ['-r', code], { cwd: root, stdio: 'ignore' });
}

function prepareDemoAuthState() {
  const statePath = demoPasswordStatePath.replaceAll('\\', '/');
  runPhp(`
    require 'vendor/autoload.php';
    $app = require 'bootstrap/app.php';
    $app->make(\\Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
    $staffId = '${demoAdminStaffId}';
    $user = \\App\\Models\\User::withTrashed()->where('staff_id', $staffId)->first();
    $attributes = $user
      ? $user->makeVisible(['password', 'remember_token'])->only([
        'id', 'name', 'email', 'password', 'role', 'permissions', 'staff_id',
        'two_factor_required', 'two_factor_confirmed_at', 'password_must_change',
        'remember_token', 'deleted_at',
      ])
      : null;
    file_put_contents('${statePath}', json_encode([
      'version' => 2,
      'staff_id' => $staffId,
      'existed' => (bool) $user,
      'attributes' => $attributes,
    ]));
    $user = $user ?: new \\App\\Models\\User();
    $user->forceFill([
      'name' => '${demoAdminName}',
      'email' => '${demoAdminEmail}',
      'staff_id' => $staffId,
      'role' => 'super_admin',
      'permissions' => ['*'],
      'password' => \\Illuminate\\Support\\Facades\\Hash::make('password'),
      'password_must_change' => false,
      'two_factor_required' => false,
      'two_factor_confirmed_at' => null,
      'deleted_at' => null,
    ]);
    $user->save();
  `);
  changedDemoPasswordState = true;
}

function restoreDemoAuthState() {
  if (!changedDemoPasswordState || !fs.existsSync(demoPasswordStatePath)) return;
  const statePath = demoPasswordStatePath.replaceAll('\\', '/');
  runPhp(`
    require 'vendor/autoload.php';
    $app = require 'bootstrap/app.php';
    $app->make(\\Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
    $state = json_decode(file_get_contents('${statePath}'), true) ?: [];
    if (($state['version'] ?? null) === 2) {
      $user = \\App\\Models\\User::withTrashed()->where('staff_id', $state['staff_id'])->first();
      if ($user && ! ($state['existed'] ?? false)) {
        $user->forceDelete();
      } elseif ($user && isset($state['attributes'])) {
        $attributes = $state['attributes'];
        unset($attributes['id']);
        $attributes['password_must_change'] = (bool) ($attributes['password_must_change'] ?? false);
        $attributes['two_factor_required'] = (bool) ($attributes['two_factor_required'] ?? false);
        $user->forceFill($attributes)->save();
      }
    } else {
      foreach ($state as $row) {
        $attributes = ['password_must_change' => (bool) $row['password_must_change']];
        if (array_key_exists('password', $row)) {
          $attributes['password'] = $row['password'];
        }
        \\App\\Models\\User::whereKey((int) $row['id'])->update($attributes);
      }
    }
  `);
  changedDemoPasswordState = false;
}

process.on('exit', () => {
  restoreViteHotFile();
  restoreDemoAuthState();
});

for (const dir of [rawDir, annotatedDir, buildDir]) {
  fs.mkdirSync(dir, { recursive: true });
}
fs.rmSync(chromeProfileDir, { recursive: true, force: true });
fs.rmSync(chromeAuthProfileDir, { recursive: true, force: true });
fs.mkdirSync(chromeProfileDir, { recursive: true });
fs.mkdirSync(chromeAuthProfileDir, { recursive: true });

const screens = [
  {
    id: 'welcome',
    title: 'Portal card landing page',
    url: '/',
    callouts: [
      { x: 7, y: 18, text: 'Role guidance helps each user choose the correct workspace' },
      { x: 56, y: 18, text: 'Module cards open the correct sign-in path for each user type' },
      { x: 9, y: 78, text: 'Staff login and access request actions sit below the introduction' },
    ],
  },
  {
    id: 'staff-login',
    title: 'Staff login',
    url: '/login',
    callouts: [
      { x: 52, y: 24, text: 'Staff sign in with full name, staff number, and password' },
      { x: 54, y: 72, text: 'Recovery and access request options support users' },
    ],
  },
  {
    id: 'member-portal',
    title: 'Member portal login',
    url: '/portal/login',
    callouts: [
      { x: 20, y: 20, text: 'Members have a separate self-service entry point' },
      { x: 51, y: 28, text: 'Members can access fees, invoices, statements, and payments' },
    ],
  },
  {
    id: 'kiosk',
    title: 'Attendance kiosk',
    url: '/kiosk',
    callouts: [
      { x: 17, y: 18, text: 'Shared devices can capture attendance without full staff login' },
      { x: 54, y: 28, text: 'Employee number and name verification protect clocking' },
    ],
  },
  {
    id: 'whistleblower',
    title: 'Whistleblower channel',
    url: '/whistleblower',
    callouts: [
      { x: 18, y: 16, text: 'Public reporting channel supports confidential governance cases' },
      { x: 55, y: 27, text: 'Tracking lets reporters follow up safely' },
    ],
  },
  {
    id: 'dpa',
    title: 'Data protection request portal',
    url: '/dpa',
    callouts: [
      { x: 17, y: 16, text: 'External data subjects can submit privacy requests' },
      { x: 54, y: 30, text: 'Verification and tracking support accountable handling' },
    ],
  },
  {
    id: 'api-docs',
    title: 'Partner API documentation',
    url: '/api/docs',
    callouts: [
      { x: 18, y: 18, text: 'Partners can discover approved integration options' },
      { x: 54, y: 24, text: 'API documentation supports external system onboarding' },
    ],
  },
  {
    id: 'complaint-tracking',
    title: 'Public complaint tracking',
    url: '/complaints/track',
    callouts: [
      { x: 18, y: 18, text: 'Public users can check complaint progress by reference' },
      { x: 54, y: 30, text: 'Tracking reduces manual follow-up calls and emails' },
    ],
  },
  {
    id: 'dashboard',
    title: 'Main dashboard',
    url: '/dashboard',
    auth: true,
    callouts: [
      { x: 16, y: 18, text: 'Dashboard summarizes current HR, finance, and operational activity' },
      { x: 58, y: 20, text: 'Quick indicators help leaders spot work that needs attention' },
    ],
  },
  {
    id: 'employees',
    title: 'Employee records',
    url: '/employees',
    auth: true,
    callouts: [
      { x: 16, y: 20, text: 'HR can search and manage employee records from one register' },
      { x: 58, y: 28, text: 'Employee information feeds leave, payroll, attendance, and reporting' },
    ],
  },
  {
    id: 'departments',
    title: 'Departments and structure',
    url: '/departments',
    auth: true,
    callouts: [
      { x: 16, y: 20, text: 'Departments group employees into the organization structure' },
      { x: 58, y: 28, text: 'Department views support managers, reporting, and access control' },
    ],
  },
  {
    id: 'leave',
    title: 'Leave management',
    url: '/leave-requests',
    auth: true,
    callouts: [
      { x: 16, y: 20, text: 'Employees and HR can track leave requests and balances' },
      { x: 58, y: 28, text: 'Approval status is visible and auditable' },
    ],
  },
  {
    id: 'attendance-admin',
    title: 'Attendance management',
    url: '/attendance',
    auth: true,
    callouts: [
      { x: 16, y: 20, text: 'Attendance records support punctuality, absence monitoring, and payroll' },
      { x: 58, y: 28, text: 'Managers can review attendance activity and corrections' },
    ],
  },
  {
    id: 'tickets',
    title: 'Service desk tickets',
    url: '/tickets',
    auth: true,
    callouts: [
      { x: 16, y: 20, text: 'Staff requests become trackable tickets instead of informal messages' },
      { x: 58, y: 28, text: 'Teams can assign, update, and resolve work with accountability' },
    ],
  },
  {
    id: 'recruitment',
    title: 'Recruitment pipeline',
    url: '/jobs',
    auth: true,
    callouts: [
      { x: 16, y: 20, text: 'HR manages vacancies and applicant progress in one place' },
      { x: 58, y: 28, text: 'Applicant records can support offers and onboarding' },
    ],
  },
  {
    id: 'payroll',
    title: 'Payroll runs',
    url: '/payroll/runs',
    auth: true,
    callouts: [
      { x: 16, y: 20, text: 'Payroll work is organized into reviewable processing runs' },
      { x: 58, y: 28, text: 'Payroll outputs connect to statutory and finance records' },
    ],
  },
  {
    id: 'finance-hub',
    title: 'Finance hub',
    url: '/finance',
    auth: true,
    callouts: [
      { x: 16, y: 20, text: 'Finance users enter payables, receivables, banking, and reports from one hub' },
      { x: 58, y: 28, text: 'Sensitive money workflows are grouped with finance controls' },
    ],
  },
  {
    id: 'finance-accounts',
    title: 'Chart of accounts',
    url: '/finance/accounts',
    auth: true,
    callouts: [
      { x: 16, y: 20, text: 'The chart of accounts defines how transactions are classified' },
      { x: 58, y: 28, text: 'Consistent accounts improve reporting and audit evidence' },
    ],
  },
  {
    id: 'finance-payables',
    title: 'Supplier invoices and payables',
    url: '/finance/ap-invoices',
    auth: true,
    callouts: [
      { x: 16, y: 20, text: 'Supplier invoices can be reviewed and controlled before payment' },
      { x: 58, y: 28, text: 'Payables activity connects to journals and approvals' },
    ],
  },
  {
    id: 'finance-receivables',
    title: 'Customer invoices and receivables',
    url: '/finance/ar-invoices',
    auth: true,
    callouts: [
      { x: 16, y: 20, text: 'Receivables track invoices issued to customers or members' },
      { x: 58, y: 28, text: 'Receipts, balances, and statements become easier to review' },
    ],
  },
  {
    id: 'finance-reconciliation',
    title: 'Bank reconciliation',
    url: '/finance/reconciliation',
    auth: true,
    callouts: [
      { x: 16, y: 20, text: 'Imported bank statements can be matched against system records' },
      { x: 58, y: 28, text: 'Reconciliation reports support finance review and audit' },
    ],
  },
  {
    id: 'documents',
    title: 'Document management',
    url: '/documents',
    auth: true,
    callouts: [
      { x: 16, y: 20, text: 'Documents are drafted, stored, routed, and controlled inside the platform' },
      { x: 58, y: 28, text: 'Templates, watermarks, and signed links protect official files' },
    ],
  },
  {
    id: 'learning',
    title: 'Learning and development',
    url: '/learning',
    auth: true,
    callouts: [
      { x: 16, y: 20, text: 'Training courses and learning records support staff development' },
      { x: 58, y: 28, text: 'Skills and certifications help managers plan capability growth' },
    ],
  },
  {
    id: 'performance',
    title: 'Performance management',
    url: '/performance',
    auth: true,
    callouts: [
      { x: 16, y: 20, text: 'Goals, reviews, calibration, and improvement plans support performance cycles' },
      { x: 58, y: 28, text: 'Performance records create a structured evidence trail' },
    ],
  },
  {
    id: 'governance',
    title: 'Governance and compliance',
    url: '/governance',
    auth: true,
    callouts: [
      { x: 16, y: 20, text: 'Governance records organize policies, certifications, and compliance activity' },
      { x: 58, y: 28, text: 'Controls support accountability and institutional oversight' },
    ],
  },
  {
    id: 'assets',
    title: 'Asset management',
    url: '/assets',
    auth: true,
    callouts: [
      { x: 16, y: 20, text: 'Assets can be registered, assigned, maintained, and tracked' },
      { x: 58, y: 28, text: 'Asset history supports responsibility and recovery during offboarding' },
    ],
  },
  {
    id: 'benefits',
    title: 'Benefits administration',
    url: '/benefits',
    auth: true,
    callouts: [
      { x: 16, y: 20, text: 'Benefit plans, enrolments, dependants, and claims are managed centrally' },
      { x: 58, y: 28, text: 'Benefit records support staff welfare and policy compliance' },
    ],
  },
  {
    id: 'admin-users',
    title: 'User and permission administration',
    url: '/admin/users',
    auth: true,
    callouts: [
      { x: 16, y: 20, text: 'Administrators manage user accounts, roles, and staff identifiers' },
      { x: 58, y: 28, text: 'Permissions control what each person can view or change' },
    ],
  },
  {
    id: 'integrations',
    title: 'System integrations',
    url: '/admin/integrations',
    auth: true,
    callouts: [
      { x: 16, y: 20, text: 'Integration settings connect CIHRMS to approved external services' },
      { x: 58, y: 28, text: 'Integration logs help monitor data exchange and provider activity' },
    ],
  },
];

function runChrome(args) {
  execFileSync(chromePath, [
    '--headless=new',
    '--disable-gpu',
    '--no-sandbox',
    '--disable-dev-shm-usage',
    '--no-proxy-server',
    '--proxy-bypass-list=*',
    '--hide-scrollbars',
    '--no-first-run',
    '--no-default-browser-check',
    `--user-data-dir=${chromeProfileDir}`,
    ...args,
  ], { cwd: root, stdio: 'ignore' });
}

function captureUrl(url, outputFile) {
  runChrome([
    '--window-size=1440,1000',
    '--virtual-time-budget=8000',
    `--screenshot=${outputFile}`,
    url,
  ]);
}

function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

async function waitForChromeDebug() {
  const endpoint = `http://127.0.0.1:${chromeDebugPort}/json/version`;
  for (let attempt = 0; attempt < 60; attempt += 1) {
    try {
      const response = await fetch(endpoint);
      if (response.ok) return;
    } catch {
      // Chrome is still starting.
    }
    await sleep(250);
  }
  throw new Error('Timed out waiting for Chrome debugging endpoint.');
}

async function openDebugPage(url) {
  const endpoint = `http://127.0.0.1:${chromeDebugPort}/json/new?${encodeURIComponent(url)}`;
  let response = await fetch(endpoint, { method: 'PUT' });
  if (!response.ok) {
    response = await fetch(endpoint);
  }
  if (!response.ok) {
    throw new Error(`Unable to open Chrome debug page: ${response.status}`);
  }
  return response.json();
}

function connectDebugSocket(webSocketDebuggerUrl) {
  const socket = new WebSocket(webSocketDebuggerUrl);
  let id = 0;
  const pending = new Map();

  socket.addEventListener('message', (event) => {
    const payload = JSON.parse(event.data);
    if (payload.id && pending.has(payload.id)) {
      const { resolve, reject } = pending.get(payload.id);
      pending.delete(payload.id);
      if (payload.error) reject(new Error(payload.error.message));
      else resolve(payload.result);
    }
  });

  return new Promise((resolve, reject) => {
    socket.addEventListener('open', () => {
      resolve({
        send(method, params = {}) {
          id += 1;
          socket.send(JSON.stringify({ id, method, params }));
          return new Promise((commandResolve, commandReject) => {
            pending.set(id, { resolve: commandResolve, reject: commandReject });
          });
        },
        close() {
          socket.close();
        },
      });
    });
    socket.addEventListener('error', reject);
  });
}

async function evaluateInPage(client, expression) {
  const result = await client.send('Runtime.evaluate', {
    expression,
    awaitPromise: true,
    returnByValue: true,
  });
  if (result.exceptionDetails) {
    throw new Error(result.exceptionDetails.exception?.description || result.exceptionDetails.text || 'Page evaluation failed');
  }
  return result.result?.value;
}

async function startAuthenticatedBrowser() {
  const chrome = spawn(chromePath, [
    '--headless=new',
    '--disable-gpu',
    '--no-sandbox',
    '--disable-dev-shm-usage',
    '--no-proxy-server',
    '--proxy-bypass-list=*',
    '--no-first-run',
    '--no-default-browser-check',
    `--remote-debugging-port=${chromeDebugPort}`,
    `--user-data-dir=${chromeAuthProfileDir}`,
    `${baseUrl}/login`,
  ], { cwd: root, stdio: 'ignore' });

  try {
    await waitForChromeDebug();
    const page = await openDebugPage(`${baseUrl}/login`);
    const client = await connectDebugSocket(page.webSocketDebuggerUrl);
    await client.send('Page.enable');
    await client.send('Runtime.enable');
    await client.send('Emulation.setDeviceMetricsOverride', {
      width: 1440,
      height: 1000,
      deviceScaleFactor: 1,
      mobile: false,
    });
    for (let attempt = 0; attempt < 80; attempt += 1) {
      await sleep(250);
      const href = await evaluateInPage(client, 'location.href');
      const bodyText = await evaluateInPage(client, 'document.body ? document.body.innerText : ""');
      const hasNameInput = await evaluateInPage(client, 'Boolean(document.querySelector("#name"))');
      if (hasNameInput) break;
      if (String(href).includes('/dashboard') && !String(bodyText).includes('Welcome back.')) {
        return { chrome, client };
      }
      if (attempt === 79) {
        throw new Error(`Login form did not become ready. Last URL: ${href}. Body: ${String(bodyText).slice(0, 500)}`);
      }
    }
    await evaluateInPage(client, `
      (() => {
        const setValue = (selector, value) => {
          const input = document.querySelector(selector);
          if (!input) throw new Error('Missing input: ' + selector);
          input.value = value;
          input.dispatchEvent(new Event('input', { bubbles: true }));
          input.dispatchEvent(new Event('change', { bubbles: true }));
        };
        setValue('#name', '${demoAdminName}');
        setValue('#staff_id', '${demoAdminStaffId}');
        setValue('#password', 'password');
        const remember = document.querySelector('input[type="checkbox"]');
        if (remember) {
          remember.checked = true;
          remember.dispatchEvent(new Event('change', { bubbles: true }));
        }
        document.querySelector('form').requestSubmit();
        return true;
      })()
    `);

    for (let attempt = 0; attempt < 80; attempt += 1) {
      await sleep(250);
      const href = await evaluateInPage(client, 'location.href');
      const readyState = await evaluateInPage(client, 'document.readyState');
      const bodyText = await evaluateInPage(client, 'document.body ? document.body.innerText : ""');
      const text = String(bodyText);
      if (
        String(href).includes('/dashboard')
        && readyState === 'complete'
        && text.trim().length > 100
        && !text.includes('Welcome back.')
        && !text.includes('SIGN IN')
      ) {
        return { chrome, client };
      }
    }
    const href = await evaluateInPage(client, 'location.href');
    const bodyText = await evaluateInPage(client, 'document.body ? document.body.innerText.slice(0, 500) : ""');
    throw new Error(`Demo login did not reach dashboard. Last URL: ${href}. Body: ${bodyText}`);
  } catch (error) {
    chrome.kill('SIGTERM');
    await sleep(750);
    throw error;
  }
}

async function stopAuthenticatedBrowser(authBrowser) {
  if (!authBrowser) return;
  authBrowser.client.close();
  authBrowser.chrome.kill('SIGTERM');
  await sleep(750);
}

async function captureAuthenticatedUrl(authBrowser, url, outputFile) {
  await authBrowser.client.send('Page.navigate', { url });
  for (let attempt = 0; attempt < 80; attempt += 1) {
    await sleep(250);
    const readyState = await evaluateInPage(authBrowser.client, 'document.readyState');
    if (readyState === 'complete') break;
  }
  await sleep(3500);
  const href = await evaluateInPage(authBrowser.client, 'location.href');
  const bodyText = await evaluateInPage(authBrowser.client, 'document.body ? document.body.innerText : ""');
  if (String(href).includes('/login') || String(bodyText).includes('Welcome back.') || String(bodyText).includes('SIGN IN')) {
    throw new Error(`Authenticated capture was redirected to login: ${url}. Last URL: ${href}`);
  }
  const screenshot = await authBrowser.client.send('Page.captureScreenshot', {
    format: 'png',
    captureBeyondViewport: false,
  });
  fs.writeFileSync(outputFile, Buffer.from(screenshot.data, 'base64'));
}

function annotatedHtml(screen, rawFile) {
  const rawHref = pathToFileURL(rawFile).href;
  const callouts = screen.callouts.map((callout, index) => `
    <div class="callout" style="left:${callout.x}%; top:${callout.y}%;">
      <span class="num">${index + 1}</span>
      <span>${escapeHtml(callout.text)}</span>
    </div>
  `).join('\n');

  return `<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <style>
    html, body { margin: 0; background: #f6f8fb; font-family: Arial, sans-serif; }
    .frame { position: relative; width: 1440px; height: 1000px; background: white; overflow: hidden; }
    img { width: 1440px; min-height: 1000px; object-fit: cover; display: block; }
    .callout {
      position: absolute;
      max-width: 330px;
      display: flex;
      gap: 10px;
      align-items: flex-start;
      padding: 12px 14px;
      color: #102033;
      background: rgba(255, 255, 255, 0.97);
      border: 2px solid #2563eb;
      border-radius: 10px;
      box-shadow: 0 18px 45px rgba(15, 23, 42, 0.26);
      font-size: 20px;
      line-height: 1.25;
    }
    .callout::after {
      content: "";
      position: absolute;
      left: 18px;
      bottom: -24px;
      width: 2px;
      height: 24px;
      background: #2563eb;
    }
    .num {
      display: inline-flex;
      width: 28px;
      height: 28px;
      flex: 0 0 28px;
      align-items: center;
      justify-content: center;
      border-radius: 999px;
      color: white;
      background: #2563eb;
      font-weight: 700;
      font-size: 16px;
    }
  </style>
</head>
<body>
  <div class="frame">
    <img src="${rawHref}" alt="${escapeHtml(screen.title)}">
    ${callouts}
  </div>
</body>
</html>`;
}

function escapeHtml(value) {
  return String(value)
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;');
}

function screenshotMarkdown({ absolutePaths = true } = {}) {
  const rows = screens.map((screen) => {
    const rel = absolutePaths
      ? pathToFileURL(path.join(annotatedDir, `${screen.id}.png`)).href
      : `assets/stakeholder-guide/annotated/${screen.id}.png`;
    const bullets = screen.callouts.map((callout, index) => `${index + 1}. ${callout.text}`).join('\n');
    return `### ${screen.title}\n\n![${screen.title}](${rel})\n\n${bullets}\n`;
  }).join('\n');

  return `## Visual walkthrough with screenshot indications\n\nThe screenshots below highlight the main areas stakeholders will see during demonstrations and training. Each numbered label points to the business purpose of that part of the screen.\n\n${rows}`;
}

function buildMarkdownWithScreenshots() {
  const original = fs.readFileSync(guideMd, 'utf8');
  const withSafeImagePaths = original.replace(
    /\]\(assets\/stakeholder-guide\/annotated\/([^)]+)\)/g,
    (_, filename) => `](${pathToFileURL(path.join(annotatedDir, filename)).href})`
  );

  if (withSafeImagePaths.includes('## Visual walkthrough with screenshot indications')) {
    return withSafeImagePaths.replace(
      /## Visual walkthrough with screenshot indications[\s\S]*?(?=## Application walkthrough)/,
      `${screenshotMarkdown({ absolutePaths: true })}\n\n`
    );
  }
  return withSafeImagePaths.replace('## Application walkthrough', `${screenshotMarkdown({ absolutePaths: true })}\n\n## Application walkthrough`);
}

function updateSourceGuideVisualSection() {
  const original = fs.readFileSync(guideMd, 'utf8');
  const replacement = `${screenshotMarkdown({ absolutePaths: false })}\n\n`;
  const updated = original.includes('## Visual walkthrough with screenshot indications')
    ? original.replace(
        /## Visual walkthrough with screenshot indications[\s\S]*?(?=## Application walkthrough)/,
        replacement
      )
    : original.replace('## Application walkthrough', `${replacement}## Application walkthrough`);

  if (updated !== original) {
    fs.writeFileSync(guideMd, updated, 'utf8');
  }
}

function markdownToHtml(markdown) {
  const lines = markdown.split(/\r?\n/);
  const html = [];
  let paragraph = [];
  let listType = null;

  const flushParagraph = () => {
    if (paragraph.length === 0) return;
    html.push(`<p>${inlineMarkdown(paragraph.join(' '))}</p>`);
    paragraph = [];
  };

  const closeList = () => {
    if (!listType) return;
    html.push(`</${listType}>`);
    listType = null;
  };

  for (const line of lines) {
    const trimmed = line.trim();

    if (!trimmed) {
      flushParagraph();
      closeList();
      continue;
    }

    const image = trimmed.match(/^!\[([^\]]*)\]\(([^)]+)\)$/);
    if (image) {
      flushParagraph();
      closeList();
      html.push(`<figure><img src="${escapeHtml(image[2])}" alt="${escapeHtml(image[1])}"><figcaption>${escapeHtml(image[1])}</figcaption></figure>`);
      continue;
    }

    const heading = trimmed.match(/^(#{1,6})\s+(.+)$/);
    if (heading) {
      flushParagraph();
      closeList();
      const level = heading[1].length;
      html.push(`<h${level}>${inlineMarkdown(heading[2])}</h${level}>`);
      continue;
    }

    const bullet = trimmed.match(/^-\s+(.+)$/);
    if (bullet) {
      flushParagraph();
      if (listType !== 'ul') {
        closeList();
        html.push('<ul>');
        listType = 'ul';
      }
      html.push(`<li>${inlineMarkdown(bullet[1])}</li>`);
      continue;
    }

    const numbered = trimmed.match(/^\d+\.\s+(.+)$/);
    if (numbered) {
      flushParagraph();
      if (listType !== 'ol') {
        closeList();
        html.push('<ol>');
        listType = 'ol';
      }
      html.push(`<li>${inlineMarkdown(numbered[1])}</li>`);
      continue;
    }

    paragraph.push(trimmed);
  }

  flushParagraph();
  closeList();
  return html.join('\n');
}

function inlineMarkdown(value) {
  return escapeHtml(value)
    .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
    .replace(/`([^`]+)`/g, '<code>$1</code>');
}

function pageHtml(markdown) {
  return `<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>CIHRMS Non-Technical Application Guide</title>
  <style>
    @page { size: A4; margin: 14mm 13mm; }
    body { color: #172033; font-family: Arial, Helvetica, sans-serif; font-size: 12.5px; line-height: 1.55; }
    h1 { color: #0f172a; font-size: 30px; line-height: 1.1; margin: 0 0 18px; }
    h2 { color: #102033; font-size: 21px; margin: 26px 0 10px; padding-top: 4px; border-top: 1px solid #d8e0ea; }
    h3 { color: #1d4ed8; font-size: 16px; margin: 18px 0 8px; }
    p { margin: 0 0 10px; }
    ul, ol { margin: 0 0 12px 22px; padding: 0; }
    li { margin: 3px 0; }
    figure { margin: 13px 0 18px; page-break-inside: avoid; }
    img { display: block; width: 100%; max-height: 640px; object-fit: contain; border: 1px solid #d8e0ea; border-radius: 10px; box-shadow: 0 8px 24px rgba(15, 23, 42, 0.12); }
    figcaption { color: #64748b; font-size: 11px; margin-top: 6px; text-align: center; }
    code { background: #eef2ff; border-radius: 4px; padding: 1px 4px; }
  </style>
</head>
<body>
${markdownToHtml(markdown)}
</body>
</html>`;
}

if (!fs.existsSync(chromePath)) {
  throw new Error(`Chrome executable not found at ${chromePath}`);
}

if (screens.some((screen) => screen.auth)) {
  if (!allowDemoAuthMutation) {
    throw new Error('Authenticated screenshots require CIHRMS_ALLOW_DEMO_AUTH_MUTATION=1 because the generator temporarily creates a docs-only admin user and removes it after capture.');
  }
  prepareDemoAuthState();
}

let authBrowser = null;
try {
  if (screens.some((screen) => screen.auth)) {
    authBrowser = await startAuthenticatedBrowser();
  }

  for (const screen of screens) {
    const rawFile = path.join(rawDir, `${screen.id}.png`);
    const annotationPage = path.join(buildDir, `${screen.id}.html`);
    const annotatedFile = path.join(annotatedDir, `${screen.id}.png`);
    console.log(`Capturing ${screen.id}${screen.auth ? ' (authenticated)' : ''}...`);
    if (screen.auth) {
      await captureAuthenticatedUrl(authBrowser, `${baseUrl}${screen.url}`, rawFile);
    } else {
      captureUrl(`${baseUrl}${screen.url}`, rawFile);
    }
    fs.writeFileSync(annotationPage, annotatedHtml(screen, rawFile), 'utf8');
    captureUrl(pathToFileURL(annotationPage).href, annotatedFile);
  }
} finally {
  await stopAuthenticatedBrowser(authBrowser);
}

updateSourceGuideVisualSection();
const markdown = buildMarkdownWithScreenshots();
fs.writeFileSync(tempMdPath, markdown, 'utf8');
fs.writeFileSync(htmlPath, pageHtml(markdown), 'utf8');

runChrome([
  '--print-to-pdf-no-header',
  `--print-to-pdf=${pdfPath}`,
  pathToFileURL(htmlPath).href,
]);

restoreViteHotFile();
restoreDemoAuthState();
