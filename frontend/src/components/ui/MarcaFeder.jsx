import { useLayoutEffect, useRef } from 'react'
import './MarcaFeder.css'

const CARPETA = import.meta.env.BASE_URL + 'marca/'
const OSCURAS = CARPETA + 'feder-logo-letras-oscuras.png'
const BLANCAS = CARPETA + 'feder-logo-letras-blancas.png'

/** Imagen independiente del tema, para los documentos impresos sobre papel blanco. */
export function MarcaFederEnPapel({ referencia }) {
  return <img ref={referencia} className="marca-feder-papel" src={OSCURAS} alt="FEDER" width="2024" height="477" />
}

function luminancia(canales) {
  const [r, g, b] = canales.map((canal) => {
    const s = canal / 255
    return s <= 0.04045 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4
  })
  return 0.2126 * r + 0.7152 * g + 0.0722 * b
}

// Luminancia a la que ambas tintas de los PNG tienen el mismo contraste.
const UMBRAL = Math.sqrt((luminancia([23, 35, 47]) + 0.05) * (luminancia([246, 245, 245]) + 0.05)) - 0.05

function varianteSobreSuperficie(elemento) {
  const fondo = [0, 0, 0]
  let transparencia = 1
  // Los fondos definidos en temas.css se resuelven en sRGB. Componer las capas
  // permite usar la misma marca dentro de enlaces y contenedores transparentes.
  for (let capa = elemento; capa && transparencia > 0; capa = capa.parentElement) {
    const color = getComputedStyle(capa).backgroundColor
    const [r, g, b, alfa = 1] = color.match(/[\d.]+/g).map(Number)
    const peso = transparencia * alfa
    fondo[0] += r * peso
    fondo[1] += g * peso
    fondo[2] += b * peso
    transparencia *= 1 - alfa
  }
  return luminancia(fondo.map((canal) => canal + transparencia * 255)) >= UMBRAL ? OSCURAS : BLANCAS
}

export default function MarcaFeder({ referenciaPapel }) {
  const imagen = useRef(null)

  useLayoutEffect(() => {
    const img = imagen.current
    const seleccionar = () => {
      const archivo = varianteSobreSuperficie(img)
      if (img.getAttribute('src') !== archivo) img.setAttribute('src', archivo)
    }
    seleccionar()
    const observador = new MutationObserver(seleccionar)
    // Solo importan los ancestros de esta marca, no el contenido de la página.
    for (let capa = img.parentElement; capa; capa = capa.parentElement) {
      observador.observe(capa, { attributeFilter: ['class', 'style', 'data-tema', 'data-modo', 'data-contraste'] })
    }
    return () => observador.disconnect()
  }, [])

  return <>
    <picture className={referenciaPapel ? 'marca-feder no-imprimir' : 'marca-feder'}>
      <img ref={imagen} alt="FEDER" width="2024" height="477" />
    </picture>
    {referenciaPapel && <MarcaFederEnPapel referencia={referenciaPapel} />}
  </>
}
