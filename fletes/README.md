# Bellandi Mudanzas

App web de mudanzas: **dentro de Buenos Aires** (CABA y GBA), **a todo el país** (100 ciudades) e **internacionales a países limítrofes** (Uruguay, Chile, Paraguay, Bolivia y Brasil, 25 ciudades). Tiene choferes verificados y se paga con Mercado Pago o con tarjeta.

## Qué hace

- **Cotizador con precio cerrado según el tamaño de la mudanza**: pocas cosas, monoambiente, 2 ambientes, 3 ambientes o casa. Cada tamaño define el vehículo (de utilitario a camión grande), los m³ y los ayudantes sugeridos. El precio suma:
  - transporte por km (más barato por km en viajes largos);
  - ayudantes (en las mudanzas fuera de Buenos Aires cuentan doble porque trabajan en las dos puntas);
  - **pisos por escalera** en origen y destino;
  - **embalaje** y **desarme y armado de muebles** (opcionales);
  - seguro de la carga (opcional).
- **Camión compartido**: en viajes de más de 150 km fuera de Buenos Aires el transporte sale un 40 % más barato si la fecha es flexible (±3 días).
- **Internacional**: la ruta pasa por el paso fronterizo más conveniente (Cristo Redentor, Fray Bentos, Posadas–Encarnación, etc.). Suma la gestión aduanera de cada país, pide un **inventario detallado** para la aduana y le muestra al cliente qué documentos va a necesitar. Solo pueden tomar estas mudanzas los choferes habilitados para viajes internacionales.
- **Verificación de choferes**: CUIL (con dígito verificador), patente, vencimientos y 7 documentos (DNI, selfie con DNI, licencia, cédula, póliza y antecedentes penales). Para hacer viajes internacionales hacen falta 2 documentos más: el permiso de transporte internacional de cargas y el seguro internacional (Carta Verde / RCTR-VI). Los documentos solo los ve el admin y se les borran los metadatos.
- **Pagos con Mercado Pago Checkout Pro**: los botones "Mercado Pago" y "Tarjeta" (hasta 12 cuotas). El webhook está firmado y la app siempre vuelve a consultar el pago en Mercado Pago antes de acreditarlo.
- La mudanza se publica recién cuando se acredita el pago. El chofer ve la dirección exacta y el inventario recién cuando acepta, y no puede haber dos choferes en la misma mudanza. Para cerrar la entrega hace falta el **código de 4 dígitos** que tiene quien recibe.
- Seguimiento público por código (`MD-XXXXXX`), WhatsApp, Google Maps, calificaciones, devoluciones y panel del chofer.

## Probarla sin servidor

`python demo/build.py salida.html` arma una página única con el frontend real y un backend simulado en el navegador, con cuentas de prueba (cliente, chofer, admin) y pagos simulados.

## Cómo correrla

```bash
cd fletes
pip install -r requirements.txt
export SECRET_KEY="$(python -c 'import secrets;print(secrets.token_urlsafe(48))')"
export ADMIN_EMAIL=vos@tudominio.com
python app.py                      # http://127.0.0.1:5000
python manage.py create-admin      # crea la cuenta de administrador
python -m unittest test_app        # tests
```

Sin `MP_ACCESS_TOKEN`, y fuera de producción, los pagos se **simulan** (modo demo).

### Producción

```bash
gunicorn 'app:create_app()' --bind 0.0.0.0:$PORT
```

| Variable | Para qué sirve |
|---|---|
| `SECRET_KEY` | Obligatoria, 32 caracteres o más |
| `APP_ENV=production` | Cookies seguras; desactiva el modo demo de pagos |
| `ADMIN_EMAIL` | Cuenta de administrador |
| `PUBLIC_URL` | URL pública https (para las URLs de retorno y el webhook de MP) |
| `MP_ACCESS_TOKEN` | Access token de Mercado Pago (Tus integraciones → Credenciales) |
| `MP_WEBHOOK_SECRET` | Clave secreta de las notificaciones webhook de MP |
| `PRICE_FACTOR` | Multiplicador de todas las tarifas (para ajustar por inflación sin tocar código) |
| `PLATFORM_FEE_PCT` | Comisión de la plataforma, por defecto 15 |
| `DATA_DIR` | Dónde van la base SQLite y los documentos (usá un disco persistente) |

En Mercado Pago, configurá el webhook en `https://TU_DOMINIO/api/payments/webhook` con el evento **Pagos**.

Las tarifas están en `VEHICLES`, `SIZES` y las constantes del principio de `app.py`; los costos de aduana y los pasos fronterizos, en `COUNTRIES` (`cities.py`). Para sumar una ciudad alcanza con agregar una fila en `cities.py`.
