# Asiento Vacío

Tablero de viajes compartidos entre particulares. El conductor publica los
asientos libres de un viaje que ya iba a hacer y su teléfono aparece a la vista
en la página. **No hay precios, reservas, pagos ni comisiones**: cada viaje
aclara que solo se comparten los gastos de combustible y alguna comida.

- Para viajar no hace falta cuenta: se llama o se escribe por WhatsApp directo al conductor.
- Se entra con **correo y contraseña** o, si la administración lo activa, con la **cuenta de
  Google** (sin documentos). Para publicar, el conductor entra y confirma que es un
  particular que comparte gastos.
- Conductores y acompañantes se califican entre ellos después del viaje.
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
php -S localhost:8000 -t public     # abrir http://localhost:8000
php tests/api_test.php              # correr las pruebas
```

Para probar el botón de Google en la computadora hace falta un ID de cliente que
tenga autorizado `http://localhost:8000` (ver abajo). Las pruebas no lo
necesitan: usan un "Google" de mentira.


## Calificaciones

Conductores y acompañantes se califican entre ellos desde el perfil público de cada
uno (`#perfil-<id>`): el acompañante llega tocando el nombre del conductor en el
viaje, y el conductor, con el enlace al perfil del acompañante que trae el mensaje
de WhatsApp. Al calificar se elige cómo viajaron ("manejaba: era el conductor" o
"viajó conmigo como acompañante"), de 1 a 5 estrellas y un comentario corto
opcional. Cada perfil muestra por separado el promedio **como conductor** y
**como acompañante**; en los viajes se ve el de conductor y la búsqueda tiene el
filtro "Solo conductores con 4 estrellas o más" (pide al menos 3 opiniones). Hay
una sola calificación por par de personas (si se vuelve a calificar, se
reemplaza) y se puede borrar.

Cuando alguien toca Llamar o WhatsApp en un viaje, ese conductor queda en "Mi
perfil → Conductores que contactaste" (guardado solo en ese teléfono), así lo
puede calificar después aunque el viaje ya no se vea. Para que no se llene un
perfil de estrellas con cuentas inventadas, hay un límite de calificaciones por
día por cuenta y por conexión, y en Administrar → Calificaciones se marcan las
hechas con cuentas de menos de una semana.

## Cuentas: correo y contraseña, y Google (opcional)

Cada uno crea su cuenta en "Entrar → Crear cuenta" con nombre, correo y contraseña
(mínimo 10 caracteres). No hace falta configurar nada.

Además, la administración puede activar el botón "Continuar con Google": la cuenta
se crea sola la primera vez con el nombre y la foto de Google. Hace falta un "ID de
cliente" de Google (es gratis), que se consigue una sola vez:

1. Entrar a <https://console.cloud.google.com/>, crear un proyecto y, en
   "Pantalla de consentimiento de OAuth", elegir público "Externo", completar
   el nombre del sitio y tocar "Publicar app" (si queda "en prueba", solo pueden
   entrar los usuarios de prueba que se carguen ahí).
2. En "Credenciales" → "Crear credenciales" → "ID de cliente de OAuth", elegir
   "Aplicación web" y en "Orígenes de JavaScript autorizados" poner
   `https://asientovacio.com.ar` y `https://www.asientovacio.com.ar`.
3. Copiar el ID (termina en `.apps.googleusercontent.com`) y pegarlo en
   Administrar → Ajustes.

Si alguien ya tenía cuenta con un correo de Gmail (o de una empresa que usa
Google), al entrar con Google queda unida a esa cuenta: la contraseña anterior
deja de servir y se cierran las sesiones que estaban abiertas. Con otros correos
(Hotmail, Yahoo, etc.) no se une sola: hay que seguir entrando con la contraseña.
El sitio comprueba la firma de Google por su cuenta, sin librerías extra (hace
falta que PHP pueda bajar archivos por HTTPS, como en Hostinger).

Las cuentas creadas con Google no tienen contraseña, así que una vez que alguien
entró con Google, Ajustes no deja apagar el ingreso con Google (sí cambiar el ID).

Las calificaciones de versiones anteriores, que no decían cómo viajaron, se toman
como de conductor si la persona calificada publicó viajes, y si no, de acompañante.

## Fotos de perfil

La foto de perfil es la de la cuenta de Google: se pone sola al entrar con Google
y se actualiza si la persona la cambia ahí. Nadie sube fotos: las cuentas con
correo y contraseña muestran la inicial del nombre. Desde "Mi perfil"
se puede quitar (y ya no vuelve sola) o volver a mostrar. La foto se recorta
cuadrada, se achica a 320 × 320 y se guarda en `asiento-vacio-datos/fotos`. Se ve
en los viajes, en el perfil público y en las opiniones; quien no tiene foto
muestra la inicial de su nombre. La administración puede quitar cualquier foto.

## Probar sin hosting

`php demo/build.php > asiento-vacio-prueba.html` arma una sola página con la
app y un servidor de mentira (`demo/mock-api.js`) que responde como `api.php`
pero guarda todo en el navegador, con viajes y opiniones de ejemplo y un botón
de Google simulado que deja entrar con una cuenta nueva o como Marta, Lucía o
Diego (para probar las calificaciones en los dos sentidos). Sirve para probarla
como artifact de Claude. El código de administración de prueba es `PRUEBA-2026`.

## Hostinger

La forma más simple es subir el zip con el contenido de `public/`:

1. hPanel → Archivos → Administrador de archivos → `public_html`.
2. Subir el zip y, con clic derecho, "Extraer" ahí mismo (si se pisa una versión
   anterior, aceptar reemplazar los archivos).
3. Si quedó una copia vieja en una subcarpeta (por ejemplo `public_html/viajes`),
   borrarla: usa la misma carpeta de datos y tiene cosas que ya no van (como
   pedir fotos del DNI).
4. Abrir el sitio. La carpeta de datos se crea sola y lo que había de antes se
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
- **Calificaciones**: todas las opiniones, con cómo viajaron ("como conductor"
  o "como acompañante"), filtro "solo de 1 y 2 estrellas" y botón para borrar las
  que no correspondan.
- **Viajes**: la lista completa, con borrar y restaurar.
- **Usuarios**: filtro por conductores, acompañantes, suspendidos o calificación
  baja. A cada usuario se lo puede suspender 1, 7 o 30 días o sin fecha, con un
  motivo que ve al intentar entrar; mientras dura no puede entrar, sus viajes no
  se ven y sus calificaciones no cuentan, y al cumplirse el plazo se levanta
  sola. También se puede quitar su foto y nombrar o quitar administradores.
- **Ajustes**: el ID de cliente de Google, para activar "Continuar con Google".

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
