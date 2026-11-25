import { useEffect, useState } from 'react'
import { usePreferencias } from './usePreferencias'

const VARIABLES = ['primario', 'primario-suave', 'foco', 'info', 'acento', 'exito', 'error', 'aviso', 'texto', 'texto-suave', 'borde', 'superficie', 'superficie-alterna']

function leerColores() {
  const estilo = getComputedStyle(document.documentElement)
  return Object.fromEntries(VARIABLES.map((v) => [v, estilo.getPropertyValue(`--color-${v}`).trim()]))
}

/**
 * Colores del tema activo leídos de las variables CSS, para los gráficos (Recharts necesita colores concretos).
 * Se leen en el siguiente cuadro, después de que PreferenciasContext aplicó el tema en <html>.
 */
export function useColoresTema() {
  const { tema, modoEfectivo, contraste } = usePreferencias()
  const [colores, setColores] = useState(leerColores)

  useEffect(() => {
    const cuadro = window.requestAnimationFrame(() => setColores(leerColores()))
    return () => window.cancelAnimationFrame(cuadro)
  }, [tema, modoEfectivo, contraste])

  return colores
}
