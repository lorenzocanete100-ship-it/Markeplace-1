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
  config.php       configuración (correo de administración)
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

## Subir a Hostinger

1. Copiá el contenido de `public/` dentro de `public_html` del dominio.
2. Entrá a la página y creá tu cuenta.
3. En `config.php`, poné tu correo en `admin_email`. Con esa cuenta vas a poder
   borrar cualquier viaje que no corresponda.

## Aviso

El sitio está pensado como tablero de avisos para compartir gastos, no como
servicio de transporte. Lo que importa ante un control es que en la práctica
sea así: que el conductor ya tenga que viajar y que no gane plata con el viaje.
Conviene consultar con un abogado las normas de tu provincia.
