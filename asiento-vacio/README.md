# Asiento Vacío

Tablero de viajes compartidos entre particulares. El conductor publica los
asientos libres de un viaje que ya iba a hacer y su teléfono aparece a la vista
en la página. **No hay precios, reservas, pagos ni comisiones**: cada viaje
aclara que solo se comparten los gastos de combustible y alguna comida.

- Para viajar no hace falta cuenta: se llama o se escribe por WhatsApp directo al conductor.
- Cada uno crea su cuenta con su **correo o su número de celular** y una contraseña (sin
  documentos). Para publicar, el conductor entra y confirma que es un particular que
  comparte gastos.
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
  config.php       configuración (código de administración)
  .htaccess        seguridad (HTTPS lo fuerza Hostinger)
  favicon.svg
  assets/          estilos y JavaScript
tests/api_test.php pruebas automáticas
```

Todo lo que se guarda va a `asiento-vacio-datos/`, una carpeta **al lado** de
`public_html` (no adentro), para que nadie la pueda descargar: la base de
datos, las sesiones y las fotos de perfil (`fotos/`). Se crea sola la primera
vez que alguien entra.

## Probar en tu computadora

```
php -S localhost:8000 -t public     # abrir http://localhost:8000
php tests/api_test.php              # correr las pruebas
```


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

## Cuentas: correo o celular

Cada uno crea su cuenta en "Entrar → Crear cuenta" con su nombre, su **correo o su
número de celular** y una contraseña (mínimo 10 caracteres). No hace falta
configurar nada ni depender de Google. El correo o el celular sirven para entrar y
no aparecen en el perfil ni en las opiniones. El teléfono que se ve en cada viaje
es el que el conductor escribe al publicarlo (si entró con su celular, ya aparece
cargado), así que en ese caso su usuario queda a la vista: por eso, además del
límite de intentos por conexión, hay uno de 10 contraseñas mal puestas cada 15
minutos por cuenta.

El celular se puede escribir de varias formas y es la misma cuenta: `11 2345 6789`,
`011 15 2345 6789` o `+54 9 11 2345 6789` se guardan como `+5491123456789`, y
`0351 15 555 1234` o `351 555 1234` como `+5493515551234` (la característica puede
ser de 2, 3 o 4 cifras). Si falta la característica (`15 2345 6789`), la página
pide escribirla. Un celular de otro país se escribe con `+` y su código. No se
manda ningún SMS ni correo para confirmar.

**Si alguien se olvida la contraseña**, la administración le da una nueva en
Administrar → Usuarios → "Contraseña nueva" y se la manda con el botón que aparece:
por WhatsApp a su celular o a su correo (no a otro número, así la recibe la persona
de la cuenta). La que tenía deja de servir y se cierran sus sesiones. Después cada
uno la cambia en "Mi perfil → Cambiar contraseña". Para que la gente sepa a quién
avisar, en Administrar está "Tu WhatsApp para quien se olvida la contraseña": ese
número aparece en Entrar como "Escribirle por WhatsApp". A otro administrador no se
le puede dar una contraseña nueva (primero hay que quitarle la administración).

**Cuentas de Google de versiones anteriores.** Las versiones anteriores tenían un
ingreso con Google; ya no existe. Las cuentas creadas con Google (o que se unieron
a Google) no tienen una contraseña que la persona conozca: al intentar entrar ven
"Esta cuenta se creó con Google… Pedile a la administración una contraseña nueva",
y en Administrar → Usuarios aparecen como "Sin contraseña (era de Google)". Si
todavía tienen la sesión abierta, en Mi perfil eligen una contraseña sin escribir
la actual. Las demás cuentas con correo y contraseña siguen funcionando igual.

Las calificaciones de versiones anteriores, que no decían cómo viajaron, se toman
como de conductor si la persona calificada publicó viajes, y si no, de acompañante.

## Fotos de perfil

Desde "Mi perfil" cada uno puede subir una foto o quitarla (es opcional). La página
la achica antes de mandarla; el servidor acepta hasta 8 MB y 4096 × 4096 píxeles, la
endereza si el teléfono la guardó girada, la recorta cuadrada, la achica a 320 × 320
y la vuelve a guardar como JPEG, así se pierden los datos ocultos de la cámara (como
la ubicación); se guarda en
`asiento-vacio-datos/fotos`. Se ve en los viajes, en el perfil público y en las
opiniones; quien no tiene foto muestra la inicial de su nombre. La administración
puede quitar cualquier foto.

## Probar sin hosting

`php demo/build.php > asiento-vacio-prueba.html` arma una sola página con la
app y un servidor de mentira (`demo/mock-api.js`) que responde como `api.php`
pero guarda todo en el navegador, con viajes y opiniones de ejemplo. Se puede
crear una cuenta con cualquier correo o celular, o entrar como Marta (celular
`11 0000 1111`, contraseña `marta-prueba`) para probar las calificaciones en los dos
sentidos. Sirve para probarla como artifact de Claude. El código de administración
de prueba es `PRUEBA-2026`.

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
  sola. También se le puede dar una contraseña nueva (si se la olvidó), quitar su
  foto y nombrar o quitar administradores. El buscador encuentra un celular
  escrito de cualquier forma (`11 2345 6789`, `+54 9 11 2345-6789`).

La primera cuenta de administración se activa con un código secreto: en
`config.php` solo está su huella SHA-256. El código sirve siempre, así que también
es la forma de recuperar la administración: si el dueño pierde el acceso a su
cuenta (por ejemplo, era de Google), crea una cuenta nueva y vuelve a poner el
código. Guardalo bien. Para cambiarlo, reemplazá esa huella por la de uno nuevo
(`printf 'CODIGONUEVO' | sha256sum`, en mayúsculas y sin guiones).

Las versiones anteriores pedían foto del DNI para verificar perfiles. Eso ya no
existe: al actualizar, el sitio borra solo las fotos de DNI que hubieran quedado
en el servidor.

## Aviso

El sitio está pensado como tablero de avisos para compartir gastos, no como
servicio de transporte. Lo que importa ante un control es que en la práctica
sea así: que el conductor ya tenga que viajar y que no gane plata con el viaje.
Conviene consultar con un abogado las normas de tu provincia.
