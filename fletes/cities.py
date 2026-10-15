"""Localidades disponibles con coordenadas aproximadas (centro de la localidad).

region: 'AMBA' (CABA + Gran Buenos Aires), 'Interior' o 'Internacional'.
Para sumar una localidad alcanza con agregar una fila: (nombre, provincia/zona, region, lat, lon[, país]).
Las mudanzas internacionales calculan la ruta pasando por el paso fronterizo más conveniente de COUNTRIES.
"""

_ROWS = [
    # CABA
    ('Microcentro', 'CABA', 'AMBA', -34.6037, -58.3816),
    ('Palermo', 'CABA', 'AMBA', -34.5889, -58.4306),
    ('Recoleta', 'CABA', 'AMBA', -34.5875, -58.3974),
    ('Belgrano', 'CABA', 'AMBA', -34.5627, -58.4583),
    ('Caballito', 'CABA', 'AMBA', -34.6186, -58.4419),
    ('Flores', 'CABA', 'AMBA', -34.6280, -58.4636),
    ('Almagro', 'CABA', 'AMBA', -34.6097, -58.4210),
    ('Villa Crespo', 'CABA', 'AMBA', -34.5990, -58.4380),
    ('Villa Urquiza', 'CABA', 'AMBA', -34.5732, -58.4870),
    ('Villa Devoto', 'CABA', 'AMBA', -34.6010, -58.5130),
    ('Núñez', 'CABA', 'AMBA', -34.5450, -58.4590),
    ('Colegiales', 'CABA', 'AMBA', -34.5740, -58.4490),
    ('San Telmo', 'CABA', 'AMBA', -34.6212, -58.3731),
    ('Puerto Madero', 'CABA', 'AMBA', -34.6118, -58.3627),
    ('La Boca', 'CABA', 'AMBA', -34.6345, -58.3631),
    ('Barracas', 'CABA', 'AMBA', -34.6450, -58.3830),
    ('Boedo', 'CABA', 'AMBA', -34.6300, -58.4170),
    ('Mataderos', 'CABA', 'AMBA', -34.6600, -58.5030),
    ('Liniers', 'CABA', 'AMBA', -34.6420, -58.5200),
    ('Villa Lugano', 'CABA', 'AMBA', -34.6760, -58.4730),
    # GBA Norte
    ('Vicente López', 'GBA Norte', 'AMBA', -34.5262, -58.4730),
    ('Olivos', 'GBA Norte', 'AMBA', -34.5100, -58.4930),
    ('Martínez', 'GBA Norte', 'AMBA', -34.4920, -58.5070),
    ('San Isidro', 'GBA Norte', 'AMBA', -34.4708, -58.5286),
    ('San Fernando', 'GBA Norte', 'AMBA', -34.4419, -58.5594),
    ('Tigre', 'GBA Norte', 'AMBA', -34.4260, -58.5796),
    ('Escobar', 'GBA Norte', 'AMBA', -34.3483, -58.7949),
    ('Pilar', 'GBA Norte', 'AMBA', -34.4587, -58.9142),
    ('San Martín', 'GBA Norte', 'AMBA', -34.5745, -58.5370),
    ('San Miguel', 'GBA Norte', 'AMBA', -34.5432, -58.7123),
    ('José C. Paz', 'GBA Norte', 'AMBA', -34.5150, -58.7680),
    ('Los Polvorines', 'GBA Norte', 'AMBA', -34.5000, -58.7000),
    # GBA Oeste
    ('Caseros', 'GBA Oeste', 'AMBA', -34.6050, -58.5630),
    ('Ramos Mejía', 'GBA Oeste', 'AMBA', -34.6410, -58.5650),
    ('San Justo', 'GBA Oeste', 'AMBA', -34.6826, -58.5596),
    ('González Catán', 'GBA Oeste', 'AMBA', -34.7710, -58.6460),
    ('Morón', 'GBA Oeste', 'AMBA', -34.6534, -58.6198),
    ('Hurlingham', 'GBA Oeste', 'AMBA', -34.5886, -58.6390),
    ('Ituzaingó', 'GBA Oeste', 'AMBA', -34.6576, -58.6670),
    ('Merlo', 'GBA Oeste', 'AMBA', -34.6653, -58.7276),
    ('Moreno', 'GBA Oeste', 'AMBA', -34.6500, -58.7900),
    ('General Rodríguez', 'GBA Oeste', 'AMBA', -34.6083, -58.9500),
    # GBA Sur
    ('Avellaneda', 'GBA Sur', 'AMBA', -34.6626, -58.3650),
    ('Lanús', 'GBA Sur', 'AMBA', -34.7061, -58.3917),
    ('Banfield', 'GBA Sur', 'AMBA', -34.7440, -58.3970),
    ('Lomas de Zamora', 'GBA Sur', 'AMBA', -34.7609, -58.4061),
    ('Quilmes', 'GBA Sur', 'AMBA', -34.7206, -58.2546),
    ('Berazategui', 'GBA Sur', 'AMBA', -34.7630, -58.2110),
    ('Florencio Varela', 'GBA Sur', 'AMBA', -34.8090, -58.2760),
    ('Adrogué', 'GBA Sur', 'AMBA', -34.8000, -58.3900),
    ('Monte Grande', 'GBA Sur', 'AMBA', -34.8190, -58.4680),
    ('Ezeiza', 'GBA Sur', 'AMBA', -34.8530, -58.5230),
    ('Guernica', 'GBA Sur', 'AMBA', -34.9150, -58.3850),
    ('San Vicente', 'GBA Sur', 'AMBA', -35.0250, -58.4230),
    ('Cañuelas', 'GBA Sur', 'AMBA', -35.0520, -58.7600),
    ('Ensenada', 'GBA Sur', 'AMBA', -34.8640, -57.9110),
    ('Berisso', 'GBA Sur', 'AMBA', -34.8730, -57.8860),
    ('La Plata', 'Buenos Aires', 'AMBA', -34.9214, -57.9545),
    # Provincia de Buenos Aires (interior)
    ('Mar del Plata', 'Buenos Aires', 'Interior', -38.0055, -57.5426),
    ('Bahía Blanca', 'Buenos Aires', 'Interior', -38.7183, -62.2663),
    ('Tandil', 'Buenos Aires', 'Interior', -37.3217, -59.1332),
    ('Olavarría', 'Buenos Aires', 'Interior', -36.8927, -60.3225),
    ('Azul', 'Buenos Aires', 'Interior', -36.7770, -59.8585),
    ('Necochea', 'Buenos Aires', 'Interior', -38.5545, -58.7396),
    ('Tres Arroyos', 'Buenos Aires', 'Interior', -38.3739, -60.2798),
    ('Pinamar', 'Buenos Aires', 'Interior', -37.1079, -56.8614),
    ('Villa Gesell', 'Buenos Aires', 'Interior', -37.2639, -56.9731),
    ('Luján', 'Buenos Aires', 'Interior', -34.5703, -59.1050),
    ('Campana', 'Buenos Aires', 'Interior', -34.1633, -58.9592),
    ('Zárate', 'Buenos Aires', 'Interior', -34.0981, -59.0286),
    ('San Pedro', 'Buenos Aires', 'Interior', -33.6790, -59.6667),
    ('San Nicolás de los Arroyos', 'Buenos Aires', 'Interior', -33.3342, -60.2108),
    ('Pergamino', 'Buenos Aires', 'Interior', -33.8895, -60.5736),
    ('Junín', 'Buenos Aires', 'Interior', -34.5850, -60.9589),
    ('Chivilcoy', 'Buenos Aires', 'Interior', -34.8957, -60.0167),
    ('9 de Julio', 'Buenos Aires', 'Interior', -35.4444, -60.8831),
    ('Trenque Lauquen', 'Buenos Aires', 'Interior', -35.9700, -62.7333),
    # Centro
    ('Córdoba', 'Córdoba', 'Interior', -31.4201, -64.1888),
    ('Villa Carlos Paz', 'Córdoba', 'Interior', -31.4241, -64.4978),
    ('Río Cuarto', 'Córdoba', 'Interior', -33.1232, -64.3493),
    ('Villa María', 'Córdoba', 'Interior', -32.4075, -63.2402),
    ('San Francisco', 'Córdoba', 'Interior', -31.4280, -62.0828),
    ('Rosario', 'Santa Fe', 'Interior', -32.9442, -60.6505),
    ('Santa Fe', 'Santa Fe', 'Interior', -31.6107, -60.6973),
    ('Rafaela', 'Santa Fe', 'Interior', -31.2503, -61.4867),
    ('Venado Tuerto', 'Santa Fe', 'Interior', -33.7456, -61.9688),
    ('Reconquista', 'Santa Fe', 'Interior', -29.1500, -59.6500),
    ('Paraná', 'Entre Ríos', 'Interior', -31.7319, -60.5238),
    ('Concordia', 'Entre Ríos', 'Interior', -31.3929, -58.0209),
    ('Gualeguaychú', 'Entre Ríos', 'Interior', -33.0094, -58.5172),
    ('Concepción del Uruguay', 'Entre Ríos', 'Interior', -32.4846, -58.2321),
    ('Santa Rosa', 'La Pampa', 'Interior', -36.6167, -64.2833),
    ('General Pico', 'La Pampa', 'Interior', -35.6566, -63.7568),
    # Cuyo
    ('Mendoza', 'Mendoza', 'Interior', -32.8895, -68.8458),
    ('San Rafael', 'Mendoza', 'Interior', -34.6177, -68.3301),
    ('San Juan', 'San Juan', 'Interior', -31.5375, -68.5364),
    ('San Luis', 'San Luis', 'Interior', -33.3017, -66.3378),
    ('Villa Mercedes', 'San Luis', 'Interior', -33.6757, -65.4578),
    # NOA
    ('San Miguel de Tucumán', 'Tucumán', 'Interior', -26.8083, -65.2176),
    ('Salta', 'Salta', 'Interior', -24.7821, -65.4232),
    ('San Salvador de Jujuy', 'Jujuy', 'Interior', -24.1858, -65.2995),
    ('Santiago del Estero', 'Santiago del Estero', 'Interior', -27.7951, -64.2615),
    ('Termas de Río Hondo', 'Santiago del Estero', 'Interior', -27.4936, -64.8597),
    ('San Fernando del Valle de Catamarca', 'Catamarca', 'Interior', -28.4696, -65.7852),
    ('La Rioja', 'La Rioja', 'Interior', -29.4131, -66.8558),
    # NEA
    ('Resistencia', 'Chaco', 'Interior', -27.4606, -58.9839),
    ('Corrientes', 'Corrientes', 'Interior', -27.4692, -58.8306),
    ('Posadas', 'Misiones', 'Interior', -27.3671, -55.8961),
    ('Oberá', 'Misiones', 'Interior', -27.4871, -55.1199),
    ('Puerto Iguazú', 'Misiones', 'Interior', -25.5972, -54.5786),
    ('Formosa', 'Formosa', 'Interior', -26.1775, -58.1781),
    # Patagonia
    ('Neuquén', 'Neuquén', 'Interior', -38.9516, -68.0591),
    ('San Martín de los Andes', 'Neuquén', 'Interior', -40.1572, -71.3533),
    ('Cipolletti', 'Río Negro', 'Interior', -38.9339, -67.9903),
    ('General Roca', 'Río Negro', 'Interior', -39.0333, -67.5833),
    ('Viedma', 'Río Negro', 'Interior', -40.8135, -62.9967),
    ('San Carlos de Bariloche', 'Río Negro', 'Interior', -41.1335, -71.3103),
    ('Puerto Madryn', 'Chubut', 'Interior', -42.7692, -65.0385),
    ('Trelew', 'Chubut', 'Interior', -43.2490, -65.3051),
    ('Rawson', 'Chubut', 'Interior', -43.3002, -65.1023),
    ('Esquel', 'Chubut', 'Interior', -42.9115, -71.3195),
    ('Comodoro Rivadavia', 'Chubut', 'Interior', -45.8641, -67.4966),
    ('Caleta Olivia', 'Santa Cruz', 'Interior', -46.4393, -67.5281),
    ('Río Gallegos', 'Santa Cruz', 'Interior', -51.6230, -69.2168),
    ('El Calafate', 'Santa Cruz', 'Interior', -50.3379, -72.2648),
    ('Río Grande', 'Tierra del Fuego', 'Interior', -53.7877, -67.7095),
    ('Ushuaia', 'Tierra del Fuego', 'Interior', -54.8019, -68.3030),
    # Países limítrofes
    ('Montevideo', 'Uruguay', 'Internacional', -34.9011, -56.1645, 'UY'),
    ('Punta del Este', 'Uruguay', 'Internacional', -34.9620, -54.9500, 'UY'),
    ('Colonia del Sacramento', 'Uruguay', 'Internacional', -34.4626, -57.8398, 'UY'),
    ('Paysandú', 'Uruguay', 'Internacional', -32.3214, -58.0756, 'UY'),
    ('Salto', 'Uruguay', 'Internacional', -31.3833, -57.9667, 'UY'),
    ('Santiago', 'Chile', 'Internacional', -33.4489, -70.6693, 'CL'),
    ('Valparaíso', 'Chile', 'Internacional', -33.0472, -71.6127, 'CL'),
    ('Viña del Mar', 'Chile', 'Internacional', -33.0245, -71.5518, 'CL'),
    ('La Serena', 'Chile', 'Internacional', -29.9027, -71.2519, 'CL'),
    ('Concepción', 'Chile', 'Internacional', -36.8270, -73.0503, 'CL'),
    ('Temuco', 'Chile', 'Internacional', -38.7359, -72.5904, 'CL'),
    ('Puerto Montt', 'Chile', 'Internacional', -41.4693, -72.9424, 'CL'),
    ('Asunción', 'Paraguay', 'Internacional', -25.2637, -57.5759, 'PY'),
    ('Ciudad del Este', 'Paraguay', 'Internacional', -25.5097, -54.6111, 'PY'),
    ('Encarnación', 'Paraguay', 'Internacional', -27.3306, -55.8667, 'PY'),
    ('Santa Cruz de la Sierra', 'Bolivia', 'Internacional', -17.7833, -63.1821, 'BO'),
    ('La Paz', 'Bolivia', 'Internacional', -16.4897, -68.1193, 'BO'),
    ('Cochabamba', 'Bolivia', 'Internacional', -17.4139, -66.1653, 'BO'),
    ('Tarija', 'Bolivia', 'Internacional', -21.5355, -64.7296, 'BO'),
    ('Porto Alegre', 'Brasil', 'Internacional', -30.0346, -51.2177, 'BR'),
    ('Florianópolis', 'Brasil', 'Internacional', -27.5954, -48.5480, 'BR'),
    ('Curitiba', 'Brasil', 'Internacional', -25.4284, -49.2733, 'BR'),
    ('Foz do Iguaçu', 'Brasil', 'Internacional', -25.5163, -54.5854, 'BR'),
    ('São Paulo', 'Brasil', 'Internacional', -23.5505, -46.6333, 'BR'),
    ('Río de Janeiro', 'Brasil', 'Internacional', -22.9068, -43.1729, 'BR'),
]

# customs_fee: gestión aduanera (despachante, inventario certificado, trámites de frontera) en ARS.
# crossings: (nombre, lat, lon, km extra por camino de montaña o rodeo).
COUNTRIES = {
    'AR': {'name': 'Argentina'},
    'UY': {'name': 'Uruguay', 'customs_fee': 180000, 'crossings': [
        ('Puente Gualeguaychú – Fray Bentos', -33.10, -58.25, 0),
        ('Puente Colón – Paysandú', -32.25, -58.09, 0),
        ('Salto Grande (Concordia – Salto)', -31.27, -57.94, 0)]},
    'CL': {'name': 'Chile', 'customs_fee': 250000, 'crossings': [
        ('Paso Cristo Redentor (Mendoza – Los Andes)', -32.83, -70.07, 90),
        ('Paso Cardenal Samoré (Neuquén – Osorno)', -40.70, -71.94, 60)]},
    'PY': {'name': 'Paraguay', 'customs_fee': 200000, 'crossings': [
        ('Puente Clorinda – Asunción', -25.32, -57.70, 0),
        ('Puente Posadas – Encarnación', -27.36, -55.88, 0)]},
    'BO': {'name': 'Bolivia', 'customs_fee': 220000, 'crossings': [
        ('La Quiaca – Villazón', -22.10, -65.59, 40),
        ('Salvador Mazza – Yacuiba', -22.05, -63.68, 0)]},
    'BR': {'name': 'Brasil', 'customs_fee': 280000, 'crossings': [
        ('Paso de los Libres – Uruguaiana', -29.74, -57.09, 0),
        ('Santo Tomé – São Borja', -28.62, -56.03, 0),
        ('Puerto Iguazú – Foz do Iguaçu', -25.59, -54.58, 0)]},
}


def _slug(text):
    table = str.maketrans('áéíóúüñãõçâêôÁÉÍÓÚÜÑ', 'aeiouunaocaeoAEIOUUN')
    return '-'.join(text.translate(table).lower().replace('.', '').split())


CITIES = {}
for _row in _ROWS:
    _name, _area, _region, _lat, _lon = _row[:5]
    _id = _slug(_name + ' ' + _area)
    CITIES[_id] = {'id': _id, 'name': _name, 'area': _area, 'region': _region, 'lat': _lat, 'lon': _lon,
                   'country': _row[5] if len(_row) > 5 else 'AR',
                   'label': f'{_name} ({_area})' if _area != _name else _name}
