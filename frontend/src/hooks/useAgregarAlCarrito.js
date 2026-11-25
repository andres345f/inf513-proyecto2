import { useCallback, useState } from 'react'
import { useLocation, useNavigate } from 'react-router-dom'
import { comercioApi } from '../api/comercioApi'
import { useSesion } from './useSesion'

/**
 * Agregar al carrito desde el catálogo o el detalle.
 * Un visitante es enviado a iniciar sesión y luego vuelve a la misma página.
 */
export function useAgregarAlCarrito() {
  const { sesion, cargando, tienePermiso } = useSesion()
  const navegar = useNavigate()
  const ubicacion = useLocation()
  const [estado, setEstado] = useState({ productoId: null, enviando: false, mensaje: null, tipo: null })

  const puedeComprar = !cargando && (!sesion || tienePermiso('carrito.usar'))

  const agregar = useCallback(async (producto, cantidad = 1) => {
    if (!sesion) {
      navegar('/ingresar', { state: { desde: ubicacion.pathname + ubicacion.search } })
      return
    }

    setEstado({ productoId: producto.id, enviando: true, mensaje: null, tipo: null })
    try {
      await comercioApi.agregarAlCarrito(producto.id, cantidad)
      setEstado({ productoId: producto.id, enviando: false, mensaje: `Agregaste «${producto.nombre}» al carrito.`, tipo: 'exito' })
    } catch (falla) {
      const detalle = Object.values(falla.errores || {})[0]?.[0]
      setEstado({ productoId: producto.id, enviando: false, mensaje: detalle || falla.mensaje, tipo: 'error' })
    }
  }, [sesion, navegar, ubicacion])

  const limpiar = useCallback(() => setEstado({ productoId: null, enviando: false, mensaje: null, tipo: null }), [])

  return { agregar, estado, limpiar, puedeComprar }
}
