# V+X Constructora

Web de la constructora con **cotizador automático**: construcción llave en mano, refacciones y humedad.
Garantía total: 10 años escrita y certificada por escribano.

## Precios (en `app.py`, constante `PRICES`)
- Con materiales: **USD 980 / m²**
- Sin materiales (mano de obra): **USD 400 / m²**
- Refacción: estimado por m², se ajusta al ver las fotos.
- Humedad: se cotiza con fotos (subidas a la página o por WhatsApp).

## Cómo correrla
```bash
pip install -r requirements.txt
export SECRET_KEY="$(python -c 'import secrets; print(secrets.token_hex(32))')"
export WHATSAPP_NUMBER=5491112345678   # número de WhatsApp de la empresa
export ADMIN_PASSWORD=una-clave-segura   # para ver solicitudes en /admin
gunicorn 'app:create_app()'
```
- `/` página pública con el cotizador.
- `/admin` lista de solicitudes con fotos (usuario cualquiera + `ADMIN_PASSWORD`).

Tests: `python -m unittest test_app`
