import { useEffect, useMemo, useState } from 'react'
import { navegacionApi } from '../api/navegacionApi'
import { useSesion } from '../hooks/useSesion'
import { NavegacionContext } from './contextos'

/**
 * Menú dinámico leído de la API. Se vuelve a pedir cada vez que cambia el rol activo
 * (iniciar sesión, cambiar de rol o cerrar sesión).
 */
export function NavegacionProvider({ children }) {
  const { sesion, cargando: cargandoSesion } = useSesion()
  const [menu, setMenu] = useState({ clave: null, grupos: [], error: null })

  const [intento, setIntento] = useState(0)
  const claveRol = cargandoSesion ? null : String(sesion?.rol_activo?.id ?? 'visitante')

  useEffect(() => {
    if (claveRol === null) return undefined

    let vigente = true
    navegacionApi.obtenerMenu()
      .then((datos) => vigente && setMenu({ clave: claveRol, grupos: datos.grupos, error: null }))
      .catch((falla) => vigente && setMenu({ clave: claveRol, grupos: [], error: falla.mensaje }))

    return () => {
      vigente = false
    }
  }, [claveRol, intento])

  const valor = useMemo(() => {
    const vigente = claveRol !== null && menu.clave === claveRol
    const grupos = vigente ? menu.grupos : []
    const entradas = grupos.flatMap((grupo) => grupo.entradas)
    return {
      grupos,
      entradas,
      // Mientras la sesión se resuelve (claveRol null) el menú todavía no corresponde a nadie.
      cargando: claveRol === null || menu.clave !== claveRol,
      error: vigente ? menu.error : null,
      reintentar: () => setIntento((anterior) => anterior + 1),
      /** Entrada del menú que corresponde a una ruta (también a sus subrutas, p. ej. /catalogo/5). */
      entradaDeRuta: (ruta) => entradas
        .filter((entrada) => ruta === entrada.ruta || (entrada.ruta !== '/' && ruta.startsWith(`${entrada.ruta}/`)))
        .sort((a, b) => b.ruta.length - a.ruta.length)[0] || null,
    }
  }, [menu, claveRol])

  return <NavegacionContext.Provider value={valor}>{children}</NavegacionContext.Provider>
}
