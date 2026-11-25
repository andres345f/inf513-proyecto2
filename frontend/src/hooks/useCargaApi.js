import { useCallback, useEffect, useState } from 'react'

/**
 * Carga datos de la API cuando cambian las dependencias y expone recargar().
 * consulta: función que devuelve una promesa (normalmente de comercioApi).
 */
export function useCargaApi(consulta, dependencias = []) {
  const [estado, setEstado] = useState({ datos: null, error: null, cargando: true })
  const [version, setVersion] = useState(0)

  useEffect(() => {
    let vigente = true
    setEstado((actual) => ({ ...actual, actualizando: actual.datos !== null }))
    consulta()
      .then((datos) => vigente && setEstado({ datos, error: null, cargando: false }))
      .catch((falla) => vigente && setEstado((actual) => ({ ...actual, error: falla.mensaje, cargando: false, actualizando: false })))
    return () => {
      vigente = false
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [...dependencias, version])

  const recargar = useCallback(() => setVersion((v) => v + 1), [])
  const reemplazar = useCallback((datos) => setEstado({ datos, error: null, cargando: false }), [])

  return { actualizando: false, ...estado, recargar, reemplazar }
}
