import { useEffect } from 'react'

const TITULO_BASE = 'FEDER · Ayudando al emprendimiento'

/**
 * Título de la pestaña por vista (WCAG 2.4.2): «<h1 de la vista> · FEDER».
 * Se toma del primer h1 del contenido principal y se actualiza cuando la vista termina de cargar
 * (el h1 puede aparecer después de una consulta a la API).
 */
export function useTituloDocumento(refContenido, pathname) {
  useEffect(() => {
    const contenido = refContenido.current
    if (!contenido) return undefined
    const actualizar = () => {
      const h1 = contenido.querySelector('h1')?.textContent?.replace(/\s+/g, ' ').trim()
      document.title = h1 ? `${h1} · FEDER` : TITULO_BASE
    }
    actualizar()
    const observador = new MutationObserver(actualizar)
    observador.observe(contenido, { childList: true, subtree: true, characterData: true })
    return () => observador.disconnect()
  }, [refContenido, pathname])
}
