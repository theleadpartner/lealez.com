# Changelog

Todos los cambios relevantes del plugin se documentan en este archivo.

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
