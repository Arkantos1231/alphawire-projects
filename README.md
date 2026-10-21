# AlphaWire Projects

Plugin de WordPress para la entidad "Project" de AlphaWire. Plan completo
(recap de docs + sitio + roadmap) en el artifact publicado en la
conversación.

## Estado: v0.8.5 — Fases 0-4 completas, más CSV import, auto-update desde
## GitHub, y un rediseño del Directory/Trending a paridad con el prototipo
## de Lovable

### Fase 0 (v0.1.0)
- CPT `project`, URL permanente `/projects/{slug}/`, archivo en `/projects/`.
- Reusa `pillar` (Categories) y `topic` (Narratives) — no crea taxonomías nuevas.
- Siembra el término "Interviews" en `category` al activar.
- Campos ACF: identidad, links, launch date, trending order, editor's pick,
  related projects, y el bloque de AI Summary (3 estados).
- Servicio de mercado contra el endpoint público y gratis de CoinGecko, con
  caché de 15 min + fallback "último valor bueno" + refresh en background
  (probamos 5 min en v0.9.2; producto pidió volver a 15 min en v0.9.3).
- `GET /alphawire-projects/v1/projects/{slug}`.

### Fase 1 (v0.2.0)
- **Relaciones de contenido**: un campo `related_project` en News, Podcasts
  y Posts (no en Project) — el editor elige el proyecto al publicar. Sin
  contenido duplicado, tal como pide el BE spec.
- **`last_activity_at` automático**: se actualiza solo al guardar el
  Project o al publicar/editar contenido relacionado — cero trabajo
  editorial extra para "Recently Updated".
- **Timeline** del proyecto (repeater ACF: fecha, título, descripción).
- **Endpoints de Directory**:
  - `GET /projects` (con `search`, `category`, `narrative`, `page`, `per_page`)
  - `GET /projects/trending` (orden editorial vía `trending_order`, no algoritmo)
  - `GET /projects/recently-launched` (por `launch_date`)
  - `GET /projects/recently-updated` (por `last_activity_at`)
  - `GET /projects/editors-picks`
  - `GET /categories` — términos de `pillar` contados solo contra Projects
  - `GET /narratives` — términos de `topic` en uso en al menos un Project
- El endpoint de un proyecto individual ahora incluye `timeline`,
  `relatedProjects` y `coverage` (contenido real enlazado, no copiado).
- Las tarjetas de listado usan `get_cached_market_data()` (solo lectura de
  caché) en vez de `get_market_data()`, para no disparar N llamadas en vivo
  a CoinGecko al renderizar un directorio con varios proyectos.

### Fase 2, parcial (v0.3.0) — Generación real del AI Summary

- **Projects → Settings** (página en wp-admin): API key de Claude/Anthropic
  (campo tipo password, nunca se muestra el valor guardado — dejar en
  blanco al guardar significa "no cambiar") y modelo (texto libre, default
  `claude-sonnet-5`; también soporta `claude-haiku-4-5-20251001` y
  `claude-opus-5-5`). **Nota:** este era originalmente OpenAI — cambiado a
  Claude a pedido de producto, ver la sección al final del documento.
  Ninguno de los dos se expone por REST ni en el front end.
- **Generación bajo demanda**: botón "Generate / refresh draft" en el
  meta box del edit screen de cada Project. Arma el prompt SOLO con datos
  editoriales del propio Project (descripción, categorías, timeline,
  coverage ya publicada) — nunca inventa datos. El resultado siempre
  queda en estado **Pending Review**, igual que Market Summaries: nunca
  se auto-aprueba ni se muestra en el front end hasta que un editor lo
  aprueba manualmente.
- **Job semanal en background** (`alphawire_projects_generate_missing_summaries`,
  Action Scheduler si está disponible, si no WP-Cron): rellena solo los
  Projects que todavía no tienen texto de AI Summary — nunca toca uno que
  ya tiene contenido, sea borrador o aprobado.
- La llamada a Claude ocurre solo en background o en una acción de
  wp-admin autenticada (`admin-post.php` + nonce) — nunca al renderizar
  una página para un visitante.

### v0.3.1 – v0.3.3 — Fix de enrutamiento

`/projects/` y `/projects/{slug}/` estaban siendo capturados por una regla
de reescritura ajena al plugin (el filtro de categoría/pilar de la página
"News", que matchea cualquier URL de dos segmentos). Se resolvió
registrando las reglas propias del plugin en el filtro `rewrite_rules_array`
con prioridad `PHP_INT_MAX` — la última etapa posible antes de que
WordPress guarde las reglas — con un flush autoreparable (compara un
número de versión guardado en opciones) para que un sitio ya activo lo
recoja solo, sin desactivar/reactivar. Ver `class-post-type.php`.

### Fases 3-4 (v0.4.0) — Templates de Directory y Project Profile

- **`/projects/` (Directory)**: buscador (nombre, ticker, categoría o
  narrativa) + filtros por Category/Narrative vía querystring — misma
  lógica de `WP_Query` que usa el endpoint REST `/projects`, sin loopback
  HTTP. Sin filtros activos muestra: Trending, Top Categories, Trending
  Narratives, Recently Launched, Recently Updated, Editor's Picks y la
  grilla paginada de todos los Projects. Con filtros activos muestra solo
  los resultados.
- **`/projects/{slug}/` (Project Profile)**: reusa
  `AlphaWire_Projects_REST::build_payload()` — el mismo contrato de datos
  que devuelve la API — así la página y el endpoint REST nunca pueden
  desincronizarse. Header (logo/inicial, nombre, verificado, ticker,
  categorías, narrativas, links), Key Stats con sparkline inline en SVG
  (sin librería de gráficos), AI Project Summary (solo texto si está
  aprobado), Timeline, cobertura de AlphaWire agrupada por tipo
  (News/Podcast/Research/Interviews), y Related Projects.
- Templates PHP planas en el plugin (`templates/archive-project.php`,
  `templates/single-project.php`), enganchadas vía
  `single_template`/`archive_template` — no un template de Elementor — así
  que `get_header()`/`get_footer()` siguen trayendo el nav, la barra de
  precios y el footer reales del sitio, y el diseño vive versionado en el
  plugin en vez de en la base de datos.
- Paleta y tipografía tomadas de los estilos computados reales del sitio
  (fondo `#1a1a1a`, tarjetas `#1d2327`, acento lima `#c8f323`, verde
  `#10ac84`/rojo `#ef877f` para precios) en `assets/css/projects.css`.

### v0.5.0 — Importador CSV

**Projects → Import**: crear/actualizar muchos Projects de una sola vez
desde un CSV. Matchea Projects existentes primero por ticker y después por
nombre, así que volver a correr el mismo archivo actualiza en vez de
duplicar. Nunca crea un término nuevo de Pillar/Narrative por su cuenta —
una columna de categoría/narrativa en el CSV solo aplica si ese término ya
existe en el sitio. Ver `includes/class-csv-importer.php`.

### v0.6.0 — Auto-actualización desde GitHub

El plugin se actualiza solo, sin pasar por WordPress.org. **Projects →
Settings** tiene dos campos nuevos: repo de GitHub (`owner/repo`) y branch
a seguir. Una vez configurados, WordPress compara el header `Version:` de
`alphawire-projects.php` en esa branch contra la versión instalada y, si
está más adelante, lo ofrece como una actualización normal en la página de
Plugins — cada push a la branch seguida es efectivamente un release, sin
necesidad de crear un GitHub Release formal. Sin dependencia de terceros
(no había forma de instalar una librería de updater desde este entorno) —
una clase propia, mismo estilo sin dependencias que las integraciones de
CoinGecko/Claude. Ver `includes/class-updater.php`.

**v0.7.8** agregó un link **"Check for updates"** en la fila del plugin en
la página de Plugins (al lado de "Deactivate"). Antes de esto, la única
forma de que WordPress notara un push reciente antes de que expirara el
caché propio de 6 horas era el "Check again" de Dashboard → Updates, que
re-chequea todos los plugins del sitio y no es un lugar obvio para buscar
"¿ya me tomó el último push?". El link nuevo limpia el caché de este
plugin en particular y el transient de WordPress, fuerza un chequeo real
en la próxima carga del admin, y muestra un aviso con el resultado.

> **Nota de bootstrapping**: como el botón vive en el código de la propia
> actualización que lo introduce (v0.7.8), la primera vez que un sitio
> pasa de una versión anterior todavía necesita el método viejo (Check
> again en Dashboard → Updates, o esperar el caché). De ahí en adelante
> ya se puede usar el botón nuevo para cualquier push futuro.

### v0.6.1 — Filtro de Trending Narratives

`topic` también contiene términos de tipo entidad (Tether, Circle, Ripple,
Polymarket, Kalshi…) que el plan original marcaba como "no son narrativas,
hay que filtrarlas" pero el primer corte del Directory nunca filtraba en
la práctica. **Projects → Settings → Directory — Narratives** tiene ahora
una lista editable de exclusión (sembrada con esos cinco) para que un
término de tipo entidad nunca aparezca como Trending Narrative, sin
importar a cuántos Projects esté etiquetado. Top Categories queda igual —
`pillar` no tiene ese problema.

### v0.7.0 — Paridad de layout con el prototipo de Lovable + Collections
### (removido en v0.8.0, ver más abajo)

El Directory (`/projects/`) se rediseñó para acercarse al prototipo de
Lovable: sidebar izquierdo (nav Explore + Categories), buscador movido al
header, strip numerado de Trending Projects con volumen 24h real de
CoinGecko, y un panel de Top Categories/Trending Narratives con el % de
cambio promedio real (no mockeado). También se sumó en esta versión
**Collections** — favoritos con nombre propio, múltiples por usuario —
que el plan original excluía explícitamente de la Fase 3 pero se agregó a
pedido en su momento. Producto decidió en v0.8.0 que no se iba a usar y
se eliminó por completo (ver esa sección).

### v0.7.1 – v0.7.7 — Pulido visual del Directory/Trending, a pura CSS/JS

Serie de ajustes, todos verificados en vivo contra el sitio antes de
enviarse (inyectando el CSS/JS candidato en el navegador real y
comparando con capturas) porque varios de estos bugs solo aparecían con
datos reales, no en el diseño estático:

- **Overflow en la tarjeta del grid** (v0.7.1, rematado en v0.7.3): la
  estrella se superponía al precio, y la tagline corría por debajo del
  precio en vez de truncarse — un `<span>` es `display:inline` por
  defecto, y `overflow`/`text-overflow:ellipsis` no hacen nada sobre un
  elemento inline sin `display:block` explícito.
- **Regresión de rewrite rules** (v0.7.2): el mecanismo de prioridad de
  v0.3.x seguía intacto, pero el auto-flush solo comparaba un número de
  versión — prueba de que se *pidió* un flush, no de que haya quedado
  realmente persistido. `maybe_flush_rewrite_rules()` ahora también
  revisa si las reglas propias siguen presentes en la opción
  `rewrite_rules` en cada request, y si no, vuelve a flushear sin
  importar si el número de versión ya coincidía.
- **Trending Projects rediseñado** (v0.7.4): columna centrada (ícono,
  nombre, ticker, descripción), línea divisoria antes del footer,
  indicador ▲/▼ junto al % de cambio, estrella movida de la esquina
  superior a la inferior derecha.
- **Estrella de favoritos** (v0.7.5 – v0.7.6): el ★/☆ de texto se
  reemplazó por un ícono SVG de contorno (sin fondo ni borde). En el
  camino aparecieron dos reglas globales del tema que pisaban el
  ícono/botón — un reset de dark-mode que fuerza `fill`/`stroke` de
  cualquier SVG a blanco, y un reset de `[type="button"]` que le pone
  borde rosa y padding grande a cualquier botón — ambas con la misma
  especificidad que un `.aw-save-btn` simple, ganando el empate por orden
  de carga. Se resolvió calificando las reglas propias con el wrapper
  `.aw-projects`, que gana en especificidad sin depender del orden.
- **Filas sin línea divisoria + ícono del proyecto** (v0.7.7): Top
  Categories/Trending Narratives/Recently Launched/Recently Updated ya no
  tienen `border-bottom` entre filas (separación solo por espaciado), y
  Recently Launched/Updated muestran el logo o inicial de cada Project al
  lado del nombre, igual que el grid y el Trending strip.

### v0.8.0 — Se eliminó el feature de Collections

Decisión de producto: Collections (favoritos con nombre propio,
múltiples por usuario, ver v0.7.0 más arriba) no se va a usar. Como la
estrella de "guardar" en cada tarjeta no tenía otro propósito que "guardar
en una colección", se eliminó junto con Collections — no queda ningún
favorito/bookmark suelto en su lugar. Se borraron
`includes/class-collections.php` y `templates/collections.php`, la ruta
`/projects/collections/` y su query var `aw_projects_view`
(`REWRITE_VERSION` subió a 7 para que el sitio en vivo limpie la regla
vieja de la opción `rewrite_rules` en el próximo request), el modal y la
estrella en las tarjetas/Directory/Project Profile, el endpoint REST de
Collections, y todo el CSS/JS asociado.

### v0.8.1 — Fix del botón "Generate / refresh draft" (AI Summary)

El botón nunca llegaba a llamar a OpenAI: el meta box de AI Summary
renderizaba su propio `<form>` (hacia `admin-post.php`) anidado dentro
del `<form id="post">` que ya usa WordPress en la pantalla de edición —
HTML inválido, y el navegador responde a un `<form>` anidado metiendo
sus campos dentro del formulario externo en vez de tratarlo aparte. El
click terminaba re-enviando el formulario de "Actualizar" del post (lo
guardaba sin avisar nada raro) y nunca llegaba a `generate_draft()` ni a
OpenAI. La API key de OpenAI en Projects → Settings sí se guardaba bien
todo este tiempo — solo el botón estaba roto. Se arregló reemplazando el
`<form>` anidado por un link `GET` con nonce hacia `admin-post.php`
(mismo patrón que ya usa el link "Check for updates" desde v0.7.8), y
`handle_manual_trigger()` ahora lee `project_id` de `$_GET`.

### v0.8.2 — La tab Timeline del Project Profile ahora muestra la timeline

La tab "Timeline" de `/projects/{slug}/` estaba vacía: el markup de la
timeline siempre se había renderizado dentro del panel de Overview en
vez del panel dedicado (`id="aw-panel-timeline"`), que quedaba como un
`<div>` vacío. Se movió al panel correcto y se rediseñó para igualar la
referencia pedida: una fila con scroll horizontal de tarjetas de
milestone conectadas por una línea fina, cada una con un punto, fecha
formateada ("Mon YYYY"), título, descripción y una etiqueta
"Milestone N", más un badge tipo pill "AlphaWire editorial" debajo. De
paso se cambió de `array_reverse()` a orden cronológico natural (más
antiguo primero, igual que la referencia) y la fecha cruda del campo
ACF `date_picker` ahora se formatea con `wp_date( 'M Y', ... )` en vez
de imprimirse tal cual. CSS nuevo: `.aw-timeline-scroll` / `.aw-tl-item`
/ `.aw-tl-dot` / `.aw-tl-card` / `.aw-tl-milestone` / `.aw-tl-badge`
(`projects.css`); reutiliza los selectores existentes `.aw-timeline` /
`.aw-tl-date` / `.aw-tl-title` / `.aw-tl-desc`.

### v0.8.3 — Tab Overview rediseñado como grid modular (referencia Hyperliquid)

El tab "Overview" del Project Profile se rearmó como un grid de 3
columnas, siguiendo una referencia visual tipo Hyperliquid: a la
izquierda "What is {Project}?" (descripción + link "Read more" al link
principal del Project + badge "AlphaWire editorial") y "Key Links"
(cada link configurado, como fila); en el medio "Timeline" (vista
previa compacta y vertical del mismo timeline de ACF, más reciente
primero, con un link "View full timeline" que ahora cambia de tab por
JS en vez de ser un ancla muerta); a la derecha "Market" (las mismas
stats/sparkline que antes vivían en "Key Stats", renombrado, con badge
"Market data · external" salvo cuando el precio es el último conocido)
y "Top Narratives" (los términos `topic` propios del Project con un
🔥). El header fijo arriba de los tabs (que antes tenía identidad + Key
Stats + AI Summary) ahora solo tiene identidad (logo/nombre/ticker/
verificado) + AI Project Summary — Key Stats y la descripción/
narratives/links de la identidad se movieron al Overview.

De paso se arregló un bug real que esto destapó: `projects.css` definía
`.aw-timeline` dos veces — las reglas viejas de lista vertical (más
abajo en el archivo) pisaban en silencio a las reglas horizontales de
tarjetas agregadas en v0.8.2 para el tab Timeline, así que ese fix
nunca se estaba viendo como se pensaba. Se renombró la versión vertical
a `.aw-timeline-compact` / `.aw-tlc-*` (ahora solo la usa este preview
del Overview) para que dejen de chocar.

### v0.8.4 — El header vuelve a tener descripción/links/Key Stats

v0.8.3 se pasó de rosca: sacó la descripción, las narrativas, los
botones de link y el panel "Key Stats" del header fijo (arriba de los
tabs), moviéndolos únicamente a las tarjetas nuevas del tab Overview.
El diseño de referencia en realidad mantiene las dos cosas: el header
sigue siendo un resumen siempre visible sin importar qué tab esté
abierto (identidad, descripción, narrativas, links, Key Stats, AI
Project Summary), y el tab Overview además muestra sus propias
tarjetas modulares ("What is X?", Key Links, preview de Timeline,
Market, Top Narratives) para una lectura más completa. Se restauró el
header a como estaba antes de v0.8.3 (layout de 3 columnas: identidad |
Key Stats | AI Project Summary) y se restauró el CSS
`.aw-desc`/`.aw-chip-row`/`.aw-link-row`/`.aw-link-pill` que v0.8.3
había borrado creyendo que estaba muerto — no lo estaba, seguía
haciendo falta acá. El grid del Overview de v0.8.3 no cambió.

Quedó pendiente, a la espera de una decisión de producto antes de
construirlo: el screenshot de referencia más reciente también muestra
cosas sin datos reales detrás todavía — un badge de ranking ("#1 in
{categoría}"), una frase corta al lado del ticker separada del párrafo
de descripción (hoy solo existe un campo de descripción, así que esto
no se puede construir sin repetir el mismo texto dos veces o sin sumar
un campo nuevo), íconos por plataforma en los botones de link
(Website/X/Discord/Docs/GitHub) en vez de una etiqueta genérica, un
badge "EDITORIAL" en la fecha de lanzamiento, y botones de acción en AI
Summary ("Review in Editorial Preview", "Report an issue") que hoy no
son funciones reales en ningún lado del plugin — solo existe
"Generate / refresh draft".

### v0.8.5 — Key Stats calcado a la referencia

Se restyleó el panel "Key Stats" del header para que coincida con la
referencia al pixel: sin gráfico sparkline (eso se queda exclusivo de
la tarjeta "Market" del tab Overview — Key Stats es la versión
compacta, solo texto), fila nueva "Token" arriba (el ticker del
Project), la pill condicional "last known price" pasó a ser una
etiqueta de texto plano al lado del título que siempre se muestra
("Live market data" normalmente, "Last known price" cuando el dato de
mercado en caché está vencido — sin inventar "Mock market data" como
dice la referencia, porque acá el dato es real de CoinGecko, no mock),
una pill chiquita "Editorial" al lado de la fila "Launched" (es el
único dato ahí que se carga a mano en vez de venir de la API de
mercado), y abajo de la lista un divisor + pill "Market data ·
external" + el pie de nota de dos líneas. CSS nuevo: `.aw-badge-text` /
`.aw-editorial-tag` / `.aw-panel-divider` / `.aw-panel-footnote`.

### v0.9.1 — Cambio de proveedor de IA: OpenAI → Claude

A pedido de producto, **toda** integración de IA del plugin pasó de OpenAI
a la API de Claude (Anthropic) — no solo AI Project Summary, también el
blurb opcional de Smart Money agregado en v0.9.0
(`class-smart-money-service.php`). Mismo comportamiento (siempre en
background o por acción manual autenticada, nunca al renderizar una
página; AI Summary siempre queda "Pending Review"), distinto proveedor:

- **Projects → Settings**: el campo de API key y el de modelo ahora son de
  Claude — ver la nota en la sección "Fase 2" más arriba. Una key de
  OpenAI guardada previamente queda huérfana bajo su nombre de opción
  viejo; hace falta cargar una API key de Anthropic nueva.
- Endpoint: `https://api.anthropic.com/v1/messages` (Messages API) en vez
  de `https://api.openai.com/v1/chat/completions` (Chat Completions).
  Cambia la forma del request (el prompt de sistema va en un campo
  `system` de nivel superior, no como mensaje `role: system` dentro de
  `messages`; `max_tokens` en vez de `max_completion_tokens`) y de la
  respuesta (`content[0].text` en vez de `choices[0].message.content`).
- Modelo default: `claude-sonnet-5` (antes `gpt-5.6-luna`).
- Ver `includes/class-ai-summary-service.php` y
  `includes/class-smart-money-service.php::maybe_generate_blurb()`.

### v0.9.2 — CoinGecko: caché cada 5 min, cero llamadas por visitante

A pedido de producto: ningún visitante debe poder agotar el rate limit de
CoinGecko con solo navegar la página, así que se cerró el único camino que
todavía podía disparar un fetch en vivo:

- `class-market-data-service.php`: `CACHE_TTL` 900 → 300 (5 min). El job de
  background pasó de correr cada 15 min a cada 5 min (mismo mecanismo:
  Action Scheduler si está disponible, si no WP-Cron).
- `get_market_data()` (el que usa el endpoint de un solo Project) ya no
  tiene su propio fallback de fetch en vivo en caché frío — ahora es un
  alias directo de `get_cached_market_data()`. Con esto, el único código
  que llama a la API cruda de CoinGecko en todo el plugin es
  `refresh_all()`, corriendo únicamente en el job de background.
- Guard de migración (opción `alphawire_projects_market_cadence_version`):
  en el próximo request después de actualizar, un sitio que ya tenía el
  evento recurrente de 15 min agendado lo limpia y lo vuelve a agendar a 5
  min — mismo patrón de auto-reparación que `class-post-type.php` ya usa
  para las rewrite rules (ver v0.7.2 más arriba).
- Ver la sección "Arquitectura" más abajo, actualizada para reflejar que ya
  no existe el paso de "fetch en vivo" en el fallback en cascada.

### v0.9.3 — CoinGecko: cadencia de vuelta a 15 min

Producto decidió volver a 15 min después de probar 5 en v0.9.2. El fix de
v0.9.2 que cerraba el fetch en vivo por visitante **no cambia** — sigue sin
existir ningún camino donde cargar una página dispare una llamada a
CoinGecko. Lo único que cambió es qué tan seguido corre el job de
background:

- `class-market-data-service.php`: `CACHE_TTL` 300 → 900 (15 min otra vez).
  `register_schedule()` y el hook de cron vuelven a `alphawire_projects_15min`.
- Guard de migración bumpeado de nuevo (`v2` → `v3`): un sitio que quedó en
  el intervalo de 5 min de v0.9.2 lo limpia y lo re-agenda a 15 min en el
  siguiente request.

### v0.9.4 — Fixes de la revisión MVP de SEO en staging (lo que no dependía de una decisión)

A partir de la tarea de ClickUp 869fcq5rx (feedback de 18 items del equipo
de SEO sobre el MVP en staging), esta versión resuelve los items que
tenían un fix de código claro, sin esperar ninguna decisión de producto.
Quedan abiertos, a propósito, los items que sí necesitan una decisión
(mismo news en Projects de una misma categoría, botón "Report Issue" +
disclaimer, el tipo de structured data, y el naming distinto entre
wp-admin y el front end) — el detalle completo de los 18 items, con su
veredicto, está en el comentario de investigación de esa tarea.

- **AI Summary** (`class-ai-summary-service.php`): el prompt de sistema
  ahora le pide a Claude que no mencione precio/market cap específicos (la
  página ya muestra el dato en vivo al lado, así que un número viejo en el
  resumen puede contradecirlo) y que no arranque con un símbolo/markdown;
  el draft guardado también se limpia (`ltrim`) de cualquier símbolo
  sobrante al inicio. Se agregó un botón "Reject" (uno nuevo, al lado de
  "Generate / refresh draft") que manda el summary de vuelta a Draft sin
  tener que tocar el campo Status a mano.
- **Fechas del Timeline** (`class-fields.php`): el campo `date_picker` del
  Timeline ahora fija `return_format => 'Y-m-d'`. El default de ACF
  (`d/m/Y`) se pasaba a `strtotime()` en `single-project.php`, que lee una
  fecha con barras como m/d/Y (EE.UU.) — "10/09" (10 de septiembre) se
  mostraba como "9 de octubre". No requiere migración de datos: ACF
  siempre guarda el valor crudo como Ymd, este setting solo afecta lo que
  devuelve `get_field()`.
- **Research tab mostrándose a sí mismo** (`class-content-relationships.php`):
  `get_selected_coverage()` ahora descarta un Project que esté listado en
  su propio campo "Related projects", en vez de renderizarse a sí mismo.
- **Orden de Trending inestable** (`class-directory-rest-api.php`): la
  query de `trending()` ahora tiene un segundo criterio de orden estable
  (ID del post) además de `trending_order`, así que un empate en ese valor
  ya no cambia de orden entre pedidos.
- **Project Profile** (`templates/single-project.php`): el breadcrumb
  ahora incluye Home; el panel de AI Summary muestra su fecha "Last
  updated" (el dato ya existía, solo no se mostraba); Market Cap y 24h
  Volume ahora usan el formato compacto que ya existía para otras tarjetas
  en vez de la cifra completa en dólares; el cambio de precio quedó
  etiquetado "24h" y las notas al pie de market data ahora nombran a
  CoinGecko explícitamente; aparece un aviso cuando el precio cacheado
  está vencido; las tabs de Timeline y Related (y la preview de Timeline
  en Overview) ahora muestran un mensaje corto en vez de quedar en blanco
  cuando no hay datos.
- **Directory** (`templates/archive-project.php`): el heading de
  resultados filtrados ahora nombra la categoría/narrativa activa (ej.
  "DeFi — 6 resultados") en vez de solo mostrar el conteo.
- **Tabs compartibles** (`assets/js/projects.js`): la tab activa del
  Project Profile ahora se refleja en el hash de la URL
  (`history.replaceState`) y se restaura al cargar la página, así un link
  a una tab específica es realmente compartible.
- **Mobile Directory** (`assets/css/projects.css`): el sidebar de filtros
  ahora se renderiza después del heading "Projects" en mobile (`order` de
  CSS), no antes.

### v0.9.5 — Seguimiento al feedback de SEO tras la respuesta de Andy/Anke (869fcq5rx)

Andy y Anke respondieron en la tarea de ClickUp 869fcq5rx al comentario de
investigación de la v0.9.4, resolviendo dos de los items que habían
quedado abiertos esperando una decisión (item #3, Coverage vs. Research; y
item #14, el naming distinto entre wp-admin y el front end) y confirmando
un pedido de front end (item #18: mostrar cuándo se actualizaron los datos
de mercado por última vez). Esta versión no cambia la lógica ni los datos
de Coverage — Research (manual) y Coverage (automático, por Pillar/Topic
compartidos) ya eran dos caminos separados; lo que se corrige es el texto
que los describía mal, y el naming para que diga lo que realmente hace:

- **Item #3 — texto engañoso en "Related Project"**
  (`class-content-relationships.php`): el campo del lado del contenido
  (News/Podcast/Post) decía en sus instrucciones que seleccionar un
  Project ahí lo agregaba automáticamente a su Coverage/Research — eso es
  falso, ese campo solo alimenta el ranking de "recently updated"
  (`class-activity.php`). Las instrucciones ahora lo dicen correctamente y
  señalan los campos correctos en el Project (Related News/Related
  Podcasts) para curar contenido en su Research tab.
- **Item #14 — naming admin vs. front end**: los campos del Project
  "Related News"/"Related Podcasts" (`class-fields.php`) se renombraron a
  "Research — News"/"Research — Podcasts" para que su label en wp-admin
  coincida con la tab "Research" que realmente alimentan
  (`get_selected_coverage()`), y sus instrucciones ahora aclaran que es
  curación manual, separada de Coverage automático. "Related projects"
  suma una nota de instrucciones aclarando que alimenta tanto la tab
  Related como la lista de la tab Research (uso doble que ya existía, no
  se tocó la funcionalidad). En el front end
  (`templates/single-project.php`), el heading "Latest from AlphaWire"
  (en la preview de Overview y en la tab dedicada) se renombró a
  "AlphaWire Coverage" para que coincida con el label de su propia tab.
- **Item #18 — "cuándo se actualizaron los datos de mercado"**
  (`templates/single-project.php`): Key Stats y el panel de Market en
  Overview ahora muestran "Data as of {fecha y hora}" junto al precio,
  usando `market['updatedAt']` (ya lo guardaba
  `class-market-data-service.php` en cada fetch exitoso a CoinGecko, solo
  no se mostraba). Es independiente del aviso de "precio vencido" — se ve
  siempre que hay un dato, no solo cuando está stale.
  (`assets/css/projects.css`: estilo `.aw-market-updated` para la nota.)

Sin acción de código, según el mismo hilo: item #5 (Report Issue/disclaimer,
confirmado que es fase 2) e item #6 (tipo de schema.org, pendiente de que
Ronnie/Andy indiquen el tipo correcto) — solo quedan en seguimiento.

### v0.9.6 — "Report Issue" / flujo de corrección del AI Summary

Esto no era un "nice to have" de fase futura: estaba en el alcance desde el
inicio en tres documentos — el Darian & Andy Engineering Review (sección 11,
"AI Correction Workflow"), el ticket [BE] de Sebastian (sección 29, "REPORT /
CORRECTION WORKFLOW", listado como **Backend Acceptance Criterion**), y el
ticket [FE] (sección 17, "Report Issue interaction" en el webpart de AI
Project Summary). Lo único que esos documentos marcaban como bloqueante era
que el texto del disclaimer/corrección se acordara antes de salir a
producción — eso nunca se resolvió, así que el feature nunca se construyó.
Esta versión lo construye completo, con un texto de disclaimer razonable
que sirve de borrador para ese sign-off pendiente (marcado en el código para
reemplazarlo fácilmente cuando haya copy final aprobado):

- **Endpoint público** (`class-rest-api.php`): nuevo
  `POST /alphawire-projects/v1/projects/{slug}/report-issue`. Cualquier
  visitante que vea el AI Summary aprobado de un Project puede reportarlo.
  No edita ni republica nada — solo encola el reporte (referencia del
  Project + mensaje + una foto del texto que el visitante vio) para que un
  editor lo revise en wp-admin, el mismo filtro editorial que ya usa el
  resto del flujo de AI Summary. Protecciones livianas anti-abuso (no son
  autenticación): requiere el nonce de `wp_rest` que el front end ya manda
  en cada fetch, un campo honeypot, un mensaje de 10 a 1000 caracteres, y un
  límite de un reporte cada 10 minutos por Project/IP.
- **Nonce para visitantes anónimos** (`class-templates.php`): antes solo se
  generaba para usuarios logueados; ahora siempre existe, porque reportar un
  issue es una acción pensada justamente para visitantes anónimos.
- **Versionado** (`class-ai-summary-service.php`): antes de sobrescribir un
  summary que está en estado **Approved**, el texto anterior se guarda en
  `ai_summary_history` (hasta 5 versiones) — "donde sea práctico, las
  versiones aprobadas anteriores deben conservarse" (Darian & Andy §11).
  También se agregó la acción "Mark reviewed" para sacar un reporte de la
  lista de pendientes una vez que un editor actuó sobre él.
- **Revisión editorial** (`class-ai-summary-metabox.php`): la pantalla de
  edición del Project ahora muestra los reportes abiertos (con un botón
  "Mark reviewed" cada uno) y un historial colapsado de versiones anteriores
  aprobadas, debajo de los botones existentes de Generate/Reject.
- **Front end** (`templates/single-project.php`): el panel de AI Summary
  ahora muestra una línea de disclaimer con un botón "Report an issue" (solo
  en summaries aprobados) que despliega un formulario en línea.
- **JS** (`assets/js/projects.js`): conecta el trigger/cancel/submit del
  formulario con el POST al endpoint nuevo, con estados de éxito/error en
  línea, sin recargar la página.
- **CSS** (`assets/css/projects.css`): estilos para el disclaimer, el
  formulario de reporte y su campo honeypot.

### v0.9.7 — Reconciliación con el commit `bb9a40b` de GitHub

El 7 de octubre, otra sesión de Claude (independiente de esta, modelo
"Opus 5.5") subió a `main` su propio pase sobre la misma lista de 18 ítems
del feedback de SEO, sin que esta rama lo supiera — ambas ramas venían del
mismo commit base (`3267043`, v0.9.3), así que varios ítems quedaron
arreglados dos veces, de formas distintas e incompatibles entre sí. Esta
versión reconcilia ambos lados, ítem por ítem, en vez de descartar uno
completo:

- **De esa otra rama, se incorpora:** #1 (la limpieza de Markdown del AI
  Summary ahora es más robusta — limpia todo el texto, no solo el inicio, y
  también se aplica retroactivamente a summaries ya aprobados), #6 (fix de
  schema.org — `Thing` en vez de `Organization`, sin figuras de mercado que
  se desactualizan), #13 (el fix real del bug de Trending — antes el
  `meta_query` con `EXISTS` hacía que TODOS los Projects calificaran como
  Trending; ahora solo los que tienen un valor real seteado), y del Report
  Issue (#5): disclaimer configurable desde Projects → Settings en vez de
  hardcodeado, y un email de aviso además del listado en wp-admin.
- **De esta rama, se mantiene** (no se revirtió nada): #3/#4 — Coverage
  sigue siendo automático por taxonomía (pillar/topic), no por un campo
  manual de "Related Project" — confirmado contra el hilo de Andy/Anke en
  la tarea de ClickUp 869fcq5rx (posterior al commit del 7 de oct), donde
  Andy pidió explícitamente mantenerlo automático. También se mantiene la
  verificación de nonce y el historial de versiones (`ai_summary_history`)
  del Report Issue, que la otra rama no tenía.
- **#15 (Reject)**: ambas ramas habían registrado el mismo action hook
  `admin_post_alphawire_projects_reject_summary` en clases distintas con
  semántica distinta — quedó consolidado en una sola implementación
  (`class-ai-summary-service.php`), ahora con un status `rejected` propio
  en vez de reusar `draft`.
- **#16 (mobile)**: se adoptó el layout de la otra rama (heading → filtros
  → resultados, vía `display:contents` en `.aw-directory-main`), que no
  deja los filtros varados debajo de todos los resultados.

### v0.9.8 — Panel de Smart Money (Nansen) + controles de cumplimiento

Tarea de ClickUp `1amdve27ccr` ("[AW] Projects Work Item 4" — integración
de Nansen Blockchain Intelligence), subtareas 1, 3 y 4. El mapeo de
cadena/dirección de token por Project, el servicio de sync cacheado
(`class-smart-money-service.php`) y el endpoint del Leaderboard ya
existían de una fase previa (Intelligence proposal, Phase 1); esta versión
agrega la superficie en la página del Project y los controles que pide el
ticket antes de avanzar más:

- **Subtarea 1 (bloques de UI):** `templates/single-project.php` ahora
  muestra un panel "Smart Money" (net flow 24h, cantidad de holders,
  atribución a Nansen, y el blurb de Claude cuando existe) en la tercera
  columna del tab Overview, junto a Market. Se omite por completo — no se
  muestra vacío — si el Project no tiene chain/token mapeado o si la
  integración está desactivada. Incluye una vista previa con datos de
  ejemplo solo para administradores (`?aw_nansen_demo=1`, exige
  `manage_options`, nunca llama a Nansen) para que el equipo pueda revisar
  el diseño antes de tener acceso real a la API o el visto bueno legal —
  el ticket permite explícitamente avanzar el frontend con datos de
  placeholder.
- **Subtarea 3 (separar datos de Nansen de Claude/AI Summaries):** se
  auditó `class-ai-summary-service.php::build_prompt()` — confirmado que
  nunca usa datos de Nansen. El único lugar que sí lo hacía,
  `class-smart-money-service.php::maybe_generate_blurb()`, ahora depende
  de un nuevo flag en Settings (`OPTION_NANSEN_AI_BLURB_ENABLED`) que
  viene **desactivado por default** — ningún dato de Nansen llega a un
  prompt de Claude hasta que Legal lo apruebe explícitamente.
- **Subtarea 4 (desactivar/purgar):** un nuevo interruptor general
  (`OPTION_NANSEN_ENABLED`, **activado por default** porque ya hay una key
  real de Nansen configurada en staging) corta el sync horario y oculta
  el panel/Leaderboard en todo el sitio al apagarlo, sin afectar el resto
  de la página del Project. También se agregó un botón "Purgar datos de
  Nansen en caché" en Projects → Settings
  (`class-smart-money-service.php::handle_purge_request()`) que borra
  todo el caché y el "último valor conocido" de Smart Money, y deja
  registrado quién lo hizo y cuándo, para auditoría.
- **Fuera de alcance por ahora** (el propio ticket los marca como
  pendientes de algo externo): el mapeo completo de todos los campos
  aprobados de Nansen (subtarea 2) y la restricción geográfica real
  (subtarea 4) — ambos dependen de confirmación legal/de producto que
  todavía no llegó.

### v0.9.9 — Feedback de diseño phase 2 (Miguel/Anke, ClickUp `1amdve27mrz`)

Cinco items chicos de UI, cada uno independiente de los demás — sin cambios de datos ni de arquitectura:

- **Form de Report Issue** (`1amdve27mt4`): el link "Report an issue" ya era un link de texto discreto, no el botón outlined que describía el ticket — eso no cambió. Lo que sí faltaba: un control **Close** separado junto al label, distinto de Cancel (ambos hacen lo mismo — cerrar sin enviar — pero antes solo existía Cancel); Cancel pasó de un borde gris neutro a borde+texto verde lima como pide el ticket; Submit/Cancel ahora miden 36px de alto con 0.75rem de fuente (antes ~26px/11px).
- **Selected state de categorías** (`1amdve27muy`): `.aw-nav-item.is-active` solo se distinguía por el color del texto y compartía fondo con `:hover` — en la tira horizontal de mobile, todos los pills terminaban con el mismo fondo, así que el seleccionado casi no se notaba. Ahora tiene su propio borde + relleno tintado en verde, con una regla de mayor especificidad para que sobreviva al override de mobile.
- **Badge de Verified** (`1amdve27n1c`): se cambió el carácter "✓" por el ícono circle-check de Tabler — traído del render real de tabler.io (no de memoria) vía Claude in Chrome, ya que el ícono dibuja su propio círculo y reemplaza el borde que `.aw-verified` dibujaba a mano alrededor del check.
- **Estandarizar íconos a 24px** (`1amdve27n1r`): nueva función `aw_projects_icon()` en `template-functions.php` (mismo enfoque de markup real de Tabler) que reemplaza los glyphs unicode del Directory (◎ ⚡ ↻ ✦ ⌕, sin tamaño consistente) por SVGs reales a 24px. Acotado a `templates/archive-project.php`, tal como pide el ticket.
- **Tag de categoría redundante** (`1amdve27n8r`): el header del Project tenía un chip de narrativa entre la descripción y los links externos — se sacó (la misma info sigue en el panel "Top Narratives" del tab Overview), así que los links quedan pegados debajo de la descripción.

Cada uno se verificó antes de integrar: lint de PHP/JS, balance de llaves en CSS, y un render aislado con la CSS real del plugin (Playwright headless) comparando antes/después — capturas enviadas en el chat de la tarea de reconciliación.

## Arquitectura: endpoints propios y datos de mercado

El plugin expone sus propios endpoints REST bajo el namespace
`alphawire-projects/v1` — el front end nunca pega directo a CoinGecko, solo
consume estos:

- `GET /projects/{slug}` — payload completo de un Project (`class-rest-api.php`),
  para la Project Profile page.
- `GET /projects`, `/projects/trending`, `/projects/recently-launched`,
  `/projects/recently-updated`, `/projects/editors-picks`, `/categories`,
  `/narratives` — endpoints de Directory (`class-directory-rest-api.php`).

**Orden de registro importa** (v0.7.9): WordPress matchea rutas REST en el
orden en que se registraron y se queda con la primera que matchea. El
catch-all de un solo proyecto (`/projects/{slug}`, patrón
`[a-zA-Z0-9-]+`) también matchea literales como `trending` o
`recently-launched` — si se registra antes que las rutas específicas de
Directory, se las come a todas (404 "Project not found"). Por eso
`AlphaWire_Projects_Directory_REST::register_routes()` está enganchado en
`rest_api_init` con prioridad 5 y `AlphaWire_Projects_REST::register_routes()`
con prioridad 20 — las rutas específicas siempre se registran primero.

Cuando alguno de estos endpoints tiene que devolver precio/market cap/etc.,
arma esa parte del JSON llamando internamente a
`AlphaWire_Projects_Market_Data_Service` (`class-market-data-service.php`) —
es la única clase del plugin que habla con CoinGecko, y ningún otro código
toca la API cruda:

- `get_market_data()` — para el endpoint de un solo Project. Desde v0.9.2 es
  un alias de `get_cached_market_data()`: cache-only, nunca fetchea en vivo.
  Hasta v0.9.1 sí tenía su propio fallback de fetch en vivo (timeout corto)
  cuando el caché estaba frío; producto pidió explícitamente que ningún
  visitante pueda disparar una llamada a CoinGecko con solo cargar una
  página, así que ese camino se eliminó.
- `get_cached_market_data()` — para los endpoints de Directory/listados, y
  ahora (vía `get_market_data()`) también para el de un solo Project. Nunca
  fetchea; solo lee caché, para no disparar llamadas bloqueantes a
  CoinGecko al renderizar una página.
- Fallback en cascada, sin paso de fetch en vivo: caché de 15 min → último
  valor bueno guardado sin expiración (marcado `stale: true`) → payload
  vacío si nunca hubo un fetch exitoso. Ningún caller recibe `null` ni
  tiene que manejar "esto falló" como caso especial.
- Refresh real solo en background: un job cada 15 min (Action Scheduler si
  está disponible, si no WP-Cron; probamos 5 min en v0.9.2, producto pidió
  volver a 15 en v0.9.3 — con un guard de migración que fuerza a los sitios
  que quedaron en el intervalo de 5 min a pasarse al de 15 min en el
  siguiente request) recorre todos los Projects publicados con
  `coingecko_id` y repuebla el caché, con una pausa de 300ms entre
  proyectos para no pisar el rate limit del tier gratis de CoinGecko (5-15
  req/min). CoinGecko nunca se llama fuera de este job — ni al renderizar
  una página ni por ningún otro camino.
- Ya tiene un filtro (`alphawire_projects_coingecko_request_args`) listo
  para agregar una API key de CoinGecko Demo/paga el día que exista, sin
  tocar nada más del plugin.

## Todavía no está en esta versión

SEO (metadatos específicos de Project vía Rank Math) y analítica. Ver Fase
5 del plan. El buscador del Directory recarga la página al enviar el
formulario (sin JS) — queda como posible mejora progresiva más adelante.

## Requisitos

- WordPress con `pillar`/`topic` ya registradas (las trae el sitio actual;
  si faltan, el plugin no falla, Projects solo queda sin esa taxonomía).
- ACF activo para ver los campos en wp-admin — recomendado, opcional.
- Para el auto-update desde GitHub (v0.6.0+): repo público en GitHub con
  `owner/repo` y branch configurados en Projects → Settings. Sin esos dos
  campos completos, el plugin funciona igual pero WordPress nunca ofrece
  la actualización automática — hay que instalar los cambios a mano.

## Instalar / actualizar

**Ya está en el sitio** (a partir de v0.6.0): con el repo/branch
configurados en Projects → Settings, cada push a esa branch aparece como
una actualización normal en la página de Plugins. Para chequear antes de
que expire el caché de 6 horas, usar el link **"Check for updates"** en la
fila del plugin (v0.7.8+), o "Check again" en Dashboard → Updates si el
sitio todavía está en una versión anterior a 0.7.8.

**Instalación manual / primera vez**: copiar `alphawire-projects/` a
`wp-content/plugins/` y activar (o, si ya estaba activo, simplemente
reemplazar los archivos — no hace falta reactivar salvo que quieras
forzar el flush de rewrite rules; desde v0.3.2 el propio plugin se
autorepara en el siguiente request si detecta que sus reglas cambiaron).
