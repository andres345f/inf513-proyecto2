import { Navigate } from 'react-router-dom'
import Cargando from '../components/ui/Cargando'
import { useSesion } from '../hooks/useSesion'

/** Páginas solo para quien no inició sesión (ingresar, registro). */
export default function RutaInvitado({ children }) {
  const { sesion, cargando } = useSesion()

  if (cargando) return <Cargando texto="Verificando tu sesión…" />
  if (sesion) return <Navigate to="/" replace />

  return children
}
