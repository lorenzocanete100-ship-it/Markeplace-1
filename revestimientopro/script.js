// ===== Configuración: editá estos datos =====
const CONFIG = {
  // Número de WhatsApp con código de país, sin "+" ni espacios (ej: 5491112345678)
  whatsapp: "5490000000000",
  telefono: "+54 9 0000 000000",
  // Precios por m², en pesos
  precios: {
    tradicional: { nombre: "Tradicional", material: 15000, manoDeObra: 7000 },
    fina: { nombre: "Fina", material: 18000, manoDeObra: 9000 },
  },
};

// Las fotos de tus trabajos se cargan desde fotos/fotos.json, que se edita desde admin.php.
// Mientras no haya fotos, se muestran estas texturas de ejemplo.
const EJEMPLOS = [
  "Frente de vivienda", "Medianera exterior", "Living interior",
  "Fachada comercial", "Quincho", "Muro perimetral",
].map((titulo) => ({ titulo }));

// ===== Utilidades =====
const $ = (sel) => document.querySelector(sel);
const pesos = (n) =>
  new Intl.NumberFormat("es-AR", { style: "currency", currency: "ARS", maximumFractionDigits: 0 }).format(n);
const num = (el) => Math.max(0, parseFloat(String(el.value).replace(",", ".")) || 0);
const waLink = (texto) => `https://wa.me/${CONFIG.whatsapp}?text=${encodeURIComponent(texto)}`;

// ===== Menú móvil =====
const toggle = $(".nav-toggle");
const links = $(".nav-links");
toggle.addEventListener("click", () => {
  const open = links.classList.toggle("open");
  toggle.setAttribute("aria-expanded", open);
});
links.addEventListener("click", (e) => {
  if (e.target.tagName === "A") links.classList.remove("open");
});

// ===== Calculadora =====
let modo = "m2";
const inputs = { m2: $("#m2"), largo: $("#largo"), alto: $("#alto") };

document.querySelectorAll(".tab").forEach((tab) =>
  tab.addEventListener("click", () => {
    document.querySelectorAll(".tab").forEach((t) => t.classList.toggle("active", t === tab));
    modo = tab.dataset.mode;
    $(".mode-m2").classList.toggle("hidden", modo !== "m2");
    $(".mode-medidas").classList.toggle("hidden", modo !== "medidas");
    calcular();
  })
);

// Muestra en toda la página los precios definidos en CONFIG
document.querySelectorAll("[data-precio]").forEach((el) => {
  const [textura, tipo] = el.dataset.precio.split(".");
  el.textContent = pesos(CONFIG.precios[textura][tipo]);
});

function calcular() {
  const superficie = modo === "m2" ? num(inputs.m2) : num(inputs.largo) * num(inputs.alto);
  const textura = CONFIG.precios[document.querySelector('input[name="textura"]:checked').value];
  const tipo = document.querySelector('input[name="tipo"]:checked').value;
  const unitario = textura[tipo];
  const total = Math.round(superficie * unitario);
  const conMaterial = tipo === "material";
  const m2Texto = superficie.toLocaleString("es-AR", { maximumFractionDigits: 2 });

  $("#p-material").textContent = `${pesos(textura.material)} / m²`;
  $("#p-manoDeObra").textContent = `${pesos(textura.manoDeObra)} / m²`;
  $("#r-textura").textContent = textura.nombre;
  $("#r-m2").textContent = `${m2Texto} m²`;
  $("#r-unit").textContent = pesos(unitario);
  $("#r-total").textContent = pesos(total);

  const msg = superficie > 0
    ? `Hola RevestimientoPro! Quiero un presupuesto de textura ${textura.nombre.toLowerCase()}: ${m2Texto} m², ${conMaterial ? "con material Ancaflex" : "solo mano de obra"}. Total estimado: ${pesos(total)}.`
    : "Hola RevestimientoPro! Quiero pedir un presupuesto de texturado.";
  $("#wa-quote").href = waLink(msg);
}

$("#calc").addEventListener("input", calcular);
$("#calc").addEventListener("submit", (e) => e.preventDefault());
calcular();

// ===== Contacto =====
$("#wa-contact").href = waLink("Hola RevestimientoPro! Quiero consultar por un trabajo de texturado.");
$("#tel-contact").href = `tel:${CONFIG.telefono.replace(/\s/g, "")}`;
$("#year").textContent = new Date().getFullYear();

// ===== Galería =====
const gallery = $("#gallery");
const lightbox = $("#lightbox");
const placeholders = ["var(--tex-a)", "var(--tex-b)", "var(--tex-c)"];

function placeholder(i) {
  const ph = document.createElement("span");
  ph.className = "ph";
  ph.style.background = placeholders[i % placeholders.length];
  return ph;
}

function renderGallery(items) {
  const lista = items.length ? items : EJEMPLOS;
  gallery.replaceChildren();
  lista.forEach((t, i) => {
    const fig = document.createElement("figure");
    fig.className = "gallery-item";
    if (t.src) {
      const img = new Image();
      img.alt = t.titulo || "Trabajo de revestimiento texturado";
      img.loading = "lazy";
      img.src = t.src;
      img.onerror = () => img.replaceWith(placeholder(i));
      fig.append(img);
      fig.addEventListener("click", () => {
        const el = fig.querySelector("img");
        if (!el) return;
        lightbox.querySelector("img").src = el.src;
        lightbox.querySelector("img").alt = el.alt;
        lightbox.hidden = false;
      });
    } else {
      fig.append(placeholder(i));
      fig.classList.add("is-example");
    }
    if (t.titulo) {
      const cap = document.createElement("figcaption");
      cap.textContent = t.titulo;
      fig.append(cap);
    }
    gallery.append(fig);
  });
}

const closeLightbox = () => (lightbox.hidden = true);
lightbox.addEventListener("click", (e) => {
  if (e.target !== lightbox.querySelector("img")) closeLightbox();
});
document.addEventListener("keydown", (e) => e.key === "Escape" && closeLightbox());

// ===== Fuente de fotos =====
renderGallery([]);
fetch("fotos/fotos.json", { cache: "no-store" })
  .then((r) => (r.ok ? r.json() : []))
  .then((fotos) => renderGallery(Array.isArray(fotos) ? fotos : []))
  .catch(() => {});
