import { useCallback, useEffect, useMemo, useState } from 'react'
import { ESCALAS, PREFERENCIAS_INICIALES, modoSegunHora } from '../config/preferencias'
import { guardar, leer } from '../utils/almacenamiento'
import { PreferenciasContext } from './contextos'

const CLAVE = 'feder:preferencias'

/**
 * Tema visual, modo día/noche, alto contraste y tamaño de letra.
 * Se aplican como atributos data-* en <html>; los estilos viven en styles/temas.css.
 */
export function PreferenciasProvider({ children }) {
  const [preferencias, setPreferencias] = useState(() => ({ ...PREFERENCIAS_INICIALES, ...leer(CLAVE, {}) }))
  const [modoAutomatico, setModoAutomatico] = useState(() => modoSegunHora())

  useEffect(() => {
    const intervalo = window.setInterval(() => setModoAutomatico(modoSegunHora()), 60_000)
    return () => window.clearInterval(intervalo)
  }, [])

  const modoEfectivo = preferencias.modo === 'auto' ? modoAutomatico : preferencias.modo

  useEffect(() => {
    const raiz = document.documentElement
    raiz.dataset.tema = preferencias.tema
    raiz.dataset.modo = modoEfectivo
    raiz.dataset.contraste = preferencias.contraste
    raiz.dataset.escala = String(preferencias.escala)
    guardar(CLAVE, preferencias)
  }, [preferencias, modoEfectivo])

  const actualizar = useCallback((cambios) => setPreferencias((actual) => ({ ...actual, ...cambios })), [])

  const cambiarEscala = useCallback((paso) => {
    setPreferencias((actual) => {
      const indice = ESCALAS.indexOf(actual.escala) + paso
      const escala = ESCALAS[Math.min(Math.max(indice, 0), ESCALAS.length - 1)]
      return { ...actual, escala }
    })
  }, [])

  const restablecer = useCallback(() => setPreferencias(PREFERENCIAS_INICIALES), [])

  const valor = useMemo(
    () => ({ ...preferencias, modoEfectivo, actualizar, cambiarEscala, restablecer }),
    [preferencias, modoEfectivo, actualizar, cambiarEscala, restablecer],
  )

  return <PreferenciasContext.Provider value={valor}>{children}</PreferenciasContext.Provider>
}
