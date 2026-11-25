import { useEffect, useState } from 'react'
import { navegacionApi } from '../api/navegacionApi'

const ESPERA_MS = 300
const MINIMO_CARACTERES = 2

/** Búsqueda predictiva: espera a que el usuario deje de escribir y cancela las consultas viejas. */
export function useBusquedaGlobal(texto) {
  const [estado, setEstado] = useState({ consulta: '', resultados: null, error: null })
  const consulta = texto.trim()
  const habilitada = consulta.length >= MINIMO_CARACTERES

  useEffect(() => {
    if (!habilitada) return undefined

    const controlador = new AbortController()
    const temporizador = window.setTimeout(() => {
      navegacionApi.buscar(consulta, controlador.signal)
        .then((resultados) => setEstado({ consulta, resultados, error: null }))
        .catch((falla) => {
          if (!falla.cancelado) setEstado({ consulta, resultados: null, error: falla.mensaje })
        })
    }, ESPERA_MS)

    return () => {
      window.clearTimeout(temporizador)
      controlador.abort()
    }
  }, [consulta, habilitada])

  const vigente = habilitada && estado.consulta === consulta

  return {
    habilitada,
    cargando: habilitada && !vigente,
    resultados: vigente ? estado.resultados : null,
    error: vigente ? estado.error : null,
  }
}
