# Asiento Vacío

Tablero de viajes compartidos entre particulares. El conductor publica los
asientos libres de un viaje que ya iba a hacer y su teléfono aparece a la vista
en la página. **No hay precios, reservas, pagos ni comisiones**: cada viaje
aclara que solo se comparten los gastos de combustible y alguna comida.

- Para viajar no hace falta cuenta: se llama o se escribe por WhatsApp directo al conductor.
- Para publicar, el conductor crea una cuenta y confirma que es un particular que comparte gastos.
- Si alguien escribe un precio, un monto o la palabra "tarifa", la página no lo deja publicar.
- Los viajes que ya pasaron y los que están completos dejan de verse solos.

Funciona con PHP 8.1 o superior y SQLite, sin instalar nada: sirve para el
hosting web de Hostinger (planes Premium o Business).

## Archivos

```
public/            ← esto es lo que va en public_html
  index.html       página
  api.php          servidor (cuentas, viajes, calificaciones y fotos)
  config.php       configuración (código de administración y Google)
  .htaccess        seguridad (HTTPS lo fuerza Hostinger)
  favicon.svg
  assets/          estilos y JavaScript
tests/api_test.php pruebas automáticas
```

Todo lo que se guarda va a `asiento-vacio-datos/`, una carpeta **al lado** de
`public_html` (no adentro), para que nadie la pueda descargar: la base de
datos, las sesiones, las fotos de perfil (`fotos/`) y las claves públicas de
Google (`google-certs.json`). Se crea sola la primera vez que alguien entra.

## Probar en tu computadora

```
php -S 127.0.0.1:8000 -t public     # abrir http://127.0.0.1:8000
php tests/api_test.php              # correr las pruebas
```

## Calificaciones

Cualquier persona con cuenta puede calificar a otra desde su perfil público
(`#perfil-<id>`, se llega tocando el nombre del conductor en un viaje): de 1 a 5
estrellas y un comentario corto opcional. Hay una sola calificación por par de
personas (si se vuelve a calificar, se reemplaza) y se puede borrar. El promedio
aparece en cada viaje y en el perfil, y la búsqueda tiene el filtro "Solo
conductores con 4 estrellas o más" (pide al menos 3 opiniones). No se pide DNI
ni ningún documento.

Cuando alguien toca Llamar o WhatsApp en un viaje, ese conductor queda en "Mi
perfil → Conductores que contactaste" (guardado solo en ese teléfono), así lo
puede calificar después aunque el viaje ya no se vea. Para que no se llene un
perfil de estrellas con cuentas inventadas, hay un límite de calificaciones por
día por cuenta y por conexión, y en Administrar → Calificaciones se marcan las
hechas con cuentas de menos de una semana.

## Ingreso con Google

Además de correo y contraseña se puede entrar con la cuenta de Google. Para
activarlo hace falta un "ID de cliente" de Google (es gratis):

1. Entrar a <https://console.cloud.google.com/>, crear un proyecto y, en
   "Pantalla de consentimiento de OAuth", completar el nombre del sitio.
2. En "Credenciales" → "Crear credenciales" → "ID de cliente de OAuth", elegir
   "Aplicación web" y en "Orígenes de JavaScript autorizados" poner
   `https://asientovacio.com.ar` (y `https://www.asientovacio.com.ar` si se usa).
3. Copiar el ID (termina en `.apps.googleusercontent.com`) y pegarlo en
   Administrar → Ajustes.

Si alguien ya tenía cuenta con un correo de Gmail (o de una empresa que usa
Google), al entrar con Google queda unida a esa cuenta: la contraseña anterior
deja de servir y se cierran las sesiones que estaban abiertas. Con otros correos
(Hotmail, Yahoo, etc.) no se une sola: hay que seguir entrando con la contraseña.
El sitio comprueba la firma de Google por su cuenta, sin librerías extra (hace
falta que PHP pueda bajar archivos por HTTPS, como en Hostinger).

Las cuentas de Google no tienen contraseña, así que una vez que alguien entró
con Google, Ajustes no deja apagar el ingreso con Google (sí cambiar el ID).

Cada uno puede cambiar en "Mi perfil" el nombre que ven los demás.

## Fotos de perfil

Desde "Mi perfil" cada uno puede subir una foto, usar la de su cuenta de Google o
quitarla. La foto se recorta cuadrada, se achica a 320 × 320 y se vuelve a
guardar como JPEG, así se pierden los datos ocultos de la cámara (como la
ubicación). Se ve en los viajes, en el perfil público y en las opiniones; quien
no tiene foto muestra la inicial de su nombre. La administración puede quitar
cualquier foto.

## Probar sin hosting

`php demo/build.php > asiento-vacio-prueba.html` arma una sola página con la
app y un servidor de mentira (`demo/mock-api.js`) que responde como `api.php`
pero guarda todo en el navegador, con viajes y opiniones de ejemplo y un botón
de Google simulado. Sirve para probarla como artifact de Claude. El código de
administración de prueba es `PRUEBA-2026`.

## Hostinger

La forma más simple es subir el zip con el contenido de `public/`:

1. hPanel → Archivos → Administrador de archivos → `public_html`.
2. Subir el zip y, con clic derecho, "Extraer" ahí mismo (si se pisa una versión
   anterior, aceptar reemplazar los archivos).
3. Abrir el sitio. La carpeta de datos se crea sola y lo que había de antes se
   conserva.

El zip se arma desde la rama `hostinger` de este repositorio, que tiene solo el
contenido de `public/`:

```
git subtree split --prefix=asiento-vacio/public -b hostinger
git push origin hostinger
git archive --format=zip -o asiento-vacio-hostinger.zip hostinger
```

También se puede publicar con hPanel → Avanzado → Git, clonando esa rama.

## Administrar

En `#admin` (link "Administración" al pie de la página, y "Administrar" en el
menú para quien ya es administrador) hay un resumen y estas pestañas:

- **Denuncias** que manda la gente desde cada viaje.
- **Calificaciones**: todas las opiniones, con filtro "solo de 1 y 2 estrellas"
  y botón para borrar las que no correspondan.
- **Viajes**: la lista completa, con borrar y restaurar.
- **Usuarios**: filtro por conductores, acompañantes, suspendidos o calificación
  baja. A cada usuario se lo puede suspender 1, 7 o 30 días o sin fecha, con un
  motivo que ve al intentar entrar; mientras dura no puede entrar, sus viajes no
  se ven y sus calificaciones no cuentan, y al cumplirse el plazo se levanta
  sola. También se puede quitar su foto y nombrar o quitar administradores.
- **Ajustes**: el ID de cliente de Google.

La primera cuenta de administración se activa una sola vez con un código
secreto: en `config.php` solo está su huella SHA-256. Para cambiar el código,
reemplazá esa huella por la de uno nuevo (`printf 'CODIGONUEVO' | sha256sum`,
en mayúsculas y sin guiones).

Las versiones anteriores pedían foto del DNI para verificar perfiles. Eso ya no
existe: al actualizar, el sitio borra solo las fotos de DNI que hubieran quedado
en el servidor.

## Aviso

El sitio está pensado como tablero de avisos para compartir gastos, no como
servicio de transporte. Lo que importa ante un control es que en la práctica
sea así: que el conductor ya tenga que viajar y que no gane plata con el viaje.
Conviene consultar con un abogado las normas de tu provincia.
