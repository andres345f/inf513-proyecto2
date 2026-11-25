import { Navigate, useLocation } from 'react-router-dom'
import Cargando from '../components/ui/Cargando'
import { useSesion } from '../hooks/useSesion'
import SinPermisoPagina from '../pages/estado/SinPermisoPagina'

/**
 * Exige sesión y, si se indica, un permiso del rol activo.
 * El backend vuelve a verificar el permiso en cada petición: esto solo mejora la experiencia.
 */
export default function RutaProtegida({ permiso, children }) {
  const { sesion, cargando, tienePermiso } = useSesion()
  const ubicacion = useLocation()

  if (cargando) return <Cargando texto="Verificando tu sesión…" />
  if (!sesion) return <Navigate to="/ingresar" replace state={{ desde: ubicacion.pathname + ubicacion.search }} />
  if (permiso && !tienePermiso(permiso)) return <SinPermisoPagina />

  return children
}
