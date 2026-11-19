<?php
// Admin stock manager for Mateo Gaffing Empire.
require_once __DIR__ . '/includes/admin-config.php';
require_once __DIR__ . '/includes/stock.php';

session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$loginError = '';

// ---- Logout ----
if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    header('Location: admin.php');
    exit;
}

// ---- Login ----
if (($_POST['action'] ?? '') === 'login') {
    if (password_verify((string) ($_POST['password'] ?? ''), MGE_ADMIN_PASSWORD_HASH)) {
        session_regenerate_id(true);
        $_SESSION['admin'] = true;
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
        header('Location: admin.php');
        exit;
    }
    sleep(1); // slow down password guessing
    $loginError = 'Incorrect password.';
}

$isAdmin = !empty($_SESSION['admin']);

// ---- Save stock (AJAX) ----
if ($isAdmin && ($_POST['action'] ?? '') === 'save') {
    header('Content-Type: application/json');
    if (!hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Session expired. Reload the page.']);
        exit;
    }
    $incoming = json_decode((string) ($_POST['stock'] ?? ''), true);
    if (!is_array($incoming)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid data.']);
        exit;
    }
    $clean = [];
    foreach ($incoming as $key => $qty) {
        if (!is_string($key) || $key === '' || strlen($key) > 300 || !is_numeric($qty)) {
            continue;
        }
        $clean[$key] = max(0, min(99999, (int) $qty));
    }
    $ok = mge_stock_update(fn(array $current) => array_merge($current, $clean));
    echo json_encode(['ok' => $ok, 'saved' => count($clean)]);
    exit;
}

// ---- Pull the product list straight from index.php so there is one source of truth ----
$catalogJs = '{}';
if ($isAdmin) {
    $source = (string) file_get_contents(__DIR__ . '/index.php');
    if (preg_match('/const defaultProductCatalog = (\{.*?\n    \});\s*\n\s*let productCatalog/s', $source, $m)) {
        $catalogJs = $m[1];
    }
}
$serverStock = mge_stock_load();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Stock Manager | Mateo Gaffing Empire</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --bg:#000; --card:#121212; --orange:#ff7b00; --accent:#ff9d33; --muted:#888; --line:#2b2b2b; }
        * { margin:0; padding:0; box-sizing:border-box; font-family:'Segoe UI',Tahoma,Verdana,sans-serif; }
        body { background:var(--bg); color:#fff; line-height:1.5; }
        .bar { position:sticky; top:0; z-index:10; display:flex; flex-wrap:wrap; align-items:center; gap:12px; padding:12px 24px; background:var(--card); border-bottom:2px solid var(--line); }
        .bar h1 { font-size:16px; letter-spacing:.5px; margin-right:auto; }
        .bar h1 i { color:var(--orange); margin-right:8px; }
        a.link { color:var(--accent); font-size:12px; text-decoration:none; }
        .btn { border:0; border-radius:8px; padding:9px 16px; font-weight:700; font-size:12px; cursor:pointer; background:var(--orange); color:#fff; }
        .btn:hover:not(:disabled) { filter:brightness(1.12); }
        .btn:disabled { background:#2a2a2a; color:#777; cursor:not-allowed; }
        .btn.ghost { background:transparent; border:1px solid var(--orange); color:var(--accent); }
        .login { max-width:340px; margin:12vh auto; padding:28px; background:var(--card); border:1px solid var(--line); border-radius:14px; text-align:center; }
        .login h1 { font-size:18px; margin-bottom:6px; }
        .login p { color:var(--muted); font-size:12px; margin-bottom:16px; }
        .login input { width:100%; padding:11px 12px; margin-bottom:12px; border:1px solid #444; border-radius:6px; background:#1b1b1b; color:#fff; font-size:14px; }
        .login .btn { width:100%; padding:11px; }
        .err { color:#ff8a65; font-size:12px; margin-bottom:10px; }
        main { max-width:1100px; margin:0 auto; padding:22px 24px 90px; }
        .tools { display:flex; flex-wrap:wrap; gap:10px; margin-bottom:18px; align-items:center; }
        .tools input[type=search], .tools input[type=number] { padding:9px 12px; border:1px solid #444; border-radius:6px; background:#1b1b1b; color:#fff; font-size:13px; }
        .tools input[type=search] { flex:1 1 220px; }
        .tools input[type=number] { width:80px; }
        .chips { display:flex; gap:6px; }
        .chip { padding:7px 12px; border:1px solid #444; border-radius:999px; background:transparent; color:#ccc; font-size:12px; cursor:pointer; }
        .chip.active { border-color:var(--orange); color:var(--accent); background:rgba(255,123,0,.1); }
        .summary { color:var(--muted); font-size:12px; margin-bottom:14px; }
        .product { background:var(--card); border:1px solid var(--line); border-radius:12px; margin-bottom:14px; overflow:hidden; }
        .product h2 { padding:12px 16px; font-size:14px; background:#181818; border-bottom:1px solid var(--line); }
        .row { display:grid; grid-template-columns:48px minmax(0,1fr) 120px 190px; align-items:center; gap:12px; padding:10px 16px; border-bottom:1px solid #1f1f1f; }
        .row:last-child { border-bottom:0; }
        .row img { width:44px; height:44px; border-radius:6px; background:#222; object-fit:cover; }
        .row .name { font-size:13px; overflow-wrap:anywhere; }
        .row .price { color:var(--accent); font-size:12px; }
        .badge { display:inline-block; margin-top:2px; padding:1px 8px; border-radius:999px; font-size:10px; font-weight:700; }
        .badge.out { background:rgba(255,107,74,.15); color:#ff8a65; }
        .badge.low { background:rgba(255,176,46,.15); color:#ffb02e; }
        .badge.ok { background:rgba(143,206,143,.12); color:#8fce8f; }
        .qty { display:flex; align-items:center; border:1px solid #444; border-radius:6px; overflow:hidden; width:fit-content; justify-self:end; }
        .qty button { width:34px; height:34px; border:0; background:#202020; color:#fff; font-size:18px; cursor:pointer; }
        .qty input { width:70px; height:34px; border:0; border-left:1px solid #444; border-right:1px solid #444; background:#111; color:#fff; text-align:center; font-size:13px; }
        .row.dirty { background:rgba(255,123,0,.07); }
        .savebar { position:fixed; left:0; right:0; bottom:0; display:flex; justify-content:center; align-items:center; gap:14px; padding:12px; background:#161616; border-top:2px solid var(--orange); transform:translateY(100%); transition:transform .2s; }
        .savebar.show { transform:none; }
        #msg { position:fixed; right:18px; top:70px; padding:10px 14px; border-radius:8px; background:#161616; border:1px solid var(--orange); font-size:12px; opacity:0; transition:opacity .2s; }
        #msg.show { opacity:1; }
        @media (max-width:700px) {
            .row { grid-template-columns:44px minmax(0,1fr); }
            .row .price-col, .qty { grid-column:2; justify-self:start; }
            .bar { padding:10px 14px; } main { padding:16px 12px 90px; }
        }
    </style>
</head>
<body>
<?php if (!$isAdmin): ?>
    <form class="login" method="post" autocomplete="off">
        <h1><i class="fa-solid fa-lock" style="color:var(--orange)"></i> Admin Login</h1>
        <p>Mateo Gaffing Empire stock manager</p>
        <?php if ($loginError): ?><div class="err"><?= $e($loginError) ?></div><?php endif; ?>
        <input type="hidden" name="action" value="login">
        <input type="password" name="password" placeholder="Admin password" required autofocus>
        <button class="btn" type="submit">Log in</button>
    </form>
<?php else: ?>
    <div class="bar">
        <h1><i class="fa-solid fa-boxes-stacked"></i>STOCK MANAGER</h1>
        <a class="link" href="index.php" target="_blank" rel="noopener">View store</a>
        <a class="link" href="admin.php?logout=1">Log out</a>
    </div>
    <main>
        <div class="tools">
            <input type="search" id="search" placeholder="Search product or option...">
            <div class="chips">
                <button class="chip active" data-filter="all" type="button">All</button>
                <button class="chip" data-filter="out" type="button">Out of stock</button>
                <button class="chip" data-filter="low" type="button">Low (1–5)</button>
            </div>
            <input type="number" id="bulk-value" min="0" max="99999" value="10" aria-label="Quantity for bulk set">
            <button class="btn ghost" id="bulk-btn" type="button">Set shown items to this quantity</button>
        </div>
        <p class="summary" id="summary"></p>
        <div id="list"></div>
    </main>
    <div class="savebar" id="savebar">
        <span id="dirty-count">0 changes</span>
        <button class="btn ghost" id="discard" type="button">Discard</button>
        <button class="btn" id="save" type="button"><i class="fa-solid fa-floppy-disk"></i> Save stock</button>
    </div>
    <div id="msg" role="status"></div>
    <script>
        const catalog = <?= $catalogJs ?>;
        const savedStock = <?= json_encode((object) $serverStock, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
        const csrf = <?= json_encode($_SESSION['csrf']) ?>;

        // Same key rule as the store: "Product||Option" (a repeated option name in one product gets " #2", " #3"...).
        const rows = [];
        Object.entries(catalog).forEach(([product, data]) => {
            const seen = {};
            (data.variations || []).forEach(v => {
                seen[v.name] = (seen[v.name] || 0) + 1;
                const key = product + '||' + v.name + (seen[v.name] > 1 ? ' #' + seen[v.name] : '');
                const original = Object.prototype.hasOwnProperty.call(savedStock, key) ? Number(savedStock[key]) : Number(v.stock) || 0;
                rows.push({ key, product, name: v.name, price: Number(v.price) || 0, image: (v.images || [data.image])[0], original, value: original, hasSaved: Object.prototype.hasOwnProperty.call(savedStock, key) });
            });
        });
        const byKey = Object.fromEntries(rows.map(r => [r.key, r]));
        let filter = 'all';
        const esc = s => String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
        const peso = n => '₱' + n.toLocaleString('en-PH', { minimumFractionDigits: 2 });
        const status = n => n <= 0 ? ['out', 'Out of stock'] : n <= 5 ? ['low', 'Low stock'] : ['ok', 'In stock'];

        function visible(r) {
            const q = document.getElementById('search').value.trim().toLowerCase();
            if (q && !(r.product + ' ' + r.name).toLowerCase().includes(q)) return false;
            if (filter === 'out') return r.value <= 0;
            if (filter === 'low') return r.value > 0 && r.value <= 5;
            return true;
        }

        function render() {
            const groups = {};
            rows.filter(visible).forEach(r => (groups[r.product] ||= []).push(r));
            const html = Object.entries(groups).map(([product, items]) => `
                <section class="product"><h2>${esc(product)}</h2>${items.map(r => {
                    const [cls, label] = status(r.value);
                    return `<div class="row ${r.value !== r.original ? 'dirty' : ''}" data-key="${esc(r.key)}">
                        <img src="${esc(r.image)}" alt="" onerror="this.style.visibility='hidden'">
                        <div><div class="name">${esc(r.name)}</div><span class="badge ${cls}">${label}</span></div>
                        <div class="price price-col">${r.price > 0 ? peso(r.price) : 'No price'}</div>
                        <div class="qty">
                            <button type="button" data-step="-1" aria-label="Decrease">−</button>
                            <input type="number" min="0" max="99999" value="${r.value}" aria-label="Stock for ${esc(r.name)}">
                            <button type="button" data-step="1" aria-label="Increase">+</button>
                        </div></div>`;
                }).join('')}</section>`).join('');
            document.getElementById('list').innerHTML = html || '<p class="summary">No matching products.</p>';
            const out = rows.filter(r => r.value <= 0).length;
            document.getElementById('summary').textContent = `${rows.length} options · ${out} out of stock`;
            updateSaveBar();
        }

        function updateSaveBar() {
            const n = rows.filter(r => r.value !== r.original).length;
            document.getElementById('dirty-count').textContent = n + (n === 1 ? ' change' : ' changes');
            document.getElementById('savebar').classList.toggle('show', n > 0);
        }

        function setValue(key, value) {
            const r = byKey[key];
            r.value = Math.max(0, Math.min(99999, Math.floor(Number(value) || 0)));
            const row = document.querySelector(`.row[data-key="${CSS.escape(key)}"]`);
            if (row) {
                row.querySelector('input').value = r.value;
                row.classList.toggle('dirty', r.value !== r.original);
                const [cls, label] = status(r.value);
                const badge = row.querySelector('.badge');
                badge.className = 'badge ' + cls; badge.textContent = label;
            }
            updateSaveBar();
        }

        document.getElementById('list').addEventListener('click', ev => {
            const btn = ev.target.closest('button[data-step]');
            if (!btn) return;
            const row = btn.closest('.row');
            setValue(row.dataset.key, byKey[row.dataset.key].value + Number(btn.dataset.step));
        });
        document.getElementById('list').addEventListener('change', ev => {
            if (ev.target.matches('.qty input')) setValue(ev.target.closest('.row').dataset.key, ev.target.value);
        });
        document.getElementById('search').addEventListener('input', render);
        document.querySelectorAll('.chip').forEach(chip => chip.addEventListener('click', () => {
            filter = chip.dataset.filter;
            document.querySelectorAll('.chip').forEach(c => c.classList.toggle('active', c === chip));
            render();
        }));
        document.getElementById('bulk-btn').addEventListener('click', () => {
            const value = document.getElementById('bulk-value').value;
            const targets = rows.filter(visible);
            if (!targets.length) return;
            if (!confirm(`Set ${targets.length} option(s) to ${Math.max(0, Math.floor(Number(value) || 0))}?`)) return;
            targets.forEach(r => r.value = Math.max(0, Math.min(99999, Math.floor(Number(value) || 0))));
            render();
        });
        document.getElementById('discard').addEventListener('click', () => { rows.forEach(r => r.value = r.original); render(); });

        function toast(text) {
            const msg = document.getElementById('msg');
            msg.textContent = text; msg.classList.add('show');
            setTimeout(() => msg.classList.remove('show'), 2500);
        }

        document.getElementById('save').addEventListener('click', async () => {
            const changed = {};
            rows.filter(r => r.value !== r.original).forEach(r => changed[r.key] = r.value);
            if (!Object.keys(changed).length) return;
            const saveBtn = document.getElementById('save');
            saveBtn.disabled = true;
            try {
                const body = new URLSearchParams({ action: 'save', csrf, stock: JSON.stringify(changed) });
                const res = await fetch('admin.php', { method: 'POST', body });
                const data = await res.json();
                if (!data.ok) throw new Error(data.error || 'Could not save.');
                rows.forEach(r => { if (r.key in changed) r.original = r.value; });
                toast('Stock saved.');
                render();
            } catch (err) {
                toast(err.message || 'Could not save. Check that the data folder is writable.');
            } finally {
                saveBtn.disabled = false;
            }
        });
        window.addEventListener('beforeunload', ev => { if (rows.some(r => r.value !== r.original)) { ev.preventDefault(); ev.returnValue = ''; } });
        render();
    </script>
<?php endif; ?>
</body>
</html>
