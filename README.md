# AlphaWire Projects

Plugin de WordPress para la entidad "Project" de AlphaWire. Plan completo
(recap de docs + sitio + roadmap) en el artifact publicado en la
conversación.

## Estado: v0.7.8 — Fases 0-4 completas, más CSV import, auto-update desde
## GitHub, y un rediseño del Directory/Trending a paridad con el prototipo
## de Lovable (incluye Collections, fuera del plan original)

### Fase 0 (v0.1.0)
- CPT `project`, URL permanente `/projects/{slug}/`, archivo en `/projects/`.
- Reusa `pillar` (Categories) y `topic` (Narratives) — no crea taxonomías nuevas.
- Siembra el término "Interviews" en `category` al activar.
- Campos ACF: identidad, links, launch date, trending order, editor's pick,
  related projects, y el bloque de AI Summary (3 estados).
- Servicio de mercado contra el endpoint público y gratis de CoinGecko, con
  caché de 15 min + fallback "último valor bueno" + refresh en background.
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

- **Projects → Settings** (página en wp-admin): API key de OpenAI (campo
  tipo password, nunca se muestra el valor guardado — dejar en blanco al
  guardar significa "no cambiar") y modelo (texto libre, default
  `gpt-5.6-luna`; también soporta `gpt-5.6-terra` y `gpt-5.6-sol`). Ninguno
  de los dos se expone por REST ni en el front end.
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
- La llamada a OpenAI ocurre solo en background o en una acción de
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
CoinGecko/OpenAI. Ver `includes/class-updater.php`.

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

El Directory (`/projects/`) se rediseñó para acercarse al prototipo de
Lovable: sidebar izquierdo (nav Explore + Categories + CTA de
Collections), buscador movido al header, strip numerado de Trending
Projects con volumen 24h real de CoinGecko, y un panel de Top
Categories/Trending Narratives con el % de cambio promedio real (no
mockeado). Se sumó además **Collections** — favoritos con nombre propio,
múltiples por usuario — que el plan original excluía explícitamente de la
Fase 3 pero se agregó a pedido, sobre el sistema de login ya existente del
sitio (Thirdweb Auth SSO ya crea/mapea un usuario real de WP; no hizo
falta autenticación nueva). Cada tarjeta tiene una estrella, hay una
página "My Collections" en `/projects/collections/`, todo vía user-meta +
un pequeño surface REST autenticado — sin tablas nuevas en la base de
datos. Ver `includes/class-collections.php`.

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
