import { useCallback, useEffect, useMemo, useState } from 'react'
import { autenticacionApi } from '../api/autenticacionApi'
import { almacenToken, EVENTO_SESION_EXPIRADA } from '../api/clienteHttp'
import { SesionContext } from './contextos'

/**
 * Estado de la sesión web: usuario, rol activo, roles vigentes y permisos.
 * El token vive en sessionStorage; los datos de la sesión siempre se piden a la API.
 */
export function SesionProvider({ children }) {
  const [sesion, setSesion] = useState(null)
  const [cargando, setCargando] = useState(() => Boolean(almacenToken.obtener()))

  useEffect(() => {
    if (!almacenToken.obtener()) return

    autenticacionApi.obtenerSesion()
      .then(setSesion)
      .catch(() => {
        almacenToken.limpiar()
        setSesion(null)
      })
      .finally(() => setCargando(false))
  }, [])

  useEffect(() => {
    const alExpirar = () => setSesion(null)
    window.addEventListener(EVENTO_SESION_EXPIRADA, alExpirar)
    return () => window.removeEventListener(EVENTO_SESION_EXPIRADA, alExpirar)
  }, [])

  const abrirSesion = useCallback((respuesta) => {
    const { token, ...datos } = respuesta
    almacenToken.guardar(token)
    setSesion(datos)
    return datos
  }, [])

  const iniciarSesion = useCallback(
    async (credenciales) => abrirSesion(await autenticacionApi.iniciarSesion(credenciales)),
    [abrirSesion],
  )

  const registrarse = useCallback(
    async (datos) => abrirSesion(await autenticacionApi.registrarse(datos)),
    [abrirSesion],
  )

  const cambiarRol = useCallback(async (rolId) => {
    const datos = await autenticacionApi.cambiarRol(rolId)
    setSesion(datos)
    return datos
  }, [])

  /** Guarda los datos personales y refresca la sesión (el encabezado muestra el nombre nuevo). */
  const actualizarPerfil = useCallback(async (datosPerfil) => {
    const { mensaje, ...datos } = await autenticacionApi.actualizarPerfil(datosPerfil)
    setSesion(datos)
    return mensaje
  }, [])

  const cerrarSesion = useCallback(async () => {
    try {
      await autenticacionApi.cerrarSesion()
    } catch {
      // Aunque el servidor no responda, la sesión local se cierra igual.
    } finally {
      almacenToken.limpiar()
      setSesion(null)
    }
  }, [])

  const tienePermiso = useCallback((permiso) => Boolean(sesion?.permisos?.includes(permiso)), [sesion])

  const valor = useMemo(
    () => ({ sesion, cargando, iniciarSesion, registrarse, cambiarRol, actualizarPerfil, cerrarSesion, tienePermiso }),
    [sesion, cargando, iniciarSesion, registrarse, cambiarRol, actualizarPerfil, cerrarSesion, tienePermiso],
  )

  return <SesionContext.Provider value={valor}>{children}</SesionContext.Provider>
}
