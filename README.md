# Lealez

Plugin para administrar empresas, ubicaciones, perfiles de Google Business Profile y la base funcional de programas y tarjetas de lealtad. Desde la versión **1.4.0** el portal de autogestión se publica mediante widgets nativos de Elementor; desde la versión **1.5.0** el perfil frontend de ubicación incorpora navegación orientada al cliente, diligenciamiento por porcentaje, semáforos por sección y presentación contextual según el tipo de negocio, manteniendo intactos los procesos existentes de guardado y sincronización con Google.

![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-21759B?logo=wordpress)
![PHP](https://img.shields.io/badge/PHP-7.4%2B-777BB4?logo=php)
![Version](https://img.shields.io/badge/version-1.5.0-3782c4)
![Elementor](https://img.shields.io/badge/Elementor-required%20for%20frontend%20pages-92003B?logo=elementor)

## Contenido

- [Objetivo](#objetivo)
- [Alcance actual](#alcance-actual)
- [Requisitos](#requisitos)
- [Arquitectura](#arquitectura)
- [Modelo de datos](#modelo-de-datos)
- [Portal frontend con Elementor](#portal-frontend-con-elementor)
- [Páginas y widgets](#páginas-y-widgets)
- [Perfil unificado de empresa](#perfil-unificado-de-empresa)
- [Perfil unificado de ubicación](#perfil-unificado-de-ubicación)
- [Google Business Profile](#google-business-profile)
- [Flujo local → Google](#flujo-local--google)
- [Comportamiento por categoría](#comportamiento-por-categoría)
- [Diligenciamiento y semáforos](#diligenciamiento-y-semáforos)
- [Separación cliente / administración](#separación-cliente--administración)
- [Permisos y seguridad](#permisos-y-seguridad)
- [Migración y compatibilidad](#migración-y-compatibilidad)
- [Estructura del repositorio](#estructura-del-repositorio)
- [Instalación](#instalación)
- [Configuración de páginas](#configuración-de-páginas)
- [Pruebas recomendadas](#pruebas-recomendadas)
- [Control de cambios](#control-de-cambios)
- [Solución de problemas](#solución-de-problemas)
- [Versiones](#versiones)

## Objetivo

Lealez separa claramente dos responsabilidades:

1. **Lógica de negocio:** CPT, permisos, metadatos, nonces, formularios, AJAX, Google Business Profile, cron, logs, validaciones, estados de publicación y persistencia local.
2. **Presentación:** páginas frontend editables con Elementor que reutilizan la lógica real del plugin en lugar de duplicarla dentro del constructor visual.

Principios de implementación:

- no duplicar reglas de acceso en JavaScript;
- no guardar secretos ni tokens en Elementor;
- no sustituir metaboxes, AJAX handlers o jobs existentes cuando pueden reutilizarse;
- conservar shortcodes y acciones públicas como contratos de compatibilidad;
- separar guardado local de publicación en Google;
- no exponer al cliente identificadores, nombres de campos de API, payloads o datos RAW que solo sirven para soporte técnico;
- consultar capacidades, categorías y características dinámicas desde Google en lugar de hardcodearlas cuando dependen del tipo de negocio.

## Alcance actual

El repositorio incluye:

- CPT `oy_business` para empresas;
- CPT `oy_location` para ubicaciones o sedes;
- CPT base para programas y tarjetas de lealtad;
- metaboxes administrativos de empresa y ubicación;
- OAuth y conexión con Google Business Profile;
- importación y actualización de ubicaciones de Google;
- edición local y publicación por módulos;
- categorías dinámicas;
- características/atributos dinámicos por categoría y región;
- dirección y áreas de servicio;
- contacto y enlaces públicos;
- horarios regulares y especiales;
- menús de alimentos cuando aplican;
- servicios/catálogos cuando aplican;
- publicaciones;
- reseñas y respuestas;
- medios;
- rendimiento, frases de búsqueda y mayor interés;
- portal frontend autenticado;
- creación, edición, archivo y restauración de empresas y ubicaciones;
- perfil frontend unificado de empresa;
- perfil frontend unificado de ubicación;
- instalador de páginas Elementor;
- seis widgets Elementor;
- migración segura desde páginas con shortcode;
- redirecciones de rutas heredadas;
- protección contra caché de páginas personalizadas.

## Requisitos

| Componente | Requisito |
|---|---|
| WordPress | 6.0 o superior |
| PHP | 7.4 o superior |
| Elementor | Activo para crear, reparar y editar páginas frontend |
| HTTPS | Recomendado y necesario para OAuth en producción |
| Permalinks | Estructura amigable recomendada |
| Google Business Profile APIs | Proyecto y acceso configurados para los módulos que se vayan a usar |

Elementor no es necesario para cargar los CPT o la administración interna. Si Elementor no está activo, Lealez bloquea únicamente la creación o reparación de páginas que dependerían de sus widgets.

## Arquitectura

```text
WordPress
└── Lealez_Plugin
    ├── CPT y taxonomías
    ├── Integración Google Business Profile
    │   ├── OAuth
    │   ├── API
    │   ├── AJAX
    │   ├── rate limiting
    │   └── logs
    ├── Metaboxes oy_location
    │   ├── información básica
    │   ├── dirección
    │   ├── contacto
    │   ├── horarios
    │   ├── características
    │   ├── menú / servicios
    │   ├── publicaciones / reseñas / medios
    │   └── analítica
    ├── Lealez_Frontend_Portal
    │   ├── páginas y rutas
    │   ├── empresas
    │   ├── ubicaciones
    │   ├── usuario
    │   └── perfil unificado de ubicación
    └── Lealez_Elementor_Integration
        ├── categoría Lealez
        ├── registro de assets
        └── widgets del portal
```

### Carga del plugin

`lealez.php`:

1. define constantes y versión;
2. registra activación y desactivación;
3. carga CPT y taxonomías;
4. carga la integración Google Business Profile cuando sus archivos existen;
5. carga el portal frontend tanto en administración como en frontend;
6. carga el puente Elementor de forma segura;
7. carga clases administrativas únicamente en `is_admin()`.

Constantes principales:

| Constante | Uso |
|---|---|
| `LEALEZ_VERSION` | Versionado de código y assets |
| `LEALEZ_PLUGIN_FILE` | Archivo principal |
| `LEALEZ_PLUGIN_DIR` | Ruta absoluta |
| `LEALEZ_PLUGIN_URL` | URL base |
| `LEALEZ_ASSETS_URL` | CSS, JS e imágenes |
| `LEALEZ_INCLUDES_DIR` | Clases y traits |

## Modelo de datos

### `oy_business`

Representa una empresa. El acceso se concede cuando el usuario:

- tiene `manage_options`;
- es autor del post;
- está en `_admin_users`; o
- está en `_manager_users`.

La administración del equipo exige permisos administrativos de la empresa, autoría o privilegios globales.

### `oy_location`

Representa una sede, sucursal, punto de atención o negocio local. Se relaciona con la empresa mediante `parent_business_id`.

El acceso se concede al autor, a un administrador global o a usuarios autorizados en la empresa padre.

### Estado y archivo

Lealez utiliza estados de WordPress para conservar los datos. Archivar no elimina metadatos. Las páginas gestionadas por Lealez también se mueven a Papelera en lugar de eliminarse permanentemente.

## Portal frontend con Elementor

La sección **Lealez → Páginas frontend** es el instalador central.

El instalador:

- detecta Elementor activo;
- distingue Elementor instalado pero inactivo;
- bloquea crear o reparar si Elementor no está activo;
- crea páginas publicadas;
- guarda estructura nativa en `_elementor_data`;
- deja `post_content` vacío;
- asigna `elementor_header_footer`;
- marca `_elementor_edit_mode` como `builder`;
- valida que exista el widget esperado;
- agrega el widget faltante sin borrar otros elementos;
- limpia caché de Elementor;
- conserva respaldo del contenido clásico anterior.

## Páginas y widgets

La versión 1.5.0 mantiene seis páginas de usuario:

| Clave | Página | Slug de instalación nueva | Widget |
|---|---|---|---|
| `portal` | Mi cuenta Lealez | `/mi-cuenta-lealez/` | `lealez-account-dashboard` |
| `businesses` | Mis empresas | `/mi-cuenta-lealez/mis-empresas/` | `lealez-business-list` |
| `business_editor` | Perfil de empresa | `/mi-cuenta-lealez/editar-empresa/` | `lealez-business-profile` |
| `locations` | Mis ubicaciones | `/mi-cuenta-lealez/mis-ubicaciones/` | `lealez-location-list` |
| `location_editor` | Perfil de ubicación | `/mi-cuenta-lealez/editar-ubicacion/` | `lealez-location-profile` |
| `user_profile` | Mi perfil | `/mi-cuenta-lealez/mi-perfil/` | `lealez-user-profile` |

Los IDs se guardan en `lealez_frontend_page_ids` y cada página administrada recibe `_lealez_frontend_page_key`.

Los widgets ofrecen controles de ancho, densidad, relleno, fondos, superficies, colores, tipografías, bordes, radios, sombras, botones y visibilidad de cabeceras/resúmenes. Las variables CSS se limitan al wrapper del widget.

## Perfil unificado de empresa

Una sola página concentra:

- datos generales;
- marca;
- contacto;
- redes y datos legales;
- equipo;
- integraciones;
- Google Business Profile.

Navegación por query string:

```text
?business_id=123
?business_id=123&section=team
?business_id=123&section=integrations
?business_id=123&section=google
```

## Perfil unificado de ubicación

El perfil de ubicación reutiliza los metaboxes y procesos existentes, pero reorganiza la experiencia del cliente.

### Grupos de navegación

**General**

- Resumen.
- Configuración interna.
- Conexión con Google.
- Sincronización.

**Información del perfil**

- Información básica.
- Dirección y cobertura.
- Contacto y enlaces.
- Horarios.
- Características.

**Contenido**

- Fotos en Google.
- Menú cuando corresponde.
- Servicios cuando corresponde.

**Opiniones y actividad**

- Publicaciones.
- Opiniones.

**Resultados**

- Rendimiento.
- Frases de búsqueda.
- Mayor interés.

La navegación lateral permanece visible en escritorio y vuelve a flujo normal en pantallas pequeñas.

## Google Business Profile

La integración existente se conserva:

- OAuth;
- cuentas y grupos;
- selección de propiedades;
- metaboxes de empresa y ubicación;
- AJAX;
- jobs y verificadores;
- cron y polling;
- estados locales, enviados, en revisión y aplicados;
- logs;
- límites de sincronización;
- importación desde Google;
- publicación de módulos compatibles.

La Business Information API moderna gestiona campos de ubicación como nombre, teléfonos, dirección, web, horarios y categorías mediante actualizaciones parciales. Lealez mantiene esas claves técnicas dentro de la capa de integración; el frontend del cliente muestra nombres de negocio entendibles.

## Flujo local → Google

La regla del perfil es explícita:

1. **Editar y guardar en Lealez.** Los cambios se persisten localmente y pueden revisarse antes de publicar.
2. **Publicar en Google.** El usuario utiliza la acción del módulo cuando los datos están listos.
3. **Verificar estado.** Los jobs y verificadores existentes comparan lo enviado con lo que Google devuelve.

Guardar un formulario local **no equivale** a publicar en Google.

Estados que puede mostrar Lealez:

- guardado, falta publicar;
- enviado / en cola;
- Google está revisando;
- aplicado en Google;
- aplicado parcialmente;
- Google devolvió otro valor;
- pendiente de verificación;
- requiere corregir datos;
- no aplicado;
- error de envío.

## Comportamiento por categoría

Google no ofrece exactamente las mismas opciones a todos los negocios. Lealez respeta ese comportamiento.

### Categorías

- La categoría principal y las adicionales se seleccionan desde resultados dinámicos de Google.
- No se debe depender de una lista estática incluida en el plugin.
- Las opciones varían por región e idioma y pueden cambiar.
- El frontend muestra el nombre legible; los identificadores internos permanecen en la integración.

### Características

La sección **Características** reutiliza el metabox dinámico existente:

- consulta las opciones disponibles para la categoría y el país;
- usa valores actuales sincronizados desde Google;
- permite sobreescrituras locales;
- guarda localmente antes del envío;
- publica únicamente opciones compatibles.

Esto evita presentar, por ejemplo, características de un restaurante a un negocio cuya categoría no las admite.

### Menú y Servicios

La visibilidad del catálogo usa esta prioridad:

1. datos locales ya existentes, para no ocultar contenido del usuario;
2. `gmb_catalog_type` detectado por la sincronización existente (`food_menu`, `services`, `products` o `none`);
3. solo cuando todavía no existe detección, la categoría principal se utiliza como fallback conservador.

Así, los perfiles de alimentos pueden mostrar **Menú** y los negocios de servicios pueden mostrar **Servicios** sin obligar a todos los tipos de negocio a navegar módulos irrelevantes.

## Diligenciamiento y semáforos

El perfil calcula un porcentaje orientativo de información diligenciada. **No es un ranking, no es un SEO score y no representa aprobación de Google.**

Se evalúan únicamente secciones relevantes y datos que Lealez puede comprobar localmente:

- Información: nombre, descripción, categoría y apertura.
- Ubicación: dirección completa o país + áreas de servicio según el tipo de negocio.
- Contacto: teléfono, sitio web y enlaces de acción cuando existen.
- Horarios: presencia de horario regular.
- Características: datos disponibles para la categoría cuando esa sección aplica.
- Menú o Servicios: se incluye únicamente el catálogo aplicable.

Semáforo:

| Estado | Rango | Lectura |
|---|---:|---|
| Rojo | 0–44% | La sección requiere diligenciamiento |
| Amarillo | 45–79% | Hay información, pero aún falta completar |
| Verde | 80–100% | La mayor parte de los datos recomendados está diligenciada |

El resumen general promedia únicamente las secciones aplicables al perfil visible.

## Separación cliente / administración

### Frontend del cliente

El cliente ve nombres como:

- Categoría principal.
- Teléfono principal.
- Conexión con Google.
- Publicar en Google.
- Características.
- Historial de sincronización.

La capa frontend oculta detalles que no aportan a la gestión diaria, entre ellos:

- Account ID;
- Location ID;
- resource names;
- claves de campos de API;
- `updateMask` / `fieldMask`;
- payloads;
- endpoints;
- datos RAW;
- códigos internos mostrados por los metaboxes.

La ocultación es exclusivamente de presentación. No modifica nonces, AJAX handlers, jobs ni datos almacenados.

### Administración y soporte

El backend conserva los metaboxes originales y la información técnica completa necesaria para:

- auditoría;
- soporte;
- diagnóstico de sincronización;
- revisión de payloads y respuestas;
- inspección de identificadores;
- seguimiento de jobs y logs.

## Permisos y seguridad

### Administración

Crear, reparar o retirar páginas requiere:

- `manage_options`;
- nonce válido;
- acción `admin-post.php` registrada.

### Frontend

Cada renderer valida:

- sesión iniciada;
- tipo de post;
- ID válido;
- acceso a empresa o ubicación;
- permisos adicionales para administración de empresa;
- nonce en operaciones de escritura.

El perfil no concede permisos por aparecer dentro de Elementor; las validaciones se ejecutan del lado del servidor.

### Caché

Las páginas administradas por Lealez definen:

```php
DONOTCACHEPAGE
DONOTCACHEOBJECT
```

y envían `nocache_headers()` para reducir el riesgo de servir contenido personalizado de un usuario a otro.

## Migración y compatibilidad

Shortcodes conservados:

```text
[lealez_account_dashboard]
[lealez_business_list]
[lealez_business_editor]
[lealez_business_team]
[lealez_business_integrations]
[lealez_business_google_center]
[lealez_location_list]
[lealez_location_editor]
[lealez_location_google_center]
[lealez_user_profile]
```

No deben retirarse sin migración porque los widgets y rutas existentes los utilizan como capa de compatibilidad.

Ya no se crean páginas frontend independientes para Equipo de empresa, Integraciones de empresa, Google de empresa o Google de ubicación. Las funciones se concentran en perfiles unificados. Las páginas heredadas gestionadas por Lealez se envían a Papelera, nunca se eliminan permanentemente.

Cuando una página existente no contiene el widget esperado, **Agregar widget de Lealez**:

1. valida permisos y nonce;
2. confirma Elementor activo;
3. lee `_elementor_data`;
4. agrega el widget faltante sin borrar la composición existente;
5. guarda el contenido clásico anterior en `_lealez_pre_elementor_content_backup`;
6. vacía `post_content` para evitar doble renderizado;
7. activa el modo builder;
8. elimina CSS obsoleto y limpia caché.

## Estructura del repositorio

```text
lealez.com/
├── lealez.php
├── README.md
├── CHANGELOG.md
├── assets/
│   ├── css/frontend/
│   │   ├── lealez-frontend-portal.css
│   │   ├── lealez-elementor-portal.css
│   │   ├── lealez-frontend-gmb-center.css
│   │   ├── lealez-gmb-admin-compat.css
│   │   └── lealez-frontend-unified-location.css
│   └── js/
│       ├── admin/
│       └── frontend/
│           ├── lealez-frontend-portal.js
│           ├── lealez-frontend-gmb-center.js
│           └── lealez-frontend-unified-location.js
├── includes/
│   ├── admin/
│   ├── cpts/
│   │   └── metaboxes/
│   ├── elementor/
│   │   └── widgets/
│   ├── frontend/
│   │   ├── class-lealez-frontend-portal.php
│   │   ├── class-lealez-frontend-gmb-center.php
│   │   ├── class-lealez-frontend-unified-location-profile.php
│   │   ├── lealez-unified-location-routing-trait.php
│   │   ├── lealez-unified-location-render-trait.php
│   │   ├── lealez-unified-location-modules-trait.php
│   │   ├── lealez-unified-location-quality-trait.php
│   │   ├── lealez-unified-location-internal-trait.php
│   │   ├── lealez-unified-location-metabox-trait.php
│   │   └── lealez-unified-location-access-trait.php
│   ├── integrations/google-my-business/
│   └── taxonomies/
└── templates/
```

### Archivos clave

| Archivo | Responsabilidad |
|---|---|
| `lealez.php` | Bootstrap, versión y carga de módulos |
| `class-lealez-frontend-portal.php` | Hooks del portal y protección de caché |
| `class-lealez-frontend-unified-location-profile.php` | Composición del perfil frontend de ubicación |
| `lealez-unified-location-routing-trait.php` | Carga de assets, redirecciones y routing |
| `lealez-unified-location-render-trait.php` | Estructura visual y navegación del perfil |
| `lealez-unified-location-modules-trait.php` | Definición y agrupación de módulos |
| `lealez-unified-location-quality-trait.php` | Diligenciamiento, semáforos, aplicabilidad de catálogo y resumen de conexión |
| `lealez-unified-location-metabox-trait.php` | Reutilización segura de metaboxes existentes |
| `lealez-unified-location-access-trait.php` | Permisos, estados de publicación y URLs |
| `lealez-frontend-unified-location.js` | Capa de presentación cliente y ocultación de detalles técnicos |
| `lealez-frontend-unified-location.css` | UI responsive del perfil, score y semáforos |

## Instalación

1. Descargar o clonar el repositorio.
2. Ubicarlo en `wp-content/plugins/lealez`.
3. Verificar PHP 7.4+ y WordPress 6.0+.
4. Instalar y activar Elementor si se utilizará el portal generado.
5. Activar Lealez.
6. Guardar enlaces permanentes en una instalación nueva.
7. Configurar Google Business Profile.
8. Crear o reparar las páginas frontend desde Lealez.

## Configuración de páginas

1. Entrar como administrador.
2. Ir a **Lealez → Páginas frontend**.
3. Confirmar **Elementor activo**.
4. Pulsar **Crear o reparar todas con Elementor**.
5. Verificar que las seis filas indiquen **Lista en Elementor**.
6. Abrir cada página con **Editar con Elementor**.
7. Ajustar estilos desde el widget Lealez.
8. Mantener el widget funcional en la página.

## Pruebas recomendadas

### Carga

- Lealez activa sin Elementor: no hay fatal error.
- Elementor activo: aparecen seis widgets en la categoría Lealez.
- Administración y CPT siguen disponibles.

### Ubicación y navegación

- Crear ubicación.
- Abrir perfil unificado.
- Navegar todas las secciones aplicables.
- Confirmar que la barra lateral no tapa contenido y funciona en escritorio/móvil.
- Confirmar que Menú o Servicios cambian de acuerdo con `gmb_catalog_type` y no ocultan contenido local ya existente.

### Guardado y Google

- Editar Información Básica y guardar localmente.
- Confirmar estado **Guardado · falta enviar** cuando aplica.
- Publicar y verificar el estado posterior.
- Repetir en Dirección, Contacto, Horarios, Características y Menú.
- Confirmar que guardar no dispara publicación automática.
- Confirmar que una ubicación sin conexión permite diligenciar datos locales, pero no publicar.

### Categorías y características

- Buscar categoría en el selector dinámico.
- Guardar categoría principal y adicionales.
- Publicar categorías y verificar la respuesta.
- Cambiar categoría y refrescar Características.
- Confirmar que las características mostradas corresponden a la categoría y país actuales.

### Diligenciamiento

- Perfil vacío: semáforos rojos.
- Perfil parcialmente diligenciado: amarillo.
- Perfil ampliamente diligenciado: verde.
- Negocio de área de servicio: el cálculo usa áreas de servicio en lugar de exigir dirección física.
- Negocio con menú: el cálculo incluye Menú y no Servicios.
- Negocio de servicios: el cálculo incluye Servicios y no Menú.

### Privacidad de información técnica

En frontend de cliente confirmar que no aparecen:

- Account ID;
- Location ID;
- resource names;
- `updateMask`;
- `fieldMask`;
- payloads;
- endpoints;
- JSON RAW;
- nombres internos de campos como rutas de API.

En backend administrativo confirmar que esa información sigue disponible para diagnóstico.

### Responsive

Probar al menos 1440 px, 1024 px, 768 px, 390 px y 320 px:

- sin desbordamiento horizontal;
- botones legibles;
- score visible;
- navegación usable;
- formularios y metaboxes embebidos operables.

## Control de cambios

Flujo recomendado:

1. Crear rama desde `main`.
2. Limitar el alcance del cambio.
3. No reutilizar una rama ya fusionada.
4. Ejecutar `php -l` sobre PHP modificado.
5. Ejecutar `node --check` sobre JS modificado cuando Node esté disponible.
6. Probar WordPress y Elementor.
7. Actualizar `CHANGELOG.md`.
8. Actualizar versión en `lealez.php` cuando corresponda.
9. Abrir pull request con resumen, riesgos y pruebas.

Convenciones de commit:

```text
feat: improve location profile and Google sync UX
fix: preserve local state before Google publish
docs: document category-aware location profiles
chore: bump plugin version to 1.5.0
```

## Solución de problemas

### “Elementor no está activo”

Activar `elementor/elementor.php`. Lealez no crea páginas incompletas.

### El perfil no permite publicar en Google

Comprobar que la empresa tenga conexión válida y que la ubicación esté vinculada. El cliente no necesita ver identificadores internos para realizar esta verificación.

### No aparecen Características

1. Confirmar categoría principal.
2. Confirmar país/región.
3. Conectar la ubicación con Google.
4. Pulsar **Actualizar opciones disponibles**.

Las características dependen de categoría y región y Google puede modificarlas.

### Aparece Servicios cuando debería existir Menú, o viceversa

Ejecutar una sincronización de la ubicación para actualizar el tipo de catálogo detectado. Si ya existen datos locales en un módulo, Lealez lo mantiene visible para no ocultar contenido.

### El porcentaje no coincide con lo esperado

El porcentaje mide diligenciamiento local de campos recomendados y se ajusta al tipo de ubicación. No mide SEO, ranking, verificación ni calidad de Google.

### Google devuelve otro valor

Usar el estado del módulo y **Verificar estado**. Google puede revisar, normalizar o rechazar cambios. El backend conserva el detalle técnico para soporte.

### Una ruta antigua devuelve 404

Guardar enlaces permanentes y confirmar que las páginas unificadas existen. Las redirecciones requieren una página destino válida.

## Versiones

### 1.5.0

- Perfil frontend de ubicación rediseñado para gestión de cliente.
- Porcentaje global de diligenciamiento.
- Semáforos por sección.
- Menú/Servicios contextuales según el tipo de negocio.
- Categorías identificadas como publicables mediante el flujo dinámico existente.
- Características dinámicas presentadas con nomenclatura amigable.
- Ocultación frontend de IDs, claves de API, payloads y datos RAW.
- Flujo visible Guardar en Lealez → Publicar en Google.
- Backend técnico conservado para soporte.

### 1.4.0

- Páginas frontend nativas de Elementor.
- Seis widgets editables.
- Perfiles unificados de empresa y ubicación.
- Migración desde shortcodes con backup.
- Retiro seguro de páginas redundantes.
- Redirecciones y alias de compatibilidad.

### 1.3.0

- Perfil unificado de ubicación.
- Alcance Google/Lealez por sección y campo.
- Compatibilidad con módulos Google existentes.

### 1.2.0

- Centros frontend de Google Business Profile.
- Puente de permisos para metaboxes existentes.

### 1.1.0

- Portal frontend de empresas, ubicaciones y usuario.
- Instalador inicial basado en shortcodes.
