# Fletá — fletes y envíos entre particulares

App web estilo Vía Cargo para particulares: viajes cortos (CABA y GBA) y de larga distancia (130 localidades de todo el país), con choferes verificados y pago con Mercado Pago o tarjeta.

## Qué hace

- **Cotizador con precio cerrado**: *Flete exclusivo* (moto, auto, utilitario, camioneta o camión, con 0 a 3 ayudantes) o *Envío de bultos* (hasta 300 kg, más barato). Detecta si el viaje es de corta o de larga distancia y opcionalmente asegura la carga (1,5 % del valor declarado).
- **Verificación de choferes**: CUIL (con dígito verificador), patente, vencimientos de licencia y seguro, y 7 documentos: DNI frente y dorso, selfie con el DNI, licencia, cédula, póliza y certificado de antecedentes penales. Los documentos se guardan en privado y solo los ve el admin; al subirlos se les borran los metadatos (EXIF/GPS). El admin aprueba, rechaza con un motivo o suspende. Si la licencia o el seguro vencen, el chofer deja de poder tomar viajes.
- **Pagos con Mercado Pago Checkout Pro**: botón "Mercado Pago" (dinero en cuenta, tarjetas, efectivo) y botón "Tarjeta" (solo crédito o débito, hasta 12 cuotas). Los datos de la tarjeta nunca pasan por el servidor. El webhook verifica la firma `x-signature` y siempre vuelve a consultar el pago a la API de MP antes de acreditarlo (y comprueba el monto y la moneda).
- **El viaje se publica recién cuando se acredita el pago**. El chofer ve las ciudades y lo que cobra, pero no la dirección exacta ni los datos de contacto hasta que acepta el viaje. Aceptar es atómico: no puede haber dos choferes en el mismo viaje.
- **Código de entrega**: 4 dígitos que tiene solo el cliente. El chofer los necesita para cerrar el viaje (máximo 5 intentos por hora).
- Seguimiento público por código (`FL-XXXXXX`), contacto por WhatsApp, links a Google Maps, calificaciones, cancelación con devolución pendiente para el admin y panel del chofer con lo que cobró.

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
| `PRICE_FACTOR` | Multiplicador de la tarifa (para ajustar por inflación sin tocar código) |
| `PLATFORM_FEE_PCT` | Comisión de la plataforma, por defecto 15 |
| `DATA_DIR` | Dónde van la base SQLite y los documentos (usá un disco persistente) |

En Mercado Pago, configurá el webhook en `https://TU_DOMINIO/api/payments/webhook` con el evento **Pagos**.

Las tarifas base están en `VEHICLES` en `app.py`, y para sumar ciudades alcanza con agregar una fila en `cities.py`.
