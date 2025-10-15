<?php
// parse_database_url: función que recibe una URL tipo DATABASE_URL
// y la divide en partes (host, user, pass, dbname, params).
// Esto nos permite leer la variable de entorno DATABASE_URL que pone Render/Neon.
function parse_database_url($url) {
  $p = parse_url($url);
  if (!$p || !isset($p['scheme']) || !isset($p['host'])) return null;
  $query = [];
  if (isset($p['query'])) parse_str($p['query'], $query);
  return [
    'scheme' => $p['scheme'] ?? 'postgres',            // esquema (postgres)
    'host'   => $p['host'] ?? 'localhost',            // host / servidor
    'port'   => $p['port'] ?? 5432,                   // puerto (por defecto 5432)
    'user'   => $p['user'] ?? '',                     // usuario de BD
    'pass'   => $p['pass'] ?? '',                     // contraseña
    'dbname' => isset($p['path']) ? ltrim($p['path'], '/') : '', // nombre BD
    'params' => $query                                // parámetros extra (sslmode, etc)
  ];
}

// Recuperamos la conexión completa desde la variable de entorno
$database_url = getenv('DATABASE_URL');
// La parseamos para obtener host, user, pass, dbname, params, etc.
$db_info = parse_database_url($database_url);

// Inicializamos variables que usaremos en la página
$db_ok = false;           // si la conexión funcionó
$db_error = '';           // mensaje de error si algo falla
$db_time = null;          // hora que devuelve la BD (para mostrar evidencia)
$tables = [];             // lista de tablas detectadas
$rows = [];               // filas de la tabla seleccionada
$selected = isset($_GET['table']) ? $_GET['table'] : ''; // tabla seleccionada por GET

// Función para validar que el nombre de la tabla no venga con caracteres raros
// Evita inyección vía nombre de tabla. Solo permitimos letras, números y guión bajo.
function valid_table($name) { return preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name); }

// Si parseamos correctamente DATABASE_URL, intentamos conectar con PDO
if ($db_info) {
  // Tomamos sslmode (si viene en params) o usamos 'require' por defecto
  $sslmode = isset($db_info['params']['sslmode']) ? $db_info['params']['sslmode'] : 'require';

  // Armamos el DSN para PDO (driver pgsql)
  $dsn = sprintf("pgsql:host=%s;port=%d;dbname=%s;sslmode=%s",
                 $db_info['host'],$db_info['port'],$db_info['dbname'],$sslmode);

  try {
    // Conectamos con PDO. Si falla, el catch captura el error.
    $pdo = new PDO($dsn, $db_info['user'], $db_info['pass'], [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,       // lanzar excepciones en errores
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC   // fetch devuelve array asociativo
    ]);

    // Pequeña consulta para comprobar que la BD responde (SELECT 1)
    $r = $pdo->query("SELECT 1 AS ok")->fetch();
    $db_ok = isset($r['ok']) && intval($r['ok']) === 1;

    // Leemos la hora actual de la BD (evidencia de conexión y zona horaria)
    $t = $pdo->query("SELECT now() AS ts")->fetch();
    $db_time = $t['ts'] ?? null;

    // Listamos las tablas públicas (information_schema). Esto es para el selector.
    $stmt = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema='public' AND table_type='BASE TABLE' ORDER BY table_name");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Si el usuario pidió ver una tabla y pasa la validación del nombre, la leemos
    if ($selected && valid_table($selected)) {
      // NOTA: aquí usamos comillas dobles para el nombre de la tabla; las columnas/valores
      // se mostrarán sanadas cuando las imprimamos (función h()).
      $stmt = $pdo->query('SELECT * FROM "' . $selected . '" ORDER BY 1 DESC LIMIT 50');
      $rows = $stmt->fetchAll();
    }
  } catch (Exception $e) {
    // Si algo falla al conectar/consultar, guardamos el mensaje en $db_error para mostrarlo.
    $db_ok = false;
    $db_error = $e->getMessage();
  }
} else {
  // Si no hay DATABASE_URL en el entorno, avisamos al usuario en la página.
  $db_error = "DATABASE_URL no está definida en el entorno.";
}

// Función helper para escapar y evitar XSS al mostrar en HTML.
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="es"><head><meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Taquería — PHP + PostgreSQL (Neon) en Render</title>
<style>
/* Estilos sencillos para que la página se vea bien */
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
<h1>Taquería</h1>
<p class="muted">Conectividad</p>

<!-- Bloque superior: servidor OK y estado de la BD -->
<div class="row">
  <div class="card"><div><strong>Servidor PHP</strong> <span class="badge ok">CONECTADA</span></div><div class="muted">index.php cargado.</div></div>

  <div class="card"><div><strong>Base de datos</strong>
  <?php if ($db_ok): ?><span class="badge ok">CONECTADA</span><?php else: ?><span class="badge bad">ERROR</span><?php endif; ?>
  </div><div class="muted">
  <?php if ($db_ok): ?>
    <!-- Si la BD está OK, mostramos usuario y host como evidencia (sin la contraseña) -->
    Conectado como <span class="code"><?php echo h($db_info['user']); ?></span> a <span class="code"><?php echo h($db_info['host']); ?></span> — Hora BD: <span class="code"><?php echo h($db_time); ?></span>
  <?php else: ?>
    <!-- Si hay error, mostramos el mensaje para debugging -->
    <?php echo h($db_error); ?>
  <?php endif; ?>
  </div></div>
</div>

<!-- Selector de tablas: llenado con $tables -->
<h3>Tablas en esquema <code>public</code></h3>
<form method="get" id="controls"><label>Tabla:</label>
<select name="table" id="tables">
<?php if (!empty($tables)): foreach ($tables as $t): ?>
  <!-- Generamos las opciones del select -->
  <option value="<?php echo h($t); ?>" <?php echo ($t===$selected)?'selected':''; ?>><?php echo h($t); ?></option>
<?php endforeach; else: ?><option>(sin tablas)</option><?php endif; ?>
</select>
<button type="submit">Mostrar registros</button></form>

<?php if ($selected): ?>
  <!-- Si se seleccionó tabla, mostramos hasta 50 registros -->
  <h3>Registros: <code><?php echo h($selected); ?></code></h3>
  <?php if (!empty($rows)): ?>
    <table><thead><tr>
      <!-- cabeceras con los nombres de columna -->
      <?php foreach (array_keys($rows[0]) as $c): ?><th><?php echo h($c); ?></th><?php endforeach; ?>
    </tr></thead><tbody>
      <!-- cuerpo con las filas; usamos h() para escapar los valores -->
      <?php foreach ($rows as $r): ?><tr><?php foreach ($r as $v): ?><td><?php echo h($v); ?></td><?php endforeach; ?></tr><?php endforeach; ?>
    </tbody></table>
  <?php else: ?><p class="muted">Sin registros.</p><?php endif; ?>
<?php endif; ?>

<hr/><p class="muted">Conexión de <span class="code">DATABASE_URL</span> (formato <span class="code">postgres://user:pass@host:port/db?sslmode=require</span>).</p>

<?php
// =======================================================
// 🔴 DEMO INSEGURA MULTI-TABLA: activada solo si ALLOW_UNSAFE_DEMO=1
// =======================================================

$allow_demo = getenv('ALLOW_UNSAFE_DEMO') === '1';
$raw = isset($_GET['raw']) ? $_GET['raw'] : '';
$results_inseguro = [];
$sql_inseguro = '';
$error_inseguro = '';
$col_text = null;

/**
 * Devuelve la primera columna textual (text, varchar, character varying, char)
 * de la tabla $tabla dentro del esquema public.
 */
function primera_columna_text($pdo, $tabla) {
  try {
    $stmt = $pdo->prepare("
      SELECT column_name
      FROM information_schema.columns
      WHERE table_schema='public' AND table_name = :t
      AND data_type IN ('text','character varying','varchar','char')
      ORDER BY ordinal_position
      LIMIT 1
    ");
    $stmt->execute([':t' => $tabla]);
    return $stmt->fetchColumn() ?: null;
  } catch (Exception $e) {
    return null;
  }
}

if ($allow_demo && isset($pdo)) {
  // Solo si el usuario seleccionó una tabla válida
  if (!empty($selected) && valid_table($selected)) {

    // 1) intentamos la primera columna textual
    $col_text = primera_columna_text($pdo, $selected);

    // 2) Si no hay columna textual, fallback: tomar la PRIMERA columna de la tabla (cualquier tipo)
    if (!$col_text) {
      try {
        $c = $pdo->prepare("
          SELECT column_name
          FROM information_schema.columns
          WHERE table_schema='public' AND table_name = :t
          ORDER BY ordinal_position LIMIT 1
        ");
        $c->execute([':t' => $selected]);
        $firstcol = $c->fetchColumn();
        if ($firstcol) {
          $col_text = $firstcol; // la usaremos casteada a text en la consulta
        }
      } catch (Exception $e) {
        // dejamos $col_text = null y mostraremos el error más abajo
      }
    }

    // 3) Si tenemos columna (textual o fallback) y recibimos $raw, construimos la consulta VULNERABLE
    if ($col_text && $raw !== '') {
      try {
        // ---------- Construir consulta VULNERABLE intencional (solo lectura)
        // NOTA: aquí NO escapamos $raw a propósito para la demo educativa.
        // USAR SOLO con app_readonly y ALLOW_UNSAFE_DEMO=1.
        $sql_inseguro = sprintf(
          'SELECT * FROM "%s" WHERE "%s"::text ILIKE \'%%%s%%\' ORDER BY 1 DESC LIMIT 50',
          $selected,
          $col_text,
          $raw   // <<-- intencionalmente sin escape para la demo
        );

        // Ejecutamos la consulta tal cual (vulnerable) y obtenemos resultados (solo lectura)
        $results_inseguro = $pdo->query($sql_inseguro)->fetchAll(PDO::FETCH_ASSOC);
      } catch (Exception $e) {
        $error_inseguro = $e->getMessage();
      }
    } elseif (!$col_text) {
      $error_inseguro = "No se encontró columna en la tabla " . h($selected);
    }
  }
}

if ($allow_demo):
?>
<hr>
<div style="border:2px dashed red; padding:10px; margin:12px 0;">
  <h3 style="color:#a00">INYECCIÓN SQL</h3>

  <p class="muted">Tabla seleccionada: <strong><?php echo h($selected ?: '(ninguna)'); ?></strong>
  <?php if ($col_text): ?> — columna usada: <span class="code"><?php echo h($col_text); ?></span><?php endif; ?></p>

  <form method="get" style="display:flex; gap:8px; align-items:center;">
    <!-- mantenemos el selector de tabla en la querystring -->
    <input type="hidden" name="table" value="<?php echo h($selected); ?>" />
    <input type="text" name="raw" placeholder="Payload: ej. ' OR '1'='1' --" value="<?php echo h($raw); ?>" style="flex:1;">
    <button>Probar inyección</button>
  </form>

  <?php if ($raw !== ''): ?>
    <p><strong>Consulta generada (vulnerable):</strong></p>
    <pre style="background:#f8f8f8; padding:8px; border:1px solid #ddd;"><?php echo h($sql_inseguro); ?></pre>

    <?php if ($error_inseguro): ?>
      <p style="color:red;"><?php echo h($error_inseguro); ?></p>
    <?php elseif (!empty($results_inseguro)): ?>
      <p><strong>Resultados devueltos:</strong></p>
      <table border="1" cellpadding="6" style="border-collapse:collapse; margin-top:8px;">
        <thead><tr>
          <?php foreach (array_keys($results_inseguro[0]) as $c): ?><th><?php echo h($c); ?></th><?php endforeach; ?>
        </tr></thead>
        <tbody>
          <?php foreach ($results_inseguro as $r): ?>
            <tr><?php foreach ($r as $v): ?><td><?php echo h($v); ?></td><?php endforeach; ?></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php else: ?>
      <p style="color:#666;">Sin resultados (o tabla vacía).</p>
    <?php endif; ?>
  <?php endif; ?>
</div>
<?php endif; ?>

</body></html>
