<?php
function parse_database_url($url) {
  $p = parse_url($url);
  if (!$p || !isset($p['scheme']) || !isset($p['host'])) return null;
  $query = [];
  if (isset($p['query'])) parse_str($p['query'], $query);
  return [
    'scheme' => $p['scheme'] ?? 'postgres',
    'host'   => $p['host'] ?? 'localhost',
    'port'   => $p['port'] ?? 5432,
    'user'   => $p['user'] ?? '',
    'pass'   => $p['pass'] ?? '',
    'dbname' => isset($p['path']) ? ltrim($p['path'], '/') : '',
    'params' => $query
  ];
}
$database_url = getenv('DATABASE_URL');
$db_info = parse_database_url($database_url);
$db_ok = false; $db_error = ''; $db_time = null; $tables = []; $rows = [];
$selected = isset($_GET['table']) ? $_GET['table'] : '';
function valid_table($name) { return preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name); }
if ($db_info) {
  $sslmode = isset($db_info['params']['sslmode']) ? $db_info['params']['sslmode'] : 'require';
  $dsn = sprintf("pgsql:host=%s;port=%d;dbname=%s;sslmode=%s",$db_info['host'],$db_info['port'],$db_info['dbname'],$sslmode);
  try {
    $pdo = new PDO($dsn, $db_info['user'], $db_info['pass'], [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    $r = $pdo->query("SELECT 1 AS ok")->fetch(); $db_ok = isset($r['ok']) && intval($r['ok']) === 1;
    $t = $pdo->query("SELECT now() AS ts")->fetch(); $db_time = $t['ts'] ?? null;
    $stmt = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema='public' AND table_type='BASE TABLE' ORDER BY table_name");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if ($selected && valid_table($selected)) {
      $stmt = $pdo->query('SELECT * FROM "' . $selected . '" ORDER BY 1 DESC LIMIT 50');
      $rows = $stmt->fetchAll();
    }
  } catch (Exception $e) { $db_ok = false; $db_error = $e->getMessage(); }
} else { $db_error = "DATABASE_URL no está definida en el entorno."; }
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?><!doctype html>
<html lang="es"><head><meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Taquería — PHP + Neon</title>
<style>
:root{--ok:#0a0;--bad:#a00;--muted:#666;}
body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;max-width:980px;margin:32px auto;padding:0 16px;}
h1{margin:0 0 8px 0}.row{display:flex;gap:16px;flex-wrap:wrap}.card{border:1px solid #ddd;border-radius:8px;padding:12px 16px}
.badge{display:inline-block;padding:4px 8px;border-radius:999px;font-size:12px;font-weight:600}
.ok{background:#e8ffe8;border:1px solid #b7e6b7;color:#064}.bad{background:#ffe8e8;border:1px solid #e6b7b7;color:#600}
.muted{color:var(--muted);font-size:12px}select,button,input{padding:8px;border:1px solid #bbb;border-radius:6px}
table{border-collapse:collapse;width:100%;margin-top:10px}th,td{border:1px solid #e5e5e5;padding:6px 8px;text-align:left;font-size:14px;vertical-align:top}
th{background:#fafafa}#controls{display:flex;gap:8px;align-items:center;margin:10px 0}
.code{font-family:ui-monospace,Consolas,monospace;background:#f5f5f5;padding:2px 4px;border-radius:4px}
</style></head><body>
<h1>Taquería — PHP + PostgreSQL (Neon) en Render</h1>
<p class="muted">Conectividad, tablas detectadas y registros reales (primeros 50).</p>
<div class="row">
  <div class="card"><div><strong>Servidor PHP</strong> <span class="badge ok">OK</span></div><div class="muted">index.php cargado.</div></div>
  <div class="card"><div><strong>Base de datos</strong>
  <?php if ($db_ok): ?><span class="badge ok">OK</span><?php else: ?><span class="badge bad">ERROR</span><?php endif; ?>
  </div><div class="muted">
  <?php if ($db_ok): ?>Conectado como <span class="code"><?php echo h($db_info['user']); ?></span> a <span class="code"><?php echo h($db_info['host']); ?></span> — Hora BD: <span class="code"><?php echo h($db_time); ?></span>
  <?php else: ?><?php echo h($db_error); ?><?php endif; ?>
  </div></div>
</div>
<h3>Tablas en esquema <code>public</code></h3>
<form method="get" id="controls"><label>Tabla:</label>
<select name="table" id="tables">
<?php if (!empty($tables)): foreach ($tables as $t): ?>
  <option value="<?php echo h($t); ?>" <?php echo ($t===$selected)?'selected':''; ?>><?php echo h($t); ?></option>
<?php endforeach; else: ?><option>(sin tablas)</option><?php endif; ?>
</select>
<button type="submit">Mostrar registros</button></form>
<?php if ($selected): ?>
<h3>Registros: <code><?php echo h($selected); ?></code> (máx. 50)</h3>
<?php if (!empty($rows)): ?>
<table><thead><tr>
<?php foreach (array_keys($rows[0]) as $c): ?><th><?php echo h($c); ?></th><?php endforeach; ?>
</tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr><?php foreach ($r as $v): ?><td><?php echo h($v); ?></td><?php endforeach; ?></tr><?php endforeach; ?>
</tbody></table>
<?php else: ?><p class="muted">Sin registros (o tabla vacía).</p><?php endif; ?>
<?php endif; ?>
<hr/><p class="muted">Conexión de <span class="code">DATABASE_URL</span> (formato <span class="code">postgres://user:pass@host:port/db?sslmode=require</span>).</p>
</body></html>
