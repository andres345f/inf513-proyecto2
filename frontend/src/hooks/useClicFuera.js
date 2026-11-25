import { useEffect } from 'react'

/** Llama a alCerrar cuando se hace clic fuera del elemento o se presiona Escape. */
export function useClicFuera(referencia, activo, alCerrar) {
  useEffect(() => {
    if (!activo) return undefined

    const alClic = (evento) => {
      if (referencia.current && !referencia.current.contains(evento.target)) alCerrar()
    }
    const alTecla = (evento) => {
      if (evento.key === 'Escape') alCerrar()
    }

    document.addEventListener('mousedown', alClic)
    document.addEventListener('keydown', alTecla)
    return () => {
      document.removeEventListener('mousedown', alClic)
      document.removeEventListener('keydown', alTecla)
    }
  }, [referencia, activo, alCerrar])
}
