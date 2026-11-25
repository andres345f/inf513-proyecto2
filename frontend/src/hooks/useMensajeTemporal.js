import { useEffect } from 'react'

/** Oculta automáticamente solo las confirmaciones exitosas; los errores permanecen visibles. */
export function useMensajeTemporal(mensaje, setMensaje, demora = 4500, esExito = mensaje?.tipo === 'exito') {
  useEffect(() => {
    if (!esExito) return undefined
    const id = window.setTimeout(() => setMensaje(null), demora)
    return () => window.clearTimeout(id)
  }, [demora, esExito, mensaje, setMensaje])
}
