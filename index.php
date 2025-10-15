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
// ===================================================================
// BLOQUE DEMO INSEGURA (solo lectura) - activarse con ALLOW_UNSAFE_DEMO
// ===================================================================

// Si en Render pusiste Environment variable ALLOW_UNSAFE_DEMO = '1', entonces $allow_demo será true.
// Por defecto (si la variable no existe o es distinta de '1'), el bloque no hace nada.
$allow_demo = getenv('ALLOW_UNSAFE_DEMO') === '1';

// Leemos el parámetro 'raw' que viene por GET (ej: ?raw=' OR '1'='1)
$raw = isset($_GET['raw']) ? $_GET['raw'] : '';

// Variables para resultados / SQL generado / errores
$clientes_inseguro = [];
$sql_inseguro = '';
$error_inseguro = '';

// Si el demo está activado y el usuario envía algo en 'raw', construimos una consulta SIN PARAMETRIZAR
// Esto es exactamente lo que produce la vulnerabilidad SQLi; lo hacemos a propósito para la demo.
if ($allow_demo && $raw !== '') {
  try {
    // Consulta vulnerable (concatenación directa del input)
    $sql_inseguro = "SELECT id_cliente, nombre, telefono, correo
                     FROM cliente
                     WHERE nombre ILIKE '%{$raw}%'
                     ORDER BY id_cliente DESC LIMIT 50";

    // Ejecutamos la consulta y guardamos los resultados (solo lectura)
    $clientes_inseguro = $pdo->query($sql_inseguro)->fetchAll(PDO::FETCH_ASSOC);
  } catch (Exception $e) {
    // Si hay error (por ejemplo tabla no existe), lo guardamos para mostrarlo
    $error_inseguro = $e->getMessage();
  }
}

// Si el demo está activado, mostramos el bloque rojo con formulario y resultados
if ($allow_demo):
?>
<hr>
<div style="border:2px dashed red; padding:10px; margin:12px 0;">
  <h3 style="color:#a00">⚠️ DEMO DE INYECCIÓN SQL (educativa, solo lectura)</h3>

  <!-- Formulario simple para enviar payloads de prueba -->
  <form method="get" style="display:flex; gap:8px; align-items:center;">
    <input type="text" name="raw" placeholder="Ejemplo: ' OR '1'='1"
           value="<?php echo h($raw ?? ''); ?>" style="flex:1;">
    <button>Probar inyección</button>
  </form>

  <?php if ($raw !== ''): ?>
    <!-- Mostramos la consulta exactamente como se construyó (evidencia) -->
    <p><strong>Consulta generada:</strong></p>
    <pre style="background:#f8f8f8; padding:8px; border:1px solid #ddd;"><?php echo h($sql_inseguro); ?></pre>

    <?php if ($error_inseguro): ?>
      <!-- Si hubo error, lo mostramos -->
      <p style="color:red;"><?php echo h($error_inseguro); ?></p>
    <?php elseif (!empty($clientes_inseguro)): ?>
      <!-- Si hay resultados, los listamos -->
      <p><strong>Resultados devueltos:</strong></p>
      <table border="1" cellpadding="6" style="border-collapse:collapse; margin-top:8px;">
        <thead><tr><th>ID</th><th>Nombre</th><th>Teléfono</th><th>Correo</th></tr></thead>
        <tbody>
          <?php foreach ($clientes_inseguro as $c): ?>
            <tr>
              <td><?php echo h($c['id_cliente']); ?></td>
              <td><?php echo h($c['nombre']); ?></td>
              <td><?php echo h($c['telefono']); ?></td>
              <td><?php echo h($c['correo']); ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php else: ?>
      <!-- Si no hay filas, aviso -->
      <p style="color:#666;">Sin resultados o tabla vacía.</p>
    <?php endif; ?>
  <?php endif; ?>
</div>
<?php endif; // fin allow_demo ?>

</body></html>
