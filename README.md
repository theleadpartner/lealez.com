# Lealez

Plugin de WordPress para administrar empresas, ubicaciones, perfiles de Google Business Profile y la base de programas de lealtad. Desde la versión **1.4.0**, el portal de autogestión se publica mediante **widgets nativos de Elementor**, mientras Lealez conserva permisos, validaciones, guardado, sincronización con Google y compatibilidad con los shortcodes existentes. La versión **1.5.0** refuerza el perfil de ubicación con una experiencia orientada al cliente, completitud por sección y opciones adaptadas a las capacidades que Google reporta para cada tipo de negocio.

![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-21759B?logo=wordpress)
![PHP](https://img.shields.io/badge/PHP-7.4%2B-777BB4?logo=php)
![Version](https://img.shields.io/badge/version-1.5.0-3782c4)
![Elementor](https://img.shields.io/badge/Elementor-required%20for%20frontend%20pages-92003B?logo=elementor)

## Contenido

- [Objetivo](#objetivo)
- [Alcance actual](#alcance-actual)
- [Requisitos](#requisitos)
- [Arquitectura](#arquitectura)
- [Carga del plugin](#carga-del-plugin)
- [Modelo de datos](#modelo-de-datos)
- [Portal frontend con Elementor](#portal-frontend-con-elementor)
- [Páginas generadas](#páginas-generadas)
- [Widgets de Elementor](#widgets-de-elementor)
- [Controles editables](#controles-editables)
- [Perfil unificado de empresa](#perfil-unificado-de-empresa)
- [Perfil unificado de ubicación](#perfil-unificado-de-ubicación)
- [Completitud y adaptación por tipo de negocio](#completitud-y-adaptación-por-tipo-de-negocio)
- [Migración desde shortcodes](#migración-desde-shortcodes)
- [Páginas heredadas retiradas](#páginas-heredadas-retiradas)
- [Google Business Profile](#google-business-profile)
- [Permisos y seguridad](#permisos-y-seguridad)
- [Shortcodes compatibles](#shortcodes-compatibles)
- [Estructura del repositorio](#estructura-del-repositorio)
- [Instalación](#instalación)
- [Configuración de páginas](#configuración-de-páginas)
- [Pruebas recomendadas](#pruebas-recomendadas)
- [Control de cambios](#control-de-cambios)
- [Solución de problemas](#solución-de-problemas)

## Objetivo

Lealez separa dos responsabilidades:

1. **Lógica de negocio:** CPT, permisos, metadatos, nonces, formularios, AJAX, Google Business Profile, cron, logs y validaciones.
2. **Presentación:** páginas WordPress editables con Elementor, sin copiar la lógica funcional dentro del constructor visual.

Esta separación permite cambiar el diseño del portal sin reemplazar los procesos que ya funcionan. Los widgets de Elementor usan los renderers y shortcodes de Lealez como API interna para preservar compatibilidad.

En el frontend de cliente se prioriza lenguaje de negocio. Identificadores internos, nombres de campos de API, recursos técnicos, claves, payloads, límites y logs no forman parte de la experiencia normal del usuario. La información técnica se conserva para administración y soporte donde corresponde.

## Alcance actual

El repositorio incluye:

- CPT `oy_business` para empresas.
- CPT `oy_location` para ubicaciones o sedes.
- CPT base de programas y tarjetas de lealtad.
- metaboxes administrativos;
- portal frontend autenticado;
- creación, edición, archivo y restauración de empresas y ubicaciones;
- perfil de usuario;
- administración de equipo e integraciones;
- módulos de Google Business Profile;
- perfil frontend unificado de ubicación;
- indicador de completitud de la ubicación;
- navegación y contenido adaptados al tipo/capacidad de negocio sincronizada desde Google;
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
| Elementor | Activo para crear, reparar y editar las páginas frontend |
| HTTPS | Recomendado y necesario para OAuth y producción |
| Permalinks | Recomendado usar una estructura amigable |

Elementor no es necesario para cargar los CPT o la administración interna. Cuando no está activo, Lealez bloquea únicamente la creación o reparación de páginas que dependerían de un widget Elementor inexistente.

## Arquitectura

```text
WordPress
└── Lealez_Plugin
    ├── CPT y taxonomías
    ├── Integración Google Business Profile
    ├── Administración de WordPress
    ├── Lealez_Frontend_Portal
    │   ├── páginas y rutas
    │   ├── empresas
    │   ├── ubicaciones
    │   ├── usuario
    │   └── perfil unificado de ubicación
    │       ├── navegación y render
    │       ├── metaboxes Google existentes
    │       ├── permisos
    │       └── completitud y aplicabilidad
    └── Lealez_Elementor_Integration
        ├── categoría Lealez
        ├── registro de assets
        └── widgets del portal
```

### Principios

- No duplicar reglas de acceso en JavaScript.
- No guardar secretos en Elementor.
- No reemplazar metaboxes o handlers existentes sin necesidad.
- Mantener el flujo **editar → guardar en Lealez → enviar a Google → verificar**.
- No presentar un guardado local como publicación exitosa.
- Consultar capacidades/categorías/atributos disponibles antes de asumir que una opción aplica a todos los negocios.
- Mantener shortcodes y acciones públicas como contratos de compatibilidad.
- Crear páginas idempotentes: ejecutar el instalador varias veces no debe duplicarlas.
- Reparar una página sin borrar una composición Elementor existente.
- Retirar páginas antiguas solo cuando Lealez pueda demostrar que las gestiona.

## Carga del plugin

`lealez.php` realiza el arranque:

1. Define constantes y versión.
2. Registra activación y desactivación.
3. Carga CPT y taxonomías.
4. Carga Google Business Profile si sus archivos existen.
5. Carga el portal frontend tanto en administración como en frontend.
6. Carga el puente Elementor sin producir errores cuando Elementor está inactivo.
7. Espera el hook `elementor/init` antes de registrar categoría y widgets.
8. Carga clases administrativas únicamente en `is_admin()`.

Constantes principales:

| Constante | Uso |
|---|---|
| `LEALEZ_VERSION` | Versionado de código y assets |
| `LEALEZ_PLUGIN_FILE` | Archivo principal |
| `LEALEZ_PLUGIN_DIR` | Ruta absoluta del plugin |
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

El equipo solo puede administrarlo un usuario con permisos administrativos, el autor o un usuario listado como administrador.

### `oy_location`

Representa una sede, sucursal, punto de atención o negocio local. Se relaciona con la empresa mediante `parent_business_id`.

El acceso se concede al autor, a un administrador de WordPress o a quien tenga acceso a la empresa padre.

Los metadatos de sincronización con Google continúan existiendo para compatibilidad técnica, pero el frontend de cliente no muestra IDs de cuenta, IDs de ubicación, resource names, payloads RAW ni nombres internos de propiedades de la API.

### Estado y archivo

Lealez usa estados de WordPress para conservar datos. Archivar no elimina metadatos. Las operaciones de retiro de páginas también usan la Papelera para permitir recuperación.

## Portal frontend con Elementor

La sección **Lealez → Páginas frontend** es el instalador central.

El instalador:

- detecta Elementor activo;
- distingue Elementor instalado pero inactivo;
- bloquea crear o reparar si Elementor no está activo;
- crea páginas publicadas;
- guarda una estructura nativa en `_elementor_data`;
- deja `post_content` vacío;
- asigna `elementor_header_footer`;
- marca `_elementor_edit_mode` como `builder`;
- valida que exista el widget esperado;
- agrega el widget faltante sin borrar otros elementos;
- limpia la caché de archivos de Elementor;
- conserva una copia del contenido clásico anterior.

## Páginas generadas

Se mantienen seis páginas de usuario:

| Clave | Página | Slug de instalación nueva | Widget |
|---|---|---|---|
| `portal` | Mi cuenta Lealez | `/mi-cuenta-lealez/` | `lealez-account-dashboard` |
| `businesses` | Mis empresas | `/mi-cuenta-lealez/mis-empresas/` | `lealez-business-list` |
| `business_editor` | Perfil de empresa | `/mi-cuenta-lealez/editar-empresa/` | `lealez-business-profile` |
| `locations` | Mis ubicaciones | `/mi-cuenta-lealez/mis-ubicaciones/` | `lealez-location-list` |
| `location_editor` | Perfil de ubicación | `/mi-cuenta-lealez/editar-ubicacion/` | `lealez-location-profile` |
| `user_profile` | Mi perfil | `/mi-cuenta-lealez/mi-perfil/` | `lealez-user-profile` |

Las instalaciones existentes conservan el ID y el permalink de la página previamente vinculada; la migración no obliga a cambiar enlaces activos.

Los IDs se guardan en:

```text
lealez_frontend_page_ids
```

Cada página administrada por el instalador recibe:

```text
_lealez_frontend_page_key
```

## Widgets de Elementor

Los widgets aparecen en la categoría **Lealez**:

### Lealez — Panel de cuenta

Renderiza resumen de empresas, ubicaciones y accesos principales.

### Lealez — Mis empresas

Renderiza listado, creación, edición, archivo y restauración de empresas autorizadas.

### Lealez — Perfil de empresa

Concentra:

- datos generales;
- marca;
- contacto;
- redes y datos legales;
- equipo;
- integraciones;
- Google Business Profile.

### Lealez — Mis ubicaciones

Renderiza listado, filtro por empresa, creación, edición, archivo y restauración.

### Lealez — Perfil de ubicación

Renderiza el perfil unificado con datos internos, información pública, contenido, interacción, resultados y los módulos reales de Google Business Profile. En 1.5.0 agrega completitud, semáforo por sección y adaptación de módulos por tipo/capacidad del negocio.

### Lealez — Mi perfil

Renderiza datos personales, correo y cambio de contraseña con las validaciones existentes.

## Controles editables

Cada widget ofrece controles de Elementor para:

- ancho máximo responsive;
- densidad compacta, cómoda o amplia;
- relleno general;
- relleno de tarjetas;
- color de fondo de página;
- color de superficies;
- color principal y principal oscuro;
- texto principal y secundario;
- bordes;
- radio de tarjetas;
- sombras;
- tipografía general;
- tipografía de encabezados;
- color, tipografía y radio de botones;
- ocultar el encabezado interno;
- mostrar u ocultar el resumen visual en perfiles.

Los controles generan variables CSS dentro del wrapper del widget. No alteran otros widgets ni el administrador de WordPress.

## Perfil unificado de empresa

El perfil usa una sola página y navegación por query string:

```text
?business_id=123
?business_id=123&section=team
?business_id=123&section=integrations
?business_id=123&section=google
```

La cabecera visual prioriza lectura rápida:

- portada;
- logotipo;
- nombre;
- descripción o tagline;
- industria;
- número de ubicaciones;
- estado;
- acceso a ubicaciones.

El enfoque toma como referencia la jerarquía clara de perfiles de directorios modernos: identidad primero, navegación visible y contenido agrupado. No replica marcas, textos ni componentes propietarios de terceros.

## Perfil unificado de ubicación

La página conserva el sistema introducido en 1.3.0 y ampliado en 1.5.0:

- resumen de publicación;
- datos internos;
- alcance de sincronización por sección;
- metaboxes existentes;
- guardado local;
- envío a Google;
- revisión y estado de aplicación;
- módulos de contenido, interacción y resultados;
- porcentaje de completitud global;
- semáforo por cada sección relevante;
- conteo de secciones con cambios por revisar;
- estado de conexión expresado sin identificadores técnicos;
- navegación agrupada y más corta;
- menú/servicios adaptados a la capacidad detectada para la ubicación.

La cabecera conserva portada, identidad, dirección, categoría, teléfono, estado y enlace al sitio web mediante los controles del widget Elementor.

### Navegación 1.5.0

El cliente ve grupos orientados a tareas:

- **General:** Resumen y Administración Lealez.
- **Perfil público:** Información del negocio, Ubicación y áreas de servicio, Contacto y acciones, Horarios, Características del negocio.
- **Contenido:** Fotos, Menú o Servicios/Catálogo según corresponda, Publicaciones.
- **Interacción:** Opiniones.
- **Resultados:** Rendimiento, Búsquedas y Mayor interés.

Los módulos de **Vinculación con Google** y **Diagnóstico de sincronización** son técnicos y se muestran únicamente a administradores del sitio. Los usuarios de empresa siguen viendo el estado de conexión, los cambios pendientes y el estado de cada envío, sin exponer información innecesaria.

## Completitud y adaptación por tipo de negocio

`Lealez_Unified_Location_Health_Trait` implementa una capa de experiencia sin modificar la lógica de publicación de los metaboxes.

### Porcentaje global

El porcentaje es una **guía interna de Lealez**, no una puntuación oficial de Google. Se calcula con información que el usuario puede completar de manera razonable:

- información del negocio;
- dirección o cobertura;
- contacto;
- horarios;
- menú o catálogo únicamente cuando ese módulo aplica.

Los estados visuales son:

- **Rojo — Requiere atención:** menos de 50%.
- **Amarillo — En progreso:** 50% a 79%.
- **Verde — Bien completado:** 80% o más.

### Áreas de servicio

Si la ubicación está configurada como negocio de área de servicio, el cálculo no exige una dirección física completa. Se priorizan país, ciudad/cobertura y la configuración correspondiente. Esto evita tratar como incompleta una ficha para la cual una dirección visible no corresponde.

### Atributos dinámicos

Los atributos se muestran como sección de estado, pero no reducen el porcentaje global. Google define atributos dinámicos por categoría y país, y estos pueden cambiar. Lealez conserva el flujo existente que consulta las opciones compatibles antes de publicar.

### Menú y servicios

Lealez reutiliza `gmb_catalog_type`, ya generado por la sincronización existente:

- `food_menu`: muestra Menú y oculta Servicios/Catálogo.
- `services` o `products`: muestra Servicios/Catálogo y oculta Menú.
- `none`: no presenta un módulo de catálogo incompatible.
- sin valor aún: mantiene ambos accesibles de forma informativa hasta que Google confirme las capacidades.

Esta lógica no inventa compatibilidad. El metabox y el endpoint existentes siguen siendo la fuente real de lectura/publicación.

## Migración desde shortcodes

Cuando una página existe pero no contiene el widget esperado, el botón **Agregar widget de Lealez** ejecuta una reparación.

Proceso:

1. Verifica permisos y nonce.
2. Confirma que Elementor está activo.
3. Lee `_elementor_data`.
4. Si la página ya tiene una composición, agrega una nueva sección con el widget faltante.
5. Si no tiene datos Elementor, crea la estructura inicial.
6. Guarda el contenido clásico anterior en `_lealez_pre_elementor_content_backup`.
7. Vacía `post_content` para evitar doble renderizado.
8. Activa el modo builder.
9. Elimina `_elementor_css` obsoleto.
10. Limpia la caché de Elementor.

La migración no elimina los shortcodes del plugin. Estos continúan disponibles como API interna y para páginas personalizadas no administradas por el instalador.

## Páginas heredadas retiradas

Ya no se crean páginas independientes para:

- Equipo de empresa;
- Integraciones de empresa;
- Google de empresa;
- Google de ubicación.

Estas funciones ahora son secciones de perfiles unificados.

La limpieza solo mueve una página a la Papelera cuando:

- su ID estaba guardado por Lealez; y
- su meta `_lealez_frontend_page_key` coincide; o
- contiene el shortcode heredado correspondiente.

Nunca se elimina permanentemente una página. Las rutas antiguas redirigen al destino unificado conservando IDs y módulo cuando aplica.

## Google Business Profile

La integración existente se conserva:

- OAuth;
- cuentas y grupos;
- selección de propiedades;
- metaboxes de empresa y ubicación;
- AJAX;
- jobs y verificadores;
- estados locales, enviados, en revisión y aplicados;
- logs y rate limiting.

### Flujo de edición y publicación

Lealez diferencia explícitamente cuatro pasos:

1. **Editar.** El usuario modifica una sección.
2. **Guardar en Lealez.** Los datos quedan locales y pueden marcarse como pendientes.
3. **Enviar a GMB.** El metabox correspondiente usa su handler real y únicamente los campos que soporta.
4. **Verificar.** El estado se actualiza cuando el verificador confirma qué ocurrió en Google.

Guardar no equivale a publicar. Google puede aplicar, revisar, modificar o rechazar un cambio.

### Compatibilidad con Business Information API

La UI 1.5.0 se diseñó teniendo en cuenta el comportamiento documentado de Google Business Profile:

- las actualizaciones de una ubicación se realizan por campos concretos mediante `updateMask`;
- categorías y atributos disponibles deben consultarse con los servicios oficiales porque cambian por país, idioma y tipo de negocio;
- los atributos compatibles dependen de categoría y región;
- un negocio que atiende únicamente en área de servicio no debe tratarse como si necesitara dirección física visible;
- los menús de alimentos solo se deben administrar cuando la ubicación es elegible para esa capacidad;
- información de salida o diagnóstico de Google no debe confundirse con campos que el cliente deba editar.

Por esta razón, la capa de perfil no intenta enviar campos que los metaboxes existentes todavía no soportan de forma segura. Las capacidades reales siguen estando determinadas por la integración y los endpoints ya implementados.

Para compatibilidad, `business_google` se mantiene como alias interno del ID de `business_editor`. Esto permite que el centro GMB precargue sus assets antes de `wp_head`, aunque ya no exista una página pública separada.

La URL con `module` también abre la sección Google:

```text
?business_id=123&module=snapshot
```

## Permisos y seguridad

### Administración

Crear, reparar o retirar páginas requiere:

- `manage_options`;
- nonce válido;
- acción `admin-post.php` registrada.

Los módulos técnicos de una ubicación —vinculación, identificadores, límites, procesos e historial de sincronización— se reservan para administradores del sitio. La administración de WordPress conserva la información técnica necesaria para soporte y auditoría.

### Frontend

Cada renderer valida de nuevo:

- sesión iniciada;
- tipo de post;
- ID válido;
- acceso a empresa o ubicación;
- visibilidad del módulo para el rol actual;
- permisos adicionales para equipo;
- nonce en operaciones de escritura.

El frontend de cliente evita mostrar nombres internos de propiedades de Google, IDs técnicos, payloads RAW, claves o variables que no sean necesarias para tomar una decisión de negocio.

### Caché

Las páginas administradas por Lealez definen:

```php
DONOTCACHEPAGE
DONOTCACHEOBJECT
```

y envían `nocache_headers()` para reducir el riesgo de servir contenido personalizado de un usuario a otro.

### Elementor

Elementor controla presentación. La existencia de un widget en una página no concede acceso a registros. Los secretos, tokens y permisos permanecen fuera de `_elementor_data`.

## Shortcodes compatibles

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

No deben retirarse sin una migración de compatibilidad porque son utilizados por widgets, filtros y páginas existentes.

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
│   │   └── lealez-frontend-unified-location.css
│   └── js/frontend/
├── includes/
│   ├── admin/
│   ├── cpts/
│   ├── elementor/
│   │   ├── class-lealez-elementor.php
│   │   └── widgets/
│   ├── frontend/
│   │   ├── class-lealez-frontend-portal.php
│   │   ├── lealez-frontend-pages-trait.php
│   │   ├── lealez-frontend-page-definitions-trait.php
│   │   ├── lealez-frontend-page-admin-trait.php
│   │   ├── lealez-frontend-page-status-trait.php
│   │   ├── lealez-frontend-page-installer-trait.php
│   │   ├── lealez-frontend-page-routing-trait.php
│   │   ├── lealez-frontend-page-access-trait.php
│   │   ├── lealez-frontend-business-trait.php
│   │   ├── lealez-frontend-location-trait.php
│   │   ├── class-lealez-frontend-unified-location-profile.php
│   │   ├── lealez-unified-location-routing-trait.php
│   │   ├── lealez-unified-location-render-trait.php
│   │   ├── lealez-unified-location-modules-trait.php
│   │   ├── lealez-unified-location-health-trait.php
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
| `lealez-frontend-pages-trait.php` | Composición de traits de páginas |
| `lealez-frontend-page-definitions-trait.php` | Definiciones de páginas, widgets, páginas heredadas y detección de Elementor |
| `lealez-frontend-page-admin-trait.php` | Interfaz administrativa de creación, reparación y limpieza |
| `lealez-frontend-page-status-trait.php` | Descubrimiento de páginas y validación recursiva del widget esperado |
| `lealez-frontend-page-installer-trait.php` | Creación y migración Elementor |
| `lealez-frontend-page-routing-trait.php` | URLs, alias, limpieza y redirecciones |
| `lealez-frontend-page-access-trait.php` | Helpers, avisos y permisos |
| `class-lealez-frontend-unified-location-profile.php` | Composición del perfil frontend de ubicación |
| `lealez-unified-location-modules-trait.php` | Mapa de secciones y alcance funcional |
| `lealez-unified-location-health-trait.php` | Completitud, semáforo, aplicabilidad y visibilidad segura por módulo |
| `lealez-unified-location-render-trait.php` | Navegación, resumen y render del perfil |
| `lealez-unified-location-routing-trait.php` | Rutas, assets y validación de sección visible |
| `class-lealez-elementor.php` | Inicialización y registro de widgets |
| `class-lealez-elementor-portal-widget.php` | Clase base y dependencias de los widgets |
| `class-lealez-elementor-portal-widgets.php` | Seis widgets concretos del portal |
| `trait-lealez-elementor-content-controls.php` | Controles funcionales y de presentación general |
| `trait-lealez-elementor-style-controls.php` | Controles de color, espacio, tarjetas, tipografía y botones |
| `trait-lealez-elementor-profile-render.php` | Enrutamiento del renderizado unificado |
| `trait-lealez-elementor-profile-summary.php` | Cabeceras visuales de empresa y ubicación |
| `trait-lealez-elementor-profile-access.php` | Acceso y resolución de páginas dentro de los widgets |
| `lealez-frontend-unified-location.css` | Completitud, semáforos, navegación y responsive del perfil de ubicación |
| `lealez-elementor-portal.css` | Capa visual aislada para Elementor |

## Instalación

1. Descargar o clonar el repositorio.
2. Ubicarlo en `wp-content/plugins/lealez`.
3. Verificar PHP 7.4+ y WordPress 6.0+.
4. Instalar y activar Elementor.
5. Activar Lealez.
6. Guardar enlaces permanentes si es una instalación nueva.
7. Configurar Google Business Profile si aplica.
8. Crear las páginas frontend desde el menú Lealez.

## Configuración de páginas

1. Entrar como administrador.
2. Ir a **Lealez → Páginas frontend**.
3. Confirmar el aviso **Elementor activo**.
4. Pulsar **Crear o reparar todas con Elementor**.
5. Verificar que las seis filas indiquen **Lista en Elementor**.
6. Abrir cada página con **Editar con Elementor**.
7. Ajustar estilos desde el widget Lealez.
8. Mantener el widget funcional en la página.
9. Si se retiraron páginas heredadas, revisar la Papelera antes de vaciarla.

## Pruebas recomendadas

### Carga

- Lealez activa sin Elementor: no hay fatal error.
- Elementor activo: aparecen seis widgets en categoría Lealez.
- Administración y CPT siguen disponibles.

### Instalador

- Sin Elementor activo, botones de creación están bloqueados.
- Crear todas produce seis páginas, no diez.
- Repetir la acción no duplica páginas.
- `post_content` queda vacío.
- `_elementor_data` contiene el widget correcto.
- El botón editar abre Elementor.

### Migración

- Página con shortcode conserva backup.
- Página Elementor existente conserva sus elementos.
- Reparar agrega solo el widget faltante.
- Página heredada gestionada pasa a Papelera.
- Página ajena con slug parecido no se modifica.

### Empresa

- Crear y editar empresa.
- Navegar por Perfil, Equipo, Integraciones y Google.
- Guardar cada sección y volver a la sección correcta.
- Usuario sin acceso recibe panel de prohibición.

### Ubicación

- Crear ubicación.
- Abrir perfil unificado.
- Confirmar porcentaje global y semáforos por sección.
- Verificar que el porcentaje se identifique expresamente como indicador de Lealez, no de Google.
- Probar una sede física y un negocio de área de servicio.
- Verificar que un área de servicio no requiera dirección física para el cálculo de completitud.
- Probar ubicación `food_menu`: debe mostrar Menú y ocultar Servicios/Catálogo.
- Probar ubicación `services`/`products`: debe mostrar Servicios/Catálogo y ocultar Menú.
- Probar `gmb_catalog_type=none`: no debe presentar un catálogo incompatible.
- Probar ubicación aún no clasificada: Menú y Servicios permanecen consultables hasta confirmar capacidad.
- Guardar cambios y confirmar que no se publican automáticamente.
- Enviar desde una sección compatible y verificar estados local/en cola/revisión/aplicado/error.
- Validar que un administrador de empresa no vea IDs técnicos, payloads, límites, logs ni los módulos técnicos de sincronización.
- Validar que un administrador del sitio sí pueda acceder a Vinculación y Diagnóstico.
- Confirmar que rutas antiguas redirigen.

### Responsive

- 1440 px, 1024 px, 768 px, 390 px y 320 px.
- Navegación utilizable y sidebar sin bloquear el scroll.
- Tarjetas de completitud legibles.
- Formularios sin desbordamiento.
- Botones y cabecera legibles.

## Control de cambios

Flujo recomendado:

1. Crear rama desde `main`.
2. Limitar el alcance del cambio.
3. No reutilizar una rama ya fusionada.
4. Ejecutar `php -l` sobre todos los PHP modificados.
5. Probar WordPress y Elementor.
6. Actualizar `CHANGELOG.md`.
7. Actualizar versión en `lealez.php` cuando corresponda.
8. Abrir pull request con resumen, riesgos, migración y pruebas.

Convenciones de commit:

```text
feat: add location completion and applicability model
fix: preserve existing Elementor layout during repair
docs: document location profile UX version 1.5.0
chore: bump plugin version to 1.5.0
```

## Solución de problemas

### “Elementor no está activo”

Activar `elementor/elementor.php`. Lealez no crea páginas incompletas.

### “Requiere migración a Elementor”

La página existe, pero usa contenido clásico o shortcode. Pulsar **Agregar widget de Lealez**.

### “Falta el widget de Lealez”

La página tiene datos Elementor, pero no el widget esperado. Reparar agrega una sección sin borrar la composición existente.

### Los estilos no cambian

- Regenerar CSS y datos en Elementor.
- Limpiar caché de WordPress, CDN y navegador.
- Confirmar que `lealez-elementor-portal.css` y `lealez-frontend-unified-location.css` cargan después del CSS base.

### No aparece Menú o Servicios

La versión 1.5.0 adapta esos módulos a la capacidad ya detectada para la ubicación. Revisar primero una sincronización correcta de la propiedad. Si Google todavía no ha confirmado el tipo de catálogo, Lealez mantiene las opciones disponibles de manera informativa.

### El porcentaje no coincide con una puntuación de Google

Es correcto. **Completitud en Lealez** es una guía interna para ayudar a detectar datos pendientes. No representa ranking, calidad ni puntuación oficial de Google Business Profile.

### Google abre la sección incorrecta

Confirmar que la URL conserva `business_id` y `section=google` o `module`. Ejecutar **Crear o reparar todas** para sincronizar el alias `business_google`.

### Una ruta antigua devuelve 404

Guardar enlaces permanentes y confirmar que las páginas unificadas existen. Las redirecciones dependen de una página destino válida.

## Versiones

### 1.5.0

- Perfil de ubicación orientado a tareas y lenguaje de cliente.
- Completitud global y semáforo por sección.
- Adaptación de Menú/Servicios al tipo de negocio detectado.
- Manejo de completitud específico para áreas de servicio.
- Atributos dinámicos sin penalización artificial del porcentaje general.
- Módulos técnicos restringidos a administradores del sitio.
- Eliminación de IDs y términos internos del resumen del cliente.
- Conservación del flujo local → envío → verificación de Google.

### 1.4.0

- Páginas frontend nativas de Elementor.
- Seis widgets editables.
- Perfiles unificados de empresa y ubicación.
- Migración desde shortcodes con backup.
- Retiro seguro de páginas redundantes.
- Redirecciones y alias de compatibilidad.
- Nueva capa visual inspirada en la claridad de perfiles de directorios modernos.

### 1.3.0

- Perfil unificado de ubicación.
- Alcance Google/Lealez por sección y campo.
- Compatibilidad con módulos GMB existentes.

### 1.2.0

- Centros frontend de Google Business Profile.
- Puente de permisos para metaboxes existentes.

### 1.1.0

- Portal frontend de empresas, ubicaciones y usuario.
- Instalador inicial basado en shortcodes.
