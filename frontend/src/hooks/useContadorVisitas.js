import { useEffect, useRef, useState } from 'react'
import { navegacionApi } from '../api/navegacionApi'

/** Atributo que marca una vista que no debe contarse como visita (dirección inexistente). */
export const ATRIBUTO_SIN_CONTADOR = 'data-sin-contador'

/**
 * Registra una visita cada vez que cambia la página y devuelve su contador acumulado.
 * - Espera a que la vista esté resuelta (`lista`: sesión y menú cargados) y un ciclo más, para no contar
 *   rutas que terminan en una redirección (por ejemplo, una página privada sin sesión).
 * - No cuenta direcciones inexistentes: la página «no encontrada» lleva ATRIBUTO_SIN_CONTADOR.
 * - Una misma página no se registra dos veces seguidas (evita el doble efecto de React en desarrollo).
 * Devuelve { total, contable }: total es null mientras se cuenta; contable es false en una dirección inexistente.
 */
export function useContadorVisitas(pagina, lista = true) {
  const [conteo, setConteo] = useState({ pagina: null, total: null, contable: true })
  const paginaActual = useRef(null)

  useEffect(() => {
    if (!lista || paginaActual.current === pagina) return undefined
    const temporizador = window.setTimeout(() => {
      if (document.querySelector(`[${ATRIBUTO_SIN_CONTADOR}]`)) {
        setConteo({ pagina, total: null, contable: false })
        return
      }
      paginaActual.current = pagina
      navegacionApi.registrarVisita(pagina)
        .then((datos) => {
          if (paginaActual.current === pagina) setConteo({ pagina, total: datos.registrada === false ? null : datos.total, contable: datos.registrada !== false })
        })
        .catch(() => {
          if (paginaActual.current === pagina) setConteo({ pagina, total: null, contable: true })
        })
    }, 0)
    return () => window.clearTimeout(temporizador)
  }, [pagina, lista])

  return conteo.pagina === pagina ? conteo : { total: null, contable: true }
}
