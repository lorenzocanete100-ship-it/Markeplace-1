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
  api.php          servidor (cuentas y viajes)
  config.php       configuración (código de administración)
  .htaccess        seguridad (HTTPS lo fuerza Hostinger)
  favicon.svg
  assets/          estilos y JavaScript
tests/api_test.php pruebas automáticas
```

La base de datos se crea sola en `asiento-vacio-datos/`, una carpeta **al lado**
de `public_html` (no adentro), para que nadie la pueda descargar.

## Probar en tu computadora

```
php -S 127.0.0.1:8000 -t public     # abrir http://127.0.0.1:8000
php tests/api_test.php              # correr las pruebas
```

## Verificación de perfiles (opcional)

Conductores y acompañantes pueden crear una cuenta y, si quieren, mandar desde
"Mi perfil" foto del DNI (frente y dorso) y una selfie con el DNI. La
administración las revisa en Administrar → Verificaciones y aprueba o rechaza
con un motivo. Las fotos se recodifican (se pierden los datos ocultos de la
cámara, como la ubicación), se guardan en `asiento-vacio-datos/verificaciones`
(fuera de `public_html`), solo las puede ver la administración y **se borran
apenas se aprueban o rechazan**. Los perfiles verificados muestran la insignia
"Perfil verificado" en sus viajes y en su perfil público (`#perfil-<id>`), y un
acompañante verificado manda el enlace a su perfil en el WhatsApp al conductor.

## Probar sin hosting

`php demo/build.php > asiento-vacio-prueba.html` arma una sola página con la
app y un servidor de mentira (`demo/mock-api.js`) que responde como `api.php`
pero guarda todo en el navegador, con viajes de ejemplo. Sirve para probarla
como artifact de Claude. El código de administración de prueba es `PRUEBA-2026`.

## Hostinger

Está publicada en `public_html/viajes` de asientovacio.com.ar, clonada por
Hostinger desde la rama `hostinger` de este repositorio (que tiene solo el
contenido de `public/`).

Para actualizarla después de cambiar algo:

```
git subtree split --prefix=asiento-vacio/public -b hostinger
git push origin hostinger
```

y en hPanel → Avanzado → Git, "Deploy" sobre la carpeta `viajes`.

Para que asientovacio.com.ar (sin `/viajes/`) abra la app, crear en
`public_html` un archivo `.htaccess` con estas dos líneas (solo redirige la
página principal, no el resto):

```
RewriteEngine On
RewriteRule ^$ /viajes/ [R=302,L]
```

## Administrar

En `#admin` (menú "Administrar") hay un resumen, las denuncias que manda la
gente desde cada viaje, la lista de todos los viajes (con borrar y restaurar) y
la de conductores (bloquear, desbloquear y nombrar administradores). Un
conductor bloqueado no puede entrar y sus viajes dejan de verse.

La primera cuenta de administración se activa una sola vez con un código
secreto: en `config.php` solo está su huella SHA-256. Para cambiar el código,
reemplazá esa huella por la de uno nuevo (`printf 'CODIGONUEVO' | sha256sum`,
en mayúsculas y sin guiones).

## Aviso

El sitio está pensado como tablero de avisos para compartir gastos, no como
servicio de transporte. Lo que importa ante un control es que en la práctica
sea así: que el conductor ya tenga que viajar y que no gane plata con el viaje.
Conviene consultar con un abogado las normas de tu provincia.
