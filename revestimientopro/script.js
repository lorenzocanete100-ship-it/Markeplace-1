// ===== Configuración: editá estos datos =====
const CONFIG = {
  // Número de WhatsApp con código de país, sin "+" ni espacios (ej: 5491112345678)
  whatsapp: "5490000000000",
  telefono: "+54 9 0000 000000",
  precios: { material: 15000, manoDeObra: 7000 },
};

// Fotos de tus trabajos: subí las imágenes a la carpeta "fotos/" y agregalas acá.
// Si una foto no existe todavía, se muestra una textura de ejemplo.
const TRABAJOS = [
  { src: "fotos/trabajo1.jpg", titulo: "Frente de vivienda" },
  { src: "fotos/trabajo2.jpg", titulo: "Medianera exterior" },
  { src: "fotos/trabajo3.jpg", titulo: "Living interior" },
  { src: "fotos/trabajo4.jpg", titulo: "Fachada comercial" },
  { src: "fotos/trabajo5.jpg", titulo: "Quincho" },
  { src: "fotos/trabajo6.jpg", titulo: "Muro perimetral" },
];

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

function calcular() {
  const superficie = modo === "m2" ? num(inputs.m2) : num(inputs.largo) * num(inputs.alto);
  const tipo = document.querySelector('input[name="tipo"]:checked');
  const unitario = Number(tipo.value);
  const total = Math.round(superficie * unitario);
  const conMaterial = unitario === CONFIG.precios.material;
  const m2Texto = superficie.toLocaleString("es-AR", { maximumFractionDigits: 2 });

  $("#r-m2").textContent = `${m2Texto} m²`;
  $("#r-unit").textContent = pesos(unitario);
  $("#r-total").textContent = pesos(total);

  const msg = superficie > 0
    ? `Hola RevestimientoPro! Quiero un presupuesto de texturado: ${m2Texto} m², ${conMaterial ? "con material Ancaflex" : "solo mano de obra"}. Total estimado: ${pesos(total)}.`
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

TRABAJOS.forEach((t, i) => {
  const fig = document.createElement("figure");
  fig.className = "gallery-item";
  const img = new Image();
  img.alt = t.titulo;
  img.loading = "lazy";
  img.src = t.src;
  img.onerror = () => {
    const ph = document.createElement("span");
    ph.className = "ph";
    ph.style.background = placeholders[i % placeholders.length];
    img.replaceWith(ph);
    fig.style.cursor = "default";
  };
  fig.append(img);
  const cap = document.createElement("figcaption");
  cap.textContent = t.titulo;
  fig.append(cap);
  fig.addEventListener("click", () => {
    const el = fig.querySelector("img");
    if (!el) return;
    lightbox.querySelector("img").src = el.src;
    lightbox.querySelector("img").alt = el.alt;
    lightbox.hidden = false;
  });
  gallery.append(fig);
});

const closeLightbox = () => (lightbox.hidden = true);
lightbox.addEventListener("click", (e) => {
  if (e.target !== lightbox.querySelector("img")) closeLightbox();
});
document.addEventListener("keydown", (e) => e.key === "Escape" && closeLightbox());
