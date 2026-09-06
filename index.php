<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/api-client.php';

// CORS basico (ajustar en produccion)
if (!empty($_SERVER['HTTP_ORIGIN'])) {
    header('Access-Control-Allow-Origin: ' . $_SERVER['HTTP_ORIGIN']);
    header('Vary: Origin');
} else {
    header('Access-Control-Allow-Origin: *');
}
header('Access-Control-Allow-Headers: Content-Type, X-API-KEY');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(204);
  exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url($requestUri, PHP_URL_PATH);
$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/');
if ($basePath === '') {
    $basePath = '/';
}
// Normalizar path para que funcione en subcarpetas (ej: /php-api/api/...)
if ($basePath !== '/' && str_starts_with($path, $basePath)) {
    $path = substr($path, strlen($basePath));
    if ($path === '') {
        $path = '/';
    }
}
if (!str_starts_with($path, '/api')) {
    if (preg_match('#/api/[^a]*#', $requestUri, $m)) {
        $path = $m[0];
    } else {
        $apiPos = strpos($path, '/api/');
        if ($apiPos !== false) {
            $path = substr($path, $apiPos);
        }
    }
}

if (isDebug() && isset($_GET['__debug_global'])) {
    jsonResponse([
        'ok' => true,
        'request_uri' => $requestUri,
        'parsed_path' => $path,
        'script_name' => $_SERVER['SCRIPT_NAME'] ?? null,
        'base_path' => $basePath,
        'host' => $_SERVER['HTTP_HOST'] ?? null
    ]);
}

if (isDebug() && isset($_GET['__debug'])) {
    jsonResponse([
        'ok' => true,
        'request_uri' => $requestUri,
        'parsed_path' => $path,
        'script_name' => $_SERVER['SCRIPT_NAME'] ?? null,
        'base_path' => $basePath
    ]);
}

function getReservationCutoffDateTime(): DateTimeImmutable
{
    return (new DateTimeImmutable('now'))->modify('+24 hours');
}

function parseReservationDateTime(string $fecha, string $hora): ?DateTimeImmutable
{
    $fecha = trim($fecha);
    $hora = trim($hora);
    if ($fecha === '' || $hora === '') {
        return null;
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
        return null;
    }
    if (!preg_match('/^\d{1,2}:\d{2}$/', $hora)) {
        return null;
    }

    [$h, $m] = array_map('intval', explode(':', $hora, 2));
    if ($h < 0 || $h > 23 || $m < 0 || $m > 59) {
        return null;
    }

    try {
        return new DateTimeImmutable(sprintf('%s %02d:%02d:00', $fecha, $h, $m));
    } catch (Throwable) {
        return null;
    }
}

// ---------- API ----------
if (str_starts_with($path, '/api')) {
  if (!rateLimitCheck('api_global', 180, 60)) {
    jsonResponse(['ok' => false, 'error' => 'Demasiadas solicitudes. Intenta nuevamente.'], 429);
  }

    // Obtener cliente remoto
    $remoteUrl = remoteApiUrl();
    $remoteToken = remoteApiToken();
    
    if ($remoteUrl === '' || $remoteToken === '') {
        jsonResponse(['ok' => false, 'error' => 'API remota no configurada'], 500);
    }

    $client = new RemoteApiClient($remoteUrl, $remoteToken);

    if ($method === 'GET' && $path === '/api/debug') {
        jsonResponse([
            'ok' => true,
            'debug' => isDebug(),
            'base_path' => $basePath,
            'token_loaded' => apiToken() !== '' ? 'yes' : 'no',
            'remote_api_url' => $remoteUrl,
            'remote_api_configured' => $remoteToken !== '' ? 'yes' : 'no'
        ]);
    }

    if ($method === 'GET' && $path === '/api/horarios') {
        $fecha = $_GET['fecha'] ?? '';
        if ($fecha === '') {
            jsonResponse(['ok' => false, 'error' => 'Fecha requerida'], 400);
        }
        // Proxy hacia la API remota
        $result = $client->obtenerHorarios($fecha);
        if (!$result['ok'] ?? false) {
            jsonResponse($result, 400);
        }
        jsonResponse($result);
    }

    if ($method === 'GET' && $path === '/api/vehiculo') {
        $matricula = $_GET['matricula'] ?? '';
        if ($matricula === '') {
            jsonResponse(['ok' => false, 'error' => 'Matrícula requerida'], 400);
        }
        // Proxy hacia la API remota
        $result = $client->obtenerVehiculo($matricula);
      if (!($result['ok'] ?? false)) {
            jsonResponse($result, 400);
        }
        jsonResponse($result);
    }

    if ($method === 'GET' && $path === '/api/vehiculos/por-cedula') {
      $cedula = $_GET['cedula'] ?? '';
      if ($cedula === '') {
        jsonResponse(['ok' => false, 'error' => 'Cédula requerida'], 400);
      }
      $result = $client->obtenerVehiculosPorCedula($cedula);
      if (!($result['ok'] ?? false)) {
        jsonResponse($result, 400);
      }
      jsonResponse($result);
    }

    if ($method === 'GET' && $path === '/api/vehiculos/catalogo') {
      $result = $client->obtenerCatalogoVehiculos();
      if (!($result['ok'] ?? false)) {
        jsonResponse($result, 400);
      }
      jsonResponse($result);
    }

    if ($method === 'POST' && $path === '/api/reservas') {
      if (!rateLimitCheck('api_reservas_post', 8, 900)) {
        jsonResponse(['ok' => false, 'error' => 'Demasiadas reservas desde esta IP. Espera unos minutos.'], 429);
      }

      $origin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
      if (!isOriginAllowed($origin)) {
        jsonResponse(['ok' => false, 'error' => 'Origen no permitido'], 403);
      }

        $data = readJsonBody();

      // Honeypot anti-bot: si viene con contenido, se rechaza.
      if (!empty($data['website'])) {
        jsonResponse(['ok' => false, 'error' => 'Solicitud rechazada'], 400);
      }

      // Si el cliente reporta envio demasiado rapido, bloquear.
      $elapsedMs = isset($data['client_elapsed_ms']) ? (int)$data['client_elapsed_ms'] : 0;
      if ($elapsedMs > 0 && $elapsedMs < 2500) {
        jsonResponse(['ok' => false, 'error' => 'Solicitud rechazada'], 400);
      }
        
        // Validar campos requeridos localmente
        $required = ['nombre', 'cedula', 'telefono', 'marca', 'modelo', 'matricula', 'tipo_turno', 'fecha', 'hora'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                jsonResponse(['ok' => false, 'error' => "Campo requerido: {$field}"], 400);
            }
        }

        // Proxy hacia la API remota
        $result = $client->crearReserva($data);
        if (!$result['ok'] ?? false) {
            $status = $result['status'] ?? 400;
            jsonResponse(['ok' => false, 'error' => $result['error'] ?? 'Error creando reserva'], $status);
        }
        jsonResponse($result, 201);
    }

    jsonResponse(['ok' => false, 'error' => 'Endpoint no encontrado'], 404);
}

// ---------- WEB ----------
$siteBase = $basePath === '/' ? '' : $basePath;
$normalizedWebPath = '/' . ltrim(rtrim($path, '/'), '/');
if ($normalizedWebPath === '//') {
    $normalizedWebPath = '/';
}
$turnosUrl = $siteBase . '/turnos/';
$homeUrl = $siteBase === '' ? '/' : $siteBase . '/';
$instagramUrl = 'https://www.instagram.com/rosas.uy.online/';
$whatsappUrl = 'https://wa.me/59894860496';
$previewUrl = $siteBase . '/exclusivo/';
$logoUrl = $siteBase . '/Logo.png';
$isTurnosRoute = $normalizedWebPath === '/turnos';
$isExclusiveRoute = $normalizedWebPath === '/exclusivo';

if ($isExclusiveRoute):
requireToken();
$previewPayload = [
    'nombre' => trim((string)($_GET['nombre'] ?? 'Cliente de prueba')),
    'telefono' => trim((string)($_GET['telefono'] ?? '099 123 456')),
    'fecha' => trim((string)($_GET['fecha'] ?? date('Y-m-d'))),
    'hora' => trim((string)($_GET['hora'] ?? '10:30')),
    'matricula' => trim((string)($_GET['matricula'] ?? 'SCQ1423')),
    'marca' => trim((string)($_GET['marca'] ?? 'Bajaj')),
    'tipo_turno' => trim((string)($_GET['tipo_turno'] ?? 'Particular')),
    'particular_tipo' => trim((string)($_GET['particular_tipo'] ?? 'Service')),
    'km' => trim((string)($_GET['km'] ?? '12500')),
    'detalles' => trim((string)($_GET['detalles'] ?? 'Cambio de aceite, revisión general y control de frenos.')),
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Comprobante privado | Rosas Uy</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://cdn.jsdelivr.net/npm/jspdf@2.5.1/dist/jspdf.umd.min.js"></script>
</head>
<body class="bg-slate-100 text-slate-900">
  <div class="min-h-screen">
    <main class="max-w-4xl mx-auto px-5 py-8">
      <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-4 mb-6">
        <div>
          <div class="text-xs uppercase tracking-[0.28em] text-emerald-600 font-black">Vista privada</div>
          <h1 class="text-3xl md:text-4xl font-black tracking-tight text-slate-900 mt-2">Comprobante de reserva</h1>
          <p class="text-slate-600 mt-2 max-w-2xl">Esta ruta está protegida por token y sirve para que solo tú puedas revisar cómo queda el comprobante antes de usarlo con clientes.</p>
        </div>
        <div class="flex flex-wrap gap-2">
          <button id="descargarComprobante" type="button" class="px-4 py-2 rounded-xl bg-emerald-500 text-white font-black text-sm hover:bg-emerald-600 transition-colors">Descargar PDF</button>
          <a href="<?php echo htmlspecialchars($homeUrl, ENT_QUOTES); ?>" class="px-4 py-2 rounded-xl border border-slate-200 bg-white text-slate-700 font-black text-sm">Volver</a>
        </div>
      </div>

      <section class="rounded-[28px] border border-slate-200 bg-white shadow-sm p-6 md:p-8">
        <div class="flex items-center gap-3 mb-6">
          <div class="h-12 w-12 rounded-2xl bg-slate-950 p-2 flex items-center justify-center overflow-hidden">
            <img src="<?php echo htmlspecialchars($logoUrl, ENT_QUOTES); ?>" alt="Rosas Uy" class="h-full w-full object-contain" />
          </div>
          <div>
            <div class="text-xs uppercase tracking-[0.28em] text-emerald-600 font-black">Rosas Uy</div>
            <div class="text-sm text-slate-500 font-semibold">Tu servicio de confianza</div>
          </div>
        </div>

        <div class="border-b border-slate-200 pb-4 mb-5">
          <h2 class="text-2xl font-black text-slate-900">Reserva confirmada</h2>
          <p class="text-slate-600 mt-2">Esta es la previsualización real del comprobante que se puede descargar en PDF.</p>
        </div>

        <div class="grid sm:grid-cols-2 gap-3 text-sm">
          <div class="rounded-2xl bg-slate-50 px-4 py-3"><span class="block text-slate-500 font-semibold">Cliente</span><span class="block text-slate-900 font-black mt-1"><?php echo htmlspecialchars($previewPayload['nombre'], ENT_QUOTES); ?></span></div>
          <div class="rounded-2xl bg-slate-50 px-4 py-3"><span class="block text-slate-500 font-semibold">Teléfono</span><span class="block text-slate-900 font-black mt-1"><?php echo htmlspecialchars($previewPayload['telefono'], ENT_QUOTES); ?></span></div>
          <div class="rounded-2xl bg-slate-50 px-4 py-3"><span class="block text-slate-500 font-semibold">Fecha</span><span class="block text-slate-900 font-black mt-1"><?php echo htmlspecialchars($previewPayload['fecha'], ENT_QUOTES); ?></span></div>
          <div class="rounded-2xl bg-slate-50 px-4 py-3"><span class="block text-slate-500 font-semibold">Hora</span><span class="block text-slate-900 font-black mt-1"><?php echo htmlspecialchars($previewPayload['hora'], ENT_QUOTES); ?></span></div>
          <div class="rounded-2xl bg-slate-50 px-4 py-3"><span class="block text-slate-500 font-semibold">Matrícula</span><span class="block text-slate-900 font-black mt-1"><?php echo htmlspecialchars($previewPayload['matricula'], ENT_QUOTES); ?></span></div>
          <div class="rounded-2xl bg-slate-50 px-4 py-3"><span class="block text-slate-500 font-semibold">Vehículo</span><span class="block text-slate-900 font-black mt-1"><?php echo htmlspecialchars($previewPayload['marca'], ENT_QUOTES); ?></span></div>
          <div class="rounded-2xl bg-slate-50 px-4 py-3"><span class="block text-slate-500 font-semibold">Tipo</span><span class="block text-slate-900 font-black mt-1"><?php echo htmlspecialchars($previewPayload['tipo_turno'] . ($previewPayload['particular_tipo'] !== '' ? ' - ' . $previewPayload['particular_tipo'] : ''), ENT_QUOTES); ?></span></div>
          <div class="rounded-2xl bg-slate-50 px-4 py-3"><span class="block text-slate-500 font-semibold">Kilómetros</span><span class="block text-slate-900 font-black mt-1"><?php echo htmlspecialchars($previewPayload['km'] !== '' ? $previewPayload['km'] : '-', ENT_QUOTES); ?></span></div>
        </div>

        <div class="mt-4 rounded-2xl bg-emerald-50 px-4 py-4 text-sm text-slate-700">
          <div class="font-black text-emerald-700 mb-1">Detalle del trabajo</div>
          <div><?php echo htmlspecialchars($previewPayload['detalles'] !== '' ? $previewPayload['detalles'] : 'Sin detalle adicional.', ENT_QUOTES); ?></div>
        </div>
      </section>
    </main>
  </div>

  <script>
    const previewPayload = <?php echo json_encode($previewPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    const PDF_LOGO_URL = <?php echo json_encode($logoUrl, JSON_UNESCAPED_SLASHES); ?>;

    function loadWhiteLogoDataUrl(url) {
      return new Promise((resolve) => {
        const img = new Image();
        img.crossOrigin = 'anonymous';
        img.onload = () => {
          const canvas = document.createElement('canvas');
          const size = 220;
          canvas.width = size;
          canvas.height = size;
          const ctx = canvas.getContext('2d');
          if (!ctx) {
            resolve(null);
            return;
          }
          ctx.clearRect(0, 0, size, size);
          ctx.drawImage(img, 0, 0, size, size);
          ctx.globalCompositeOperation = 'source-in';
          ctx.fillStyle = '#FFFFFF';
          ctx.fillRect(0, 0, size, size);
          resolve(canvas.toDataURL('image/png'));
        };
        img.onerror = () => resolve(null);
        img.src = url;
      });
    }

    async function descargarComprobante(payload) {
      if (!window.jspdf) return;
      const { jsPDF } = window.jspdf;
      const doc = new jsPDF({ unit: 'mm', format: 'a4' });
      const pageWidth = doc.internal.pageSize.getWidth();
      const margin = 14;
      const contentWidth = pageWidth - (margin * 2);
      const gap = 4;
      const colWidth = (contentWidth - gap) / 2;
      const cardH = 14;

      const whiteLogo = await loadWhiteLogoDataUrl(PDF_LOGO_URL);

      doc.setFillColor(5, 120, 90);
      doc.rect(0, 0, pageWidth, 40, 'F');
      if (whiteLogo) {
        doc.addImage(whiteLogo, 'PNG', 14, 8, 16, 16);
      }
      doc.setTextColor(255, 255, 255);
      doc.setFont('helvetica', 'bold');
      doc.setFontSize(16);
      doc.text('Taller Rosas', 36, 16);
      doc.setFontSize(11);
      doc.setFont('helvetica', 'normal');
      doc.text('Comprobante de Reserva', 36, 24);

      const drawCard = (x, y, label, value) => {
        doc.setFillColor(236, 253, 245);
        doc.setDrawColor(209, 250, 229);
        doc.roundedRect(x, y, colWidth, cardH, 2, 2, 'FD');
        doc.setTextColor(71, 85, 105);
        doc.setFont('helvetica', 'bold');
        doc.setFontSize(8);
        doc.text(String(label).toUpperCase(), x + 3, y + 5);
        doc.setTextColor(15, 23, 42);
        doc.setFont('helvetica', 'normal');
        doc.setFontSize(10);
        doc.text(String(value || '-'), x + 3, y + 10);
      };

      let y = 48;
      drawCard(margin, y, 'Cliente', payload.nombre);
      drawCard(margin + colWidth + gap, y, 'Teléfono', payload.telefono);
      y += cardH + gap;
      drawCard(margin, y, 'Fecha', payload.fecha);
      drawCard(margin + colWidth + gap, y, 'Hora', payload.hora);
      y += cardH + gap;
      drawCard(margin, y, 'Matrícula', payload.matricula);
      drawCard(margin + colWidth + gap, y, 'Vehículo', payload.marca || '');
      y += cardH + gap;
      drawCard(margin, y, 'Tipo', `${payload.tipo_turno || ''}${payload.particular_tipo ? ' - ' + payload.particular_tipo : ''}`);
      drawCard(margin + colWidth + gap, y, 'KM', payload.km || '-');
      y += cardH + gap;

      doc.setFillColor(248, 250, 252);
      doc.setDrawColor(226, 232, 240);
      doc.roundedRect(margin, y, contentWidth, 24, 2, 2, 'FD');
      doc.setTextColor(71, 85, 105);
      doc.setFont('helvetica', 'bold');
      doc.setFontSize(8);
      doc.text('DETALLE', margin + 3, y + 5);
      doc.setTextColor(15, 23, 42);
      doc.setFont('helvetica', 'normal');
      doc.setFontSize(10);
      const wrapped = doc.splitTextToSize(String(payload.detalles || 'Sin detalle adicional.'), contentWidth - 6);
      doc.text(wrapped, margin + 3, y + 11);

      doc.setTextColor(100, 116, 139);
      doc.setFont('helvetica', 'italic');
      doc.setFontSize(8);
      doc.text('Gracias por confiar en Taller Rosas', margin, 286);
      doc.text(`Generado: ${new Date().toLocaleString()}`, pageWidth - margin, 286, { align: 'right' });

      const file = `comprobante-reserva-${payload.fecha || 'preview'}.pdf`.replace(/\s+/g, '-');
      doc.save(file);
    }

    document.getElementById('descargarComprobante')?.addEventListener('click', () => descargarComprobante(previewPayload));
  </script>
</body>
</html>
<?php
exit;
endif;

if (!$isTurnosRoute):
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Rosas Uy | Agenda aquí</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-[#07110d] text-[#e6fff1]">
  <div class="relative min-h-screen overflow-hidden bg-[radial-gradient(circle_at_top_left,_rgba(22,185,84,0.18),_transparent_36%),radial-gradient(circle_at_top_right,_rgba(18,51,36,0.95),_transparent_30%),linear-gradient(180deg,#07110d_0%,#0d1713_48%,#07110d_100%)]">
    <div class="pointer-events-none absolute -top-24 left-1/2 h-80 w-80 -translate-x-1/2 rounded-full bg-[#16b95422] blur-3xl"></div>
    <header class="relative z-10 max-w-6xl mx-auto px-5 py-5 flex flex-col md:flex-row md:items-center md:justify-between gap-4 border-b border-[#1e382d]/80">
      <a href="<?php echo htmlspecialchars($homeUrl, ENT_QUOTES); ?>" class="flex items-center gap-3">
        <div class="h-14 w-14 rounded-2xl border border-[#24503d] bg-[#123324] p-2 flex items-center justify-center overflow-hidden shadow-lg shadow-black/20">
          <img src="<?php echo htmlspecialchars($logoUrl, ENT_QUOTES); ?>" alt="Rosas Uy" class="h-full w-full object-contain" />
        </div>
        <div>
          <div class="text-xs uppercase tracking-[0.28em] text-[#74f3a5] font-black">Rosas Uy</div>
          <div class="text-sm text-[#9dc9b2] font-semibold">Tu servicio de confianza</div>
        </div>
      </a>

      <nav class="flex flex-wrap items-center gap-2">
        <a href="<?php echo htmlspecialchars($turnosUrl, ENT_QUOTES); ?>" class="px-4 py-2 rounded-xl bg-[#16b954] text-white text-sm font-black shadow-lg shadow-[#16b95433] hover:bg-[#0f9e46] transition-colors">Agenda</a>
        <a href="<?php echo htmlspecialchars($instagramUrl, ENT_QUOTES); ?>" target="_blank" rel="noreferrer" class="px-4 py-2 rounded-xl border border-[#2a6449] bg-[#16271f] text-sm font-bold text-[#d7f5e6] hover:bg-[#1c3328] transition-colors">Instagram</a>
      </nav>
    </header>

    <main class="relative z-10 max-w-6xl mx-auto px-5 pb-16 pt-10 md:pt-16">
      <section class="grid lg:grid-cols-[1.05fr_0.95fr] gap-10 items-center">
        <div>
          <span class="inline-flex px-3 py-1 rounded-full bg-[#123324] text-[#74f3a5] text-[11px] font-black uppercase tracking-[0.24em] border border-[#2a6449]">Agenda aquí</span>
          <h1 class="mt-4 text-4xl md:text-6xl font-black tracking-tight leading-[0.95] text-[#f0fff7]">Tu servicio de confianza, con agenda simple y rápida.</h1>
          <p class="mt-5 text-[#b1d8c4] text-base md:text-lg leading-relaxed max-w-2xl">
            Reservá tu turno en pocos pasos, dejá claros los datos de la moto y llegá al taller con todo ordenado desde el inicio.
          </p>

          <div class="mt-7 flex flex-wrap gap-3">
            <a href="<?php echo htmlspecialchars($turnosUrl, ENT_QUOTES); ?>" class="px-5 py-3 rounded-2xl bg-[#16b954] text-white font-black uppercase tracking-widest text-sm shadow-lg shadow-[#16b95433] hover:bg-[#0f9e46] transition-colors">Agendar ahora</a>
            <a href="<?php echo htmlspecialchars($whatsappUrl, ENT_QUOTES); ?>" target="_blank" rel="noreferrer" class="px-5 py-3 rounded-2xl border border-[#2a6449] bg-[#16271f] text-[#d7f5e6] font-black uppercase tracking-widest text-sm hover:bg-[#1c3328] transition-colors">WhatsApp</a>
            <a href="<?php echo htmlspecialchars($instagramUrl, ENT_QUOTES); ?>" target="_blank" rel="noreferrer" class="px-5 py-3 rounded-2xl border border-[#2a6449] bg-[#16271f] text-[#d7f5e6] font-black uppercase tracking-widest text-sm hover:bg-[#1c3328] transition-colors">Instagram</a>
          </div>

          <div class="mt-8 grid sm:grid-cols-3 gap-3 max-w-2xl">
            <div class="rounded-2xl border border-[#234435] bg-[#0f1d17]/90 p-4 backdrop-blur">
              <div class="text-xs uppercase tracking-[0.22em] text-[#74f3a5] font-black">Agenda</div>
              <div class="mt-2 text-sm text-[#e6fff1] font-semibold">Reservas claras y rápidas.</div>
            </div>
            <div class="rounded-2xl border border-[#234435] bg-[#0f1d17]/90 p-4 backdrop-blur">
              <div class="text-xs uppercase tracking-[0.22em] text-[#74f3a5] font-black">Atención</div>
              <div class="mt-2 text-sm text-[#e6fff1] font-semibold">Datos del cliente y la moto en orden.</div>
            </div>
            <div class="rounded-2xl border border-[#234435] bg-[#0f1d17]/90 p-4 backdrop-blur">
              <div class="text-xs uppercase tracking-[0.22em] text-[#74f3a5] font-black">Confianza</div>
              <div class="mt-2 text-sm text-[#e6fff1] font-semibold">Tu servicio de confianza, siempre a mano.</div>
            </div>
          </div>
        </div>

        <div class="relative">
          <div class="absolute inset-0 rounded-[32px] bg-[#16b9541a] blur-3xl"></div>
          <div class="relative rounded-[32px] border border-[#2a6449] bg-[linear-gradient(180deg,rgba(18,51,36,0.96),rgba(12,25,20,0.96))] p-6 md:p-8 shadow-2xl shadow-black/30">
            <div class="flex items-center justify-between gap-4 mb-6">
              <div>
                <div class="text-xs uppercase tracking-[0.28em] text-[#74f3a5] font-black">Rosas Uy</div>
                <h2 class="mt-2 text-2xl md:text-3xl font-black text-[#f0fff7]">Agenda aquí</h2>
              </div>
              <div class="h-16 w-16 rounded-2xl bg-[#123324] border border-[#2a6449] p-2 flex items-center justify-center overflow-hidden">
                <img src="<?php echo htmlspecialchars($logoUrl, ENT_QUOTES); ?>" alt="Rosas Uy" class="h-full w-full object-contain" />
              </div>
            </div>

            <div class="grid gap-3">
              <div class="rounded-2xl border border-[#234435] bg-[#0f1d17] p-4">
                <div class="text-xs uppercase tracking-[0.22em] text-[#74f3a5] font-black">Service</div>
                <div class="mt-1 text-sm text-[#d7f5e6]">Mantenimientos para dejar la moto lista.</div>
              </div>
              <div class="rounded-2xl border border-[#234435] bg-[#0f1d17] p-4">
                <div class="text-xs uppercase tracking-[0.22em] text-[#74f3a5] font-black">Reparación</div>
                <div class="mt-1 text-sm text-[#d7f5e6]">Diagnóstico y solución de fallas.</div>
              </div>
              <div class="rounded-2xl border border-[#234435] bg-[#0f1d17] p-4">
                <div class="text-xs uppercase tracking-[0.22em] text-[#74f3a5] font-black">Toma</div>
                <div class="mt-1 text-sm text-[#d7f5e6]">Dejá la moto y seguimos con el trabajo.</div>
              </div>
            </div>

            <div class="mt-6 flex items-center justify-between rounded-2xl border border-[#234435] bg-[#123324] px-4 py-3">
              <div>
                <div class="text-xs uppercase tracking-[0.22em] text-[#74f3a5] font-black">Contacto</div>
                <div class="text-sm text-[#e6fff1] font-semibold">WhatsApp e Instagram activos.</div>
              </div>
              <a href="<?php echo htmlspecialchars($turnosUrl, ENT_QUOTES); ?>" class="px-4 py-2 rounded-xl bg-[#16b954] text-white font-black text-sm hover:bg-[#0f9e46] transition-colors">Ir a turnos</a>
            </div>
          </div>
        </div>
      </section>
    </main>
  </div>
</body>
</html>
<?php
exit;
endif;
?>
<!DOCTYPE html>
<html lang="es">

<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Turnos | Rosas Uy</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://cdn.jsdelivr.net/npm/jspdf@2.5.1/dist/jspdf.umd.min.js"></script>
  <style>
    :root {
      --rr-bg: #0d1411;
      --rr-bg-soft: #11201a;
      --rr-card: #16271f;
      --rr-card-soft: #1c3328;
      --rr-border: #2a6449;
      --rr-accent: #16b954;
      --rr-accent-strong: #0f9e46;
      --rr-text: #d7f5e6;
      --rr-text-soft: #abd4bf;
    }
  </style>
</head>

<body class="[color-scheme:dark]">
  <div class="min-h-screen bg-gradient-to-b from-[#0d1411] via-[#11201a] to-[#0d1411] text-[var(--rr-text)]">
    <div class="max-w-5xl mx-auto px-5 py-10">
      <header class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-8">
        <div class="flex items-center gap-3">
          <div class="h-12 w-12 rounded-xl bg-[#123324] border border-[var(--rr-border)] p-1.5 overflow-hidden flex items-center justify-center">
            <img src="<?php echo htmlspecialchars($logoUrl, ENT_QUOTES); ?>" alt="Rosas Uy" class="h-full w-full object-contain" />
          </div>
          <div>
            <h1 class="text-3xl md:text-4xl font-black tracking-tight text-[#ecfff5]">Agenda tu turno</h1>
            <p class="text-[#9dc9b2] mt-1">Tu servicio de confianza</p>
          </div>
        </div>
        <div class="flex items-center gap-2">
          <a href="<?php echo htmlspecialchars($homeUrl, ENT_QUOTES); ?>" class="px-3 py-1.5 rounded-full border border-[var(--rr-border)] text-xs text-[#d7f5e6] bg-[var(--rr-card)]">Inicio</a>
          <a href="<?php echo htmlspecialchars($instagramUrl, ENT_QUOTES); ?>" target="_blank" rel="noreferrer" class="px-3 py-1.5 rounded-full border border-[var(--rr-border)] text-xs text-[#d7f5e6] bg-[var(--rr-card)]">Instagram</a>
          <span class="px-3 py-1.5 rounded-full border border-[var(--rr-border)] text-xs text-[#7ef0a9] bg-[var(--rr-card)]">Online</span>
        </div>
      </header>

      <div class="rounded-2xl border border-[var(--rr-border)] bg-[var(--rr-card)] p-6 md:p-8 shadow-xl shadow-black/20" id="stepCalendario">
        <div class="text-sm font-black uppercase tracking-widest text-[#7ef0a9] mb-4">Paso 1 : Elegir fecha y hora</div>
        <div class="grid gap-6 md:grid-cols-[280px_1fr] md:items-start">
          <div class="rounded-2xl border border-[var(--rr-border)] bg-[var(--rr-card-soft)] p-3 sm:p-4 w-full max-w-[22rem] mx-auto">
            <div class="flex items-center justify-between gap-2 mb-3">
              <button id="calPrev" type="button"
                class="h-8 w-8 shrink-0 rounded-full border border-[var(--rr-border)] bg-[var(--rr-card)] text-[#d7f5e6] hover:border-[var(--rr-accent)]">&lsaquo;</button>
              <div id="calTitle" class="flex-1 text-center text-sm sm:text-base font-black text-[#ecfff5]"></div>
              <button id="calNext" type="button"
                class="h-8 w-8 shrink-0 rounded-full border border-[var(--rr-border)] bg-[var(--rr-card)] text-[#d7f5e6] hover:border-[var(--rr-accent)]">&rsaquo;</button>
            </div>
            <div class="grid grid-cols-7 text-[8px] sm:text-[10px] uppercase tracking-[0.18em] text-[#9dc9b2] font-black mb-2 gap-0.5 sm:gap-1">
              <div class="text-center">Dom</div>
              <div class="text-center">Lun</div>
              <div class="text-center">Mar</div>
              <div class="text-center">Mié</div>
              <div class="text-center">Jue</div>
              <div class="text-center">Vie</div>
              <div class="text-center">Sab</div>
            </div>
            <div id="calGrid" class="grid grid-cols-7 gap-0.5 sm:gap-1"></div>
            <input id="fecha" type="hidden" />
            <div id="fechaSeleccion" class="mt-3 text-[11px] sm:text-xs text-[#9dc9b2] leading-snug"></div>
          </div>
          <div class="space-y-3 w-full">
            <div>
              <label class="block text-[10px] uppercase tracking-widest text-[#9dc9b2] font-black mb-2">Horarios disponibles</label>
              <div id="horarios" class="space-y-2 max-h-64 overflow-auto pr-1"></div>
              <div id="horariosStatus" class="text-xs mt-2 text-[#9dc9b2]"></div>
            </div>
            <div class="pt-2">
              <button id="btnContinuar"
                class="bg-[var(--rr-accent)] text-white font-black tracking-widest uppercase px-6 py-3 rounded-xl shadow-lg shadow-[#16b95433] disabled:opacity-50 disabled:cursor-not-allowed"
                disabled>Continuar</button>
            </div>
          </div>
        </div>
      </div>

      <div class="rounded-2xl border border-[var(--rr-border)] bg-[var(--rr-card)] p-6 md:p-8 shadow-xl shadow-black/20 hidden" id="stepFormulario">
  <div class="text-sm font-black uppercase tracking-widest text-[#7ef0a9] mb-4">Paso 2 · Datos del cliente y tipo de trabajo</div>
  <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
    <div>
      <label class="block text-[10px] uppercase tracking-widest text-[#9dc9b2] font-black mb-2">Nombre</label>
      <input id="nombre" type="text" placeholder="Tu nombre"
        class="w-full rounded-xl bg-[var(--rr-card-soft)] border border-[var(--rr-border)] px-4 py-3 text-[var(--rr-text)]" />
      <div id="nombreStatus" class="text-xs mt-1"></div>
    </div>
    <div>
      <label class="block text-[10px] uppercase tracking-widest text-[#9dc9b2] font-black mb-2">Cédula</label>
      <input id="cedula" type="text" placeholder="1.234.567-8"
        class="w-full rounded-xl bg-[var(--rr-card-soft)] border border-[var(--rr-border)] px-4 py-3 text-[var(--rr-text)]" />
      <div id="cedulaStatus" class="text-xs mt-1"></div>
    </div>
    <div>
      <label class="block text-[10px] uppercase tracking-widest text-[#9dc9b2] font-black mb-2">Teléfono</label>
      <input id="telefono" type="tel" inputmode="numeric" placeholder="99111111"
        class="w-full rounded-xl bg-[var(--rr-card-soft)] border border-[var(--rr-border)] px-4 py-3 text-[var(--rr-text)]" />
      <div id="telefonoStatus" class="text-xs mt-1"></div>
    </div>
    <div class="md:col-span-2 rounded-2xl border border-[#234435] bg-[#0f1d17]/80 p-4" id="vehiculosClienteBox">
      <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3">
        <div>
          <div class="text-[10px] uppercase tracking-[0.22em] text-[#74f3a5] font-black">Motos del cliente</div>
          <div id="vehiculosClienteStatus" class="mt-1 text-sm text-[#d7f5e6]">Escribí la cédula para buscar motos registradas.</div>
        </div>
        <button id="btnLimpiarVehiculo" type="button" class="px-3 py-2 rounded-xl border border-[#2a6449] bg-[#16271f] text-[#d7f5e6] font-black uppercase tracking-widest text-[11px] hover:bg-[#1c3328] transition-colors">Limpiar</button>
      </div>
      <div id="vehiculosClienteList" class="mt-3 grid gap-2"></div>
      <div id="vehiculoSeleccionadoResumen" class="mt-3 text-xs text-[#9dc9b2]"></div>
    </div>
    <div>
      <label class="block text-[10px] uppercase tracking-widest text-[#9dc9b2] font-black mb-2">Marca</label>
      <input id="marca" type="text" class="w-full rounded-xl bg-[var(--rr-card-soft)] border border-[var(--rr-border)] px-4 py-3 text-[var(--rr-text)]" />
    </div>
    <div>
      <label class="block text-[10px] uppercase tracking-widest text-[#9dc9b2] font-black mb-2">Modelo</label>
      <input id="modelo" type="text" class="w-full rounded-xl bg-[var(--rr-card-soft)] border border-[var(--rr-border)] px-4 py-3 text-[var(--rr-text)]" />
    </div>
    <div style="position:absolute;left:-9999px;opacity:0;pointer-events:none;" aria-hidden="true">
      <label for="website">No completar</label>
      <input id="website" type="text" autocomplete="off" tabindex="-1" />
    </div>
  </div>

  <input id="tipo_turno" type="hidden" value="" />
  <input id="particular_tipo" type="hidden" value="" />
  <input id="garantia_tipo" type="hidden" value="" />
  <input id="matricula" type="hidden" value="WEBCLIENTE" />
  <input id="garantia_numero_service" type="hidden" value="" />
  <input id="garantia_problema" type="hidden" value="" />

  <div class="mt-5">
    <label class="block text-[10px] uppercase tracking-widest text-[#9dc9b2] font-black mb-2">Selecciona el tipo</label>
    <div class="grid md:grid-cols-2 gap-3">
      <button type="button" id="btnTipoServiceGarantia" class="px-4 py-3 rounded-xl border border-[var(--rr-border)] bg-[var(--rr-card-soft)] text-[var(--rr-text)] font-bold uppercase text-xs tracking-widest">SERVICE EN GARANTIA</button>
      <button type="button" id="btnTipoServiceParticular" class="px-4 py-3 rounded-xl border border-[var(--rr-border)] bg-[var(--rr-card-soft)] text-[var(--rr-text)] font-bold uppercase text-xs tracking-widest">SERVICE PARTICULAR</button>
      <button type="button" id="btnTipoReparacionGarantia" class="px-4 py-3 rounded-xl border border-[var(--rr-border)] bg-[var(--rr-card-soft)] text-[var(--rr-text)] font-bold uppercase text-xs tracking-widest">REPARACION EN GARANTIA</button>
      <button type="button" id="btnTipoReparacionParticular" class="px-4 py-3 rounded-xl border border-[var(--rr-border)] bg-[var(--rr-card-soft)] text-[var(--rr-text)] font-bold uppercase text-xs tracking-widest">REPARACION PARTICULAR</button>
      <button type="button" id="btnTipoToma" class="px-4 py-3 rounded-xl border border-[var(--rr-border)] bg-[var(--rr-card-soft)] text-[var(--rr-text)] font-bold uppercase text-xs tracking-widest md:col-span-2">TOMA</button>
    </div>
  </div>

  <div id="kmBox" class="mt-4">
    <label class="block text-[10px] uppercase tracking-widest text-[#9dc9b2] font-black mb-2">KM</label>
    <input id="km" type="text" inputmode="numeric" pattern="[0-9]*" placeholder="Ej: 45000"
      class="w-full rounded-xl bg-[var(--rr-card-soft)] border border-[var(--rr-border)] px-4 py-3 text-[var(--rr-text)]" />
  </div>

  <div id="descripcionBox" class="mt-4">
    <label class="block text-[10px] uppercase tracking-widest text-[#9dc9b2] font-black mb-2">Descripción</label>
    <input id="detalles" type="text" placeholder="Contanos qué necesita la moto"
      class="w-full rounded-xl bg-[var(--rr-card-soft)] border border-[var(--rr-border)] px-4 py-3 text-[var(--rr-text)]" />
  </div>

  <div class="mt-6 flex flex-wrap gap-3">
    <button id="btnVolver"
      class="border border-[var(--rr-border)] text-[#d7f5e6] font-black tracking-widest uppercase px-6 py-3 rounded-xl bg-[var(--rr-card-soft)]">Volver</button>
    <button id="btnReservar"
      class="bg-[var(--rr-accent)] text-white font-black tracking-widest uppercase px-6 py-3 rounded-xl shadow-lg shadow-[#16b95433] disabled:opacity-50 disabled:cursor-not-allowed"
      disabled>Confirmar Reserva</button>
  </div>
  <div id="formStatus" class="text-xs mt-2"></div>
</div>

<div id="stepConfirmacion" class="hidden rounded-2xl border border-[#234435] bg-gradient-to-b from-[#11251c] to-[#0c1713] p-6 md:p-8 shadow-xl shadow-black/20">
  <div class="max-w-3xl mx-auto">
    <div class="inline-flex items-center gap-2 rounded-full border border-[#2a6449] bg-[#123324] px-3 py-1 text-[11px] font-black uppercase tracking-[0.22em] text-[#9bf7bd] mb-4">
      Reserva confirmada
    </div>
    <h2 class="text-3xl md:text-4xl font-black tracking-tight text-[#ecfff5]">Tu turno quedó confirmado</h2>
    <p class="mt-3 text-[#9dc9b2] max-w-2xl">La reserva ya fue enviada. Podés guardar el comprobante y crear otra si hace falta.</p>

    <div class="mt-6 grid gap-3 sm:grid-cols-2 text-sm">
      <div class="rounded-2xl border border-[#234435] bg-[#0f1d17] px-4 py-3">
        <span class="block text-[#9dc9b2] font-semibold">Cliente</span>
        <span id="confirmacionNombre" class="block text-[#f0fff7] font-black mt-1"></span>
      </div>
      <div class="rounded-2xl border border-[#234435] bg-[#0f1d17] px-4 py-3">
        <span class="block text-[#9dc9b2] font-semibold">Fecha y hora</span>
        <span id="confirmacionFechaHora" class="block text-[#f0fff7] font-black mt-1"></span>
      </div>
      <div class="rounded-2xl border border-[#234435] bg-[#0f1d17] px-4 py-3">
        <span class="block text-[#9dc9b2] font-semibold">Teléfono</span>
        <span id="confirmacionTelefono" class="block text-[#f0fff7] font-black mt-1"></span>
      </div>
      <div class="rounded-2xl border border-[#234435] bg-[#0f1d17] px-4 py-3">
        <span class="block text-[#9dc9b2] font-semibold">Matrícula</span>
        <span id="confirmacionMatricula" class="block text-[#f0fff7] font-black mt-1"></span>
      </div>
    </div>

    <div class="mt-4 rounded-2xl border border-[#234435] bg-[#0f1d17] px-4 py-4">
      <span class="block text-[#9dc9b2] font-semibold text-sm">Estado</span>
      <span id="confirmacionEstado" class="block text-[#f0fff7] font-black mt-1 text-base"></span>
    </div>

    <div class="mt-6 flex flex-wrap gap-3">
      <button id="btnNuevaReserva" type="button" class="border border-[#234435] text-[#d7f5e6] font-black tracking-widest uppercase px-6 py-3 rounded-xl bg-[#16271f]">Nueva reserva</button>
      <a href="<?php echo htmlspecialchars($homeUrl, ENT_QUOTES); ?>" class="bg-[var(--rr-accent)] text-white font-black tracking-widest uppercase px-6 py-3 rounded-xl shadow-lg shadow-[#16b95433]">Volver al inicio</a>
    </div>
  </div>
</div>
    </div>
  </div>
  <a id="btnWhatsapp"
    class="fixed bottom-6 right-6 h-14 w-14 rounded-full bg-[var(--rr-accent)] text-white shadow-xl shadow-[#16b95444] flex items-center justify-center text-xl"
    target="_blank" href="https://wa.me/" aria-label="WhatsApp">
    <svg viewBox="0 0 32 32" class="h-6 w-6 fill-current" aria-hidden="true">
      <path d="M19.11 17.2c-.27-.13-1.62-.8-1.87-.89-.25-.09-.44-.13-.62.13-.18.27-.71.89-.88 1.07-.16.18-.32.2-.6.07-.27-.13-1.15-.43-2.2-1.36-.81-.72-1.36-1.61-1.52-1.88-.16-.27-.02-.41.12-.55.12-.12.27-.32.4-.48.13-.16.18-.27.27-.45.09-.18.04-.33-.02-.46-.07-.13-.62-1.5-.85-2.06-.22-.53-.45-.46-.62-.47l-.53-.01c-.18 0-.46.07-.71.33-.25.27-.93.91-.93 2.22 0 1.31.96 2.58 1.09 2.75.13.18 1.88 2.87 4.56 4.03.64.28 1.14.45 1.53.58.64.2 1.22.17 1.68.1.51-.08 1.62-.66 1.85-1.29.23-.64.23-1.18.16-1.29-.07-.11-.25-.18-.53-.31zM16.02 5.5c-5.77 0-10.46 4.7-10.46 10.46 0 1.85.5 3.65 1.45 5.22L5.5 26.5l5.5-1.43a10.43 10.43 0 0 0 5.02 1.27c5.77 0 10.46-4.7 10.46-10.46S21.8 5.5 16.02 5.5zm0 19.1c-1.64 0-3.24-.44-4.65-1.27l-.33-.2-3.26.85.87-3.18-.21-.33a8.36 8.36 0 0 1-1.32-4.54c0-4.62 3.75-8.37 8.37-8.37 4.62 0 8.37 3.75 8.37 8.37 0 4.62-3.75 8.37-8.37 8.37z"/>
    </svg>
  </a>
    <script>
    const BASE_PATH = <?php echo json_encode($basePath); ?>;
    const API_BASE = (BASE_PATH === '/' ? '' : BASE_PATH);
    const API_ORIGIN = window.location.origin + API_BASE;
    const PAGE_LOADED_AT = Date.now();
    const PDF_LOGO_URL = <?php echo json_encode($logoUrl, JSON_UNESCAPED_SLASHES); ?>;
    const $ = (id) => document.getElementById(id);

    const fecha = $('fecha');
    const calPrev = $('calPrev');
    const calNext = $('calNext');
    const calTitle = $('calTitle');
    const calGrid = $('calGrid');
    const fechaSeleccion = $('fechaSeleccion');
    const horariosEl = $('horarios');
    const horariosStatus = $('horariosStatus');
    const btnReservar = $('btnReservar');
    const btnContinuar = $('btnContinuar');
    const btnVolver = $('btnVolver');
    const stepCalendario = $('stepCalendario');
    const stepFormulario = $('stepFormulario');
    const stepConfirmacion = $('stepConfirmacion');
    const formStatus = $('formStatus');
    const tipoTurno = $('tipo_turno');
    const particularTipo = $('particular_tipo');
    const garantiaTipo = $('garantia_tipo');
    const detalles = $('detalles');
    const cedula = $('cedula');
    const cedulaStatus = $('cedulaStatus');
    const nombreStatus = $('nombreStatus');
    const matricula = $('matricula');
    const marca = $('marca');
    const modelo = $('modelo');
    const telefono = $('telefono');
    const telefonoStatus = $('telefonoStatus');
    const vehiculosClienteBox = $('vehiculosClienteBox');
    const vehiculosClienteStatus = $('vehiculosClienteStatus');
    const vehiculosClienteList = $('vehiculosClienteList');
    const vehiculoSeleccionadoResumen = $('vehiculoSeleccionadoResumen');
    const btnLimpiarVehiculo = $('btnLimpiarVehiculo');
    const btnWhatsapp = $('btnWhatsapp');
    const garantiaNumeroService = $('garantia_numero_service');
    const btnTipoServiceGarantia = $('btnTipoServiceGarantia');
    const btnTipoServiceParticular = $('btnTipoServiceParticular');
    const btnTipoReparacionGarantia = $('btnTipoReparacionGarantia');
    const btnTipoReparacionParticular = $('btnTipoReparacionParticular');
    const btnTipoToma = $('btnTipoToma');
    const kmBox = $('kmBox');
    const descripcionBox = $('descripcionBox');
    const kmInput = $('km');
    const confirmacionNombre = $('confirmacionNombre');
    const confirmacionFechaHora = $('confirmacionFechaHora');
    const confirmacionTelefono = $('confirmacionTelefono');
    const confirmacionMatricula = $('confirmacionMatricula');
    const confirmacionEstado = $('confirmacionEstado');
    const btnNuevaReserva = $('btnNuevaReserva');

    let horaSeleccionada = '';
    let calendarioMes = new Date();
    calendarioMes.setDate(1);
    const availabilityCache = {};
    let cargandoDisponibilidad = false;
    let lastDisponibilidadKey = '';
    let cedulaLookupTimer = null;
    let vehiculosCliente = [];
    let clienteLookup = null;
    let vehiculoSeleccionado = null;

    const monthNames = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
    const MIN_ADVANCE_HOURS = 24;

    function getReservaMinDateTime() {
      const d = new Date();
      d.setHours(d.getHours() + MIN_ADVANCE_HOURS);
      return d;
    }

    function startOfDay(date) {
      const d = new Date(date);
      d.setHours(0, 0, 0, 0);
      return d;
    }

    function getMinCalendarDate() {
      return startOfDay(getReservaMinDateTime());
    }

    function getMaxCalendarDate() {
      const d = getMinCalendarDate();
      d.setDate(d.getDate() + 21);
      return d;
    }

    function formatFechaISO(d) {
      const y = d.getFullYear();
      const m = String(d.getMonth() + 1).padStart(2, '0');
      const day = String(d.getDate()).padStart(2, '0');
      return `${y}-${m}-${day}`;
    }

    function getMinutesFromHora(label) {
      const raw = String(label).trim().toLowerCase();
      const match = raw.match(/(\d{1,2}):(\d{2})\s*(am|pm)?/);
      if (!match) return null;
      let h = Number(match[1]);
      const m = Number(match[2]);
      const ampm = match[3];
      if (ampm) {
        if (ampm === 'pm' && h < 12) h += 12;
        if (ampm === 'am' && h === 12) h = 0;
      }
      return h * 60 + m;
    }

    function formatFechaLarga(d) {
      return `${d.getDate()} de ${monthNames[d.getMonth()]} ${d.getFullYear()}`;
    }

    function esDomingo(d) {
      return d.getDay() === 0;
    }

    function estaEnRango(d) {
      return d >= getMinCalendarDate() && d <= getMaxCalendarDate();
    }

    function isHorarioPermitido(fechaIso, horaLabel) {
      const mins = getMinutesFromHora(horaLabel);
      if (mins === null) return true;
      const [year, month, day] = fechaIso.split('-').map(Number);
      const h = Math.floor(mins / 60);
      const m = mins % 60;
      const reservaDateTime = new Date(year, month - 1, day, h, m, 0, 0);
      return reservaDateTime.getTime() >= getReservaMinDateTime().getTime();
    }

    async function consultarDisponibilidadFecha(iso) {
      try {
        const res = await fetch(`${API_ORIGIN}/api/horarios?fecha=${iso}`);
        const json = await res.json();
        return !!(json && json.ok && Array.isArray(json.data) && json.data.length);
      } catch {
        return false;
      }
    }

    async function cargarDisponibilidadMes(year, month) {
      if (cargandoDisponibilidad) return;
      cargandoDisponibilidad = true;
      try {
        const start = new Date(year, month, 1);
        const end = new Date(year, month + 1, 0);
        for (let day = 1; day <= end.getDate(); day++) {
          const d = new Date(year, month, day);
          if (!estaEnRango(d) || esDomingo(d)) {
            availabilityCache[formatFechaISO(d)] = false;
            continue;
          }
          const iso = formatFechaISO(d);
          if (availabilityCache[iso] !== undefined) continue;
          availabilityCache[iso] = await consultarDisponibilidadFecha(iso);
        }
      } finally {
        cargandoDisponibilidad = false;
        renderCalendar();
      }
    }

    function resetVehiculosCliente(message = 'Escribí la cédula para buscar motos registradas.') {
      vehiculosCliente = [];
      clienteLookup = null;
      vehiculoSeleccionado = null;
      if (vehiculosClienteStatus) {
        vehiculosClienteStatus.textContent = message;
      }
      if (vehiculosClienteList) {
        vehiculosClienteList.innerHTML = '';
      }
      if (vehiculoSeleccionadoResumen) {
        vehiculoSeleccionadoResumen.textContent = '';
      }
    }

    function renderVehiculosCliente() {
      if (!vehiculosClienteList || !vehiculosClienteStatus) return;

      vehiculosClienteList.innerHTML = '';
      const cedulaBusqueda = cedula.value.replace(/\D/g, '');

      if (!cedulaBusqueda) {
        vehiculosClienteStatus.textContent = 'Escribí la cédula para buscar motos registradas.';
        if (vehiculoSeleccionadoResumen) vehiculoSeleccionadoResumen.textContent = '';
        return;
      }

      if (cedulaBusqueda.length < 7) {
        vehiculosClienteStatus.textContent = 'Ingresá una cédula válida para buscar.';
        if (vehiculoSeleccionadoResumen) vehiculoSeleccionadoResumen.textContent = '';
        return;
      }

      if (clienteLookup) {
        vehiculosClienteStatus.textContent = `Cliente encontrado: ${clienteLookup.nombre}${clienteLookup.telefono ? ` · ${clienteLookup.telefono}` : ''}`;
      } else {
        vehiculosClienteStatus.textContent = 'No hay cliente registrado con esa cédula. Podés cargar una nueva reserva manualmente.';
      }

      if (!vehiculosCliente.length) {
        const empty = document.createElement('div');
        empty.className = 'rounded-xl border border-[#2a6449] bg-[#16271f] px-4 py-3 text-sm text-[#9dc9b2]';
        empty.textContent = 'No hay motos registradas para esta cédula.';
        vehiculosClienteList.appendChild(empty);
        if (vehiculoSeleccionadoResumen) vehiculoSeleccionadoResumen.textContent = '';
        return;
      }

      vehiculosCliente.forEach((vehiculo) => {
        const selected = vehiculoSeleccionado && String(vehiculoSeleccionado.id) === String(vehiculo.id);
        const button = document.createElement('button');
        button.type = 'button';
        button.className = selected
          ? 'text-left rounded-xl border border-[#16b954] bg-[#123324] px-4 py-3 transition-colors'
          : 'text-left rounded-xl border border-[#2a6449] bg-[#16271f] px-4 py-3 hover:bg-[#1c3328] transition-colors';
        button.innerHTML = `
          <div class="flex items-center justify-between gap-2">
            <div class="text-sm font-black text-[#f0fff7]">${vehiculo.matricula || 'Sin matrícula'}</div>
            <div class="text-[10px] font-black uppercase tracking-[0.22em] text-[#74f3a5]">${vehiculo.dt_vehiculo_codigo || 'Sin código'}</div>
          </div>
          <div class="mt-1 text-xs text-[#b1d8c4]">${vehiculo.marca || ''}${vehiculo.color ? ` · ${vehiculo.color}` : ''}</div>
        `;
        button.addEventListener('click', () => {
          vehiculoSeleccionado = vehiculo;
          marca.value = vehiculo.marca || marca.value;
          if (vehiculo.telefono) {
            telefono.value = normalizarTelefonoUy(String(vehiculo.telefono));
          } else if (clienteLookup?.telefono) {
            telefono.value = normalizarTelefonoUy(String(clienteLookup.telefono));
          }
          if (clienteLookup?.nombre && !nombre.value.trim()) {
            nombre.value = String(clienteLookup.nombre);
          }
          matricula.value = normalizarMatricula(String(vehiculo.matricula || 'WEBCLIENTE')) || 'WEBCLIENTE';
          if (vehiculoSeleccionadoResumen) {
            vehiculoSeleccionadoResumen.textContent = `Seleccionada: ${vehiculo.matricula || 'Sin matrícula'} · ${vehiculo.marca || ''}`.trim();
          }
          renderVehiculosCliente();
          validarForm();
        });
        vehiculosClienteList.appendChild(button);
      });
    }

    async function cargarVehiculosCliente() {
      const cedulaNormalizada = cedula.value.replace(/\D/g, '');
      if (cedulaNormalizada.length < 7) {
        resetVehiculosCliente(cedula.value.trim() ? 'Ingresá una cédula válida para buscar.' : 'Escribí la cédula para buscar motos registradas.');
        return;
      }

      if (vehiculosClienteStatus) {
        vehiculosClienteStatus.textContent = 'Buscando motos registradas...';
      }

      try {
        const res = await fetch(`${API_ORIGIN}/api/vehiculos/por-cedula?cedula=${encodeURIComponent(cedulaNormalizada)}`);
        const json = await res.json();
        if (!json.ok) {
          throw new Error(json.error || 'No se pudo buscar la cédula');
        }
        clienteLookup = json.data?.cliente || null;
        vehiculosCliente = Array.isArray(json.data?.vehiculos) ? json.data.vehiculos : [];
        if (clienteLookup?.telefono && !telefono.value.trim()) {
          telefono.value = normalizarTelefonoUy(String(clienteLookup.telefono));
        }
        if (clienteLookup?.nombre && !nombre.value.trim()) {
          nombre.value = String(clienteLookup.nombre);
        }
        vehiculoSeleccionado = null;
        matricula.value = 'WEBCLIENTE';
        renderVehiculosCliente();
        validarForm();
      } catch (error) {
        console.error('[Turnos] Error buscando vehiculos por cedula:', error);
        resetVehiculosCliente('No se pudo consultar la cédula. Podés continuar cargando la reserva manualmente.');
      }
    }

    function programarBusquedaVehiculosCliente() {
      if (cedulaLookupTimer) {
        clearTimeout(cedulaLookupTimer);
      }
      cedulaLookupTimer = setTimeout(() => {
        cargarVehiculosCliente();
      }, 350);
    }

    function renderCalendar() {
      const year = calendarioMes.getFullYear();
      const month = calendarioMes.getMonth();
      calTitle.textContent = `${monthNames[month]} ${year}`;
      calGrid.innerHTML = '';
      const first = new Date(year, month, 1);
      const startDay = first.getDay();
      const daysInMonth = new Date(year, month + 1, 0).getDate();

      for (let i = 0; i < startDay; i++) {
        const empty = document.createElement('div');
        empty.className = 'h-8 w-8 sm:h-9 sm:w-9';
        calGrid.appendChild(empty);
      }

      for (let day = 1; day <= daysInMonth; day++) {
        const d = new Date(year, month, day);
        const iso = formatFechaISO(d);
        const isDisabled = esDomingo(d) || !estaEnRango(d) || availabilityCache[iso] === false;
        const isSelected = fecha.value === iso;
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.textContent = day;
        btn.className = `h-8 w-8 sm:h-9 sm:w-9 rounded-full text-[11px] sm:text-sm font-bold ${
          isSelected
            ? 'bg-[#16b954] text-white'
            : 'bg-[#16271f] text-[#d7f5e6] border border-[#2a6449] hover:border-[#16b954]'
        }`;
        if (isDisabled) {
          btn.className = 'h-8 w-8 sm:h-9 sm:w-9 rounded-full text-[11px] sm:text-sm font-bold text-slate-600 bg-transparent border border-transparent cursor-not-allowed';
          btn.disabled = true;
        } else {
          btn.onclick = () => {
            fecha.value = iso;
            fechaSeleccion.textContent = `Fecha seleccionada: ${formatFechaLarga(d)}`;
            renderCalendar();
            fetchHorarios();
          };
        }
        calGrid.appendChild(btn);
      }

      const key = `${year}-${month}`;
      if (lastDisponibilidadKey !== key) {
        lastDisponibilidadKey = key;
        cargarDisponibilidadMes(year, month);
      }
    }

    function setStatus(el, text, ok = true) {
      el.textContent = text;
      el.className = ok ? 'text-xs mt-1 text-[#7ef0a9]' : 'text-xs mt-1 text-rose-400';
    }

    async function fetchHorarios() {
      horariosEl.innerHTML = '';
      horaSeleccionada = '';
      btnReservar.disabled = true;
      if (!fecha.value) {
        horariosStatus.textContent = '';
        return;
      }
      horariosStatus.textContent = 'Cargando...';
      try {
        const res = await fetch(`${API_ORIGIN}/api/horarios?fecha=${fecha.value}`);
        const json = await res.json();
        if (!res.ok && res.status === 401) {
          setStatus(horariosStatus, 'Token invalido o faltante', false);
          return;
        }
        if (!json.ok) throw new Error(json.error || 'Error');
        let data = json.data || [];
        data = data.filter(h => isHorarioPermitido(fecha.value, h.hora));
        horariosStatus.textContent = data.length ? '' : 'Sin horarios disponibles';
        data.forEach(h => {
          const btn = document.createElement('button');
          btn.className = 'w-full text-left px-4 py-2 rounded-lg border border-[#2a6449] bg-[#16271f] text-[#d7f5e6] text-sm font-bold hover:border-[#16b954]';
          btn.textContent = h.hora;
          btn.onclick = () => {
            horaSeleccionada = h.hora;
            document.querySelectorAll('#horarios button').forEach(b => b.classList.remove('border-[#16b954]', 'text-[#9bf7bd]', 'bg-[#123324]'));
            btn.classList.add('border-[#16b954]', 'text-[#9bf7bd]', 'bg-[#123324]');
            validarForm();
          };
          horariosEl.appendChild(btn);
        });
      } catch (e) {
        setStatus(horariosStatus, 'Error al cargar horarios', false);
      }
    }

    function validarCedulaUY(value) {
      const digits = value.replace(/\D/g, '');
      if (digits.length < 7 || digits.length > 8) return false;
      const padded = digits.padStart(8, '0').split('').map(d => parseInt(d, 10));
      const weights = [2, 9, 8, 7, 6, 3, 4];
      let sum = 0;
      for (let i = 0; i < 7; i++) sum += padded[i] * weights[i];
      const check = (10 - (sum % 10)) % 10;
      return check === padded[7];
    }

    function validarNombreCompleto(value) {
      return value.trim().length >= 3;
    }

    function normalizarTelefonoUy(value) {
      const digits = value.replace(/\D/g, '');
      if (digits.length === 0) {
        return { formatted: '', local: '' };
      }
      return { formatted: digits, local: digits };
    }

    function telefonoValido(value) {
      return /^\d{8,9}$/.test(value.trim());
    }

    function normalizarMatricula(value) {
      return value.toUpperCase().replace(/[^A-Z0-9]/g, '');
    }

    function setActive(btn, group) {
      group.forEach(b => b.classList.remove('border-[#16b954]', 'bg-[#123324]', 'text-[#9bf7bd]'));
      btn.classList.add('border-[#16b954]', 'bg-[#123324]', 'text-[#9bf7bd]');
    }

    function validarForm() {
      const required = [$('nombre').value, cedula.value, telefono.value, marca.value, modelo.value, fecha.value, horaSeleccionada, tipoTurno.value];
      const okBase = required.every(v => String(v).trim() !== '');

      const ciOk = validarCedulaUY(cedula.value);
      if (!ciOk) {
        setStatus(cedulaStatus, 'Cedula invalida', false);
      } else {
        cedulaStatus.textContent = '';
      }

      const nombreOk = validarNombreCompleto($('nombre').value);
      if (!nombreOk) {
        setStatus(nombreStatus, 'Ingresa un nombre válido', false);
      } else {
        nombreStatus.textContent = '';
      }

      const telOk = telefonoValido(telefono.value);
      if (!telOk) {
        setStatus(telefonoStatus, 'Formato valido: 8 o 9 digitos', false);
      } else {
        telefonoStatus.textContent = '';
      }

      const esToma = tipoTurno.value === 'Toma';
      const kmOk = esToma ? true : /^\d+$/.test(kmInput.value.trim());
      const descripcionOk = esToma ? true : detalles.value.trim() !== '';

      btnReservar.disabled = !(okBase && ciOk && nombreOk && telOk && kmOk && descripcionOk);
      btnContinuar.disabled = !fecha.value || !horaSeleccionada;
    }

    function aplicarTipoServicio(config, activeButton) {
      tipoTurno.value = config.tipo_turno;
      particularTipo.value = config.particular_tipo || '';
      garantiaTipo.value = config.garantia_tipo || '';
      garantiaNumeroService.value = config.garantia_numero_service || '';

      const esToma = config.tipo_turno === 'Toma';
      kmBox.classList.toggle('hidden', esToma);
      descripcionBox.classList.toggle('hidden', esToma);
      kmInput.disabled = esToma;
      detalles.disabled = esToma;
      if (esToma) {
        kmInput.value = '';
        detalles.value = '';
        resetVehiculosCliente('Las motos registradas no se usan en toma.')
      }

      setActive(activeButton, [
        btnTipoServiceGarantia,
        btnTipoServiceParticular,
        btnTipoReparacionGarantia,
        btnTipoReparacionParticular,
        btnTipoToma
      ]);
      validarForm();
    }

    function loadWhiteLogoDataUrl(url) {
      return new Promise((resolve) => {
        const img = new Image();
        img.crossOrigin = 'anonymous';
        img.onload = () => {
          const canvas = document.createElement('canvas');
          const size = 220;
          canvas.width = size;
          canvas.height = size;
          const ctx = canvas.getContext('2d');
          if (!ctx) {
            resolve(null);
            return;
          }
          ctx.clearRect(0, 0, size, size);
          ctx.drawImage(img, 0, 0, size, size);
          ctx.globalCompositeOperation = 'source-in';
          ctx.fillStyle = '#FFFFFF';
          ctx.fillRect(0, 0, size, size);
          resolve(canvas.toDataURL('image/png'));
        };
        img.onerror = () => resolve(null);
        img.src = url;
      });
    }

    async function generarPdfReserva(payload) {
      if (!window.jspdf) return;
      const { jsPDF } = window.jspdf;
      const doc = new jsPDF({ unit: 'mm', format: 'a4' });
      const pageWidth = doc.internal.pageSize.getWidth();
      const margin = 14;
      const contentWidth = pageWidth - (margin * 2);

      const colors = {
        greenDark: [5, 120, 90],
        greenSoft: [236, 253, 245],
        text: [0, 0, 0],
        muted: [0, 0, 0]
      };

      const drawHeader = async () => {
        const whiteLogo = await loadWhiteLogoDataUrl(PDF_LOGO_URL);

        doc.setFillColor(...colors.greenDark);
        doc.rect(0, 0, pageWidth, 42, 'F');
        if (whiteLogo) {
          doc.addImage(whiteLogo, 'PNG', 14, 8, 16, 16);
        }

        doc.setTextColor(255, 255, 255);
        doc.setFont('helvetica', 'bold');
        doc.setFontSize(16);
        doc.text('Taller Rosas', 36, 18);
        doc.setFontSize(11);
        doc.setFont('helvetica', 'normal');
        doc.text('Comprobante de Reserva', 36, 26);
      };

      const drawCard = (x, y, w, h, label, value) => {
        doc.setFillColor(...colors.greenSoft);
        doc.setDrawColor(209, 250, 229);
        doc.roundedRect(x, y, w, h, 2, 2, 'FD');
        doc.setTextColor(...colors.muted);
        doc.setFont('helvetica', 'bold');
        doc.setFontSize(8);
        doc.text(label.toUpperCase(), x + 3, y + 5);
        doc.setTextColor(...colors.text);
        doc.setFont('helvetica', 'normal');
        doc.setFontSize(10);
        doc.text(String(value || '-'), x + 3, y + 10);
      };

      await drawHeader();

      let y = 50;
      const gap = 4;
      const colWidth = (contentWidth - gap) / 2;
      const cardH = 14;

      drawCard(margin, y, colWidth, cardH, 'Cliente', payload.nombre);
      drawCard(margin + colWidth + gap, y, colWidth, cardH, 'Telefono', payload.telefono);
      y += cardH + gap;

      drawCard(margin, y, colWidth, cardH, 'Fecha', payload.fecha);
      drawCard(margin + colWidth + gap, y, colWidth, cardH, 'Hora', payload.hora);
      y += cardH + gap;

      drawCard(margin, y, colWidth, cardH, 'Cédula', payload.cedula);
      drawCard(margin + colWidth + gap, y, colWidth, cardH, 'Vehículo', payload.marca);
      y += cardH + gap;

      if (payload.km) {
        drawCard(margin, y, colWidth, cardH, 'KM', payload.km);
      }
      drawCard(margin + colWidth + gap, y, colWidth, cardH, 'Turno', payload.tipo_label || payload.tipo_turno);
      y += cardH + gap;

      if (payload.tipo_turno === 'Garantia') {
        drawCard(margin, y, colWidth, cardH, 'Garantía', payload.garantia_tipo || '-');
        if (payload.garantia_numero_service) {
          drawCard(margin + colWidth + gap, y, colWidth, cardH, 'Número de service', payload.garantia_numero_service);
          y += cardH + gap;
        } else if (payload.garantia_problema) {
          drawCard(margin + colWidth + gap, y, colWidth, cardH, 'Motivo', 'Reparación');
          y += cardH + gap;
        } else {
          y += cardH + gap;
        }
      }

      if (payload.descripcion || payload.garantia_problema || payload.detalles) {
        const detailText = payload.descripcion || payload.garantia_problema || payload.detalles;
        doc.setFillColor(248, 250, 252);
        doc.setDrawColor(226, 232, 240);
        doc.roundedRect(margin, y, contentWidth, 20, 2, 2, 'FD');
        doc.setTextColor(...colors.muted);
        doc.setFont('helvetica', 'bold');
        doc.setFontSize(8);
        doc.text('DETALLE', margin + 3, y + 5);
        doc.setTextColor(...colors.text);
        doc.setFont('helvetica', 'normal');
        doc.setFontSize(10);
        const wrapped = doc.splitTextToSize(String(detailText), contentWidth - 6);
        doc.text(wrapped, margin + 3, y + 10);
        y += 24;
      }

      doc.setTextColor(...colors.muted);
      doc.setFont('helvetica', 'italic');
      doc.setFontSize(8);
      doc.text('Gracias por confiar en Taller Rosas', margin, 286);
      doc.text(`Generado: ${new Date().toLocaleString()}`, pageWidth - margin, 286, { align: 'right' });

      const file = `reserva-${payload.fecha}-${payload.cedula || 'cliente'}.pdf`;
      doc.save(file.replace(/\s+/g, ''));
    }

    async function enviarReserva() {
      formStatus.textContent = 'Enviando...';
      const cedulaNormalizada = cedula.value.replace(/\D/g, '');
      const matriculaBase = vehiculoSeleccionado?.matricula || (matricula.value && matricula.value !== 'WEBCLIENTE' ? matricula.value : (cedulaNormalizada ? `CLI${cedulaNormalizada}` : 'WEBCLIENTE'));
      const matriculaVirtual = normalizarMatricula(String(matriculaBase)).slice(0, 10) || 'WEBCLIENTE';
      const esReparacionGarantia = tipoTurno.value === 'Garantia' && garantiaTipo.value === 'Reparacion';
      const esToma = tipoTurno.value === 'Toma';

      const payload = {
        nombre: $('nombre').value.trim(),
        cedula: cedula.value.trim(),
        telefono: normalizarTelefonoUy(telefono.value).local,
        marca: marca.value.trim(),
        modelo: modelo.value.trim(),
        km: kmInput.disabled ? '' : kmInput.value.replace(/\D/g, '').trim(),
        matricula: normalizarMatricula(matriculaVirtual),
        tipo_turno: tipoTurno.value,
        particular_tipo: tipoTurno.value === 'Particular' ? particularTipo.value : null,
        garantia_tipo: tipoTurno.value === 'Garantia' ? garantiaTipo.value : null,
        garantia_numero_service: tipoTurno.value === 'Garantia' ? garantiaNumeroService.value : null,
        garantia_problema: esReparacionGarantia ? detalles.value.trim() : null,
        fecha: fecha.value,
        hora: horaSeleccionada,
        detalles: esToma ? '' : detalles.value.trim(),
        descripcion: detalles.value.trim(),
        tipo_label: (
          tipoTurno.value === 'Garantia' && garantiaTipo.value === 'Service' ? 'SERVICE EN GARANTIA'
            : tipoTurno.value === 'Particular' && particularTipo.value === 'Service' ? 'SERVICE PARTICULAR'
            : tipoTurno.value === 'Garantia' && garantiaTipo.value === 'Reparacion' ? 'REPARACION EN GARANTIA'
            : tipoTurno.value === 'Particular' && particularTipo.value === 'Taller' ? 'REPARACION PARTICULAR'
            : 'TOMA'
        ),
        website: ($('website')?.value || '').trim(),
        client_elapsed_ms: Math.max(0, Date.now() - PAGE_LOADED_AT)
      };

      try {
        const res = await fetch(`${API_ORIGIN}/api/reservas`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload)
        });
        const json = await res.json();
        if (!json.ok) throw new Error(json.error || 'Error');
        setStatus(formStatus, 'Reserva creada con éxito', true);
        try {
          await generarPdfReserva(payload);
        } catch (pdfError) {
          console.error('No se pudo generar el comprobante:', pdfError);
        }
        confirmacionNombre.textContent = payload.nombre || '-';
        confirmacionFechaHora.textContent = `${payload.fecha || '-'} · ${payload.hora || '-'}`;
        confirmacionTelefono.textContent = payload.telefono || '-';
        confirmacionMatricula.textContent = payload.matricula || '-';
        confirmacionEstado.textContent = 'Reserva guardada correctamente. Ya podés cerrar esta pantalla o crear otra.';
        stepCalendario.classList.add('hidden');
        stepFormulario.classList.add('hidden');
        stepConfirmacion.classList.remove('hidden');
        window.scrollTo({ top: 0, behavior: 'smooth' });
        fetchHorarios();
      } catch (e) {
        setStatus(formStatus, e.message || 'Error al crear reserva', false);
      }
    }

    async function setFechaInicial() {
      const maxDate = getMaxCalendarDate();
      let d = getMinCalendarDate();
      while (d <= maxDate) {
        if (!esDomingo(d)) {
          const iso = formatFechaISO(d);
          if (availabilityCache[iso] === undefined) {
            availabilityCache[iso] = await consultarDisponibilidadFecha(iso);
          }
          if (availabilityCache[iso]) {
            break;
          }
        }
        d.setDate(d.getDate() + 1);
      }
      if (d > maxDate) {
        d = getMinCalendarDate();
      }
      fecha.value = formatFechaISO(d);
      fechaSeleccion.textContent = `Fecha seleccionada: ${formatFechaLarga(d)}`;
      calendarioMes = new Date(d.getFullYear(), d.getMonth(), 1);
      renderCalendar();
      await fetchHorarios();
    }

    calPrev.addEventListener('click', () => {
      calendarioMes.setMonth(calendarioMes.getMonth() - 1);
      renderCalendar();
    });
    calNext.addEventListener('click', () => {
      calendarioMes.setMonth(calendarioMes.getMonth() + 1);
      renderCalendar();
    });

    setFechaInicial();

    ['nombre', 'marca', 'modelo', 'km', 'detalles'].forEach(id => {
      $(id).addEventListener('input', validarForm);
    });
    kmInput.addEventListener('input', () => {
      kmInput.value = kmInput.value.replace(/\D/g, '');
      validarForm();
    });
    cedula.addEventListener('input', () => {
      validarForm();
      programarBusquedaVehiculosCliente();
    });

    telefono.addEventListener('input', () => {
      const { formatted } = normalizarTelefonoUy(telefono.value);
      telefono.value = formatted;
      const t = telefono.value.replace(/\D/g, '');
      btnWhatsapp.href = t ? `https://wa.me/${t}` : 'https://wa.me/';
      validarForm();
    });

    btnTipoServiceGarantia.addEventListener('click', () => {
      aplicarTipoServicio({
        tipo_turno: 'Garantia',
        garantia_tipo: 'Service',
        garantia_numero_service: '1'
      }, btnTipoServiceGarantia);
    });
    btnTipoServiceParticular.addEventListener('click', () => {
      aplicarTipoServicio({
        tipo_turno: 'Particular',
        particular_tipo: 'Service'
      }, btnTipoServiceParticular);
    });
    btnTipoReparacionGarantia.addEventListener('click', () => {
      aplicarTipoServicio({
        tipo_turno: 'Garantia',
        garantia_tipo: 'Reparacion'
      }, btnTipoReparacionGarantia);
    });
    btnTipoReparacionParticular.addEventListener('click', () => {
      aplicarTipoServicio({
        tipo_turno: 'Particular',
        particular_tipo: 'Taller'
      }, btnTipoReparacionParticular);
    });
    btnTipoToma.addEventListener('click', () => {
      aplicarTipoServicio({
        tipo_turno: 'Toma'
      }, btnTipoToma);
    });

    btnReservar.addEventListener('click', enviarReserva);
    btnContinuar.addEventListener('click', () => {
      if (btnContinuar.disabled) return;
      stepCalendario.classList.add('hidden');
      stepFormulario.classList.remove('hidden');
    });
    btnVolver.addEventListener('click', () => {
      stepFormulario.classList.add('hidden');
      stepCalendario.classList.remove('hidden');
    });

    btnNuevaReserva.addEventListener('click', () => {
      stepConfirmacion.classList.add('hidden');
      stepCalendario.classList.remove('hidden');
      stepFormulario.classList.add('hidden');
      formStatus.textContent = '';
      window.scrollTo({ top: 0, behavior: 'smooth' });
    });

    btnLimpiarVehiculo.addEventListener('click', () => {
      resetVehiculosCliente();
      validarForm();
    });

    btnWhatsapp.href = 'https://wa.me/59894860496';
    btnTipoServiceParticular.click();
    resetVehiculosCliente();
  </script>
</body>

</html>