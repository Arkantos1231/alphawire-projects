# AlphaWire Projects

Plugin de WordPress para la entidad "Project" de AlphaWire. Plan completo
(recap de docs + sitio + roadmap) en el artifact publicado en la
conversación.

## Estado: v0.4.0 — Fases 0-4

### Fase 0 (v0.1.0)
- CPT `project`, URL permanente `/projects/{slug}/`, archivo en `/projects/`.
- Reusa `pillar` (Categories) y `topic` (Narratives) — no crea taxonomías nuevas.
- Siembra el término "Interviews" en `category` al activar.
- Campos ACF: identidad, links, launch date, trending order, editor's pick,
  related projects, y el bloque de AI Summary (3 estados).
- Servicio de mercado contra el endpoint público y gratis de CoinGecko, con
  caché de 15 min + fallback "último valor bueno" + refresh en background.
- `GET /alphawire-projects/v1/projects/{slug}`.

### Fase 1 (v0.2.0, nuevo)
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

### Fase 2, parcial (v0.3.0, nuevo) — Generación real del AI Summary

- **Projects → Settings** (nueva página en wp-admin): API key de OpenAI
  (campo tipo password, nunca se muestra el valor guardado — dejar en
  blanco al guardar significa "no cambiar") y modelo (texto libre,
  default `gpt-5.6-luna`; también soporta `gpt-5.6-terra` y `gpt-5.6-sol`).
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

### Fases 3-4 (v0.4.0, nuevo) — Templates de Directory y Project Profile

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

## Todavía no está en esta versión

SEO (metadatos específicos de Project vía Rank Math) y analítica. Ver Fase
5 del plan. El buscador del Directory recarga la página al enviar el
formulario (sin JS) — queda como posible mejora progresiva más adelante.

## Requisitos

- WordPress con `pillar`/`topic` ya registradas (las trae el sitio actual;
  si faltan, el plugin no falla, Projects solo queda sin esa taxonomía).
- ACF activo para ver los campos en wp-admin — recomendado, opcional.

## Instalar / actualizar

Copiar `alphawire-projects/` a `wp-content/plugins/` y activar (o, si ya
estaba activo, simplemente reemplazar los archivos — no hace falta
reactivar salvo que quieras forzar el flush de rewrite rules).
