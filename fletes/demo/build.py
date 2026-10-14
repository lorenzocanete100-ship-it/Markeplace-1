"""Build a single-file demo of Fletá (real frontend + in-browser fake backend) for quick testing.

Usage: python demo/build.py [output.html]
"""
import json
import os
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT))
os.environ.setdefault('SECRET_KEY', 'x' * 32)
import app  # noqa: E402
from cities import CITIES  # noqa: E402

config = {
    'cities': CITIES,
    'city_list': sorted(({k: c[k] for k in ('id', 'label', 'area', 'region')} for c in CITIES.values()),
                        key=lambda c: (c['region'] != 'AMBA', c['area'], c['label'])),
    'vehicles': app.VEHICLES, 'services': app.SERVICES, 'slots': app.SLOTS, 'docs': app.DRIVER_DOCS,
    'pdf_docs': sorted(app.PDF_DOCS), 'status': app.STATUS, 'helper_price': app.HELPER_PRICE,
    'bultos_max_kg': app.BULTOS_MAX_KG, 'moto_max_km': app.MOTO_MAX_KM, 'road_factor': app.ROAD_FACTOR,
    'price_factor': 1.0, 'fee_pct': 15.0, 'active_trips_max': app.ACTIVE_TRIPS_MAX, 'code_alphabet': app.CODE_ALPHABET,
}
static = ROOT / 'static'
html = (static / 'index.html').read_text()
body = html[html.index('<body>') + len('<body>'):html.index('</body>')]
demo_css = '''
.demobar { display: flex; flex-wrap: wrap; gap: .4rem; align-items: center; padding-block: .5rem;
  padding-left: max(16px, env(safe-area-inset-left, 0px)); padding-right: max(16px, env(safe-area-inset-right, 0px));
  background: var(--warn-soft); border-bottom: 1px solid var(--line); font-size: .85rem; }
.demobar span { font-weight: 700; color: var(--warn); margin-right: .25rem; }
.demobar button { font: inherit; padding: .3rem .7rem; border-radius: 999px; border: 1px solid var(--line); background: var(--card); color: var(--text); cursor: pointer; }
.demobar button.on { background: var(--brand); color: var(--brand-ink); border-color: var(--brand); }
.demobar .reset { margin-left: auto; color: var(--bad); }
'''
script = lambda code: '<script>\n' + code.replace('</script', '<\\/script') + '\n</script>'
page = '\n'.join([
    '<title>Fletá</title>',
    '<meta name="description" content="Fletes y envíos entre particulares con choferes verificados.">',
    '<style>\n' + (static / 'styles.css').read_text() + demo_css + '</style>',
    body.strip(),
    script('window.FLETA_CONFIG = ' + json.dumps(config, ensure_ascii=False) + ';'),
    script((ROOT / 'demo' / 'mock-api.js').read_text()),
    script((static / 'app.js').read_text()),
])
out = Path(sys.argv[1] if len(sys.argv) > 1 else ROOT / 'demo' / 'fleta-demo.html')
out.write_text(page)
print('Demo escrita en', out)
