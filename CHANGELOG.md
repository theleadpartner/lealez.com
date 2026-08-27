# Changelog

Todos los cambios relevantes del plugin se documentan en este archivo.

## [1.5.0] - 2026-08-27

### Agregado
- Indicador de **completitud de la ficha en Lealez** con porcentaje global orientativo y semáforo rojo/amarillo/verde por sección.
- Resumen de secciones pendientes y estado amigable de conexión con Google Business Profile.
- Detección de aplicabilidad del contenido según la capacidad ya identificada por la sincronización de Google: menú para ubicaciones aptas y servicios/catálogo para los demás tipos compatibles.
- Nueva capa `Lealez_Unified_Location_Health_Trait` para centralizar completitud, aplicabilidad y visibilidad segura de módulos.

### Modificado
- Navegación del perfil de ubicación reorganizada en General, Perfil público, Contenido, Interacción y Resultados.
- Los nombres y explicaciones del frontend usan lenguaje de negocio y evitan exponer nombres internos de campos, identificadores o estructuras de API.
- El resumen de ubicación deja de mostrar IDs técnicos de cuenta/ubicación y presenta únicamente información necesaria para el cliente.
- Los módulos técnicos de vinculación y diagnóstico de sincronización quedan disponibles únicamente para administradores del sitio; el cliente conserva estados amigables de conexión y publicación.
- El flujo de edición conserva la secuencia existente: guardar en Lealez, revisar, enviar a GMB y verificar el resultado.
- El cálculo de dirección distingue negocios con ubicación física de negocios de área de servicio y no penaliza una dirección física ausente cuando no corresponde.
- Los atributos continúan siendo dinámicos según categoría y país y su indicador no altera el porcentaje global porque Google los trata como opciones variables.
- El menú y el catálogo se muestran u ocultan según la capacidad detectada para la ubicación, evitando presentar opciones incompatibles.
- Versión del plugin actualizada a `1.5.0`.

### Conservado
- Metaboxes originales, nonces, handlers AJAX, jobs, verificadores, logs, rate limits y procesos reales de publicación hacia Google.
- Guardado local previo a cualquier envío a Google.
- Shortcodes, widgets Elementor, rutas heredadas y permisos existentes fuera del alcance de esta mejora.
- La administración técnica completa en WordPress para soporte y auditoría.

## [1.4.0] - 2026-07-31

### Agregado
- Integración nativa con Elementor cargada únicamente cuando Elementor está disponible.
- Categoría `Lealez` en el editor de Elementor.
- Seis widgets nativos para el portal frontend:
  - Panel de cuenta.
  - Mis empresas.
  - Perfil de empresa.
  - Mis ubicaciones.
  - Perfil de ubicación.
  - Mi perfil.
- Controles Elementor de ancho, densidad, fondos, superficies, colores, tipografía, rellenos, bordes, sombras y botones.
- Resúmenes visuales para empresa y ubicación, inspirados en la lectura clara de perfiles modernos de directorios sin copiar una interfaz externa.
- Navegación interna del perfil de empresa para Perfil, Equipo, Integraciones y Google Business Profile.
- Migración segura de páginas antiguas basadas en shortcode, con respaldo en `_lealez_pre_elementor_content_backup`.
- Detección diferenciada de Elementor activo, instalado pero inactivo y no instalado.
- Limpieza controlada de páginas frontend heredadas gestionadas por Lealez.
- Redirecciones permanentes desde slugs heredados hacia los perfiles unificados.

### Modificado
- El instalador `Páginas frontend de Lealez` crea páginas con datos nativos en `_elementor_data`; ya no inserta shortcodes en `post_content`.
- Crear o reparar páginas requiere Elementor activo para evitar páginas incompletas.
- `Perfil de empresa` concentra edición general, equipo, integraciones y centro Google en una sola página.
- `Perfil de ubicación` conserva el perfil unificado de la versión 1.3.0 y añade una cabecera visual configurable.
- El estado de cada página se valida por la presencia del widget Elementor esperado.
- El botón de edición abre directamente el editor de Elementor.
- Los estilos del portal aceptan variables configurables desde cada widget sin alterar la lógica funcional.
- El alias interno `business_google` apunta al perfil de empresa para conservar la precarga de recursos y los flujos existentes del centro GMB.
- README actualizado con arquitectura, widgets, migración, pruebas, seguridad y control de cambios de la versión 1.4.0.
- Versión del plugin actualizada a `1.4.0`.

### Retirado
- Creación de páginas frontend independientes para Equipo de empresa, Integraciones de empresa, Google de empresa y Google de ubicación.
- Dependencia del shortcode como contenido de las páginas generadas.

### Conservado
- Shortcodes existentes como API interna y capa de compatibilidad.
- Metaboxes, handlers AJAX, nonces, procesos de guardado, permisos, cron jobs, logs y verificadores existentes.
- Redirección compatible de enlaces antiguos.
- Recuperación de páginas retiradas desde la Papelera de WordPress.

## [1.3.0] - 2026-07-30

### Agregado
- Perfil frontend unificado para `oy_location` dentro de `[lealez_location_editor]`.
- Leyenda y etiquetas por sección/campo: Google, datos de Google, solo Lealez, mixto y control técnico.
- Formulario específico para datos administrativos internos sin exposición a Google.
- Resumen de estados locales, envíos, revisión y aplicación en Google.
- Compatibilidad responsive y anotación de campos durante la creación inicial.

### Modificado
- `Editar ubicación` concentra datos internos, GMB, contenido, interacción y analítica.
- El instalador deja de crear la página independiente `Google de ubicación`.
- Los enlaces existentes de la antigua página redirigen al perfil unificado conservando ubicación y módulo.
- La documentación de publicación distingue guardado local, envío y aprobación de Google.
- Versión del plugin actualizada a `1.3.0`.

### Conservado
- La página `Google de empresa` para OAuth, cuentas y propiedades.
- Los metaboxes, handlers AJAX, nonces, jobs, logs y verificadores originales.
- Las meta keys y funciones existentes de los CPT.

## [1.2.0] - 2026-07-30

### Agregado
- Centros frontend para reutilizar módulos reales de Google Business Profile.
- Puente de permisos limitado a empresas y ubicaciones autorizadas.
- Estados de publicación y revisión de Google.

## [1.1.0] - 2026-07-30

### Agregado
- Portal frontend de empresas, ubicaciones y perfil del usuario.
- Instalador de páginas con shortcodes.
- Archivo y restauración sin eliminación de datos.
